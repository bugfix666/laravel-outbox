<?php

return [
    'table' => env('OUTBOX_TABLE', 'outbox_messages'),
    'connection' => env('OUTBOX_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    'publisher' => env('OUTBOX_PUBLISHER', Bugfix666\LaravelOutbox\Publishers\LogPublisher::class),

    'worker' => [
        'batch_size' => env('OUTBOX_BATCH_SIZE', 100),
        'lock_timeout' => env('OUTBOX_LOCK_TIMEOUT', 10),
        'lock_store' => env('OUTBOX_LOCK_STORE', 'cache'),
    ],

    'cleanup' => [
        'days' => env('OUTBOX_CLEANUP_DAYS', 7),
    ],

    'retry' => [
        'max_attempts' => env('OUTBOX_MAX_ATTEMPTS', 5),
        'delay_seconds' => env('OUTBOX_RETRY_DELAY', 60),
    ],
];