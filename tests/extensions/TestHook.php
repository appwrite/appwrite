<?php

namespace Appwrite\Tests;

use PHPUnit\Runner\AfterTestHook;
use Exception;

class TestHook implements AfterTestHook
{
    protected const MAX_SECONDS_ALLOWED = 15;
    public function executeAfterTest(string $test, float $time): void
    {
        printf(
            "%s ended in %s milliseconds\n",
            $test,
            $time * 1000
        );

        if($time > self::MAX_SECONDS_ALLOWED) {
            fwrite(STDERR, sprintf("\nThe %s test is slow, it took %s seconds!\n", $test, $time));
        }
    }
}
