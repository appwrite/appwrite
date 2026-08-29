<?php

namespace Tests\Unit\Usage;

use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

/**
 * Captures published payloads so tests can assert on them.
 */
final class CapturingPublisher implements Publisher
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        $this->published[] = $payload;

        return true;
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
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
}
