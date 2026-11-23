<?php

declare(strict_types=1);

namespace Tests\Unit\Extend;

use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string}>
     */
    public static function unpublished(): \Iterator
    {
        yield 'runner unavailable' => [Exception::DISTRIBUTION_RUNNER_UNAVAILABLE];
        yield 'store unavailable' => [Exception::DISTRIBUTION_STORE_UNAVAILABLE];
    }

    #[DataProvider('unpublished')]
    public function testExpectedOutageIsNotPublished(string $type): void
    {
        $exception = new Exception($type);

        $this->assertGreaterThanOrEqual(500, $exception->getCode());
        $this->assertFalse($exception->isPublishable());
    }
}
