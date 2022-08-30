<?php

namespace Appwrite\Tests;

use PHPUnit\Runner\AfterTestHook;

class TestHook implements AfterTestHook
{
    public function executeAfterTest(string $test, float $time): void
    {
        printf(
            "%s ended in %s milliseconds\n",
            $test,
            $time * 1000
        );
    }
}
