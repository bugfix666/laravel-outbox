<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests;

use Bugfix666\LaravelOutbox\Providers\OutboxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OutboxServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
        ]);

        $app['config']->set('outbox.table', 'outbox_messages');
        $app['config']->set('outbox.worker.batch_size', 5);
        $app['config']->set('outbox.worker.lock_store', 'array');
        $app['config']->set('outbox.retry.max_attempts', 3);
        $app['config']->set('outbox.retry.delay_seconds', 10);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}