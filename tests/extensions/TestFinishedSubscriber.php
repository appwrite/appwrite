<?php

namespace Appwrite\Tests;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(
        private readonly float $maxSecondsAllowed
    ) {
    }

    public function notify(Finished $event): void
    {
        $test = $event->test()->name();
        $time = $event->telemetryInfo()->durationSinceStart()->seconds();

        printf(
            "%s ended in %s milliseconds\n",
            $test,
            $time * 1000
        );

        if ($time > $this->maxSecondsAllowed) {
            fwrite(STDOUT, sprintf("\e[31mThe %s test is slow, it took %s seconds!\n\e[0m", $test, $time));
        }
    }
}
