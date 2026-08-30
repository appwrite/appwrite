<?php

namespace Appwrite\Tests;

/**
 * Marker trait for classes that support retry functionality.
 * The actual retry logic is handled by the RetrySubscriber extension.
 *
 * Test methods can be annotated with #[Retry(count: N)] to enable retries.
 * When a test with this attribute fails, the RetrySubscriber logs the failure
 * and tracks retry attempts.
 */
trait Retryable
{
}
