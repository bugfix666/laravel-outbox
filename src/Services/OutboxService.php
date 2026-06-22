<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Services;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Events\OutboxMessageFailed;
use Bugfix666\LaravelOutbox\Events\OutboxMessageProcessed;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class OutboxService
{
    public function __construct(private OutboxPublisher $publisher)
    {
    }

    public function store(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array  $payload,
    ): OutboxMessage {
        return OutboxMessage::create([
            'id' => (string)Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'attempts' => 0,
        ]);
    }

    public function processBatch(int $batchSize): int
    {
        $processed = 0;

        $messages = OutboxMessage::pending()
            ->orderBy('created_at')
            ->limit($batchSize)
            ->get();

        foreach ($messages as $message) {
            $message->recordAttempt();

            try {
                $this->publisher->publish(
                    $message->event_type,
                    $message->payload->toArray(),
                    $message->aggregate_id,
                );

                DB::transaction(function () use ($message) {
                    $message->markProcessed();
                    event(new OutboxMessageProcessed($message));
                });

                $processed++;
            } catch (Throwable $e) {
                event(new OutboxMessageFailed($message, $e));

                Log::error('Outbox message processing failed', [
                    'id' => $message->id,
                    'error' => $e->getMessage(),
                    'attempts' => $message->attempts,
                ]);
            }
        }

        return $processed;
    }

    public function cleanup(int $days): int
    {
        $cutoff = now()->subDays($days);
        return OutboxMessage::where('processed_at', '<', $cutoff)->delete();
    }
}