<?php

namespace Appwrite\Tests;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

class TestPreparedSubscriber implements PreparedSubscriber
{
    /**
     * Elapsed-since-run-start, in seconds, captured when each test is prepared.
     * Keyed by the test id so TestFinishedSubscriber can compute the true
     * per-test duration as (finish elapsed - start elapsed).
     *
     * @var array<string, float>
     */
    public static array $startSeconds = [];

    public function notify(Prepared $event): void
    {
        self::$startSeconds[$event->test()->id()] = $event->telemetryInfo()->durationSinceStart()->seconds();
    }
}
