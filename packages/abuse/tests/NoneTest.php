<?php

namespace Utopia\Abuse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Adapter;
use Utopia\Abuse\Adapter\SlidingWindow;
use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TokenBucket;

final class NoneTest extends TestCase
{
    /**
     * @return array<string, array{Adapter}>
     */
    public static function adapters(): array
    {
        return [
            'time limit' => [new TimeLimit\None('none-key', 1, 60)],
            'sliding window' => [new SlidingWindow\None('none-key', 1, 60)],
            'token bucket' => [new TokenBucket\None('none-key', 1, 1.0)],
        ];
    }

    #[DataProvider('adapters')]
    public function testNeverLimitsRequests(Adapter $adapter): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($adapter->check()->limited);
        }

        $this->assertFalse($adapter->peek()->limited);
    }

    #[DataProvider('adapters')]
    public function testReturnsNoLogsAndCleanupSucceeds(Adapter $adapter): void
    {
        $this->assertSame([], $adapter->getLogs());
        $this->assertTrue($adapter->cleanup(\time()));
    }

    #[DataProvider('adapters')]
    public function testResetIsNoop(Adapter $adapter): void
    {
        $adapter->reset();

        $this->assertFalse($adapter->check()->limited);
    }
}
