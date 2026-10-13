<?php

declare(strict_types=1);

namespace Satlane\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Satlane\Exceptions\SignatureException;
use Satlane\WebhookSignature;
use Throwable;

/** Verify and durably accept a webhook, then delegate to the shared processor.
 * Storage failures are always retryable; handler failures follow explicit policy. */
class WebhookController
{
    public function __invoke(Request $request, ?string $handlerClass = null, array $connection = []): Response
    {
        $rawBody = $request->getContent();
        $sigHeader = $request->header('X-SatLane-Signature', '');

        // ── 1. Signature ────────────────────────────────────────
        $secret = $connection['secret'] ?? config('satlane.webhook_secret');
        if (!$secret) {
            Log::error('satlane: webhook hit but SATLANE_WEBHOOK_SECRET is not set');
            return response('webhook secret not configured', 500);
        }

        $previous = isset($connection['secret']) ? ($connection['previous_secret'] ?? null) : config('satlane.webhook_secret_previous');
        $secrets = $previous ? [$secret, $previous] : $secret;

        try {
            WebhookSignature::verify(
                $rawBody,
                $sigHeader,
                $secrets,
                (int) config('satlane.signature_tolerance_seconds', 300),
            );
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

        if (!is_string($event['event_id']) || strlen($event['event_id']) > 64 || !is_string($event['event_type']) || strlen($event['event_type']) > 64) {
            return response('malformed event', 400);
        }
        if (str_starts_with($eventType, 'invoice.') && (!is_array($invoiceData) || !is_string($invoiceData['id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $invoiceData['id']))) {
            return response('malformed invoice event', 400);
        }
        if (isset($event['created_at']) && (!is_string($event['created_at']) || strtotime($event['created_at']) === false)) {
            return response('malformed event timestamp', 400);
        }
        $expectedStore = $connection['store_id'] ?? config('satlane.route.store_id');
        $expectedEnvironment = $connection['environment'] ?? config('satlane.route.environment');
        if (is_array($invoiceData) && (($expectedStore && ($invoiceData['store_id'] ?? null) !== $expectedStore) || ($expectedEnvironment && ($invoiceData['environment'] ?? null) !== $expectedEnvironment))) {
            return response('invoice connection mismatch', 400);
        }
        $processor = new \Satlane\Laravel\Webhooks\EventProcessor();
        try {
            $row = $processor->receive($event, $handlerClass);
        } catch (Throwable $e) {
            Log::error('satlane: could not persist webhook', ['event_id' => $eventId]);
            return response('event storage unavailable', 503);
        }
        try {
            return response($processor->process($row), 200);
        } catch (Throwable $e) {
            Log::error('satlane: webhook processing failed', ['event_id' => $eventId]);
            return response('event processing failed', config('satlane.on_handler_error', 'retry') === 'ignore' ? 200 : 503);
        }
    }
}
