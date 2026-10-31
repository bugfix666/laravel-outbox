<?php

declare(strict_types=1);

use Bugfix666\LaravelOutbox\Publishers\LogPublisher;

return [
    'table' => env('OUTBOX_TABLE', 'outbox_messages'),
    'connection' => env('OUTBOX_DB_CONNECTION', env('DB_CONNECTION')),

    // LogPublisher is intended for local development and diagnostics.
    // Configure a real broker publisher in production.
    'publisher' => env('OUTBOX_PUBLISHER', LogPublisher::class),

    'worker' => [
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
        // Cache lock prevents overlapping processor commands. Per-message
        // database leases provide a second line of protection.
        'lock_timeout' => (int) env('OUTBOX_LOCK_TIMEOUT', 900),
        'lock_store' => env('OUTBOX_LOCK_STORE', env('CACHE_STORE', 'database')),
        'message_lock_timeout' => (int) env('OUTBOX_MESSAGE_LOCK_TIMEOUT', 300),
    ],

    'cleanup' => [
        'days' => (int) env('OUTBOX_CLEANUP_DAYS', 7),
    ],

    'retry' => [
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 5),
        'delay_seconds' => (int) env('OUTBOX_RETRY_DELAY', 60),
        'max_delay_seconds' => (int) env('OUTBOX_RETRY_MAX_DELAY', 3600),
        'multiplier' => (float) env('OUTBOX_RETRY_MULTIPLIER', 2),
    ],
];
