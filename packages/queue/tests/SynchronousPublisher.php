<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/** A publisher with no keyed publish, counting every call it receives. */
final class SynchronousPublisher implements Synchronous
{
    public int $calls = 0;

    public function publish(Queue $queue, array $payload): bool
    {
        $this->calls++;

        return true;
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        $this->calls++;

        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        $this->calls++;
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $this->calls++;

        return 0;
    }

    public function getFailedCount(Queue $queue): int
    {
        $this->calls++;

        return 0;
    }
}
