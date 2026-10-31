# Laravel Outbox

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bugfix666/laravel-outbox.svg?style=flat-square)](https://packagist.org/packages/bugfix666/laravel-outbox)
[![PHP Version](https://img.shields.io/packagist/php-v/bugfix666/laravel-outbox.svg?style=flat-square)](https://packagist.org/packages/bugfix666/laravel-outbox)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-red.svg?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/packagist/l/bugfix666/laravel-outbox.svg?style=flat-square)](https://packagist.org/packages/bugfix666/laravel-outbox)
[![Run Tests](https://github.com/bugfix666/laravel-outbox/actions/workflows/tests.yml/badge.svg)](https://github.com/bugfix666/laravel-outbox/actions/workflows/tests.yml)

**Transactional Outbox pattern implementation for Laravel 12+ and PHP 8.4+.**

The package stores outgoing messages in the same database transaction as your business changes, then publishes them asynchronously through a configurable publisher.

> **Delivery semantics:** the package provides at-least-once publication, not exactly-once delivery. A process can crash after a broker accepts a message but before the outbox row is marked as processed. Consumers must be idempotent.

## Requirements

- PHP 8.4+
- Laravel 12+
- A transactional database supported by Laravel
- A cache store supporting atomic locks for the processor command (for example Redis or the database cache store)

For concurrent production workers, use a database engine with reliable transactional row updates, such as PostgreSQL or MySQL/InnoDB. SQLite is useful for local development and tests, but is not a recommended high-concurrency outbox backend.

## Installation

```bash
composer require bugfix666/laravel-outbox

php artisan vendor:publish --tag=outbox-config
php artisan vendor:publish --tag=outbox-migrations
php artisan migrate
```

The package migrations create `outbox_messages` and add delivery-state columns (`failed_at`, `last_error`, `locked_at`, and `lock_token`). If you have already published and run the original migration, make sure the new additive migration is also published before running `php artisan migrate`.

## Configuration

The published `config/outbox.php` file contains the following settings:

```php
return [
    'table' => env('OUTBOX_TABLE', 'outbox_messages'),
    'connection' => env('OUTBOX_DB_CONNECTION', env('DB_CONNECTION')),

    // LogPublisher is for local development. Configure a real publisher in production.
    'publisher' => env('OUTBOX_PUBLISHER', \Bugfix666\LaravelOutbox\Publishers\LogPublisher::class),

    'worker' => [
        'batch_size' => 100,
        'lock_timeout' => 900,
        'lock_store' => 'database',
        'message_lock_timeout' => 300,
    ],

    'cleanup' => [
        'days' => 7,
    ],

    'retry' => [
        'max_attempts' => 5,
        'delay_seconds' => 60,
        'max_delay_seconds' => 3600,
        'multiplier' => 2,
    ],
];
```

Environment variables:

```dotenv
OUTBOX_PUBLISHER=App\Outbox\RabbitMqPublisher
OUTBOX_BATCH_SIZE=100
OUTBOX_LOCK_TIMEOUT=900
OUTBOX_MESSAGE_LOCK_TIMEOUT=300
OUTBOX_LOCK_STORE=redis
OUTBOX_MAX_ATTEMPTS=5
OUTBOX_RETRY_DELAY=60
OUTBOX_RETRY_MAX_DELAY=3600
OUTBOX_RETRY_MULTIPLIER=2
OUTBOX_CLEANUP_DAYS=7
```

`lock_timeout` is the lifetime of the cache lock that prevents overlapping processor commands. `message_lock_timeout` is the database lease for an individual message. Set the message lease longer than the maximum expected duration of a single publisher call. Expired leases can be reclaimed after a worker crash.

The model and migrations both respect `outbox.table` and `outbox.connection`.

## Store a message in the business transaction

Always write the business change and the outbox message using the same database connection and transaction.

```php
use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreatePayment
{
    public function __construct(private OutboxService $outboxService)
    {
    }

    public function handle(PaymentData $data): Payment
    {
        $connectionName = config('outbox.connection') ?: config('database.default');

        return DB::connection($connectionName)->transaction(function () use ($data, $connectionName): Payment {
            // The business model must use the same connection as the outbox.
            $payment = Payment::on($connectionName)->create([
                'id' => (string) Str::uuid(),
                'amount' => $data->amount,
                'currency' => $data->currency,
                'status' => 'pending',
            ]);

            $this->outboxService->store(
                aggregateType: 'Payment',
                aggregateId: (string) $payment->getKey(),
                eventType: 'payment.requested',
                payload: [
                    'event_id' => (string) Str::uuid(),
                    'payment_id' => (string) $payment->getKey(),
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ],
            );

            return $payment;
        });
    }
}
```

The `aggregate_id` column in the default migration is a UUID. If your aggregate uses another identifier format, customize the migration before running it.

The package's `OutboxService::store()` persists the message; it does not publish it immediately. The row is committed or rolled back together with the surrounding business transaction.

## Run the processor

```bash
php artisan outbox:process
php artisan outbox:process --batch-size=200
```

Run this command under a process supervisor or schedule it:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:process --batch-size=100')
    ->everyMinute()
    ->withoutOverlapping();
```

The processor claims messages with a database lease and a unique lock token. If a worker dies, another run can reclaim the message after `message_lock_timeout`. The cache lock is an additional guard against overlapping processor commands; it does not replace the database claim.

## Publishers

### Log publisher

`LogPublisher` is the default so a fresh installation can be exercised without a broker. It logs and marks messages as published; it does **not** deliver them to another service. Do not use it when events must reach a real consumer.

### Queue publisher

The package includes `QueuePublisher`. It enqueues a package job, which dispatches the `OutboxEvent` Laravel event when the job runs.

```dotenv
OUTBOX_PUBLISHER=Bugfix666\LaravelOutbox\Publishers\QueuePublisher
```

You can listen for the event in your application:

```php
use Bugfix666\LaravelOutbox\Events\OutboxEvent;

final class HandlePaymentRequested
{
    public function handle(OutboxEvent $event): void
    {
        if ($event->eventType !== 'payment.requested') {
            return;
        }

        // Handle the event. Make this handler idempotent.
    }
}
```

The queue publisher uses the `default` queue. To route these jobs to another queue, bind an instance with the desired queue name in an application service provider:

```php
use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Publishers\QueuePublisher;

$this->app->bind(OutboxPublisher::class, fn () => new QueuePublisher('outbox-events'));
```

### Custom publisher

Implement `OutboxPublisher` to publish to RabbitMQ, Kafka, SNS, or another broker:

```php
namespace App\Outbox;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use RuntimeException;

final class RabbitMqPublisher implements OutboxPublisher
{
    public function __construct(private BrokerClient $client)
    {
    }

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        // Publish to the broker and wait for its publisher confirmation.
        // Throw if the broker did not accept the message durably.
        $accepted = $this->client->publish($eventType, $payload, $aggregateId);

        if (!$accepted) {
            throw new RuntimeException('The broker did not confirm publication.');
        }

        return true;
    }
}
```

A publisher must return `true` only when the broker has accepted the message according to the durability guarantees required by your application. Returning `false` is treated as a failed attempt. Throwing an exception also schedules a retry.

The built-in `KafkaPublisher` requires the `rdkafka` PHP extension and checks the producer flush result. It is not installed as a dependency automatically.

## Retry and failed messages

Failed publication attempts use exponential backoff:

`min(max_delay_seconds, delay_seconds * multiplier ^ (attempts - 1))`

When `max_attempts` is reached, the message receives `failed_at` and remains in the table for inspection. The most recent exception message is stored in `last_error`. Avoid putting secrets or sensitive payment data in exception messages.

Inspect failed messages:

```php
use Bugfix666\LaravelOutbox\Enums\OutboxStatus;
use Bugfix666\LaravelOutbox\Models\OutboxMessage;

$failed = OutboxMessage::query()
    ->whereNotNull('failed_at')
    ->get();

$status = $failed->first()?->getStatus(); // OutboxStatus::FAILED
```

After investigating and correcting the underlying issue, manually retry a failed message:

```bash
php artisan outbox:retry <message-uuid>
```

This resets its attempt counter and makes it immediately available. Use this deliberately: the consumer must be idempotent, especially if the previous delivery outcome was uncertain.

## Monitoring

The package dispatches:

- `OutboxMessageProcessed` after a message is marked as processed.
- `OutboxMessageFailed` after a publication attempt fails. This event fires for retryable attempts as well as final failures.

The service also logs publication failures. Monitoring listeners are isolated so an exception in an alerting listener does not change the delivery state.

Useful production metrics include:

- Oldest pending message age.
- Number of pending and failed messages.
- Number of expired processing leases.
- Publication attempt count and failure rate.
- Time between business commit and successful publication.

## Cleanup

Only successfully processed messages are removed by cleanup. Failed messages remain available for inspection.

```bash
php artisan outbox:cleanup --days=14
```

Schedule cleanup according to your retention and audit requirements. Do not delete outbox records before the downstream system's recovery and reconciliation window has elapsed.

## Delivery guarantees and consumer design

The outbox closes the dual-write gap between the business database transaction and recording the intent to publish. It cannot make a database and an external broker participate in one atomic transaction.

A broker can accept a message and the processor can crash before setting `processed_at`. The message may then be published again. This is why the package uses at-least-once delivery semantics.

Consumers should use a stable event ID or business reference and enforce deduplication with a database unique constraint. For financial operations, also use provider idempotency keys, ledger uniqueness constraints, and reconciliation for ambiguous external outcomes. Queue locks and unique jobs are not substitutes for business-level idempotency.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The test suite uses Orchestra Testbench and SQLite in-memory for fast functional coverage. Run additional integration tests against the same database engine and cache store used in production, especially for concurrent workers and broker publisher confirmations.

## License

This package is licensed under [GNU General Public License v3.0 only](LICENSE), consistent with the `GPL-3.0-only` declaration in `composer.json`.
