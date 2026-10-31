<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Feature;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Events\OutboxEvent;
use Bugfix666\LaravelOutbox\Events\OutboxMessageFailed;
use Bugfix666\LaravelOutbox\Events\OutboxMessageProcessed;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Bugfix666\LaravelOutbox\Jobs\DispatchOutboxEvent;
use Bugfix666\LaravelOutbox\Publishers\QueuePublisher;
use Bugfix666\LaravelOutbox\Services\OutboxService;
use Bugfix666\LaravelOutbox\Tests\TestCase;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class OutboxIntegrationTest extends TestCase
{
    public function testEndToEndFlowStoreAndProcess(): void
    {
        Event::fake();

        $service = $this->app->make(OutboxService::class);

        DB::transaction(function () use ($service) {
            $paymentId = (string)Str::uuid();

            $service->store(
                aggregateType: 'Payment',
                aggregateId: $paymentId,
                eventType: 'payment.completed',
                payload: ['payment_id' => $paymentId, 'amount' => 1000]
            );
        });

        $this->assertDatabaseHas('outbox_messages', [
            'aggregate_type' => 'Payment',
            'processed_at' => null,
        ]);

        $processed = $service->processBatch(10);
        $this->assertEquals(1, $processed);

        $this->assertDatabaseMissing('outbox_messages', ['processed_at' => null]);
        Event::assertDispatched(OutboxMessageProcessed::class);
        Event::assertNotDispatched(OutboxMessageFailed::class);
    }

    public function testQueuePublisherUsesPackageJobInsteadOfApplicationJob(): void
    {
        Queue::fake();

        $publisher = new QueuePublisher('outbox-events');
        $result = $publisher->publish(
            'payment.requested',
            ['payment_id' => (string) Str::uuid()],
            (string) Str::uuid(),
        );

        $this->assertTrue($result);
        Queue::assertPushedOn('outbox-events', DispatchOutboxEvent::class);
    }

    public function testQueuedOutboxJobDispatchesOutboxEvent(): void
    {
        Event::fake();

        $eventType = 'payment.requested';
        $payload = ['payment_id' => (string) Str::uuid()];
        $aggregateId = (string) Str::uuid();

        (new DispatchOutboxEvent($eventType, $payload, $aggregateId))->handle();

        Event::assertDispatched(OutboxEvent::class, function (OutboxEvent $event) use ($eventType, $payload, $aggregateId): bool {
            return $event->eventType === $eventType
                && $event->payload === $payload
                && $event->aggregateId === $aggregateId;
        });
    }

    public function testRetryFlowAfterFailure(): void
    {
        $failingPublisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                throw new Exception('Broker unavailable');
            }
        };

        $this->app->instance(OutboxPublisher::class, $failingPublisher);
        $service = $this->app->make(OutboxService::class);

        DB::transaction(function () use ($service) {
            $service->store(
                aggregateType: 'Payment',
                aggregateId: 'fail',
                eventType: 'payment.failed',
                payload: ['error' => 'test']
            );
        });

        $processed = $service->processBatch(10);
        $this->assertEquals(0, $processed);

        $message = OutboxMessage::where('aggregate_id', 'fail')->first();
        $this->assertEquals(1, $message->attempts);
        $this->assertNotNull($message->available_at);
        $this->assertNull($message->processed_at);

        $message->available_at = now()->subMinute();
        $message->save();

        $workingPublisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                return true;
            }
        };

        $this->app->instance(OutboxPublisher::class, $workingPublisher);
        $service = $this->app->make(OutboxService::class);

        $processed = $service->processBatch(10);
        $this->assertEquals(1, $processed);

        $message->refresh();
        $this->assertNotNull($message->processed_at);
        $this->assertEquals(2, $message->attempts);
    }
}