<?php

namespace Tests\Unit\PubSub;

use Appwrite\PubSub\Adapter;
use Appwrite\PubSub\Adapter\Pool;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Pool as UtopiaPool;

final class PoolTest extends TestCase
{
    public function testSubscribeThrowsAfterMaxRetries(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('subscribe')
            ->willThrowException(new \RedisException('SUBSCRIBE returned false'));

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($adapter) {
                return $callback($adapter);
            });

        $pubSubPool = new Pool($pool);

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('SUBSCRIBE returned false');

        $pubSubPool->subscribe(['realtime'], function () {});
    }

    public function testSubscribeSucceedsAfterTransientFailure(): void
    {
        $callCount = 0;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('subscribe')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount <= 2) {
                    throw new \RedisException('SUBSCRIBE returned false');
                }
                return null;
            });

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($adapter) {
                return $callback($adapter);
            });

        $pubSubPool = new Pool($pool);

        // Should succeed after 2 transient failures
        $pubSubPool->subscribe(['realtime'], function () {});

        $this->assertEquals(3, $callCount);
    }

    public function testSubscribeDoesNotRetryOnSuccess(): void
    {
        $callCount = 0;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('subscribe')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return null;
            });

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($adapter) {
                return $callback($adapter);
            });

        $pubSubPool = new Pool($pool);
        $pubSubPool->subscribe(['realtime'], function () {});

        $this->assertEquals(1, $callCount);
    }

    public function testSubscribeRetriesUpToMaxAttempts(): void
    {
        $callCount = 0;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('subscribe')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \RedisException('SUBSCRIBE returned false');
            });

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($adapter) {
                return $callback($adapter);
            });

        $pubSubPool = new Pool($pool);

        try {
            $pubSubPool->subscribe(['realtime'], function () {});
            $this->fail('Expected RedisException');
        } catch (\RedisException) {
            // Expected - should have retried 5 times (1 initial + 4 retries)
            $this->assertEquals(5, $callCount);
        }
    }

    public function testSubscribeDoesNotRetryNonRedisExceptions(): void
    {
        $callCount = 0;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('subscribe')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \RuntimeException('Some other error');
            });

        $pool = $this->createMock(UtopiaPool::class);
        $pool->method('use')
            ->willReturnCallback(function (callable $callback) use ($adapter) {
                return $callback($adapter);
            });

        $pubSubPool = new Pool($pool);

        $this->expectException(\RuntimeException::class);
        $pubSubPool->subscribe(['realtime'], function () {});

        // Should not retry for non-Redis exceptions
        $this->assertEquals(1, $callCount);
    }
}
