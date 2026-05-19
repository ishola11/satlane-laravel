<?php

declare(strict_types=1);

return [
    // ─── Credentials ────────────────────────────────────────────────
    'api_key'        => env('SATLANE_API_KEY'),
    'api_base'       => env('SATLANE_API_BASE', 'https://api.satlane.com'),
    'webhook_secret' => env('SATLANE_WEBHOOK_SECRET'),

    // Optional second secret valid during a rotation. SatLane keeps the
    // previous secret usable for 24h after `webhooks/:id/rotate`.
    'webhook_secret_previous' => env('SATLANE_WEBHOOK_SECRET_PREVIOUS'),

    // ─── Storage ───────────────────────────────────────────────────
    // Use a specific DB connection (e.g. 'satlane') or null for default.
    'connection'      => env('SATLANE_DB_CONNECTION'),
    'invoices_table'  => 'satlane_invoices',
    'events_table'    => 'satlane_events',

    // When true, every webhook updates the matching row in
    // `satlane_invoices` so your app can query invoice state locally
    // without an HTTP round-trip. When false, only `satlane_events` is
    // written (idempotency + audit log only).
    'mirror_invoices' => env('SATLANE_MIRROR_INVOICES', true),

    // Drop event rows older than this. `php artisan satlane:prune`
    // honors this. 0 = keep forever.
    'retain_events_days' => (int) env('SATLANE_RETAIN_EVENTS_DAYS', 90),

    // ─── Webhook receiver ──────────────────────────────────────────
    // Enable the built-in webhook route. Set `handler` to your handler
    // class (must extend Satlane\Laravel\Webhooks\WebhookHandler).
    // Alternatively, register a route yourself with the macro:
    //
    //   Route::satlaneWebhook('/webhooks/satlane', YourHandler::class);
    'route' => [
        'enabled'    => env('SATLANE_ROUTE_ENABLED', true),
        'path'       => env('SATLANE_ROUTE_PATH', '/webhooks/satlane'),
        'name'       => 'webhooks.satlane',
        'middleware' => ['api'],
        'handler'    => env('SATLANE_WEBHOOK_HANDLER'),
    ],

    // Replay protection. Reject events older than this many seconds.
    'signature_tolerance_seconds' => (int) env('SATLANE_SIG_TOLERANCE', 300),

    // ─── Behavior ──────────────────────────────────────────────────
    // When a handler method throws, what should we do?
    //   - 'retry':  return 5xx so SatLane retries (with backoff)
    //   - 'ignore': return 2xx and just store the error on the event row
    // 'retry' is safer for transient bugs; 'ignore' if your handler is
    // genuinely unsalvageable and you want to manually replay later.
    'on_handler_error' => env('SATLANE_ON_HANDLER_ERROR', 'retry'),

    // Log every webhook to the Laravel log channel for the first N days.
    // Easy to spot misconfigured handlers.
    'log_webhooks' => env('SATLANE_LOG_WEBHOOKS', true),
];
