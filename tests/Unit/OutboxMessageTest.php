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

        OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.failed',
            'payload' => [],
            'attempts' => 3,
            'failed_at' => now(),
        ]);

        $pendingMessages = OutboxMessage::pending()->get();

        $this->assertCount(1, $pendingMessages);
        $this->assertEquals($pending->id, $pendingMessages->first()->id);
    }

    public function testModelRespectsConfiguredTableName(): void
    {
        config()->set('outbox.table', 'custom_outbox_messages');
        \Illuminate\Support\Facades\Schema::create('custom_outbox_messages', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('lock_token')->nullable();
        });

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 0,
        ]);

        $this->assertDatabaseHas('custom_outbox_messages', ['id' => $message->id]);
        config()->set('outbox.table', 'outbox_messages');
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

    public function testGetStatusReturnsProcessingForAnActiveLease(): void
    {
        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.created',
            'payload' => [],
            'attempts' => 1,
            'locked_at' => now(),
            'lock_token' => (string) Str::uuid(),
        ]);

        $this->assertEquals(OutboxStatus::PROCESSING, $message->getStatus());
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