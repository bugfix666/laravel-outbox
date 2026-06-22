<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Tests\Feature;

use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Bugfix666\LaravelOutbox\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ProcessOutboxCommandTest extends TestCase
{
    public function testCommandProcessesPendingMessages(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $message = OutboxMessage::create([
                'id' => (string)Str::uuid(),
                'aggregate_type' => 'Payment',
                'aggregate_id' => (string)$i,
                'event_type' => 'payment.created',
                'payload' => ['amount' => 100],
                'attempts' => 0,
            ]);
            $ids[] = $message->id;
        }

        Artisan::call('outbox:process', ['--batch-size' => 10]);
        $output = Artisan::output();

        $this->assertStringContainsString('Processed 3 outbox messages.', $output);

        foreach ($ids as $id) {
            $this->assertNotNull(OutboxMessage::find($id)->processed_at);
        }
    }

    public function testCommandRespectsLockAndExitsIfAlreadyRunning(): void
    {
        $lock = Cache::store('array')->lock('outbox:processor', 10);
        $lock->acquire();

        Artisan::call('outbox:process');
        $output = Artisan::output();

        $this->assertStringContainsString('Another outbox worker is already running.', $output);

        $lock->release();
    }
}