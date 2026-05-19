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

---

## Integrating alongside your existing payment provider

Most apps already accept Stripe / PayPal / Paystack / Flutterwave / something. SatLane is designed to drop in as **one more option** on the same checkout, with no opinion about your existing stack. This section is a worked example for a shop that wants to add "Pay with Bitcoin" next to a Stripe button.

### The data model

Your `orders` table already has the columns you need; you just add a couple:

```php
// migration on YOUR orders table
Schema::table('orders', function (Blueprint $t) {
    $t->string('payment_method', 32)->nullable()->index();
    //   'stripe' | 'paypal' | 'satlane' | 'manual' | ...

    $t->uuid('satlane_invoice_id')->nullable()->index();
    //   foreign key into satlane_invoices. Not enforced at the DB level
    //   because satlane_invoices is populated by webhook (may arrive
    //   slightly after the order row, especially under load).
});
```

That's the only schema change. The `satlane_invoices` and `satlane_events` tables already exist from `satlane:install`.

### The Order model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Satlane\Laravel\Models\SatlaneInvoice;

class Order extends Model
{
    protected $casts = [
        'amount_usd'   => 'decimal:2',
        'paid_at'      => 'datetime',
    ];

    public function satlaneInvoice()
    {
        return $this->belongsTo(SatlaneInvoice::class, 'satlane_invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function markPaid(array $context = []): void
    {
        if ($this->isPaid()) return; // idempotent
        $this->update([
            'paid_at' => now(),
            'payment_meta' => array_merge($this->payment_meta ?? [], $context),
        ]);
        OrderPaid::dispatch($this);  // your existing event
    }

    public function reverseFulfillment(): void
    {
        // Whatever you do on a Stripe refund: ship cancel, license revoke,
        // etc. Triggered by SatLane's invoice.payment_reverted (rare).
    }
}
```

### The checkout controller — two payment methods, one page

```php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Satlane;
use Stripe\Checkout\Session as StripeSession;

class CheckoutController
{
    public function start(Request $request, Order $order)
    {
        $method = $request->validate(['method' => 'required|in:stripe,satlane'])['method'];

        return match ($method) {
            'stripe'  => $this->startStripe($order),
            'satlane' => $this->startSatlane($order),
        };
    }

    private function startStripe(Order $order)
    {
        $session = StripeSession::create([ /* … */ ]);
        $order->update(['payment_method' => 'stripe']);
        return redirect()->away($session->url);
    }

    private function startSatlane(Order $order)
    {
        $invoice = Satlane::invoices()->create([
            'amount'       => $order->amount_usd,
            'currency'     => 'USD',
            'order_ref'    => $order->id,
            'success_url'  => route('orders.thanks', $order),
            'expires_in_minutes' => 15,
        ], idempotencyKey: "order-{$order->id}-checkout");

        $order->update([
            'payment_method'      => 'satlane',
            'satlane_invoice_id'  => $invoice['id'],
        ]);

        return redirect()->away($invoice['hosted_checkout_url']);
    }
}
```

The customer picks Bitcoin or card on the same page. Each path posts to the same controller; nothing else in the app needs to know which gateway is active for a given order.

### Two webhook handlers, two URLs, one Order model

Stripe and SatLane both POST you webhooks but at **different routes**, so they coexist trivially.

```php
// Stripe — your existing handler stays exactly as it is
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware(VerifyCsrfToken::class);

// SatLane — auto-registered when SATLANE_WEBHOOK_HANDLER is set in .env.
// No route line needed.
```

Your SatLane handler:

```php
namespace App\Satlane;

use App\Models\Order;
use Satlane\Laravel\Models\SatlaneInvoice;
use Satlane\Laravel\Webhooks\WebhookHandler;

class OrderWebhookHandler extends WebhookHandler
{
    public function paid(SatlaneInvoice $invoice): void
    {
        // order_ref was what you sent on createInvoice. Use it to find
        // the row — never trust the network for the order id.
        Order::where('id', $invoice->order_ref)
            ->where('payment_method', 'satlane')   // belt-and-braces
            ->first()
            ?->markPaid([
                'gateway'     => 'satlane',
                'sats'        => $invoice->amount_paid_sats,
                'invoice_id'  => $invoice->id,
                'rate_locked' => $invoice->btc_usd_rate,
            ]);
    }

    public function latePaid(SatlaneInvoice $invoice): void
    {
        $this->paid($invoice);   // same fulfillment path
    }

    public function underpaid(SatlaneInvoice $invoice): void
    {
        // Don't fulfill. The buyer will (usually) send the remaining
        // amount and you'll get a second event when they do — top-ups
        // auto-merge into a `paid` transition. Optionally surface a
        // banner: "Partial payment received, awaiting balance."
    }

    public function paymentReverted(SatlaneInvoice $invoice): void
    {
        // Reorgs are rare but real. If you already shipped, reverse it.
        Order::where('id', $invoice->order_ref)
            ->first()
            ?->reverseFulfillment();
    }

    public function expired(SatlaneInvoice $invoice): void
    {
        // The invoice ran out before payment landed. Allow the buyer to
        // re-checkout, optionally with a different method this time.
        Order::where('id', $invoice->order_ref)
            ->update(['payment_method' => null, 'satlane_invoice_id' => null]);
    }
}
```

Stripe and SatLane each fire into their own handler. The two never collide.

### Rendering "current state" in your UI

Anywhere you used to call `Stripe::retrieveSession()` to know if an order was paid, just query locally:

```blade
@php
    $order   = Auth::user()->orders()->findOrFail($id);
    $invoice = $order->satlaneInvoice;   // null if they paid with Stripe
@endphp

@if ($order->isPaid())
    <p>Paid · {{ $order->paid_at->diffForHumans() }}</p>
@elseif ($invoice && $invoice->isUnderpaid())
    <div class="alert alert-warning">
        Partial payment received. Send <strong>{{ number_format($invoice->remainingSats()) }} more sats</strong>
        (about ${{ number_format($invoice->remainingSats() * $invoice->btc_usd_rate / 1e8, 2) }})
        to the same address. Or
        <a href="{{ $invoice->hosted_checkout_url }}">open the invoice page</a>.
    </div>
@elseif ($invoice)
    <a href="{{ $invoice->hosted_checkout_url }}" class="btn">
        Continue Bitcoin payment ({{ $invoice->status }})
    </a>
@else
    {{-- offer the gateway picker --}}
@endif
```

No HTTP round-trip to SatLane. The `satlane_invoices` row is updated on every webhook, so reads are fresh and fast.

### Coexistence checklist

| Concern | How it works |
|---|---|
| Two webhook URLs | Stripe at `/webhooks/stripe`, SatLane at `/webhooks/satlane`. Independent. |
| Two webhook secrets | `STRIPE_WEBHOOK_SECRET` + `SATLANE_WEBHOOK_SECRET`. Separate envs. |
| One Order model | `payment_method` column tells you which gateway owns the row. Handler filters by it before mutating. |
| Refunds | Stripe: API call. SatLane: not applicable (non-custodial — vendor sends BTC back manually if needed). The SDK doesn't try to abstract this. |
| Multi-currency | Pass `currency: 'USD'` (only `USD` at MVP). For other fiat, fix the price in your app and use `amount_sats` directly. |
| Test mode | Stripe has `sk_test_*`; SatLane has `sl_test_*`. Both keys can sit in the same dev `.env`. SatLane's test-mode invoices never touch the chain; trigger events from the dashboard's Simulator card. |
| Going live | Flip Stripe to `sk_live_*` and SatLane to `sl_live_*` independently. They don't share any state. |

---

## Testing locally with both gateways

You'll need to expose your local Laravel app to the public internet so the webhooks can reach you. Both Stripe and SatLane work the same way.

```bash
# 1. Local Laravel
php artisan serve

# 2. Public tunnel (Cloudflare's is free, no signup)
cloudflared tunnel --url http://localhost:8000
# Or use ngrok / expose / tailscale funnel — any of them work

# 3. Set the resulting URL as your webhook target:
#    - Stripe:  https://dashboard.stripe.com/test/webhooks → add endpoint
#    - SatLane: app.satlane.com → Store → Webhooks → add endpoint
#    Both URLs point at YOUR_TUNNEL/webhooks/<provider>.
```

Make a test order, pick "Pay with Bitcoin," get redirected to `pay.satlane.com/i/<id>`, hit the **Simulate payment** button on the invoice's detail page in your SatLane dashboard, watch your handler fire. Your Stripe button works identically with their test cards.

---

## Production deployment checklist

Before flipping to live mode:

- [ ] `SATLANE_API_KEY` starts with `sl_live_` (not `sl_test_`).
- [ ] Store on the SatLane dashboard has `test_mode = false`.
- [ ] Mainnet xpub registered on the store (not testnet/regtest).
- [ ] `SATLANE_WEBHOOK_SECRET` is the live-mode secret (refresh from dashboard if you accidentally seeded the test value).
- [ ] Webhook URL is HTTPS, reachable from the public internet, returns 2xx in under 10 seconds.
- [ ] Your handler is idempotent — same `event_id` arriving twice doesn't double-fulfill. The SDK enforces this via the unique constraint on `satlane_events.event_id`; you get this for free.
- [ ] You handle `invoice.payment_reverted` (rare but real on chain reorgs).
- [ ] `php artisan satlane:install` reports no warnings.
- [ ] Schedule `satlane:prune` daily if you keep events for less than forever:
      ```php
      // app/Console/Kernel.php
      $schedule->command('satlane:prune')->dailyAt('03:00');
      ```
- [ ] Do a $1 mainnet smoke test: real invoice, pay from your own wallet, confirm webhook + order state transition.

---

## FAQ

**Can I use SatLane without storing anything locally?**
Yes. Set `SATLANE_MIRROR_INVOICES=false` and the SDK only writes to `satlane_events` (idempotency). Query `Satlane::invoices()->retrieve($id)` whenever you need invoice state. Trade-off: a network round-trip per render.

**Can I disable the auto-route and register it myself?**
Yes. Set `SATLANE_ROUTE_ENABLED=false`, then:
```php
Route::satlaneWebhook('/payments/bitcoin', App\Satlane\OrderWebhookHandler::class)
    ->middleware('api', 'throttle:60,1');
```

**Can I have multiple handlers for different stores?**
The macro accepts any handler class, so route by URL:
```php
Route::satlaneWebhook('/webhooks/satlane/store-a', StoreAHandler::class);
Route::satlaneWebhook('/webhooks/satlane/store-b', StoreBHandler::class);
```
Each store in the SatLane dashboard points at its own URL. Idempotency tables (`satlane_events`) are shared but `event_id` is globally unique so there's no collision.

**What happens if SatLane is unreachable when I try to create an invoice?**
The SDK auto-retries 5xx + 429 + network errors with backoff. If retries exhaust, an `ApiException` is thrown. Catch it and show the buyer the Stripe option (or whatever other gateway you have).

**Do I need to verify the signature in my handler too?**
No — the package does it before your handler is called. By the time `paid()`/`underpaid()`/etc. fires, the event is verified and persisted. If you bypass the macro/auto-route and call the handler directly (e.g. from `satlane:replay`), there's nothing to verify; the row was already validated when it was stored.

**What if I don't want the `satlane_invoices` table at all?**
Skip its migration. Set `SATLANE_MIRROR_INVOICES=false`. Only `satlane_events` remains, which you can also skip if you provide your own idempotency layer (e.g. cache-based). The package gracefully no-ops on missing tables.

## License

MIT.
