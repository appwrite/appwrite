<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use Utopia\Queue\Publisher\Coalescing;
use Utopia\Queue\Publisher\Outcome;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/** Records keyed publishes and answers each with a fixed outcome. */
final class CoalescingPublisher implements Synchronous, Coalescing
{
    /** @var list<array{queue: string, payload: array<string, mixed>, key: string}> */
    public array $coalesced = [];

    public function __construct(private readonly Outcome $outcome)
    {
    }

    public function coalesce(Queue $queue, array $payload, string $key): Outcome
    {
        $this->coalesced[] = ['queue' => $queue->name, 'payload' => $payload, 'key' => $key];

        return $this->outcome;
    }

    public function publish(Queue $queue, array $payload): bool
    {
        return true;
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return 0;
    }

    public function getFailedCount(Queue $queue): int
    {
        return 0;
    }
}
