<?php

namespace Tests\Unit\Redis;

use Appwrite\Redis\RedisConnection;
use PHPUnit\Framework\TestCase;

class RedisConnectionTest extends TestCase
{
    public function testCreateSucceedsOnFirstAttempt(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('pconnect')
            ->with('localhost', 6379)
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('setOption')
            ->with(\Redis::OPT_READ_TIMEOUT, -1);
        $redis->expects($this->never())
            ->method('close');

        $result = RedisConnection::create($redis, 'localhost', 6379);
        $this->assertSame($redis, $result);
    }

    public function testCreateWithPassword(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('pconnect')
            ->willReturn(true);
        $redis->expects($this->once())
            ->method('auth')
            ->with('secret');
        $redis->expects($this->once())
            ->method('setOption')
            ->with(\Redis::OPT_READ_TIMEOUT, -1);

        $result = RedisConnection::create($redis, 'localhost', 6379, 'secret');
        $this->assertSame($redis, $result);
    }

    public function testCreateRetriesOnRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->exactly(2))
            ->method('pconnect')
            ->with('localhost', 6379)
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RedisException('read error on connection to cache:6379')),
                true
            );
        $redis->expects($this->once())
            ->method('close');
        $redis->expects($this->once())
            ->method('setOption')
            ->with(\Redis::OPT_READ_TIMEOUT, -1);

        $result = RedisConnection::create($redis, 'localhost', 6379);
        $this->assertSame($redis, $result);
    }

    public function testCreateThrowsAfterRetryFails(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->exactly(2))
            ->method('pconnect')
            ->willThrowException(new \RedisException('read error on connection to cache:6379'));
        $redis->expects($this->once())
            ->method('close');

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('read error on connection to cache:6379');

        RedisConnection::create($redis, 'localhost', 6379);
    }

    public function testCreateWithPasswordRetriesOnRedisException(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->exactly(2))
            ->method('pconnect')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RedisException('read error on connection to cache:6379')),
                true
            );
        $redis->expects($this->once())
            ->method('close');
        $redis->expects($this->once())
            ->method('auth')
            ->with('secret');
        $redis->expects($this->once())
            ->method('setOption')
            ->with(\Redis::OPT_READ_TIMEOUT, -1);

        $result = RedisConnection::create($redis, 'localhost', 6379, 'secret');
        $this->assertSame($redis, $result);
    }
}
