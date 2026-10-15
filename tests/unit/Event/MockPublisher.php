<?php

namespace Tests\Unit\Event;

use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

class MockPublisher implements Publisher
{
    private array $events = [];

    public function publish(Queue $queue, array $payload): bool
    {
        if (!isset($this->events[$queue->name])) {
            $this->events[$queue->name] = [];
        }
        $this->events[$queue->name][] = $payload;
        return true;
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            $this->publish($queue, $payload);
        }

        return true;
    }

    public function getEvents(string $queue)
    {
        return $this->events[$queue] ?? null;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        // TODO: Implement retry() method.
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return count($this->events[$queue->name] ?? []);
    }

    public function getFailedCount(Queue $queue): int
    {
        // Nothing recorded here ever fails: this double only collects what was
        // published, so the count of work that did not get through is zero.
        return 0;
    }
}
