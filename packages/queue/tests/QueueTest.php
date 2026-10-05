<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Queue;

final class QueueTest extends TestCase
{
    public function testKeyTtlDefaultsToOneDay(): void
    {
        $this->assertSame(86_400, new Queue('stats')->keyTtl);
    }

    public function testKeyTtlFollowsJobTtl(): void
    {
        $queue = new Queue('stats', 'namespace', 60, 30);

        $this->assertSame(60, $queue->jobTtl);
        $this->assertSame(30, $queue->keyTtl);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidKeyTtls(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidKeyTtls')]
    public function testKeyTtlBelowOneSecondIsRefused(int $keyTtl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create queue with a key TTL below one second.');

        new Queue('stats', keyTtl: $keyTtl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyNames(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
    }

    #[DataProvider('emptyNames')]
    public function testEmptyNameIsRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot create queue with empty name.');

        new Queue($name);
    }
}
