<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Publishers;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Jobs\DispatchOutboxEvent;
use Illuminate\Support\Facades\Queue;

final readonly class QueuePublisher implements OutboxPublisher
{
    public function __construct(private string $queue = 'default')
    {
    }

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        $result = Queue::pushOn(
            $this->queue,
            new DispatchOutboxEvent($eventType, $payload, $aggregateId),
        );

        return $result !== false;
    }
}
