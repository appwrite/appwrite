<?php

namespace Utopia\Abuse\Tests\E2E\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce\Redis;
use Utopia\Abuse\Tests\E2E\Services;

final class RedisTest extends Base
{
    private static ?\Redis $redis = null;

    #[\Override]
    public function getNonce(string $key): Redis
    {
        if (self::$redis === null) {
            self::$redis = new \Redis();
            self::$redis->connect(Services::HOST, Services::REDIS_PORT);
        }

        return new Redis($key, self::$redis);
    }

    public function testUnreachableRedisThrows(): void
    {
        $redis = new \Redis();
        $this->expectException(\RedisException::class);
        // Nothing listens on port 1.
        $redis->connect(Services::HOST, 1, 0.5);
        new Redis('nonce', $redis)->consume('abc', 60);
    }
}
