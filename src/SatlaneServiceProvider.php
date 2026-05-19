<?php

declare(strict_types=1);

namespace Satlane\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Satlane\Client;
use Satlane\Laravel\Console\InstallCommand;
use Satlane\Laravel\Console\PruneCommand;
use Satlane\Laravel\Console\ReplayCommand;
use Satlane\Laravel\Exceptions\ConfigException;
use Satlane\Laravel\Http\Controllers\WebhookController;

class SatlaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/satlane.php', 'satlane');

        // Lazy singleton — config is read on first call so we never
        // throw at boot just because env vars aren't set yet.
        $this->app->singleton(Client::class, function () {
            $apiKey = config('satlane.api_key');
            if (!$apiKey) {
                throw new ConfigException(
                    'SATLANE_API_KEY is not set. Add it to your .env and run ' .
                    '`php artisan config:clear` (or `config:cache` for prod).',
                );
            }
            return new Client(
                apiKey:  $apiKey,
                baseUrl: (string) config('satlane.api_base', 'https://api.satlane.com'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishesAssets();
        $this->registerRouteMacro();
        $this->registerConfiguredRoute();
        $this->registerCommands();
    }

    // ─────────────────────────────────────────────────────────────────

    private function publishesAssets(): void
    {
        $this->publishes(
            [__DIR__ . '/../config/satlane.php' => config_path('satlane.php')],
            ['satlane-config', 'satlane'],
        );

        $this->publishes(
            [__DIR__ . '/../database/migrations' => database_path('migrations')],
            ['satlane-migrations', 'satlane'],
        );

        // Always load our migrations from the package directory too, so
        // `php artisan migrate` works straight after install even
        // before the user runs `vendor:publish`. Publishing then becomes
        // optional, for devs who want to edit the schema themselves.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Route::satlaneWebhook('/webhooks/satlane', YourHandler::class)
     *   ->middleware('api')
     *
     * Registers the receiver with signature verification + idempotency
     * + dispatch already wired up. CSRF is excluded inside the
     * controller flow because we read the raw body.
     */
    private function registerRouteMacro(): void
    {
        Route::macro('satlaneWebhook', function (string $uri, string $handlerClass) {
            /** @var Router $this */
            return $this->post($uri, function (\Illuminate\Http\Request $request) use ($handlerClass) {
                return (new WebhookController())($request, $handlerClass);
            })->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            ]);
        });
    }

    /**
     * If `route.enabled` is true AND a handler class is configured,
     * register the webhook route automatically. Otherwise stay out of
     * the dev's way.
     */
    private function registerConfiguredRoute(): void
    {
        if (!config('satlane.route.enabled', true)) return;
        $handler = config('satlane.route.handler');
        if (!$handler) return;

        $path = (string) config('satlane.route.path', '/webhooks/satlane');
        $name = (string) config('satlane.route.name', 'webhooks.satlane');
        $middleware = (array) config('satlane.route.middleware', ['api']);

        Route::middleware($middleware)
            ->post($path, function (\Illuminate\Http\Request $request) use ($handler) {
                return (new WebhookController())($request, $handler);
            })
            ->withoutMiddleware([
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            ])
            ->name($name);
    }

    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) return;

        $this->commands([
            InstallCommand::class,
            PruneCommand::class,
            ReplayCommand::class,
        ]);
    }
}
