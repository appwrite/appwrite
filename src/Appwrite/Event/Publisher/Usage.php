<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Utopia\Console;
use Utopia\Queue\Queue;
use Utopia\System\System;

/** @extends Base<UsageMessage> */
readonly class Usage extends Base
{
    protected function dispatch(BaseMessage $message, ?Queue $queue, bool $background): string|bool
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            return false;
        }

        try {
            return parent::dispatch($message, $queue, $background);
        } catch (\Throwable $th) {
            Console::error('[Usage] Failed to publish usage message: ' . $th->getMessage());
            return false;
        }
    }

}
