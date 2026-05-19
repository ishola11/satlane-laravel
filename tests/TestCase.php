<?php

declare(strict_types=1);

namespace Satlane\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Satlane\Laravel\SatlaneServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SatlaneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('satlane.api_key', 'sl_test_xxxxxxxxxxxxxxxx');
        $app['config']->set('satlane.webhook_secret', 'whsec_test_secret');
        $app['config']->set('satlane.route.enabled', false); // tests register manually
        $app['config']->set('satlane.log_webhooks', false);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
