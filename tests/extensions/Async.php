<?php

namespace Appwrite\Tests;

use Appwrite\Tests\Async\Eventually;
use PHPUnit\Framework\Assert;

const DEFAULT_TIMEOUT_MS = 10000;
const DEFAULT_WAIT_MS = 500;

trait Async
{
    public static function assertEventually(callable $probe, int $timeoutMs = DEFAULT_TIMEOUT_MS, int $waitMs = DEFAULT_WAIT_MS): void
    {
        Assert::assertThat($probe, new Eventually($timeoutMs, $waitMs));
    }
}
