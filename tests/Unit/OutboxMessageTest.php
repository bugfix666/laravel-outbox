<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Unit;

use Bugfix666\LaravelOutbox\Enums\OutboxStatus;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Bugfix666\LaravelOutbox\Tests\TestCase;
use Illuminate\Support\Str;

final class OutboxMessageTest extends TestCase
{
    public function testCanCreateMessage(): void
    {
        $message = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => ['amount' => 100],
            'attempts' => 0,
        ]);

        $this->assertDatabaseHas('outbox_messages', ['id' => $message->id]);
        $this->assertNull($message->processed_at);
    }

    public function testPendingScopeOnlyReturnsUnprocessedMessages(): void
    {
        OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.completed',
            'payload' => ['amount' => 200],
            'processed_at' => now(),
            'attempts' => 0,
        ]);

        $pending = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.pending',
            'payload' => ['amount' => 150],
            'attempts' => 0,
        ]);

        $pendingMessages = OutboxMessage::pending()->get();

        $this->assertCount(1, $pendingMessages);
        $this->assertEquals($pending->id, $pendingMessages->first()->id);
    }

    public function testMarkProcessedUpdatesTimestamp(): void
    {
        $message = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => ['amount' => 300],
            'attempts' => 0,
        ]);

        $message->markProcessed();

        $this->assertNotNull($message->fresh()->processed_at);
    }

    public function testRecordAttemptIncrementsCounterAndSetsAvailableAt(): void
    {
        $message = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => ['amount' => 400],
            'attempts' => 0,
        ]);

        $message->recordAttempt();

        $fresh = $message->fresh();
        $this->assertEquals(1, $fresh->attempts);
        $this->assertNotNull($fresh->last_attempt_at);
        $this->assertNotNull($fresh->available_at);
        $this->assertTrue($fresh->available_at->gt(now()));
    }

    public function testGetStatusReturnsCorrectEnum(): void
    {
        $pending = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 0,
        ]);
        /** @var OutboxMessage $pending */
        $this->assertEquals(OutboxStatus::PENDING, $pending->getStatus());

        $processed = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'processed_at' => now(),
            'attempts' => 0,
        ]);
        $this->assertEquals(OutboxStatus::PROCESSED, $processed->getStatus());

        config()->set('outbox.retry.max_attempts', 3);
        $failed = OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string)Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 3,
        ]);
        $this->assertEquals(OutboxStatus::FAILED, $failed->getStatus());
    }
}