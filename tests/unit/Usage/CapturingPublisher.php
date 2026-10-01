<?php

namespace Tests\Unit\Usage;

use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

/**
 * Captures published payloads so tests can assert on them.
 */
final class CapturingPublisher implements Publisher
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    public function publish(Queue $queue, array $payload): bool
    {
        $this->published[] = $payload;

        return true;
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            $this->published[] = $payload;
        }

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
