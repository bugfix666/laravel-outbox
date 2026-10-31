<?php

declare(strict_types=1);

namespace Bugfix666\LaravelOutbox\Publishers;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use Bugfix666\LaravelOutbox\Exceptions\OutboxException;
use JsonException;
use RdKafka\Producer;
use Throwable;

final readonly class KafkaPublisher implements OutboxPublisher
{
    public function __construct(private Producer $producer)
    {
    }

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        try {
            $message = json_encode($payload, JSON_THROW_ON_ERROR);
            $topic = $this->producer->newTopic($eventType);
            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $aggregateId);

            $result = $this->producer->flush(10_000);

            if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                throw new OutboxException(sprintf(
                    'Kafka producer flush failed with error code [%s].',
                    (string) $result,
                ));
            }

            return true;
        } catch (JsonException $exception) {
            throw new OutboxException('Unable to encode outbox payload as JSON.', previous: $exception);
        } catch (Throwable $exception) {
            if ($exception instanceof OutboxException) {
                throw $exception;
            }

            throw new OutboxException('Kafka publishing failed.', previous: $exception);
        }
    }
}
