<?php

namespace Tests\Unit\Event;

use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

class MockPublisher implements Publisher
{
    private array $events = [];

    public function enqueue(Queue $queue, array $payload): bool
    {
        if (!isset($this->events[$queue->name])) {
            $this->events[$queue->name] = [];
        }
        $this->events[$queue->name][] = $payload;
        return true;
    }

    public function getEvents(string $queue)
    {
        return $this->events[$queue] ?? null;
    }

    public function retry(Queue $queue, int $limit = null): void
    {
        // TODO: Implement retry() method.
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return count($this->events[$queue->name]);
    }
}
