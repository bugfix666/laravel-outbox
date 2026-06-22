<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Models;

use Bugfix666\LaravelOutbox\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';
    protected $table = 'outbox_messages';

    protected $casts = [
        'payload' => AsArrayObject::class,
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'available_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected $fillable = [
        'id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'processed_at',
        'attempts',
        'last_attempt_at',
        'available_at',
    ];

    public function scopePending($query)
    {
        return $query->whereNull('processed_at')
            ->where(function ($q) {
                $q->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            });
    }

    public function markProcessed(): void
    {
        $this->processed_at = now();
        $this->save();
    }

    public function recordAttempt(): void
    {
        $this->attempts++;
        $this->last_attempt_at = now();

        $maxAttempts = config('outbox.retry.max_attempts', 5);
        if ($this->attempts < $maxAttempts) {
            $delay = config('outbox.retry.delay_seconds', 60);
            $this->available_at = now()->addSeconds($delay);
        }

        $this->save();
    }

    public function getStatus(): OutboxStatus
    {
        if ($this->processed_at !== null) {
            return OutboxStatus::PROCESSED;
        }

        $maxAttempts = config('outbox.retry.max_attempts', 5);
        if ($this->attempts >= $maxAttempts) {
            return OutboxStatus::FAILED;
        }

        return OutboxStatus::PENDING;
    }
}