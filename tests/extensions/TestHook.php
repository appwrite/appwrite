<?php
namespace Appwrite\Tests;

use PHPUnit\Runner\AfterTestHook;

class TestHook implements AfterTestHook
{
    public function executeAfterTest(string $test, float $time): void
    {
        printf("\n%s ended in %s seconds", 
           $test,
           $time
        );
    }
}