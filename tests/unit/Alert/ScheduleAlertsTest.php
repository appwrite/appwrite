<?php

namespace Tests\Unit\Alert;

use Appwrite\Platform\Tasks\ScheduleAlerts;
use PHPUnit\Framework\TestCase;

final class ScheduleAlertsTest extends TestCase
{
    public function testADroppedConnectionIsRetriedInsteadOfEndingTheTask(): void
    {
        $task = $this->task();
        $attempts = 0;
        $sleeps = [];

        $task->superviseForTest(
            function () use (&$attempts): void {
                $attempts++;
                if ($attempts < 3) {
                    throw new \RedisException('read error on connection to locks:6379');
                }
            },
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $this->assertSame(3, $attempts, 'each failure gets a fresh attempt');
        $this->assertSame([5, 5], $sleeps, 'every retry waits out the backoff');
    }

    public function testASchedulerThatReturnsIsNotRestarted(): void
    {
        $task = $this->task();
        $attempts = 0;
        $sleeps = [];

        $task->superviseForTest(
            function () use (&$attempts): void {
                $attempts++;
            },
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $this->assertSame(1, $attempts);
        $this->assertSame([], $sleeps, 'a stopped scheduler ends the task');
    }

    private function task(): object
    {
        return new class () extends ScheduleAlerts {
            public function superviseForTest(callable $attempt, callable $sleep): void
            {
                $this->supervise($attempt, $sleep);
            }
        };
    }
}
