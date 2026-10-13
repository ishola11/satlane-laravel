<?php

declare(strict_types=1);

namespace Satlane\Laravel\Webhooks;

use Illuminate\Database\QueryException;
use Satlane\Laravel\Exceptions\ConfigException;
use Satlane\Laravel\Models\SatlaneEvent;
use Satlane\Laravel\Models\SatlaneInvoice;
use Throwable;

/** HTTP deliveries and manual replay share the same per-invoice DB lock.
 * Keep handlers short and business side effects idempotent: external effects
 * cannot be rolled back if the process crashes before the transaction commits. */
final class EventProcessor
{
    public function receive(array $event, ?string $handlerClass): SatlaneEvent
    {
        $attributes = [
            'event_id' => $event['event_id'], 'event_type' => $event['event_type'],
            'invoice_id' => $event['data']['invoice']['id'] ?? null,
            'order_ref' => $event['data']['invoice']['order_ref'] ?? null,
            'signature_verified' => true, 'payload' => $event, 'received_at' => now(),
            'handler_class' => $handlerClass ?? config('satlane.route.handler'),
        ];
        try {
            return SatlaneEvent::create($attributes);
        } catch (QueryException $e) {
            // Only a unique-key violation with an actual matching event is a duplicate.
            $state = $e->errorInfo[0] ?? null;
            $driver = (int) ($e->errorInfo[1] ?? 0);
            if ($state !== '23505' && !($state === '23000' && in_array($driver, [19,1062,2601,2627], true))) {
                throw $e;
            }
            $existing = SatlaneEvent::where('event_id', $event['event_id'])->first();
            if (!$existing || $existing->payload != $event) {
                throw $e;
            }
            if (!$existing->handler_class && $attributes['handler_class']) {
                $existing->update(['handler_class' => $attributes['handler_class']]);
            }
            return $existing;
        }
    }
    public function process(SatlaneEvent $event, bool $force = false): string
    {
        $connection = $event->getConnection();
        try {
            return $connection->transaction(function () use ($event, $connection, $force) {
                if ($event->invoice_id) {
                    $locks = $connection->table(config('satlane.invoice_locks_table', 'satlane_invoice_locks'));
                    $locks->insertOrIgnore(['invoice_id' => $event->invoice_id]);
                    $connection->table(config('satlane.invoice_locks_table', 'satlane_invoice_locks'))->where('invoice_id', $event->invoice_id)->lockForUpdate()->first();
                }
                $row = SatlaneEvent::whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                if ($row->isProcessed() && !$force) {
                    return 'duplicate';
                }
                $payload = $row->payload['data']['invoice'] ?? null;
                $invoice = null;
                if (is_array($payload)) {
                    $attributes = $this->attributes($payload);
                    // Event snapshots remain separate from the newest local projection.
                    $snapshot = new SatlaneInvoice($attributes);
                    $snapshot->id = $payload['id'];
                    $projection = SatlaneInvoice::find($payload['id']);
                    $at = isset($row->payload['created_at']) ? new \DateTimeImmutable($row->payload['created_at']) : null;
                    $unixMs = $at ? (int) round((float)$at->format('U.u') * 1000) : null;
                    $stale = $unixMs !== null && $projection?->last_event_unix_ms !== null && $unixMs < $projection->last_event_unix_ms;
                    if ($stale && !$force) {
                        $row->update(['processed_at' => now(),'handler_error' => null,'processing_result' => 'stale']);
                        return 'stale';
                    }
                    if (config('satlane.mirror_invoices', true) && !$stale) {
                        $invoice = SatlaneInvoice::updateOrCreate(['id' => $payload['id']], $attributes + ['last_event_at' => $at ? \Carbon\CarbonImmutable::instance($at)->setTimezone(config('app.timezone', 'UTC')) : null, 'last_event_unix_ms' => $unixMs]);
                    } else {
                        $invoice = $snapshot;
                    }
                }
                $handler = $this->handler($row->handler_class ?? config('satlane.route.handler'));
                if ($handler && $invoice) {
                    $handler->dispatch($row->event_type, $invoice);
                }
                $row->update(['processed_at' => now(),'handler_error' => null,'processing_result' => 'processed','handler_attempts' => $row->handler_attempts + 1]);
                return 'processed';
            }, 1);
        } catch (Throwable $e) {
            // The transaction rolled back mirror and same-connection business writes.
            // Do not overwrite a concurrent successful retry's completion marker.
            try {
                SatlaneEvent::whereKey($event->getKey())->whereNull('processed_at')->update([
                    'handler_error' => substr($e->getMessage(), 0, 64000),
                    'handler_attempts' => $connection->raw('handler_attempts + 1'),
                    'processing_result' => 'failed',
                ]);
            } catch (Throwable) { /* Storage may itself be unavailable; still fail delivery. */
            }
            throw $e;
        }
    }
    private function handler(?string $class): ?WebhookHandler
    {
        if (!$class) {
            return null;
        }
        if (!class_exists($class)) {
            throw new ConfigException('Configured SatLane webhook handler does not exist.');
        }
        $handler = app($class);
        if (!$handler instanceof WebhookHandler) {
            throw new ConfigException('SatLane handler must extend WebhookHandler.');
        }
        return $handler;
    }
    private function attributes(array $payload): array
    {
        return [
            'order_ref' => $payload['order_ref'] ?? null, 'store_id' => $payload['store_id'] ?? null,
            'status' => $payload['status'] ?? 'pending', 'environment' => $payload['environment'] ?? 'unknown',
            'address' => $payload['address'] ?? null,
            'amount_sats' => (int)($payload['amount_sats'] ?? 0), 'amount_paid_sats' => (int)($payload['amount_paid_sats'] ?? 0),
            'amount_tolerance_sats' => (int)($payload['amount_tolerance_sats'] ?? 0),
            'amount_fiat' => $payload['amount_fiat'] ?? null,'fiat_currency' => $payload['fiat_currency'] ?? null,
            'btc_usd_rate' => $payload['btc_usd_rate'] ?? null,
            'expires_at' => $payload['expires_at'] ?? null,'paid_at' => $payload['paid_at'] ?? null,
            'buyer_email' => $payload['buyer_email'] ?? null,'callback_url' => $payload['callback_url'] ?? null,
            'success_url' => $payload['success_url'] ?? null,'hosted_checkout_url' => $payload['hosted_checkout_url'] ?? null,
            'payment_uri' => $payload['payment_uri'] ?? null,'payment_phase' => $payload['payment_phase'] ?? null,
            'payment_summary' => $payload['payment_summary'] ?? null,'checkout_urls' => $payload['checkout_urls'] ?? null,
            'raw' => $payload,'last_updated_at' => now(),
        ];
    }
}
