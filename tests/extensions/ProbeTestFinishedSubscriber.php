<?php

namespace Appwrite\Tests;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class ProbeTestFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        ProbeTimeline::record('end', $event->test()->id());
    }
}
