<?php

namespace Appwrite\Platform\Workers;

use Swoole\Runtime;
use Utopia\Platform\Action;

abstract class Blocking extends Action
{
    private static int $jobs = 0;
    private static ?int $hookFlags = null;

    protected function disableTcpHook(): void
    {
        if (!\class_exists(Runtime::class)) {
            return;
        }

        if (self::$jobs === 0) {
            self::$hookFlags = Runtime::getHookFlags();
            Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP);
        }

        self::$jobs++;
    }

    protected function restoreTcpHook(): void
    {
        if (!\class_exists(Runtime::class)) {
            return;
        }

        self::$jobs--;

        if (self::$jobs === 0 && self::$hookFlags !== null) {
            Runtime::setHookFlags(self::$hookFlags);
            self::$hookFlags = null;
        }
    }
}
