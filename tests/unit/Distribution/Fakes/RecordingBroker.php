<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * A broker that records every message and accepts or refuses all of them.
 */
final class RecordingBroker implements Synchronous
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function __construct(private bool $accepts = true)
    {
    }

    public function publish(Queue $queue, array $payload): bool
    {
        $this->messages[] = $payload;

        return $this->accepts;
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        \array_push($this->messages, ...$payloads);

        return $this->accepts;
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
