<?php

namespace Utopia\Abuse\Tests\E2E\SlidingWindow;

use Utopia\Abuse\Adapters\SlidingWindow;
use Utopia\Abuse\Adapters\SlidingWindow\RedisCluster as AdapterRedisCluster;
use Utopia\Abuse\Tests\E2E\Services;

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
        return new \RedisCluster(null, Services::CLUSTER_SEEDS);
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
