<?php

declare(strict_types=1);

namespace Satlane\Laravel\Console;

use Illuminate\Console\Command;
use Satlane\Laravel\Models\SatlaneEvent;

/**
 * `php artisan satlane:prune`
 *
 * Drop satlane_events rows older than `retain_events_days`. Idempotent.
 * Schedule it in app/Console/Kernel.php if you care:
 *
 *   $schedule->command('satlane:prune')->daily();
 */
class PruneCommand extends Command
{
    protected $signature = 'satlane:prune {--days= : Override the retention window in days.}';
    protected $description = 'Delete old SatLane webhook event rows past the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('satlane.retain_events_days', 90));
        if ($days <= 0) {
            $this->info('Retention is disabled (retain_events_days=0). Nothing to prune.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = SatlaneEvent::where('received_at', '<', $cutoff)->delete();
        $this->info("Pruned {$deleted} event row(s) older than {$days} day(s).");
        return self::SUCCESS;
    }
}
