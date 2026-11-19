<?php

namespace Tests\Unit\Schedule;

use Appwrite\Schedule\Supervisor;
use PHPUnit\Framework\TestCase;

final class SupervisorTest extends TestCase
{
    public function testAFailedAttemptIsReportedAndStartedAgainAfterTheBackoff(): void
    {
        $attempts = 0;
        $errors = [];
        $sleeps = [];
        $supervisor = new Supervisor(
            5,
            function (\Throwable $error) use (&$errors): void {
                $errors[] = $error->getMessage();
            },
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $supervisor->run(function () use (&$attempts): void {
            $attempts++;
            if ($attempts < 3) {
                throw new \RedisException('read error on connection to locks:6379');
            }
        });

        $this->assertSame(3, $attempts);
        $this->assertSame(['read error on connection to locks:6379', 'read error on connection to locks:6379'], $errors);
        $this->assertSame([5, 5], $sleeps);
    }

    public function testAnAttemptThatReturnsEndsTheLoop(): void
    {
        $attempts = 0;
        $sleeps = [];
        $supervisor = new Supervisor(
            5,
            sleep: function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $supervisor->run(function () use (&$attempts): void {
            $attempts++;
        });

        $this->assertSame(1, $attempts);
        $this->assertSame([], $sleeps);
    }
}
