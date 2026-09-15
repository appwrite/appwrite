<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Utopia\Queue\Publisher\Synchronous as QueuePublisher;
use Utopia\Queue\Queue;

/**
 * Queue publisher that can refuse a publish, either by returning false or by
 * throwing, so callers can be tested for what they do with a job that never
 * reached the queue.
 */
final class Publisher implements QueuePublisher
{
    public bool $reject = false;
    public bool $throw = false;

    /** @var array<string, array<array<string, mixed>>> */
    private array $events = [];

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        if ($this->throw) {
            throw new \RuntimeException('Queue is unreachable');
        }

        if ($this->reject) {
            return false;
        }

        $this->events[$queue->name][] = $payload;

        return true;
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        $published = true;
        foreach ($payloads as $payload) {
            $published = $this->publish($queue, $payload, $priority) && $published;
        }

        return $published;
    }

    /**
     * @return array<array<string, mixed>>|null
     */
    public function getEvents(string $queue): ?array
    {
        return $this->events[$queue] ?? null;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return \count($this->events[$queue->name] ?? []);
    }
}
