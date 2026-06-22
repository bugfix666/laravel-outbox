<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Unit;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Events\OutboxMessageFailed;
use Bugfix666\LaravelOutbox\Events\OutboxMessageProcessed;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Bugfix666\LaravelOutbox\Services\OutboxService;
use Bugfix666\LaravelOutbox\Tests\Publishers\FakePublisher;
use Bugfix666\LaravelOutbox\Tests\TestCase;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class OutboxServiceTest extends TestCase
{
    private OutboxService $service;
    private FakePublisher $publisher;

    public function testStoreCreatesMessageInsideTransaction(): void
    {
        DB::transaction(function () {
            $message = $this->service->store(
                aggregateType: 'Payment',
                aggregateId: '123',
                eventType: 'payment.created',
                payload: ['amount' => 500]
            );

            $this->assertDatabaseHas('outbox_messages', ['id' => $message->id]);
            $this->assertInstanceOf(OutboxMessage::class, $message);
        });
    }

    public function testStoreRollsBackWhenTransactionFails(): void
    {
        try {
            DB::transaction(function () {
                $this->service->store(
                    aggregateType: 'Payment',
                    aggregateId: '456',
                    eventType: 'payment.created',
                    payload: ['amount' => 600]
                );

                throw new Exception('Simulated failure');
            });
        } catch (Exception) {
            // ignore
        }

        $this->assertDatabaseMissing('outbox_messages', ['aggregate_id' => '456']);
    }

    public function testProcessBatchPublishesAndMarksMessages(): void
    {
        for ($i = 0; $i < 3; $i++) {
            OutboxMessage::create([
                'id' => (string)Str::uuid(),
                'aggregate_type' => 'Payment',
                'aggregate_id' => (string)$i,
                'event_type' => 'payment.created',
                'payload' => ['amount' => 100 + $i],
                'attempts' => 0,
            ]);
        }

        $processed = $this->service->processBatch(5);

        $this->assertEquals(3, $processed);
        $this->assertCount(3, $this->publisher->getPublished());
        $this->assertDatabaseCount('outbox_messages', 3);
        $this->assertDatabaseMissing('outbox_messages', ['processed_at' => null]);
    }

    public function testProcessBatchHandlesPublisherFailureAndRetries(): void
    {
        $failingPublisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                throw new Exception('Broker down');
            }
        };

        $this->app->instance(OutboxPublisher::class, $failingPublisher);
        $service = $this->app->make(OutboxService::class);

        $message = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => '789',
            'event_type' => 'payment.created',
            'payload' => ['amount' => 700],
            'attempts' => 0,
        ]);

        Event::fake();

        $processed = $service->processBatch(5);
        $this->assertEquals(0, $processed);

        $fresh = $message->fresh();
        $this->assertEquals(1, $fresh->attempts);
        $this->assertNotNull($fresh->last_attempt_at);
        $this->assertNotNull($fresh->available_at);
        $this->assertNull($fresh->processed_at);

        Event::assertDispatched(OutboxMessageFailed::class);
        Event::assertNotDispatched(OutboxMessageProcessed::class);
    }

    public function testCleanupRemovesOldProcessedMessages(): void
    {
        $old = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => 'old',
            'event_type' => 'payment.created',
            'payload' => [],
            'processed_at' => now()->subDays(10),
        ]);

        $recent = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => 'recent',
            'event_type' => 'payment.created',
            'payload' => [],
            'processed_at' => now()->subDay(),
        ]);

        $deleted = $this->service->cleanup(7);

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('outbox_messages', ['id' => $old->id]);
        $this->assertDatabaseHas('outbox_messages', ['id' => $recent->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = new FakePublisher();
        $this->app->instance(OutboxPublisher::class, $this->publisher);
        $this->service = $this->app->make(OutboxService::class);
    }
}