<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Utopia\Console;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

readonly class StatsResources extends Base
{
    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(StatsResourcesMessage $message): string|bool
    {
        // Resource stats are best-effort; publishing failures should not interrupt the scheduler loop.
        try {
            return $this->publish($this->queue, $message);
        } catch (\Throwable $th) {
            Console::error('[StatsResources] Failed to publish stats resources message: ' . $th->getMessage());
            return false;
        }
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
