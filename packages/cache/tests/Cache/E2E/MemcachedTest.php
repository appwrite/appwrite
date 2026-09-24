<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Memcached as Memcached;
use MemcachedException;
use Utopia\Cache\Adapter\Memcached as MemcachedAdapter;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;
use Utopia\Tests\Services;

final class MemcachedTest extends Base
{
    public static function setUpBeforeClass(): void
    {
        $mc = new Memcached();
        $mc->addServer(Services::HOST, Services::MEMCACHED_PORT);

        self::$cache = new Cache(new MemcachedAdapter($mc));
    }

    public function testGetSize(): void
    {
        // Memcached expires flushed items lazily, so they can still be counted
        // — measure the delta rather than the absolute item count.
        $before = self::$cache->getSize();
        self::$cache->save('test:file33', 'file33');
        $this->assertSame($before + 1, self::$cache->getSize());
    }

    public function testCacheReconnect(): void
    {
        $mc = new Memcached();
        $mc->addServer(Services::HOST, Services::MEMCACHED_PORT);
        self::$cache = new Cache(new MemcachedAdapter($mc)->setMaxRetries(3));
        self::$cache->save('test:reconnect', 'reconnect');

        Services::compose('stop', 'memcached');
        sleep(3);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Memcached connection should have failed');
        } catch (MemcachedException) {
        }

        Services::compose('up', '-d', '--wait', 'memcached');

        $this->assertSame('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }
}
