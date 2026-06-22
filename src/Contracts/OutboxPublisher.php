<?php

namespace Bugfix666\LaravelOutbox\Contracts;

use Throwable;

interface OutboxPublisher
{
    /**
     * Publish an event to the message broker.
     *
     * @throws Throwable
     */
    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool;
}