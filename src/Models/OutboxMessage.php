<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Models;

use Bugfix666\LaravelOutbox\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class OutboxMessage extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'processed_at',
        'failed_at',
        'last_error',
        'attempts',
        'last_attempt_at',
        'available_at',
        'locked_at',
        'lock_token',
    ];

    protected function casts(): array
    {
        return [
            'payload' => AsArrayObject::class,
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?: (string) config('outbox.table', 'outbox_messages');
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('outbox.connection') ?? parent::getConnectionName();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('attempts', '<', max(1, (int) config('outbox.retry.max_attempts', 5)))
            ->where(function (Builder $query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            });
    }

    public function scopeReadyForProcessing(Builder $query): Builder
    {
        $leaseExpiredAt = now()->subSeconds(
            max(1, (int) config('outbox.worker.message_lock_timeout', 300))
        );

        return $query->pending()->where(function (Builder $query) use ($leaseExpiredAt): void {
            $query->whereNull('locked_at')
                ->orWhere('locked_at', '<=', $leaseExpiredAt);
        });
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'failed_at' => null,
            'last_error' => null,
            'locked_at' => null,
            'lock_token' => null,
        ])->save();
    }

    public function getStatus(): OutboxStatus
    {
        if ($this->processed_at !== null) {
            return OutboxStatus::PROCESSED;
        }

        if ($this->locked_at !== null) {
            $leaseExpiredAt = now()->subSeconds(
                max(1, (int) config('outbox.worker.message_lock_timeout', 300))
            );

            if ($this->locked_at instanceof Carbon && $this->locked_at->gt($leaseExpiredAt)) {
                return OutboxStatus::PROCESSING;
            }
        }

        if ($this->failed_at !== null
            || $this->attempts >= max(1, (int) config('outbox.retry.max_attempts', 5))) {
            return OutboxStatus::FAILED;
        }

        return OutboxStatus::PENDING;
    }
}
