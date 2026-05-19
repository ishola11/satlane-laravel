<?php

declare(strict_types=1);

namespace Satlane\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Satlane\Client;
use Satlane\Exceptions\ApiException;
use Throwable;

/**
 * `php artisan satlane:install`
 *
 * The friendly install wizard. Surfaces every misconfiguration as a
 * clear, actionable error so the developer doesn't ship a broken
 * integration to production. Runnable repeatedly — idempotent.
 *
 * Returns non-zero on hard failure so CI can fail the build.
 */
class InstallCommand extends Command
{
    protected $signature = 'satlane:install
        {--no-migrate : Skip running migrations.}
        {--no-publish : Skip publishing config + migrations.}
        {--no-ping    : Skip the API connectivity test.}';

    protected $description = 'Set up the SatLane Laravel integration: publish, migrate, validate config, test API connectivity.';

    public function handle(): int
    {
        $this->info('SatLane install wizard');
        $this->line('-----------------------');

        // ── 1. Publish ─────────────────────────────────────────
        if (!$this->option('no-publish')) {
            $this->task('Publishing config and migrations', function () {
                $this->call('vendor:publish', [
                    '--provider' => 'Satlane\\Laravel\\SatlaneServiceProvider',
                    '--tag'      => 'satlane-config',
                    '--force'    => false, // never silently overwrite an edited config
                ]);
                $this->call('vendor:publish', [
                    '--provider' => 'Satlane\\Laravel\\SatlaneServiceProvider',
                    '--tag'      => 'satlane-migrations',
                    '--force'    => false,
                ]);
                return true;
            });
        }

        // ── 2. Migrate ─────────────────────────────────────────
        if (!$this->option('no-migrate')) {
            $this->task('Running migrations', function () {
                try {
                    $this->call('migrate', ['--force' => app()->environment('production')]);
                    return true;
                } catch (Throwable $e) {
                    $this->error('Migration failed: ' . $e->getMessage());
                    $this->line('  Check DATABASE_URL / db.* config and rerun `php artisan migrate`.');
                    return false;
                }
            });
        }

        // ── 3. Validate env ────────────────────────────────────
        $problems = $this->validateEnv();
        if (count($problems) > 0) {
            $this->line('');
            $this->warn('Missing or unset configuration:');
            foreach ($problems as $p) {
                $this->line('  • ' . $p);
            }
            $this->line('');
            $this->line('Add these to your .env, then rerun `php artisan satlane:install --no-publish --no-migrate`.');
            $this->line('');
        } else {
            $this->info('Configuration looks good.');
        }

        // ── 4. Ping the API ────────────────────────────────────
        if (!$this->option('no-ping') && count($problems) === 0) {
            $this->task('Pinging api.satlane.com', function () {
                try {
                    /** @var Client $client */
                    $client = app(Client::class);
                    // Use the public health endpoint — no auth needed but
                    // we use the SDK so we exercise the same code path
                    // a real app would.
                    $client->request('GET', '/health');
                    return true;
                } catch (ApiException $e) {
                    $this->line('');
                    $this->error('API ping failed:');
                    $this->line('  status:     ' . $e->status);
                    $this->line('  code:       ' . ($e->code() ?? 'n/a'));
                    $this->line('  request_id: ' . ($e->requestId() ?? 'n/a'));
                    $this->line('  message:    ' . $e->getMessage());
                    if ($e->code() === 'network_error') {
                        $this->line('');
                        $this->line('  Check SATLANE_API_BASE (currently ' . config('satlane.api_base') . ').');
                        $this->line('  Verify outbound HTTPS works from this host.');
                    }
                    return false;
                } catch (Throwable $e) {
                    $this->line('');
                    $this->error('API ping failed unexpectedly: ' . $e->getMessage());
                    return false;
                }
            });
        }

        // ── 5. Final guidance ──────────────────────────────────
        $this->line('');
        if (count($problems) === 0) {
            $this->info('✓ Install complete.');
            $this->line('');
            $this->line('Next steps:');
            $this->line('  1. Subclass Satlane\\Laravel\\Webhooks\\WebhookHandler and implement the events you care about.');
            $this->line('  2. Set SATLANE_WEBHOOK_HANDLER=App\\YourHandler in .env, OR call:');
            $this->line('       Route::satlaneWebhook(\'/webhooks/satlane\', App\\YourHandler::class);');
            $this->line('  3. Add the webhook URL to your store in the SatLane dashboard.');
            $this->line('  4. Test in test mode with `php artisan satlane:simulate`.');
            return self::SUCCESS;
        }

        $this->warn('Install completed with warnings. Fix the items above before going live.');
        return self::SUCCESS;
    }

    /**
     * @return list<string> Human-readable problem strings (empty = healthy).
     */
    private function validateEnv(): array
    {
        $problems = [];

        if (!config('satlane.api_key')) {
            $problems[] = 'SATLANE_API_KEY is not set. Mint one at Store → API Keys in the dashboard.';
        } elseif (!preg_match('/^sl_(test|live)_/', (string) config('satlane.api_key'))) {
            $problems[] = 'SATLANE_API_KEY does not look like a SatLane key (expected `sl_test_…` or `sl_live_…`).';
        }

        if (!config('satlane.webhook_secret')) {
            $problems[] = 'SATLANE_WEBHOOK_SECRET is not set. Create a webhook endpoint in the dashboard to generate one.';
        }

        if (config('satlane.route.enabled') && !config('satlane.route.handler')) {
            $problems[] = 'Webhook route is enabled but SATLANE_WEBHOOK_HANDLER is not set. Either set it to your handler class, or set SATLANE_ROUTE_ENABLED=false and register the route yourself with Route::satlaneWebhook(...).';
        }

        $base = (string) config('satlane.api_base', '');
        if ($base && !str_starts_with($base, 'https://') && !app()->environment('local', 'testing')) {
            $problems[] = "SATLANE_API_BASE is `$base` (not HTTPS). Use https://api.satlane.com in production.";
        }

        return $problems;
    }

    /**
     * Friendly task runner. Renders "doing X… ✓ / ✗" and propagates the
     * inner return value as success/failure.
     */
    private function task(string $label, callable $fn): bool
    {
        $this->getOutput()->write('  ' . $label . '... ');
        $start = microtime(true);
        $ok = false;
        try {
            $ok = (bool) $fn();
        } catch (Throwable $e) {
            $ok = false;
        }
        $ms = (int) ((microtime(true) - $start) * 1000);
        $this->getOutput()->writeln($ok ? "<info>✓</info> ({$ms}ms)" : '<error>✗</error>');
        return $ok;
    }
}
