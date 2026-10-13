# SatLane Laravel integration

Invoice creation, verified webhook delivery, local invoice snapshots and safe replay. The prepared 0.2 release supports Laravel 10, 11 and 12; PHP 8.1 supports Laravel 10, while newer Laravel versions require PHP 8.2 or newer.

## Install and configure

Once 0.2 is published:

```bash
composer require satlane/satlane-laravel:^0.2
php artisan satlane:install
```

Before publication, use the monorepo path packages. Configure:

```dotenv
SATLANE_API_KEY=sl_test_your_store_key
SATLANE_API_BASE=https://api.satlane.com
SATLANE_CONTRACT_VERSION=2026-10-05
SATLANE_WEBHOOK_SECRET=your_endpoint_secret
SATLANE_WEBHOOK_HANDLER=App\Payments\BitcoinHandler
```

The installer publishes configuration/migrations, migrates, checks configuration and performs an authenticated invoice-list probe. It returns a failing exit code if any required step fails. `--no-ping`, `--no-migrate` and `--no-publish` are available for controlled setup; skipping a probe does not verify the key.

Configure the webhook endpoint in the SatLane dashboard for the matching store/environment. Local apps need a reachable public HTTPS tunnel: the SatLane server cannot deliver to your laptop's localhost. The default receiver path is `/webhooks/satlane` with signature verification; recovery belongs to the package, while fulfillment policy belongs to your handler.

## Create an invoice

```php
$client = app(\Satlane\Client::class);
$invoice = $client->invoices->create([
    'amount' => 49.99,
    'currency' => 'USD',
    'order_ref' => (string) $order->id,
], idempotencyKey: 'checkout-' . $order->id);
return redirect()->away($invoice['hosted_checkout_url']);
```

Persist the invoice ID and idempotency key with the order. The retention for API idempotency is 24 hours. Reusing an order key with a different request body is an error.

Test keys automatically send the current Sandbox version. Wallet-free Sandbox needs no receiving wallet and accepts only supported simulation transitions. Use the dashboard simulator or `$client->invoices->simulate($id, ['event'=>'paid'])`. There is no `satlane:simulate` command. Live requires a Live key, explicit store activation, a mainnet receiving wallet, account eligibility and synchronized chain services.

## Handle payments

```php
class BitcoinHandler extends \Satlane\Laravel\Webhooks\WebhookHandler
{
    public function paid(\Satlane\Laravel\Models\SatlaneInvoice $invoice): void
    {
        // Make the order transition idempotent in your own database.
        // Use $invoice->order_ref to locate it and confirm its expected amount.
    }
}
```

Handlers are synchronous: keep them short. If fulfillment needs long-running work, durably enqueue your application's job on a compatible database connection before returning. Do not make a remote external side effect and assume the SDK can roll it back.

`created`, `paymentSeen`, `paid`, `latePaid`, `underpaid`, `overpaid`, `expired`, `paymentReverted`, `requiresReview`, `cancelled` and `graceEnding` are dispatched by their event types. Unknown events reach `default`. `webhook.test` is a connectivity event with no invoice.

Only confirmed settlement should trigger normal fulfillment. An underpaid invoice can have its full balance received while confirmations remain pending; inspect `payment_phase` and `payment_summary`. Excess payments need your own fulfillment/refund policy. Chain-reversion events must still be handled even after previous settlement.

## Durable processing and replay

The receiver verifies raw-body HMAC, persists the event and serializes processing per invoice using database locks. A completed duplicate is acknowledged; a failed event is tried again. Storage/mirroring failures return a retryable response. Event and mirror state are not marked complete before required handler work succeeds.

`SATLANE_ON_HANDLER_ERROR=retry` is the default. `ignore` acknowledges processing errors while retaining failure state for manual recovery. Event-storage failures always return 503. Business actions must be idempotent because a process can crash after an external effect but before recording completion.

```bash
php artisan satlane:replay --failed
php artisan satlane:replay EVENT_ID
```

Replay uses the same processing locks and the saved event's invoice snapshot. Completed events are skipped by default. `--force` allows an explicit replay of completed/stale events and can repeat external side effects, so review the target first. Older event timestamps do not overwrite the newer mirrored projection or execute stale handlers automatically. Newer reversion events are allowed; the SDK does not rank statuses as permanently final.

**Ordering limit:** SatLane's current webhook envelope has a timestamp but no monotonic invoice revision. Events with equal or missing timestamps cannot be conclusively ordered. Treat the mirror as a recorded projection, not an authoritative real-time read. For decisions requiring the latest state, retrieve the invoice through the authenticated API and apply an idempotent business policy.

## Models and storage

The new additive migration preserves existing records, makes receiving addresses nullable and adds `store_id`, `payment_phase`, `payment_summary`, `checkout_urls`, `last_event_at` and `last_event_unix_ms`. Raw invoice JSON remains available. Run migrations during upgrades before accepting deliveries; do not edit previously published migrations.

`SATLANE_MIRROR_INVOICES=false` disables invoice persistence, while handlers still receive an unsaved `SatlaneInvoice` built from the event snapshot. Do not call `save()` on it unless your application deliberately wants to persist it. Event logs and processing locks remain necessary. Without a mirrored projection, your application must enforce event ordering itself.

`SATLANE_DB_CONNECTION` selects the package database connection. Same-connection handler writes participate in the processing transaction; separate database connections and external services do not. PostgreSQL/MySQL row locks support concurrent receiver processes; SQLite is suitable for local single-process tests, not the recommended production receiver database.

`php artisan satlane:prune` removes only old completed events with no recorded error. Pending/failed work is preserved. Retention also defines how long completed event IDs remain deduplicated; select it to cover your delivery/replay policy. Invoice-lock anchors are retained to avoid races with active processing.

## Multiple stores and environments

Use separate API clients and webhook secrets for each store/environment. The default route configuration represents one connection. For more connections, register explicit routes with their connection settings:

```php
Route::satlaneWebhook('/webhooks/satlane/store-a', StoreAHandler::class, [
    'secret' => config('payments.store_a.webhook_secret'),
    'previous_secret' => config('payments.store_a.previous_secret'),
    'store_id' => config('payments.store_a.store_id'),
    'environment' => 'live',
]);
```

Set `SATLANE_ROUTE_ENABLED=false` when managing all routes explicitly. The default route can also enforce `SATLANE_STORE_ID` and `SATLANE_ENVIRONMENT`. Do not let a request parameter select an arbitrary secret or API key. Never send API keys to the browser.

During secret rotation, `SATLANE_WEBHOOK_SECRET_PREVIOUS` can verify the prior secret. Remove it after the rotation window rather than leaving it valid indefinitely.

## Upgrade and development

Read [UPGRADING.md](UPGRADING.md) and [AI-INTEGRATION.md](AI-INTEGRATION.md). The PHP SDK's old webhook-management methods are unavailable to bearer keys; use the dashboard instead.

```bash
composer install
composer test
```

The monorepo validation workflow covers the declared PHP/Laravel combinations before publication. Release commands default to a dry run; they do not publish unless explicitly requested.

Legacy Laravel 10/11 compatibility tests report upstream framework advisories. Use a supported, patched Laravel version for production; compatibility coverage does not certify those framework branches as secure.
