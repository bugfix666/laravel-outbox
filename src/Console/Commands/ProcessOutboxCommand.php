<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Console\Commands;

use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process
                            {--batch-size= : Number of messages per batch}
                            {--lock-timeout= : Lock timeout in seconds}';

    protected $description = 'Process pending outbox messages and publish them';

    public function handle(OutboxService $service): int
    {
        $batchSize = $this->option('batch-size') ?? config('outbox.worker.batch_size', 100);
        $lockTimeout = $this->option('lock-timeout') ?? config('outbox.worker.lock_timeout', 10);
        $lockStore = config('outbox.worker.lock_store', 'cache');

        $lock = Cache::store($lockStore)->lock('outbox:processor', (int)$lockTimeout);

        if (!$lock->acquire()) {
            $this->warn('Another outbox worker is already running.');
            return 1;
        }

        try {
            $processed = $service->processBatch((int)$batchSize);
            $this->info("Processed {$processed} outbox messages.");
            return 0;
        } finally {
            $lock->release();
        }
    }
}