<?php

namespace Utopia\Abuse\Tests\E2E\TokenBucket;

use Utopia\Abuse\Adapter\TokenBucket;
use Utopia\Abuse\Adapter\TokenBucket\RedisCluster as AdapterRedisCluster;
use Utopia\Abuse\Tests\E2E\Services;

class RedisClusterTest extends Base
{
    protected static \RedisCluster $redis;

    /**
     * @throws \Exception
     */
    #[\Override]
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

    #[\Override]
    public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket
    {
        return new AdapterRedisCluster($key, $tokens, $refillRate, self::$redis);
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
