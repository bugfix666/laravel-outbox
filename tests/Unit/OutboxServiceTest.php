<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Unit;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Events\OutboxMessageFailed;
use Bugfix666\LaravelOutbox\Events\OutboxMessageProcessed;
use Bugfix666\LaravelOutbox\Enums\OutboxStatus;
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


    public function testPublisherReturningFalseIsTreatedAsFailure(): void
    {
        $falsePublisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                return false;
            }
        };

        $this->app->instance(OutboxPublisher::class, $falsePublisher);
        $service = $this->app->make(OutboxService::class);

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => ['amount' => 100],
            'attempts' => 0,
        ]);

        $this->assertSame(0, $service->processBatch(1));

        $message->refresh();
        $this->assertNull($message->processed_at);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('Publisher returned false', (string) $message->last_error);
    }

    public function testMessageMovesToFailedStateAfterMaxAttempts(): void
    {
        config()->set('outbox.retry.max_attempts', 1);

        $failingPublisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                throw new Exception('Permanent broker failure');
            }
        };

        $this->app->instance(OutboxPublisher::class, $failingPublisher);
        $service = $this->app->make(OutboxService::class);

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 0,
        ]);

        $this->assertSame(0, $service->processBatch(1));

        $message->refresh();
        $this->assertNotNull($message->failed_at);
        $this->assertNull($message->available_at);
        $this->assertSame(OutboxStatus::FAILED, $message->getStatus());
        $this->assertSame(0, OutboxMessage::pending()->count());
    }

    public function testBatchDoesNotRetrySameMessageTwiceWhenBackoffIsZero(): void
    {
        config()->set('outbox.retry.max_attempts', 3);
        config()->set('outbox.retry.delay_seconds', 0);
        config()->set('outbox.retry.max_delay_seconds', 0);

        $failingPublisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                throw new Exception('Broker unavailable');
            }
        };

        $this->app->instance(OutboxPublisher::class, $failingPublisher);
        $service = $this->app->make(OutboxService::class);

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 0,
        ]);

        $service->processBatch(10);

        $message->refresh();
        $this->assertSame(1, $message->attempts);
        $this->assertNull($message->failed_at);
    }

    public function testExpiredLeaseOnFinalAttemptMovesMessageToFailedState(): void
    {
        config()->set('outbox.retry.max_attempts', 1);
        config()->set('outbox.worker.message_lock_timeout', 60);

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 1,
            'locked_at' => now()->subMinutes(2),
            'lock_token' => (string) Str::uuid(),
        ]);

        $processed = $this->service->processBatch(1);

        $message->refresh();
        $this->assertSame(0, $processed);
        $this->assertNotNull($message->failed_at);
        $this->assertNull($message->locked_at);
        $this->assertNull($message->lock_token);
        $this->assertStringContainsString('lease expired', (string) $message->last_error);
    }

    public function testRetryFailedMessageResetsItToPending(): void
    {
        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 3,
            'failed_at' => now(),
            'last_error' => 'Broker unavailable',
        ]);

        $this->assertTrue($this->service->retryFailedMessage($message->id));

        $message->refresh();
        $this->assertNull($message->failed_at);
        $this->assertNull($message->last_error);
        $this->assertSame(0, $message->attempts);
        $this->assertSame(OutboxStatus::PENDING, $message->getStatus());
    }

    public function testRetryDelayUsesExponentialBackoffAndCapsAtMaximum(): void
    {
        config()->set('outbox.retry.delay_seconds', 10);
        config()->set('outbox.retry.max_delay_seconds', 25);
        config()->set('outbox.retry.multiplier', 2);
        config()->set('outbox.retry.max_attempts', 5);

        $publisher = new class implements OutboxPublisher {
            public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
            {
                throw new Exception('Broker unavailable');
            }
        };
        $this->app->instance(OutboxPublisher::class, $publisher);
        $service = $this->app->make(OutboxService::class);

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 0,
        ]);

        $now = \Illuminate\Support\Carbon::parse('2026-01-01 12:00:00');
        $this->travelTo($now);
        $service->processBatch(1);

        $message->refresh();
        $this->assertTrue($message->available_at->equalTo($now->copy()->addSeconds(10)));

        $message->available_at = now()->subSecond();
        $message->save();
        $service->processBatch(1);

        $message->refresh();
        $this->assertTrue($message->available_at->equalTo($now->copy()->addSeconds(20)));

        $message->available_at = now()->subSecond();
        $message->save();
        $service->processBatch(1);

        $message->refresh();
        $this->assertTrue($message->available_at->equalTo($now->copy()->addSeconds(25)));
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