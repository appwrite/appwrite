<?php

namespace Utopia\Tests\TokenBucket;

use Utopia\Abuse\Adapters\TokenBucket;
use Utopia\Abuse\Adapters\TokenBucket\RedisPool as AdapterRedisPool;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

class RedisPoolTest extends Base
{
    /**
     * @var Pool<\Redis>|null
     */
    protected static ?Pool $pool = null;

    public static function setUpBeforeClass(): void
    {
        if (isset(self::$pool)) {
            return;
        }

        self::$pool = new Pool(new Stack(), 'abuse-tb-redis', 2, function (): \Redis {
            $redis = new \Redis();
            $redis->connect('redis', 6379);

            return $redis;
        }, timeout: 0.0);
    }

    public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\Redis> $pool */
        return new AdapterRedisPool('tb-pool-' . $key, $tokens, $refillRate, $pool);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pool)) {
            return;
        }

        self::$pool->use(function (mixed $redis): void {
            if ($redis instanceof \Redis) {
                $redis->close();
            }
        });
        self::$pool = null;
    }
}
