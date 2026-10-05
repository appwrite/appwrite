<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Utopia\Queue\Publisher\Coalescing;
use Utopia\Queue\Publisher\Outcome;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/** Records the queue of each keyed publish and answers Published. */
final class CoalescingPublisher implements Synchronous, Coalescing
{
    /** @var list<Queue> */
    public array $queues = [];

    public function coalesce(Queue $queue, array $payload, string $key): Outcome
    {
        $this->queues[] = $queue;

        return Outcome::Published;
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
