<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

/**
 * A publisher that keeps at most one pending message per key in a queue.
 *
 * A key is held until its message can no longer be delivered without operator action:
 * committed, dead-lettered, set aside as poison, or the hold expires. Brokers that hold
 * keys outside the message (Redis) bound the hold by Queue::$keyTtl; brokers that
 * redeliver on their own (NATS) bound it by their redelivery limits. Release, reaping
 * and recovery keep the key while the hold lasts. retry() re-drives without the key.
 */
interface Coalescing
{
    /**
     * Publish the payload unless a message with this key is pending in the queue.
     *
     * A broker that deduplicates by message id may also coalesce a publish whose id it saw within its duplicate window.
     *
     * @param array<string, mixed> $payload
     * @throws \InvalidArgumentException when the key is empty
     */
    public function coalesce(Queue $queue, array $payload, string $key): Outcome;
}
