<?php

namespace Utopia\Abuse\Tests\E2E\TokenBucket;

use Utopia\Abuse\Adapter\TokenBucket;
use Utopia\Abuse\Adapter\TokenBucket\Redis as AdapterRedis;
use Utopia\Abuse\Tests\E2E\Services;

class RedisTest extends Base
{
    protected static \Redis $redis;

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

    private static function initialiseRedis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect(Services::HOST, Services::REDIS_PORT);

        return $redis;
    }

    #[\Override]
    public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket
    {
        return new AdapterRedis($key, $tokens, $refillRate, self::$redis);
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$redis)) {
            self::$redis->close();
        }
    }
}
