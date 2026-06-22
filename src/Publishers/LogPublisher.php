<?php

namespace Bugfix666\LaravelOutbox\Publishers;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Illuminate\Support\Facades\Log;

final readonly class LogPublisher implements OutboxPublisher
{
    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        Log::info('[Outbox] Publishing event', [
            'event_type' => $eventType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
        ]);

        return true;
    }
}