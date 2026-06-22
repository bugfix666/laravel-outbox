<?php

namespace Bugfix666\LaravelOutbox\Publishers;

use Bugfix666\LaravelOutbox\Contracts\OutboxPublisher;
use RdKafka\Producer;

final readonly class KafkaPublisher implements OutboxPublisher
{
    public function __construct(private Producer $producer)
    {
    }

    public function publish(string $eventType, array $payload, ?string $aggregateId = null): bool
    {
        $topic = $this->producer->newTopic($eventType);
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($payload));
        $this->producer->flush(1000);
        return true;
    }
}