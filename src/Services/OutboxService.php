<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Services;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Events\OutboxMessageFailed;
use Bugfix666\LaravelOutbox\Events\OutboxMessageProcessed;
use Bugfix666\LaravelOutbox\Exceptions\OutboxException;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        array $payload,
    ): OutboxMessage {
        if ($aggregateType === '' || $aggregateId === '' || $eventType === '') {
            throw new InvalidArgumentException('Aggregate type, aggregate ID, and event type must not be empty.');
        }

        return OutboxMessage::query()->create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'attempts' => 0,
        ]);
    }

    /**
     * Publish up to the requested number of messages.
     *
     * Delivery is at-least-once: a process can crash after the broker accepts
     * a message but before this package records it as processed. Consumers
     * must therefore be idempotent.
     */
    public function processBatch(int $batchSize): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be greater than zero.');
        }

        $this->failExpiredFinalAttempts();
        $processed = 0;

        // Claim one message immediately before publishing it. Claiming an
        // entire batch up front could let later messages' leases expire while
        // the worker is still publishing earlier messages.
        $claimedIds = [];

        for ($index = 0; $index < $batchSize; $index++) {
            $message = $this->claimNextMessage($claimedIds);

            if ($message === null) {
                break;
            }

            $claimedIds[] = (string) $message->getKey();
            $lockToken = (string) $message->lock_token;

            try {
                $published = $this->publisher->publish(
                    $message->event_type,
                    $message->payload->toArray(),
                    $message->aggregate_id,
                );

                if ($published !== true) {
                    throw new OutboxException(sprintf(
                        'Publisher returned false for outbox message [%s].',
                        $message->id,
                    ));
                }

                $completedMessage = $this->markProcessed($message->id, $lockToken);

                // A worker whose lease expired must not overwrite a newer claim.
                if ($completedMessage === null) {
                    Log::warning('Outbox message was published but its processing lease was lost.', [
                        'id' => $message->id,
                    ]);
                    continue;
                }

                $processed++;
                $this->dispatchMonitoringEvent(new OutboxMessageProcessed($completedMessage));
            } catch (Throwable $exception) {
                try {
                    $failedMessage = $this->recordFailure($message->id, $lockToken, $exception);

                    if ($failedMessage !== null) {
                        $this->dispatchMonitoringEvent(new OutboxMessageFailed($failedMessage, $exception));
                    }
                } catch (Throwable $recordingException) {
                    Log::critical('Unable to persist the outbox publication failure.', [
                        'id' => $message->id,
                        'original_error' => $exception->getMessage(),
                        'recording_error' => $recordingException->getMessage(),
                    ]);
                }

                Log::error('Outbox message processing failed.', [
                    'id' => $message->id,
                    'error' => $exception->getMessage(),
                    'attempts' => $message->attempts,
                ]);
            }
        }

        return $processed;
    }

    /**
     * Move a dead-lettered message back to the pending state for manual retry.
     * The message is made immediately available and its attempt counter is reset.
     */
    public function retryFailedMessage(string $messageId): bool
    {
        $leaseExpiredAt = now()->subSeconds(
            max(1, (int) config('outbox.worker.message_lock_timeout', 300))
        );
        $maxAttempts = max(1, (int) config('outbox.retry.max_attempts', 5));

        return OutboxMessage::query()
            ->whereKey($messageId)
            ->where(function ($query) use ($leaseExpiredAt, $maxAttempts): void {
                $query->whereNotNull('failed_at')->orWhere(function ($query) use ($leaseExpiredAt, $maxAttempts): void {
                    $query->where('attempts', '>=', $maxAttempts)
                        ->whereNotNull('locked_at')
                        ->where('locked_at', '<=', $leaseExpiredAt);
                });
            })
            ->whereNull('processed_at')
            ->update([
                'attempts' => 0,
                'failed_at' => null,
                'last_error' => null,
                'last_attempt_at' => null,
                'available_at' => now(),
                'locked_at' => null,
                'lock_token' => null,
            ]) === 1;
    }

    public function cleanup(int $days): int
    {
        if ($days < 0) {
            throw new InvalidArgumentException('Cleanup retention days cannot be negative.');
        }

        $cutoff = now()->subDays($days);

        return OutboxMessage::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff)
            ->delete();
    }

    private function claimNextMessage(array $excludedIds): ?OutboxMessage
    {
        return $this->connection()->transaction(function () use ($excludedIds): ?OutboxMessage {
            // Another worker may claim the selected row between SELECT and
            // UPDATE. Try a few candidates rather than abandoning the batch.
            $forbiddenIds = $excludedIds;
            $leaseExpiredAt = now()->subSeconds(
                max(1, (int) config('outbox.worker.message_lock_timeout', 300))
            );
            $maxAttempts = max(1, (int) config('outbox.retry.max_attempts', 5));

            for ($attempt = 0; $attempt < 10; $attempt++) {
                $query = OutboxMessage::query()
                    ->readyForProcessing()
                    ->orderBy('created_at');

                if ($forbiddenIds !== []) {
                    $query->whereNotIn('id', $forbiddenIds);
                }

                $candidateId = $query->value('id');

                if ($candidateId === null) {
                    return null;
                }

                $now = now();
                $token = (string) Str::uuid();

                // The conditional UPDATE is the atomic claim. It protects
                // against two workers selecting the same candidate at once.
                $updated = OutboxMessage::query()
                    ->whereKey($candidateId)
                    ->whereNull('processed_at')
                    ->whereNull('failed_at')
                    ->where('attempts', '<', $maxAttempts)
                    ->where(function ($query) use ($leaseExpiredAt): void {
                        $query->whereNull('locked_at')
                            ->orWhere('locked_at', '<=', $leaseExpiredAt);
                    })
                    ->where(function ($query) use ($now): void {
                        $query->whereNull('available_at')
                            ->orWhere('available_at', '<=', $now);
                    })
                    ->update([
                        'attempts' => $this->connection()->raw('attempts + 1'),
                        'last_attempt_at' => $now,
                        'locked_at' => $now,
                        'lock_token' => $token,
                    ]);

                if ($updated === 1) {
                    return OutboxMessage::query()
                        ->whereKey($candidateId)
                        ->where('lock_token', $token)
                        ->first();
                }

                $forbiddenIds[] = (string) $candidateId;
            }

            return null;
        });
    }

    private function failExpiredFinalAttempts(): void
    {
        $now = now();
        $leaseExpiredAt = $now->copy()->subSeconds(
            max(1, (int) config('outbox.worker.message_lock_timeout', 300))
        );
        $maxAttempts = max(1, (int) config('outbox.retry.max_attempts', 5));

        OutboxMessage::query()
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('attempts', '>=', $maxAttempts)
            ->whereNotNull('locked_at')
            ->where('locked_at', '<=', $leaseExpiredAt)
            ->update([
                'failed_at' => $now,
                'last_error' => 'Processing lease expired after the maximum number of attempts.',
                'available_at' => null,
                'locked_at' => null,
                'lock_token' => null,
            ]);
    }

    private function markProcessed(string $messageId, string $lockToken): ?OutboxMessage
    {
        return $this->connection()->transaction(function () use ($messageId, $lockToken): ?OutboxMessage {
            $updated = OutboxMessage::query()
                ->whereKey($messageId)
                ->where('lock_token', $lockToken)
                ->whereNull('processed_at')
                ->update([
                    'processed_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                    'locked_at' => null,
                    'lock_token' => null,
                ]);

            return $updated === 1 ? OutboxMessage::query()->find($messageId) : null;
        });
    }

    private function recordFailure(string $messageId, string $lockToken, Throwable $exception): ?OutboxMessage
    {
        return $this->connection()->transaction(function () use ($messageId, $lockToken, $exception): ?OutboxMessage {
            $message = OutboxMessage::query()
                ->whereKey($messageId)
                ->where('lock_token', $lockToken)
                ->whereNull('processed_at')
                ->first();

            if ($message === null) {
                return null;
            }

            $maxAttempts = max(1, (int) config('outbox.retry.max_attempts', 5));
            $failed = $message->attempts >= $maxAttempts;

            $values = [
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                'locked_at' => null,
                'lock_token' => null,
                'failed_at' => $failed ? now() : null,
                'available_at' => $failed ? null : now()->addSeconds($this->retryDelay($message->attempts)),
            ];

            $updated = OutboxMessage::query()
                ->whereKey($messageId)
                ->where('lock_token', $lockToken)
                ->update($values);

            return $updated === 1 ? OutboxMessage::query()->find($messageId) : null;
        });
    }

    private function retryDelay(int $attempt): int
    {
        $baseDelay = max(0, (int) config('outbox.retry.delay_seconds', 60));
        $maxDelay = max(0, (int) config('outbox.retry.max_delay_seconds', 3600));
        $baseDelay = min($baseDelay, $maxDelay);
        $multiplier = max(1.0, (float) config('outbox.retry.multiplier', 2));
        $exponent = max(0, $attempt - 1);

        // Avoid overflowing pow() for unexpectedly large attempt counts.
        $delay = (int) min($maxDelay, $baseDelay * ($multiplier ** min($exponent, 20)));

        return min($maxDelay, $delay);
    }

    private function dispatchMonitoringEvent(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            // Monitoring listeners must not change the delivery outcome.
            Log::warning('An outbox monitoring listener failed.', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function connection(): Connection
    {
        return DB::connection(config('outbox.connection'));
    }
}
