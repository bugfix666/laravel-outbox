<?php

namespace Bugfix666\LaravelOutbox\Publishers;

use App\Jobs\DispatchOutboxEvent;
use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Illuminate\Support\Facades\Queue;

final readonly class QueuePublisher implements OutboxPublisher
{
    public function __construct(private string $queue = 'default')
    {
    }

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        Queue::pushOn($this->queue, new DispatchOutboxEvent($eventType, $payload));
        return true;
    }
}