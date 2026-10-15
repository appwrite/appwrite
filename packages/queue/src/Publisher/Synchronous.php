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
    public function publish(Queue $queue, array $payload): bool;

    /**
     * Publishes several messages, blocking until the broker accepts them.
     * Each payload becomes an independent message, as with publish().
     *
     * @param list<array<string, mixed>> $payloads
     */
    public function publishMany(Queue $queue, array $payloads): bool;

    /**
     * Retries failed jobs.
     */
    public function retry(Queue $queue, ?int $limit = null): void;

    /**
     * Returns the amount of pending messages in the queue.
     *
     * `$failedJobs` is the older spelling of {@see self::getFailedCount()} and
     * delegates to it. Prefer the named method: what a queue is holding and what
     * it could not get through are different questions over different objects,
     * and a boolean reads like one question with a variant.
     */
    public function getQueueSize(Queue $queue, bool $failedJobs = false): int;

    /**
     * Messages this queue could not get through, whatever stopped them.
     *
     * A message leaves the work queue for more than one reason -- rejected with
     * attempts left, rejected terminally or out of attempts, or bytes no codec
     * here could read -- and an operator asking whether a queue is in trouble
     * means all of them. Each broker sums whatever destinations it keeps.
     */
    public function getFailedCount(Queue $queue): int;
}
