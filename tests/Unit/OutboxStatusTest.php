<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Unit;

use Bugfix666\LaravelOutbox\Enums\OutboxStatus;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Bugfix666\LaravelOutbox\Tests\TestCase;
use Illuminate\Support\Str;

final class OutboxStatusTest extends TestCase
{
    public function testExpiredFinalAttemptIsReportedAsFailed(): void
    {
        config()->set('outbox.retry.max_attempts', 1);
        config()->set('outbox.worker.message_lock_timeout', 60);

        $message = OutboxMessage::create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'Payment',
            'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'payment.requested',
            'payload' => [],
            'attempts' => 1,
            'locked_at' => now()->subMinutes(2),
            'lock_token' => (string) Str::uuid(),
        ]);

        $this->assertSame(OutboxStatus::FAILED, $message->getStatus());
    }
}
