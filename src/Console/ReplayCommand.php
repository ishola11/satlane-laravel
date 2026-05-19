<?php

declare(strict_types=1);

namespace Satlane\Laravel\Console;

use Illuminate\Console\Command;
use Satlane\Laravel\Models\SatlaneEvent;
use Satlane\Laravel\Models\SatlaneInvoice;
use Satlane\Laravel\Webhooks\WebhookHandler;
use Throwable;

/**
 * `php artisan satlane:replay {event_id|--failed}`
 *
 * Re-dispatches a stored event to your handler. Useful when:
 *   - The handler was broken when the event arrived; you fixed it
 *     and want to fulfill the order now.
 *   - You ran in `on_handler_error: ignore` mode and need to retry.
 *
 * Does NOT re-fetch from SatLane. Replays the payload we stored.
 */
class ReplayCommand extends Command
{
    protected $signature = 'satlane:replay
        {event_id? : The event_id to replay. Required unless --failed is used.}
        {--failed : Replay every event row with a non-null handler_error.}
        {--limit=100 : Cap on rows processed when --failed.}';

    protected $description = 'Re-dispatch stored webhook events to your handler.';

    public function handle(): int
    {
        $handlerClass = config('satlane.route.handler');
        if (!$handlerClass || !class_exists($handlerClass)) {
            $this->error('No webhook handler class configured (SATLANE_WEBHOOK_HANDLER). Set it, then rerun.');
            return self::FAILURE;
        }
        $handler = app($handlerClass);
        if (!$handler instanceof WebhookHandler) {
            $this->error("[$handlerClass] does not extend " . WebhookHandler::class);
            return self::FAILURE;
        }

        $rows = $this->option('failed')
            ? SatlaneEvent::whereNotNull('handler_error')->orderBy('id')->limit((int) $this->option('limit'))->get()
            : SatlaneEvent::where('event_id', $this->argument('event_id'))->get();

        if ($rows->isEmpty()) {
            $this->warn('No matching event rows.');
            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $invoiceData = $row->payload['data']['invoice'] ?? null;
            if (!$invoiceData) {
                $this->warn("Event {$row->event_id}: payload has no invoice. Skipping.");
                continue;
            }
            $invoice = SatlaneInvoice::find($invoiceData['id'] ?? '');
            if (!$invoice) {
                $this->warn("Event {$row->event_id}: invoice {$invoiceData['id']} not in satlane_invoices. Skipping.");
                continue;
            }
            try {
                $handler->dispatch($row->event_type, $invoice);
                $row->update([
                    'handler_error'    => null,
                    'processed_at'     => now(),
                    'handler_attempts' => $row->handler_attempts + 1,
                ]);
                $this->line("✓ {$row->event_id} ({$row->event_type})");
                $ok++;
            } catch (Throwable $e) {
                $row->update([
                    'handler_error'    => substr($e->getMessage(), 0, 64_000),
                    'handler_attempts' => $row->handler_attempts + 1,
                ]);
                $this->error("✗ {$row->event_id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line('');
        $this->info("Done. {$ok} replayed, {$failed} failed.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
