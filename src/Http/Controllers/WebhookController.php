<?php

declare(strict_types=1);

namespace Satlane\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Satlane\Exceptions\SignatureException;
use Satlane\Laravel\Exceptions\ConfigException;
use Satlane\Laravel\Models\SatlaneEvent;
use Satlane\Laravel\Models\SatlaneInvoice;
use Satlane\Laravel\Webhooks\WebhookHandler;
use Satlane\WebhookSignature;
use Throwable;

/**
 * Receives webhooks. Wired up via the Route::satlaneWebhook macro or
 * the auto-route in the service provider.
 *
 * Order of operations:
 *   1. Verify the signature. Bad sig → 400.
 *   2. Parse the JSON. Bad JSON → 400.
 *   3. Idempotency: insert into satlane_events. Duplicate → 200.
 *   4. Mirror the invoice into satlane_invoices (if enabled).
 *   5. Dispatch to the handler. If it throws, store the error and
 *      respond per config (`on_handler_error`).
 *   6. Mark processed_at, return 200.
 */
class WebhookController
{
    public function __invoke(Request $request, ?string $handlerClass = null): Response
    {
        $rawBody = $request->getContent();
        $sigHeader = $request->header('X-SatLane-Signature', '');

        // ── 1. Signature ────────────────────────────────────────
        $secret = config('satlane.webhook_secret');
        if (!$secret) {
            Log::error('satlane: webhook hit but SATLANE_WEBHOOK_SECRET is not set');
            return response('webhook secret not configured', 500);
        }

        $previous = config('satlane.webhook_secret_previous');
        $secrets = $previous ? [$secret, $previous] : $secret;

        try {
            WebhookSignature::verify(
                $rawBody,
                $sigHeader,
                $secrets,
                (int) config('satlane.signature_tolerance_seconds', 300),
            );
            $signatureVerified = true;
        } catch (SignatureException $e) {
            Log::warning('satlane: webhook signature rejected', [
                'reason' => $e->reason,
                'remote' => $request->ip(),
            ]);
            return response('invalid signature', 400);
        }

        // ── 2. Parse ────────────────────────────────────────────
        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['event_id']) || empty($event['event_type'])) {
            return response('malformed event', 400);
        }

        $eventId   = (string) $event['event_id'];
        $eventType = (string) $event['event_type'];
        $invoiceData = $event['data']['invoice'] ?? null;

        // ── 3. Idempotency ──────────────────────────────────────
        // Use database-level unique constraint instead of read-then-write
        // so two concurrent retries can't both proceed.
        try {
            $row = SatlaneEvent::create([
                'event_id'           => $eventId,
                'event_type'         => $eventType,
                'invoice_id'         => $invoiceData['id'] ?? null,
                'order_ref'          => $invoiceData['order_ref'] ?? null,
                'signature_verified' => $signatureVerified,
                'payload'            => $event,
                'received_at'        => now(),
            ]);
        } catch (Throwable $e) {
            // Almost certainly a unique-constraint violation on event_id.
            // Either we already processed it, or another retry just
            // landed concurrently. Either way: ack so SatLane stops.
            if ($this->shouldLog()) {
                Log::info('satlane: duplicate webhook event', ['event_id' => $eventId]);
            }
            return response('duplicate', 200);
        }

        // ── 4. Mirror invoice ────────────────────────────────────
        $invoice = null;
        if ($invoiceData && config('satlane.mirror_invoices', true)) {
            try {
                $invoice = $this->mirrorInvoice($invoiceData);
            } catch (Throwable $e) {
                Log::error('satlane: failed to mirror invoice', [
                    'event_id' => $eventId,
                    'error'    => $e->getMessage(),
                ]);
                // Fall through. Mirror failure shouldn't drop the event.
            }
        }

        // ── 5. Dispatch to handler ──────────────────────────────
        $handler = $this->resolveHandler($handlerClass);
        if ($handler && $invoice) {
            try {
                $handler->dispatch($eventType, $invoice);
                $row->update(['processed_at' => now()]);
            } catch (Throwable $e) {
                $row->update([
                    'handler_error'    => substr($e->getMessage(), 0, 64_000),
                    'handler_attempts' => $row->handler_attempts + 1,
                ]);
                Log::error('satlane: webhook handler threw', [
                    'event_id'   => $eventId,
                    'event_type' => $eventType,
                    'class'      => get_class($handler),
                    'error'      => $e->getMessage(),
                ]);
                $mode = config('satlane.on_handler_error', 'retry');
                if ($mode === 'retry') {
                    // 5xx → SatLane will retry the webhook.
                    return response('handler error (will retry)', 503);
                }
                // 'ignore' — return 200 so retries stop. Replay via the
                // satlane:replay command when the handler is fixed.
            }
        } else {
            $row->update(['processed_at' => now()]);
        }

        if ($this->shouldLog()) {
            Log::info('satlane: webhook processed', [
                'event_id'   => $eventId,
                'event_type' => $eventType,
                'invoice_id' => $invoice?->id,
            ]);
        }

        return response('ok', 200);
    }

    /**
     * Upsert into satlane_invoices using the just-arrived public
     * payload as source of truth.
     */
    private function mirrorInvoice(array $payload): SatlaneInvoice
    {
        $now = now();
        return SatlaneInvoice::updateOrCreate(
            ['id' => $payload['id']],
            [
                'order_ref'             => $payload['order_ref'] ?? null,
                'status'                => $payload['status'] ?? 'pending',
                'environment'           => $payload['environment'] ?? 'live',
                'address'               => $payload['address'] ?? '',
                'amount_sats'           => (int) ($payload['amount_sats'] ?? 0),
                'amount_paid_sats'      => (int) ($payload['amount_paid_sats'] ?? 0),
                'amount_tolerance_sats' => (int) ($payload['amount_tolerance_sats'] ?? 0),
                'amount_fiat'           => $payload['amount_fiat'] ?? null,
                'fiat_currency'         => $payload['fiat_currency'] ?? null,
                'btc_usd_rate'          => $payload['btc_usd_rate'] ?? null,
                'expires_at'            => isset($payload['expires_at']) ? new \DateTime($payload['expires_at']) : null,
                'paid_at'               => isset($payload['paid_at']) ? new \DateTime($payload['paid_at']) : null,
                'buyer_email'           => $payload['buyer_email'] ?? null,
                'callback_url'          => $payload['callback_url'] ?? null,
                'success_url'           => $payload['success_url'] ?? null,
                'hosted_checkout_url'   => $payload['hosted_checkout_url'] ?? null,
                'payment_uri'           => $payload['payment_uri'] ?? null,
                'raw'                   => $payload,
                'last_updated_at'       => $now,
            ],
        );
    }

    private function resolveHandler(?string $explicit): ?WebhookHandler
    {
        $class = $explicit ?? config('satlane.route.handler');
        if (!$class) return null;

        if (!class_exists($class)) {
            throw new ConfigException(
                "SatLane webhook handler class [{$class}] does not exist. " .
                'Set SATLANE_WEBHOOK_HANDLER or pass the class to the route macro.',
            );
        }

        $instance = app($class);
        if (!$instance instanceof WebhookHandler) {
            throw new ConfigException(
                "SatLane webhook handler [{$class}] must extend " .
                WebhookHandler::class . '.',
            );
        }
        return $instance;
    }

    private function shouldLog(): bool
    {
        return (bool) config('satlane.log_webhooks', true);
    }
}
