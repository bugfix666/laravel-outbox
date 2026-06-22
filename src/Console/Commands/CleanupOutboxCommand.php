<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Console\Commands;

use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Console\Command;

class CleanupOutboxCommand extends Command
{
    protected $signature = 'outbox:cleanup {--days= : Number of days to keep processed messages}';

    protected $description = 'Delete processed outbox messages older than retention period';

    public function handle(OutboxService $service): int
    {
        $days = $this->option('days') ?? config('outbox.cleanup.days', 7);
        $deleted = $service->cleanup((int)$days);

        $this->info("Deleted {$deleted} old outbox messages.");
        return 0;
    }
}