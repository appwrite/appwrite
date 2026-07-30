<?php

namespace Appwrite\Tests;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use Swoole\Event;
use Swoole\Timer;

class SwooleCleanupSubscriber implements ExecutionFinishedSubscriber
{
    public function notify(ExecutionFinished $event): void
    {
        Timer::clearAll();
        Event::wait();
    }
}
