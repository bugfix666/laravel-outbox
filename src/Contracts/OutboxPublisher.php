<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Contracts;

use Throwable;

interface OutboxPublisher
{
    /**
     * Publish an event to the configured broker.
     *
     * Return true only after the broker has accepted the message according to
     * the durability guarantees required by the application. Returning false
     * or throwing an exception causes the outbox message to be retried.
     *
     * @throws Throwable When the message cannot be published.
     */
    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool;
}
