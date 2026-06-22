<?php

namespace Bugfix666\LaravelOutbox\Providers;

use Bugfix666\LaravelOutbox\Console\Commands\CleanupOutboxCommand;
use Bugfix666\LaravelOutbox\Console\Commands\ProcessOutboxCommand;
use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Support\ServiceProvider;

final class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/outbox.php',
            'outbox'
        );

        $this->app->bind(OutboxPublisher::class, function ($app) {
            $publisherClass = config('outbox.publisher');
            return $app->make($publisherClass);
        });

        $this->app->bind(OutboxService::class, function ($app) {
            return new OutboxService($app->make(OutboxPublisher::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/outbox.php' => config_path('outbox.php'),
            ], 'outbox-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'outbox-migrations');

            $this->commands([
                ProcessOutboxCommand::class,
                CleanupOutboxCommand::class,
            ]);
        }
    }
}