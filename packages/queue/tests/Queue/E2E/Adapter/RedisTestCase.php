<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Connection\Redis;

/** Isolated real Redis storage for broker behavior tests. */
abstract class RedisTestCase extends TestCase
{
    protected string $namespace;
    protected Redis $connection;
    protected \Redis $redis;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 16379);
        $this->namespace = '{test-' . bin2hex(random_bytes(8)) . '}';
        $this->redis = new \Redis();
        $this->redis->connect($host, $port);
        $this->connection = new Redis($host, $port);
    }

    protected function tearDown(): void
    {
        $keys = $this->redis->keys($this->namespace . '*');
        if ($keys !== []) {
            $this->redis->del($keys);
        }
        $this->connection->close();
        $this->redis->close();
    }

    /** Simulate expiry through Redis itself, without waiting for production TTLs. */
    protected function expire(string $pattern): void
    {
        foreach ($this->redis->keys($this->namespace . $pattern) as $key) {
            $this->redis->pExpireAt($key, 1);
        }
    }
}
