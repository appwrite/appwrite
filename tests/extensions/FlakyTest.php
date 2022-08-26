<?php

namespace Appwrite\Tests;

/**
 * Allows test methods to be retried if they fail.
 *
 * Requires that the test class extends {@see TestCase} and has trait {@see Retryable}.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class FlakyTest
{
    public function __construct(protected int $retries = 1)
    {
    }
}
