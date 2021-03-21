<?php
namespace Appwrite\Tests;

use PHPUnit\Runner\AfterTestHook;

class TestHook implements AfterTestHook
{
    public function executeAfterTest(string $test, float $time): void
    {
        printf(" %s ended in %s seconds\n", 
           $test,
           $time
        );
    }
}