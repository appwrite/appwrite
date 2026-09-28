<?php

namespace Utopia\Tests\SlidingWindow;

use Utopia\Abuse\Adapters\SlidingWindow;
use Utopia\Abuse\Adapters\SlidingWindow\RedisCluster as AdapterRedisCluster;

class RedisClusterTest extends Base
{
    protected static \RedisCluster $redis;

    /**
     * @throws \Exception
     */
    public static function setUpBeforeClass(): void
    {
        if (isset(self::$redis)) {
            return;
        }

        self::$redis = self::initialiseRedis();
    }

    private static function initialiseRedis(): \RedisCluster
    {
        return new \RedisCluster(null, [
            'redis-cluster-0:6379',
            'redis-cluster-1:6379',
            'redis-cluster-2:6379',
            'redis-cluster-3:6379',
        ]);
    }

    public function getAdapter(string $key, int $limit, int $windowSize, int $ttl): SlidingWindow
    {
        return new AdapterRedisCluster($key, $limit, $windowSize, $ttl, self::$redis);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
