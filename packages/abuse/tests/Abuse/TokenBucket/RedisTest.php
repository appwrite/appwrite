<?php

namespace Utopia\Tests\TokenBucket;

use Utopia\Abuse\Adapters\TokenBucket;
use Utopia\Abuse\Adapters\TokenBucket\Redis as AdapterRedis;

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

    public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket
    {
        return new AdapterRedis($key, $tokens, $refillRate, self::$redis);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
