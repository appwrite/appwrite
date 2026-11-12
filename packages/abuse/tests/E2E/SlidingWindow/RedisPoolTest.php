<?php

namespace Utopia\Abuse\Tests\E2E\SlidingWindow;

use Utopia\Abuse\Adapter\SlidingWindow;
use Utopia\Abuse\Adapter\SlidingWindow\RedisPool as AdapterRedisPool;
use Utopia\Abuse\Tests\E2E\Services;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

class RedisPoolTest extends Base
{
    /**
     * @var Pool<\Redis>|null
     */
    protected static ?Pool $pool = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if (isset(self::$pool)) {
            return;
        }

        self::$pool = new Pool(new Stack(), 'abuse-sw-redis', 2, function (): \Redis {
            $redis = new \Redis();
            $redis->connect(Services::HOST, Services::REDIS_PORT);

            return $redis;
        }, timeout: 0.0);
    }

    #[\Override]
    public function getAdapter(string $key, int $limit, int $windowSize, int $ttl): SlidingWindow
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\Redis> $pool */
        return new AdapterRedisPool('sw-pool-' . $key, $limit, $windowSize, $ttl, $pool);
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pool)) {
            return;
        }

        self::$pool->use(function (\Redis $redis): void {
            $redis->close();
        });
        self::$pool = null;
    }
}
