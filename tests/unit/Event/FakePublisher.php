<?php

namespace Tests\Unit\Event;

use Utopia\Queue\Broker\Queue;
use Utopia\Queue\Publisher;

class FakePublisher implements Publisher
{
    private $events = [];

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
}
