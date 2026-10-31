<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class OutboxEvent
{
    use Dispatchable;

    public function __construct(
        public string $eventType,
        public array $payload,
        public ?string $aggregateId = null,
    ) {
    }
}
