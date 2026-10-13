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
        {--limit=100 : Cap on rows processed when --failed.}
        {--force : Reprocess completed or stale events; handler side effects must be idempotent.}';

    protected $description = 'Re-dispatch stored webhook events to your handler.';

    public function handle(): int
    {
        if (!$this->argument('event_id') && !$this->option('failed')) {
            $this->error('Specify an event ID or --failed.');
            return self::FAILURE;
        }
        if ((int)$this->option('limit') < 1 || (int)$this->option('limit') > 10000) {
            $this->error('Replay limit must be between 1 and 10000.');
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
            try {
                $processor = new \Satlane\Laravel\Webhooks\EventProcessor();
                $result = $processor->process($row, (bool)$this->option('force'));
                $this->line("✓ {$row->event_id} ({$row->event_type}, {$result})");
                $ok++;
            } catch (Throwable $e) {
                $this->error("✗ {$row->event_id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line('');
        $this->info("Done. {$ok} replayed, {$failed} failed.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
