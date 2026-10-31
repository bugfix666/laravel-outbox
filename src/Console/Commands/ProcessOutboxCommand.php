<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Console\Commands;

use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process
                            {--batch-size= : Number of messages per batch}
                            {--lock-timeout= : Cache lock lifetime in seconds}';

    protected $description = 'Process pending outbox messages and publish them';

    public function handle(OutboxService $service): int
    {
        $batchSize = (int) ($this->option('batch-size') ?? config('outbox.worker.batch_size', 100));
        $lockTimeout = (int) ($this->option('lock-timeout') ?? config('outbox.worker.lock_timeout', 900));
        $lockStore = (string) config('outbox.worker.lock_store', config('cache.default', 'database'));

        if ($batchSize < 1 || $lockTimeout < 1) {
            $this->error('Batch size and lock timeout must be greater than zero.');

            return self::FAILURE;
        }

        try {
            $lock = Cache::store($lockStore)->lock('outbox:processor', $lockTimeout);
            $lockAcquired = $lock->get();
        } catch (Throwable $exception) {
            $this->error('Unable to acquire the outbox cache lock: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (!$lockAcquired) {
            $this->info('Another outbox worker is already running.');

            return self::SUCCESS;
        }

        try {
            $processed = $service->processBatch($batchSize);
            $this->info("Processed {$processed} outbox messages.");

            return self::SUCCESS;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                $this->warn('Unable to release the outbox cache lock: ' . $exception->getMessage());
            }
        }
    }
}
