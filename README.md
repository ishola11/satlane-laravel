# satlane-laravel

Laravel integration for [SatLane](https://satlane.com) — non-custodial Bitcoin payments. Builds on the framework-agnostic [`satlane/satlane-php`](../satlane-php) and adds:

- Auto-mirrored `satlane_invoices` table so you can query invoice state with Eloquent.
- Idempotent webhook receiver with signature verification, event log, replay command.
- Typed handler base class — one method per event type.
- `php artisan satlane:install` wizard that validates env, runs migrations, and pings the API.
- Daily prune command.

```bash
composer require satlane/satlane-laravel
php artisan satlane:install
```

## Configuration

`satlane:install` publishes `config/satlane.php` and the two migrations, then validates your `.env`:

```bash
SATLANE_API_KEY=sl_test_abc...
SATLANE_WEBHOOK_SECRET=whsec_xyz...
SATLANE_API_BASE=https://api.satlane.com   # optional

# Auto-route — point at your handler class
SATLANE_WEBHOOK_HANDLER="App\Satlane\OrderWebhookHandler"
SATLANE_ROUTE_PATH=/webhooks/satlane       # optional, defaults to this

# Optional: rotation grace period
SATLANE_WEBHOOK_SECRET_PREVIOUS=whsec_old...
```

## Writing your handler

Subclass `Satlane\Laravel\Webhooks\WebhookHandler`. Implement only the event types you care about:

```php
namespace App\Satlane;

use App\Models\Order;
use Satlane\Laravel\Models\SatlaneInvoice;
use Satlane\Laravel\Webhooks\WebhookHandler;

class OrderWebhookHandler extends WebhookHandler
{
    public function paid(SatlaneInvoice $invoice): void
    {
        Order::find($invoice->order_ref)?->markPaid([
            'sats'   => $invoice->amount_paid_sats,
            'tx'     => $invoice->raw['payments'][0]['txid'] ?? null,
        ]);
    }

    public function latePaid(SatlaneInvoice $invoice): void
    {
        $this->paid($invoice); // same fulfillment path
    }

    public function underpaid(SatlaneInvoice $invoice): void
    {
        Order::find($invoice->order_ref)?->update([
            'state'              => 'partial',
            'satlane_remaining'  => $invoice->remainingSats(),
        ]);
    }

    public function paymentReverted(SatlaneInvoice $invoice): void
    {
        Order::find($invoice->order_ref)?->reverseFulfillment();
    }
}
```

The route is auto-registered when `SATLANE_WEBHOOK_HANDLER` is set. Want to register manually instead? Set `SATLANE_ROUTE_ENABLED=false` and use the macro:

```php
Route::satlaneWebhook('/payments/satlane', App\Satlane\OrderWebhookHandler::class)
    ->middleware('api');
```

CSRF is excluded automatically — the signature header is the credential.

## Creating invoices

The `Satlane` facade exposes the underlying [`satlane/satlane-php`](../satlane-php) client:

```php
use Satlane;

$invoice = Satlane::invoices()->create([
    'amount'       => 49.99,
    'currency'     => 'USD',
    'order_ref'    => $order->id,
    'success_url'  => route('orders.thanks', $order),
], idempotencyKey: "order-{$order->id}-checkout");

return redirect()->away($invoice['hosted_checkout_url']);
```

## Querying local state

Because every webhook updates `satlane_invoices`, your views and reports can join with it like any other table:

```php
$invoice = SatlaneInvoice::where('order_ref', $order->id)->first();

if ($invoice?->isPaid()) {
    echo "Confirmed at {$invoice->paid_at}";
}

if ($invoice?->isUnderpaid()) {
    echo "Send {$invoice->remainingSats()} more sats";
}

// Recent events for one order:
$invoice->events()->orderBy('received_at', 'desc')->get();
```

## Error handling

**On install** — `php artisan satlane:install` reports every problem with a clear message:

- Missing `SATLANE_API_KEY` → "Mint one at Store → API Keys in the dashboard."
- Malformed key prefix → "Expected `sl_test_…` or `sl_live_…`."
- Missing webhook secret → "Create a webhook endpoint in the dashboard."
- Route enabled but no handler class → "Set `SATLANE_WEBHOOK_HANDLER` or call `Route::satlaneWebhook(...)`."
- Non-HTTPS API base in production → fails the check.
- API ping fails → shows status + error code + `request_id` for support.

**At webhook receive time** — the controller surfaces problems explicitly:

| Condition | Response | Behavior |
|---|---|---|
| Signature missing / wrong | `400` | Logged, event NOT stored. |
| Body malformed | `400` | Logged. |
| `event_id` already seen | `200` | Idempotency: handler is NOT re-invoked. |
| Handler throws, `on_handler_error: retry` | `503` | SatLane retries with backoff. Error stored on event row. |
| Handler throws, `on_handler_error: ignore` | `200` | SatLane stops retrying. Replay later with `satlane:replay`. |
| Mirror to `satlane_invoices` fails | `200` | Event still recorded; logged for ops. Handler still dispatched. |
| Config invalid (e.g. handler class missing) | `500` | `Satlane\Laravel\Exceptions\ConfigException` thrown — install would have caught this. |

**At runtime** — the SDK's `ApiException` has `code()`, `requestId()`, and `isRetryable()` so you can route exceptions:

```php
try {
    $invoice = Satlane::invoices()->create([...]);
} catch (\Satlane\Exceptions\ApiException $e) {
    if ($e->code() === 'gap_limit_exceeded') {
        // tell the user to extend their wallet's gap limit
    } elseif ($e->isRetryable()) {
        // temporary infra issue — back off and retry
    }
    Log::error('satlane invoice create failed', [
        'code'       => $e->code(),
        'status'     => $e->status,
        'request_id' => $e->requestId(),
    ]);
    throw $e;
}
```

## Commands

| Command | Purpose |
|---|---|
| `satlane:install` | Publish, migrate, validate env, ping API. Re-runnable. |
| `satlane:prune [--days=N]` | Drop `satlane_events` older than retention. Schedule daily. |
| `satlane:replay <event_id>` | Re-dispatch one stored event to your handler. |
| `satlane:replay --failed` | Re-dispatch every event with a non-null `handler_error`. |

## What lives where

| | `satlane/satlane-php` | `satlane/satlane-laravel` |
|---|---|---|
| HTTP client | ✓ | (re-exports) |
| Signature verification | ✓ | (re-exports) |
| Typed exceptions | ✓ | + `ConfigException` |
| Eloquent models | — | ✓ |
| Migrations | — | ✓ |
| Webhook receiver | — | ✓ |
| Handler base class | — | ✓ |
| Install wizard / commands | — | ✓ |

Use just the core SDK if you're on Symfony, CodeIgniter, or plain PHP. Use this one if you're on Laravel.

## License

MIT.
