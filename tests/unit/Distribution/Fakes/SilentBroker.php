<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * A broker that accepts every message and keeps none.
 */
final class SilentBroker implements Synchronous
{
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

    public function getQueueAge(Queue $queue): int
    {
        return 0;
    }

    public function getFailedCount(Queue $queue): int
    {
        return 0;
    }
}
