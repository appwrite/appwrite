<?php

namespace Tests\Unit\PubSub;

use Appwrite\PubSub\Adapter;
use Appwrite\PubSub\Adapter\Pool;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Pool as UtopiaPool;

class PoolTest extends TestCase
{
    public function testSubscribeRetriesOnRedisException(): void
    {
        $callCount = 0;
        $maxFailures = 2;

        $mockAdapter = $this->createMock(Adapter::class);
        $mockAdapter->method('subscribe')
            ->willReturnCallback(function () use (&$callCount, $maxFailures) {
                $callCount++;
                if ($callCount <= $maxFailures) {
                    throw new \RedisException('read error on connection to pubsub-dragonfly:6379');
                }
            });

        $mockPool = $this->createMock(UtopiaPool::class);
        $mockPool->method('use')
            ->willReturnCallback(function (callable $callback) use ($mockAdapter) {
                return $callback($mockAdapter);
            });

        $pool = new Pool($mockPool);
        $pool->subscribe(['realtime'], function () {});

        $this->assertEquals($maxFailures + 1, $callCount, 'Subscribe should have been called 3 times (2 failures + 1 success)');
    }

    public function testSubscribeThrowsAfterMaxRetries(): void
    {
        $mockAdapter = $this->createMock(Adapter::class);
        $mockAdapter->method('subscribe')
            ->willThrowException(new \RedisException('read error on connection to pubsub-dragonfly:6379'));

        $mockPool = $this->createMock(UtopiaPool::class);
        $mockPool->method('use')
            ->willReturnCallback(function (callable $callback) use ($mockAdapter) {
                return $callback($mockAdapter);
            });

        $pool = new Pool($mockPool);

        $this->expectException(\RedisException::class);
        $pool->subscribe(['realtime'], function () {});
    }

    public function testSubscribeDoesNotRetryOnOtherExceptions(): void
    {
        $callCount = 0;

        $mockAdapter = $this->createMock(Adapter::class);
        $mockAdapter->method('subscribe')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \RuntimeException('some other error');
            });

        $mockPool = $this->createMock(UtopiaPool::class);
        $mockPool->method('use')
            ->willReturnCallback(function (callable $callback) use ($mockAdapter) {
                return $callback($mockAdapter);
            });

        $pool = new Pool($mockPool);

        $this->expectException(\RuntimeException::class);
        $pool->subscribe(['realtime'], function () {});

        $this->assertEquals(1, $callCount, 'Subscribe should only have been called once for non-Redis exceptions');
    }
}
