<?php

namespace Utopia\Tests;

use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Abuse\Adapters\TimeLimit\RedisCluster as AdapterRedisCluster;

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
            'redis-cluster-3:6379'
        ]);
    }

    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new AdapterRedisCluster($key, $limit, $seconds, self::$redis);
    }

    /**
     * Clean up Redis connection after all tests
     */
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
