<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Message\Base as BaseMessage;
use Utopia\Console;
use Utopia\Queue\Queue;
use Utopia\System\System;

/** @extends Base<AuditMessage> */
readonly class Audit extends Base
{
    protected function dispatch(BaseMessage $message, ?Queue $queue, bool $background): string|bool
    {
        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            return false;
        }

        // Audit delivery is best-effort and should never fail the request lifecycle.
        try {
            return parent::dispatch($message, $queue, $background);
        } catch (\Throwable $th) {
            Console::error('[Audit] Failed to publish audit message: ' . $th->getMessage());

            return false;
        }
    }

}
