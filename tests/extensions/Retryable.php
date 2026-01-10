<?php

namespace Appwrite\Tests;

/**
 * Marker trait for classes that support retry functionality.
 * The actual retry logic is handled by the RetryExtension.
 *
 * Test methods can be annotated with #[Retry(count: N)] to enable retries.
 */
trait Retryable
{
    /**
     * Get the number of retries configured for the current test method.
     *
     * @return int
     * @throws \ReflectionException
     */
    public function getNumberOfRetries(): int
    {
        $root = new \ReflectionClass($this);
        $name = $this->name();

        if (!$root->hasMethod($name)) {
            return 0;
        }

        $method = $root->getMethod($name);
        $attributes = $method->getAttributes(Retry::class);
        $attribute = $attributes[0] ?? null;
        $args = $attribute?->getArguments();
        $retries = $args['count'] ?? $args[0] ?? 0;
        return \max(0, $retries);
    }
}
