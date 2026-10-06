<?php

namespace Appwrite\Tests;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

final class ProbeTestStartedSubscriber implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        ProbeTimeline::record('start', $event->test()->id());
    }
}
