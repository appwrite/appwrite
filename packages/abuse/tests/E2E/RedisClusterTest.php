<?php

namespace Utopia\Abuse\Tests\E2E;

use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TimeLimit\RedisCluster as AdapterRedisCluster;

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
    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new AdapterRedisCluster($key, $limit, $seconds, self::$redis);
    }

    /**
     * Clean up Redis connection after all tests
     */
    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
