<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Jobs;

use Bugfix666\LaravelOutbox\Events\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchOutboxEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $eventType,
        public array $payload,
        public ?string $aggregateId = null,
    ) {
    }

    public function handle(): void
    {
        OutboxEvent::dispatch(
            eventType: $this->eventType,
            payload: $this->payload,
            aggregateId: $this->aggregateId,
        );
    }
}
