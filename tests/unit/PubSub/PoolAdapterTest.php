<?php

namespace Tests\Unit\PubSub;

use Appwrite\PubSub\Adapter;
use Appwrite\PubSub\Adapter\Pool;
use PHPUnit\Framework\TestCase;
use RedisException;
use Utopia\Pools\Pool as UtopiaPool;

class PoolAdapterTest extends TestCase
{
    public function testSubscribePropagatesRedisException(): void
    {
        $mockAdapter = $this->createMock(Adapter::class);
        $mockAdapter->method('subscribe')
            ->willThrowException(new RedisException('read error on connection to pubsub-dragonfly:6379'));

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($mockAdapter) {
                return $callback($mockAdapter);
            });

        $poolAdapter = new Pool($pool);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('read error on connection to pubsub-dragonfly:6379');

        $poolAdapter->subscribe(['realtime'], function () {});
    }

    public function testPingDelegatesToPool(): void
    {
        $mockAdapter = $this->createMock(Adapter::class);
        $mockAdapter->method('ping')
            ->with(true)
            ->willReturn(true);

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($mockAdapter) {
                return $callback($mockAdapter);
            });

        $poolAdapter = new Pool($pool);

        $this->assertTrue($poolAdapter->ping(true));
    }

    public function testRedisExceptionIsInstanceOfThrowable(): void
    {
        $exception = new RedisException('read error on connection to pubsub-dragonfly:6379');

        $this->assertInstanceOf(\Throwable::class, $exception);
        $this->assertInstanceOf(RedisException::class, $exception);
        // Verify RedisException is NOT an instance of Appwrite\Extend\Exception
        // This is important because the error handler in realtime.php filters by exception type
        $this->assertNotInstanceOf(\Appwrite\Extend\Exception::class, $exception);
    }
}
