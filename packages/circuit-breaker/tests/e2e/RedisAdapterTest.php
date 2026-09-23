<?php

declare(strict_types=1);

namespace Utopia\Tests\e2e;

use PHPUnit\Framework\TestCase;
use Utopia\CircuitBreaker\Adapter\Redis as RedisAdapter;
use Utopia\CircuitBreaker\CircuitBreaker;

final class RedisAdapterTest extends TestCase
{
    private ?object $redis = null;
    private string $prefix;

    protected function setUp(): void
    {
        if (!\extension_loaded('redis') || !class_exists('Redis')) {
            self::markTestSkipped('The redis extension is required for Redis E2E tests.');
        }

        $host = getenv('BREAKER_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BREAKER_REDIS_PORT') ?: 6379);
        $this->prefix = 'breaker-e2e:' . bin2hex(random_bytes(4)) . ':';
        $redisClass = 'Redis';
        $this->redis = new $redisClass();

        try {
            if (!$this->redis->connect($host, $port, 2.0)) {
                $error = method_exists($this->redis, 'getLastError') ? $this->redis->getLastError() : null;
                $message = \is_string($error) && $error !== '' ? ': ' . $error : '.';
                self::markTestSkipped(\sprintf('Redis E2E server is not reachable at %s:%d%s', $host, $port, $message));
            }
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis E2E server is not reachable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis === null || !$this->redis->isConnected()) {
            return;
        }

        foreach ($this->redis->keys($this->prefix . '*') ?: [] as $key) {
            $this->redis->del($key);
        }

        $this->redis->close();
        $this->redis = null;
    }

    public function testRedisAdapterStoresValuesInRedis(): void
    {
        $adapter = new RedisAdapter($this->redis, $this->prefix);
        $adapter->set('state', 'open');
        $adapter->set('count', 2);

        $this->assertSame('open', $adapter->get('state'));
        $this->assertSame('2', $adapter->get('count'));
        $this->assertSame(3, $adapter->increment('count'));
        $this->assertSame('3', $adapter->get('count'));
        $this->assertSame('3', $this->redis->get($this->prefix . 'count'));

        $adapter->delete('count');

        $this->assertNull($adapter->get('count'));
    }

    public function testCircuitBreakerSharesStateThroughRedis(): void
    {
        $cache = new RedisAdapter($this->redis, $this->prefix);
        $first = new CircuitBreaker(timeout: 0, successThreshold: 2, cache: $cache, key: 'users-api', minimumThroughput: 1);
        $second = new CircuitBreaker(timeout: 0, successThreshold: 2, cache: $cache, key: 'users-api', minimumThroughput: 1);

        $first->call(
            open: static fn(): string => 'fallback',
            close: static function (): never {
                throw new \RuntimeException('failed');
            },
        );

        $this->assertTrue($second->isHalfOpen());
        $this->assertSame(0, $second->getFailureCount());

        $this->assertSame('probe-1', $second->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-1',
        ));
        $this->assertSame(1, $first->getSuccessCount());

        $this->assertSame('probe-2', $first->call(
            open: static fn(): string => 'fallback',
            close: static fn(): string => 'closed',
            halfOpen: static fn(): string => 'probe-2',
        ));

        $this->assertTrue($second->isClosed());
        $this->assertSame('closed', $this->redis->get($this->prefix . 'users-api:state'));
    }
}
