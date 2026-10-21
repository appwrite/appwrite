<?php

namespace Utopia\Abuse\Tests\E2E\TokenBucket;

use Utopia\Abuse\Adapter\TokenBucket;
use Utopia\Abuse\Adapter\TokenBucket\RedisPool as AdapterRedisPool;
use Utopia\Abuse\Tests\E2E\Services;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

class RedisPoolTest extends Base
{
    /**
     * @var Pool<\Redis>|null
     */
    protected static ?Pool $pool = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if (isset(self::$pool)) {
            return;
        }

        self::$pool = new Pool(new Stack(), 'abuse-tb-redis', 2, function (): \Redis {
            $redis = new \Redis();
            $redis->connect(Services::HOST, Services::REDIS_PORT);

            return $redis;
        }, timeout: 0.0);
    }

    #[\Override]
    public function getAdapter(string $key, int $tokens, float $refillRate): TokenBucket
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\Redis> $pool */
        return new AdapterRedisPool('tb-pool-' . $key, $tokens, $refillRate, $pool);
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pool)) {
            return;
        }

        self::$pool->use(function (\Redis $redis): void {
            $redis->close();
        });
        self::$pool = null;
    }
}
