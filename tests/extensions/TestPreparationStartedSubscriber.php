<?php

namespace Appwrite\Tests;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

class TestPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    /**
     * Elapsed-since-run-start, in seconds, captured when each test begins
     * preparation. Keyed by the test id so TestFinishedSubscriber can compute
     * the true per-test duration as (finish elapsed - start elapsed).
     *
     * Stamped on PreparationStarted (before setUp/fixtures run) rather than
     * Prepared, so expensive setup work is included in the reported duration.
     *
     * @var array<string, float>
     */
    public static array $startSeconds = [];

    public function notify(PreparationStarted $event): void
    {
        self::$startSeconds[$event->test()->id()] = $event->telemetryInfo()->durationSinceStart()->asFloat();
    }
}
