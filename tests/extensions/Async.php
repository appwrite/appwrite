<?php

namespace Appwrite\Tests;

use PHPUnit\Framework\Assert;
use Appwrite\Tests\Async\Eventually;

trait Async
{
    public static function assertEventually(callable $probe, int $timeoutMilliseconds = 10000, int $waitMilliseconds = 500): void
    {
        Assert::assertThat($probe, new Eventually($timeoutMilliseconds, $waitMilliseconds));
    }
}
