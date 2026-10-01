<?php

namespace Utopia\Abuse\Tests\E2E;

use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TimeLimit\Redis as AdapterRedis;

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
    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        return new AdapterRedis($key, $limit, $seconds, self::$redis);
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
