<?php

namespace Utopia\Tests\TokenBucket;

use Utopia\Abuse\Adapters\TokenBucket;
use Utopia\Abuse\Adapters\TokenBucket\RedisCluster as AdapterRedisCluster;

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

    public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket
    {
        return new AdapterRedisCluster($key, $tokens, $refillRate, self::$redis);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
