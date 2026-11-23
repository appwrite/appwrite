<?php

namespace Utopia\Abuse\Tests\E2E\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce\RedisPool;
use Utopia\Abuse\Tests\E2E\Services;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

final class RedisPoolTest extends Base
{
    /**
     * @return Pool<\Redis>
     */
    private static function pool(int $port): Pool
    {
        return new Pool(new Stack(), 'abuse-nonce-' . $port, 1, function () use ($port): \Redis {
            $redis = new \Redis();
            $redis->connect(Services::HOST, $port, 0.5);

            return $redis;
        }, timeout: 0.0);
    }

    #[\Override]
    public function getNonce(string $key): RedisPool
    {
        return new RedisPool($key, self::pool(Services::REDIS_PORT));
    }

    public function testUnreachableRedisThrows(): void
    {
        // Nothing listens on port 1, so the pool cannot create a connection.
        $this->expectException(\Throwable::class);
        new RedisPool('nonce', self::pool(1))->consume('abc', 60);
    }
}
