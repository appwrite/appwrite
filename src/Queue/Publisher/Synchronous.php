<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

/**
 * A publisher that hands messages to the broker synchronously: publish() blocks
 * until the broker accepts the message and returns whether it did. Brokers such
 * as Redis and Pool implement this directly; Broker\Background wraps one to add
 * background dispatch.
 */
interface Synchronous
{
    /**
     * Publishes a message onto the queue, blocking until the broker accepts it.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(Queue $queue, array $payload, bool $priority = false): bool;

    /**
     * Publishes several messages in one round trip.
     *
     * A caller with many messages due at the same moment pays one connection
     * checkout and one command instead of N of each. Each payload becomes its
     * own message with its own id, exactly as if enqueue() had been called for
     * it, so consumers cannot tell the difference.
     *
     * @param list<array<string, mixed>> $payloads
     */
    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool;

    /**
     * Retries failed jobs.
     */
    public function retry(Queue $queue, ?int $limit = null): void;

    /**
     * Returns the amount of pending messages in the queue.
     */
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int;
}
