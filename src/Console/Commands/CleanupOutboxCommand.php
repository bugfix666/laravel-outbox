<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Console\Commands;

use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Console\Command;

final class CleanupOutboxCommand extends Command
{
    protected $signature = 'outbox:cleanup {--days= : Number of days to keep processed messages}';

    protected $description = 'Delete processed outbox messages older than the retention period';

    public function handle(OutboxService $service): int
    {
        $days = (int) ($this->option('days') ?? config('outbox.cleanup.days', 7));

        if ($days < 0) {
            $this->error('Retention days cannot be negative.');

            return self::FAILURE;
        }

        $deleted = $service->cleanup($days);
        $this->info("Deleted {$deleted} old outbox messages.");

        return self::SUCCESS;
    }
}
