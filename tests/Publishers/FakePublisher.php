<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Publishers;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Illuminate\Support\Collection;

final class FakePublisher implements OutboxPublisher
{
    private Collection $published;

    public function __construct()
    {
        $this->published = new Collection();
    }

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        $this->published->push([
            'event_type' => $eventType,
            'payload' => $payload,
            'aggregate_id' => $aggregateId,
        ]);

        return true;
    }

    public function getPublished(): Collection
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = new Collection();
    }
}