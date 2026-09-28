<?php

namespace Utopia\Tests\SlidingWindow;

use Utopia\Abuse\Adapters\SlidingWindow;
use Utopia\Abuse\Adapters\SlidingWindow\RedisPool as AdapterRedisPool;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

class RedisPoolTest extends Base
{
    /**
     * @var Pool<\Redis>|null
     */
    protected static ?Pool $pool = null;

    public static function setUpBeforeClass(): void
    {
        if (isset(self::$pool)) {
            return;
        }

        self::$pool = new Pool(new Stack(), 'abuse-sw-redis', 2, function (): \Redis {
            $redis = new \Redis();
            $redis->connect('redis', 6379);

            return $redis;
        }, timeout: 0.0);
    }

    public function getAdapter(string $key, int $limit, int $windowSize, int $ttl): SlidingWindow
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\Redis> $pool */
        return new AdapterRedisPool('sw-pool-' . $key, $limit, $windowSize, $ttl, $pool);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pool)) {
            return;
        }

        self::$pool->use(function (mixed $redis): void {
            if ($redis instanceof \Redis) {
                $redis->close();
            }
        });
        self::$pool = null;
    }
}
