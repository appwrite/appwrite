<?php

namespace Appwrite\Tests;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use ReflectionClass;

class RetrySubscriber implements FailedSubscriber
{
    /**
     * Track retry counts for each test to avoid infinite loops
     *
     * @var array<string, int>
     */
    private static array $retryCounts = [];

    /**
     * Track tests that should be retried
     *
     * @var array<string, array{test: TestMethod, remainingRetries: int, lastError: \Throwable|null}>
     */
    private static array $pendingRetries = [];

    public function notify(Failed $event): void
    {
        $this->handleTestFailure($event->test(), $event->throwable()->asString());
    }

    private function handleTestFailure($test, string $errorMessage): void
    {
        if (!$test instanceof TestMethod) {
            return;
        }

        $testId = $test->className() . '::' . $test->methodName();
        $retryCount = $this->getRetryCountForTest($test);

        if ($retryCount <= 0) {
            return;
        }

        $currentAttempt = self::$retryCounts[$testId] ?? 0;

        if ($currentAttempt < $retryCount) {
            self::$retryCounts[$testId] = $currentAttempt + 1;
            $remainingRetries = $retryCount - self::$retryCounts[$testId];

            fwrite(
                STDOUT,
                sprintf(
                    "\e[33m[RETRY] Test %s failed (attempt %d/%d). %s\e[0m\n",
                    $testId,
                    $currentAttempt + 1,
                    $retryCount + 1,
                    $remainingRetries > 0 ? "Will retry {$remainingRetries} more time(s)." : "No more retries."
                )
            );
        }
    }

    private function getRetryCountForTest(TestMethod $test): int
    {
        try {
            $className = $test->className();
            $methodName = $test->methodName();

            if (!class_exists($className)) {
                return 0;
            }

            $reflection = new ReflectionClass($className);

            if (!$reflection->hasMethod($methodName)) {
                return 0;
            }

            $method = $reflection->getMethod($methodName);
            $attributes = $method->getAttributes(Retry::class);

            if (empty($attributes)) {
                return 0;
            }

            $attribute = $attributes[0];
            $args = $attribute->getArguments();

            return max(0, $args['count'] ?? $args[0] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Reset retry counts between test runs (useful for testing)
     */
    public static function reset(): void
    {
        self::$retryCounts = [];
        self::$pendingRetries = [];
    }
}
