<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Utopia\Console;
use Utopia\Queue\Queue;
use Utopia\System\System;

/** @extends Base<StatsResourcesMessage> */
readonly class StatsResources extends Base
{
    protected function dispatch(BaseMessage $message, ?Queue $queue, bool $background): string|bool
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return false;
        }

        // Resource stats are best-effort; publishing failures should not interrupt the scheduler loop.
        try {
            return parent::dispatch($message, $queue, $background);
        } catch (\Throwable $th) {
            Console::error('[StatsResources] Failed to publish stats resources message: ' . $th->getMessage());
            return false;
        }
    }

}
