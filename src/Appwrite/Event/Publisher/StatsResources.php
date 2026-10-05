<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Utopia\Console;
use Utopia\Queue\Publisher\Coalescing;
use Utopia\Queue\Publisher\Outcome;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;
use Utopia\System\System;

readonly class StatsResources extends Base
{
    // Intervals a pending recount may hold its project before another is queued.
    private const int HOLD = 10;

    public function __construct(
        Publisher $publisher,
        protected Queue $queue
    ) {
        parent::__construct($publisher);
    }

    public function enqueue(StatsResourcesMessage $message): string|bool
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return false;
        }

        // Resource stats are best-effort; publishing failures should not interrupt the scheduler loop.
        try {
            return $this->publish($this->queue, $message);
        } catch (\Throwable $th) {
            Console::error('[StatsResources] Failed to publish stats resources message: ' . $th->getMessage());
            return false;
        }
    }

    /**
     * Publish unless this project already has a recount pending; null when usage stats are disabled.
     */
    public function coalesce(StatsResourcesMessage $message): ?Outcome
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return null;
        }

        if (!$this->publisher instanceof Coalescing) {
            throw new \LogicException('Stats resources publisher does not support keyed publishing');
        }

        $interval = max(1, (int) System::getEnv('_APP_STATS_RESOURCES_INTERVAL', '3600'));
        $queue = new Queue($this->queue->name, $this->queue->namespace, $this->queue->jobTtl, keyTtl: $interval * self::HOLD);

        return $this->publisher->coalesce($queue, $message->toArray(), $message->project->getId());
    }

    public function getSize(bool $failed = false): int
    {
        return $this->getQueueSize($this->queue, $failed);
    }
}
