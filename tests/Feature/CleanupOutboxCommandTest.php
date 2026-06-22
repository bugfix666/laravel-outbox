<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Feature;

use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Bugfix666\LaravelOutbox\Tests\TestCase;
use Illuminate\Support\Str;

final class CleanupOutboxCommandTest extends TestCase
{
    public function testCommandDeletesOldProcessedMessages(): void
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

        $this->artisan('outbox:cleanup', ['--days' => 7])
            ->assertSuccessful()
            ->expectsOutput('Deleted 1 old outbox messages.');

        $this->assertDatabaseMissing('outbox_messages', ['id' => $old->id]);
        $this->assertDatabaseHas('outbox_messages', ['id' => $recent->id]);
    }
}