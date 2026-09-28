<?php

namespace Utopia\Tests\SlidingWindow;

use Utopia\Abuse\Adapters\SlidingWindow;
use Utopia\Abuse\Adapters\SlidingWindow\Redis as AdapterRedis;

class RedisTest extends Base
{
    protected static \Redis $redis;

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

    private static function initialiseRedis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379);

        return $redis;
    }

    public function getAdapter(string $key, int $limit, int $windowSize, int $ttl): SlidingWindow
    {
        return new AdapterRedis($key, $limit, $windowSize, $ttl, self::$redis);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
