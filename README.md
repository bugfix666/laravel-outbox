# Laravel Outbox

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bugfix666/laravel-outbox.svg?style=flat-square)](https://packagist.org/packages/bugfix666/laravel-outbox)
[![PHP Version](https://img.shields.io/packagist/php-v/bugfix666/laravel-outbox.svg?style=flat-square)](https://packagist.org/packages/bugfix666/laravel-outbox)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-red.svg?style=flat-square)](https://laravel.com)
[![License](https://img.shields.io/github/license/bugfix666/laravel-outbox.svg?style=flat-square)](https://opensource.org/licenses/MIT)
[![Tests](https://img.shields.io/github/actions/workflow/status/bugfix666/laravel-outbox/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/bugfix666/laravel-outbox/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/bugfix666/laravel-outbox.svg?style=flat-square)](https://packagist.org/packages/bugfix666/laravel-outbox)

**Transactional Outbox pattern implementation for Laravel 12+ (PHP 8.4+).**  
Guarantees atomicity and reliable event delivery in distributed systems, especially fintech, e‑commerce, and microservices.

---

## 📦 Features

- ✅ **Atomic persistence** – business data + outbox record in a single DB transaction.
- ✅ **Reliable background processing** – worker with locking, batch processing, and retries.
- ✅ **Flexible publishers** – log, queue, or your own (Kafka, RabbitMQ, SNS, etc.).
- ✅ **Built‑in retry logic** – exponential backoff (configurable attempts & delay).
- ✅ **Monitoring** – events for processed/failed messages; easy to integrate with logging or metrics.
- ✅ **Cleanup** – automated purging of old processed messages.
- ✅ **Modern PHP 8.4+** – typed properties, readonly classes, enums, constructor promotion.
- ✅ **Laravel 12+ ready** – uses new attribute‑based console commands.

---

## 🔧 Requirements

- PHP 8.4 or higher
- Laravel 12.0 or higher
- Database that supports transactions (MySQL, PostgreSQL, SQLite, etc.)

---

## 📥 Installation

```bash
composer require bugfix666/laravel-outbox
```

---

## 🚀 Setup

Publish the configuration and migration files:

```bash
php artisan vendor:publish --tag=outbox-config
php artisan vendor:publish --tag=outbox-migrations
```

Run the migration to create the `outbox_messages` table:

```bash
php artisan migrate
```

---

## ⚙️ Configuration

The config file `config/outbox.php` allows you to tailor the package to your needs:

```php
return [
    'table'      => env('OUTBOX_TABLE', 'outbox_messages'),
    'connection' => env('OUTBOX_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    // Your custom publisher class (must implement OutboxPublisher)
    'publisher'  => env('OUTBOX_PUBLISHER', Bugfix666\LaravelOutbox\Publishers\LogPublisher::class),

    'worker' => [
        'batch_size'   => env('OUTBOX_BATCH_SIZE', 100),
        'lock_timeout' => env('OUTBOX_LOCK_TIMEOUT', 10), // seconds
        'lock_store'   => env('OUTBOX_LOCK_STORE', 'cache'), // cache, redis, etc.
    ],

    'cleanup' => [
        'days' => env('OUTBOX_CLEANUP_DAYS', 7),
    ],

    'retry' => [
        'max_attempts'   => env('OUTBOX_MAX_ATTEMPTS', 5),
        'delay_seconds'  => env('OUTBOX_RETRY_DELAY', 60), // seconds between attempts
    ],
];
```

Set your preferred publisher in `.env`:

```env
OUTBOX_PUBLISHER=App\Publishers\KafkaPublisher
OUTBOX_BATCH_SIZE=50
OUTBOX_MAX_ATTEMPTS=3
```

---

## 💡 Basic Usage

### 1. Store an outbox message inside a transaction

```php
use Bugfix666\LaravelOutbox\Services\OutboxService;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($outboxService) {
    // 1. Business logic (e.g., create a payment)
    $payment = Payment::create([
        'amount' => 1000,
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    // 2. Store the outbox message – guaranteed to be persisted atomically
    $outboxService->store(
        aggregateType: 'Payment',
        aggregateId: (string) $payment->id,
        eventType: 'payment.completed',
        payload: [
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'user_id' => auth()->id(),
        ]
    );
});
```

### 2. Run the worker

Start the outbox processor (should be supervised in production):

```bash
php artisan outbox:process
```

You can override batch size on the fly:

```bash
php artisan outbox:process --batch-size=200
```

### 3. Clean up old processed messages

Schedule the cleanup command to run daily (in `routes/console.php`):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:cleanup')->daily();
```

Or run manually:

```bash
php artisan outbox:cleanup --days=14
```

---

## 🔌 Custom Publisher

By default, the package logs events. To send events to a real broker, create a class that implements `Bugfix666\LaravelOutbox\Contracts\OutboxPublisher`:

```php
namespace App\Publishers;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use RdKafka\Producer;

class KafkaPublisher implements OutboxPublisher
{
    public function __construct(private Producer $producer) {}

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        $topic = $this->producer->newTopic($eventType);
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($payload));
        $this->producer->flush(1000);
        return true;
    }
}
```

Then set `OUTBOX_PUBLISHER=App\Publishers\KafkaPublisher` in your `.env`.

> **Tip:** For RabbitMQ, use `php-amqplib`; for AWS SNS, use the Laravel SNS facade.

---

## 🧩 Monitoring and Events

The package fires two events that you can listen to:

- `Bugfix666\LaravelOutbox\Events\OutboxMessageProcessed`
- `Bugfix666\LaravelOutbox\Events\OutboxMessageFailed`

Example listener:

```php
use Bugfix666\LaravelOutbox\Events\OutboxMessageFailed;

class LogOutboxFailure
{
    public function handle(OutboxMessageFailed $event): void
    {
        logger()->error('Outbox failure', [
            'id' => $event->message->id,
            'attempts' => $event->message->attempts,
            'error' => $event->exception->getMessage(),
        ]);
    }
}
```

Register your listener in `EventServiceProvider`.

---

## 🔄 Retry Logic

When publishing fails, the worker increments the `attempts` counter and sets `available_at` to `now() + delay_seconds`. The next run will only pick messages where `available_at` is in the past, giving the broker time to recover.

After `max_attempts` failures, the message remains unprocessed but is considered failed – you can handle it manually or set up an alert.

---

## 🧪 Testing

The package is fully testable. You can mock the publisher and assert that messages are stored and processed correctly.

Example test:

```php
public function test_message_is_stored(): void
{
    DB::transaction(function () {
        $service = app(OutboxService::class);
        $message = $service->store('User', '123', 'user.created', ['name' => 'John']);
        $this->assertDatabaseHas('outbox_messages', ['id' => $message->id]);
    });
}
```

---

## 📊 Performance Considerations

- **Indexes** – The migration includes indexes on `(processed_at, created_at)`, `aggregate_type/aggregate_id`, and `available_at` to keep queries fast.
- **Batch size** – Tune `batch_size` according to your workload. Larger batches reduce DB round‑trips but increase memory usage.
- **Partitioning** – For extremely high volume, consider table partitioning by `created_at` (e.g., daily) to keep the active set small.
- **Locking** – The worker uses `FOR UPDATE` to prevent double‑processing. Ensure your database supports row‑level locking.

---

## 🛠 Supervisor Configuration

For production, keep the worker running with Supervisor:

```ini
[program:outbox-worker]
command=php /path/to/your/project/artisan outbox:process
directory=/path/to/your/project
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/outbox-worker.log
```

---

## 📖 Full Documentation

- [Transactional Outbox Pattern – Martin Fowler](https://martinfowler.com/eaaDev/Outbox.html)
- [Laravel 12 Documentation](https://laravel.com/docs/12.x)

---

## 🤝 Contributing

1. Fork the repository.
2. Create a feature branch (`git checkout -b feature/amazing-feature`).
3. Commit your changes (`git commit -m 'Add some amazing feature'`).
4. Push to the branch (`git push origin feature/amazing-feature`).
5. Open a Pull Request.

Please ensure that your code passes the existing tests and is well‑documented.

---

## 📝 License

This package is open‑source software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## 💬 Support

For questions, bug reports, or feature requests, please [open an issue](https://github.com/bugfix666/laravel-outbox/issues) on GitHub.

---

**Happy coding, and may your events always be delivered! 🚀**