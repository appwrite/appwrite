<?php

namespace Appwrite\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Allows test methods annotated with {@see Retry} to be retried.
 */
trait Retryable
{
    /**
     * Custom runBare, hides and defers to PHPUnit {@see TestCase} runBare function,
     * accounting for any retries configured by the {@see Retry} annotation.
     *
     * @return void
     * @throws \ReflectionException
     * @throws \Throwable
     */
    public function runBare(): void
    {
        $retries = $this->getNumberOfRetries();
        $ex = null;
        for ($i = 0; $i <= $retries; ++$i) {
            try {
                parent::runBare();
                return;
            } catch (\Throwable | \Exception $ex) {
                // Swallow the exception until we have exhausted our retries.
                if ($i !== $retries) {
                    echo 'Flaky test failed, retrying...' . PHP_EOL;
                }
            }
        }
        if ($ex) {
            throw $ex;
        }
    }

    /**
     * @return int
     * @throws \ReflectionException
     */
    private function getNumberOfRetries(): int
    {
        $root = new \ReflectionClass($this);
        $case = $this->getTestCaseRoot($root);
        $name = $case->getProperty('name');
        $name->setAccessible(true);
        $name = $name->getValue($this);
        $method = $root->getMethod($name);
        $attributes = $method->getAttributes(Retry::class);
        $attribute = $attributes[0] ?? null;
        $args = $attribute?->getArguments();
        $retries = $args['count'] ?? 0;
        return \max(0, $retries);
    }

    /**
     * @param \ReflectionClass $reflection
     * @return \ReflectionClass
     */
    private function getTestCaseRoot(\ReflectionClass $reflection): \ReflectionClass
    {
        if ($reflection->getName() === TestCase::class) {
            return $reflection;
        }
        return $this->getTestCaseRoot($reflection->getParentClass());
    }
}
