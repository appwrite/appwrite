<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Redis as Redis;
use Throwable;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;
use Utopia\Tests\Scope\EmptyObjectFidelity;
use Utopia\Tests\Services;

final class ShardingTest extends Base
{
    use EmptyObjectFidelity;

    public static function setUpBeforeClass(): void
    {
        $shards = [];

        foreach (Services::SHARD_PORTS as $port) {
            $redis = new Redis();
            $redis->connect(Services::HOST, $port);
            $shards[] = new RedisAdapter($redis);
        }

        self::$cache = new Cache(new Sharding($shards));
    }

    public function testGetSize(): void
    {
        self::$cache->save('test:file33', 'file33', 'test:file33');
        self::$cache->save('test:file34', 'file34', 'test:file33');
        $this->assertSame(2, self::$cache->getSize());
    }

    public function testEmptyAdapters(): void
    {
        $this->expectException(Throwable::class);

        self::$cache = new Cache(new Sharding([]));
    }
}
