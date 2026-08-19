<?php

namespace Appwrite\Platform\Workers;

use Swoole\Runtime;
use Utopia\Messaging\Adapter;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Message;
use Utopia\Platform\Action;

abstract class Blocking extends Action
{
    private static int $jobs = 0;
    private static ?int $hookFlags = null;

    protected function send(Adapter $adapter, Message $message): array
    {
        if (!$adapter instanceof SMTP || !\class_exists(Runtime::class)) {
            return $adapter->send($message);
        }

        if (self::$jobs === 0) {
            self::$hookFlags = Runtime::getHookFlags();
            Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        }

        self::$jobs++;

        try {
            return $adapter->send($message);
        } finally {
            self::$jobs--;

            if (self::$jobs === 0 && self::$hookFlags !== null) {
                Runtime::setHookFlags(self::$hookFlags);
                self::$hookFlags = null;
            }
        }
    }
}
