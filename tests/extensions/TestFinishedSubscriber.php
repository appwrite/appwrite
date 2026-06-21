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
        $testId = $event->test()->id();

        $elapsed = $event->telemetryInfo()->durationSinceStart()->asFloat();
        $start = TestPreparationStartedSubscriber::$startSeconds[$testId] ?? null;
        $time = $start === null ? $elapsed : $elapsed - $start;

        unset(TestPreparationStartedSubscriber::$startSeconds[$testId]);

        printf(
            "%s ended in %d milliseconds\n",
            $test,
            (int) round($time * 1000)
        );

        if ($time > $this->maxSecondsAllowed) {
            fwrite(STDOUT, sprintf("\e[31mThe %s test is slow, it took %.2f seconds!\n\e[0m", $test, $time));
        }
    }
}
