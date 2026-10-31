<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Console\Commands;

use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Console\Command;

final class RetryOutboxMessageCommand extends Command
{
    protected $signature = 'outbox:retry {id : UUID of the failed outbox message}';

    protected $description = 'Move a failed outbox message back to the pending state';

    public function handle(OutboxService $service): int
    {
        $retried = $service->retryFailedMessage((string) $this->argument('id'));

        if (!$retried) {
            $this->error('Failed outbox message was not found.');

            return self::FAILURE;
        }

        $this->info('Outbox message moved back to pending.');

        return self::SUCCESS;
    }
}
