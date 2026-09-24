<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Memcached as Memcached;
use MemcachedException;
use Utopia\Cache\Adapter\Hazelcast as HazelcastAdapter;
use Utopia\Cache\Cache;
use Utopia\Tests\Base;
use Utopia\Tests\Scope\EmptyObjectFidelity;
use Utopia\Tests\Services;

final class HazelcastTest extends Base
{
    use EmptyObjectFidelity;

    public static function setUpBeforeClass(): void
    {
        $memcached = new Memcached();
        $memcached->addServer(Services::HOST, Services::HAZELCAST_PORT);
        self::$cache = new Cache(new HazelcastAdapter($memcached));
    }

    public function testGetSize(): void
    {
        $this->assertSame(0, self::$cache->getSize());
    }

    public function testCacheReconnect(): void
    {
        $memcached = new Memcached();
        $memcached->addServer(Services::HOST, Services::HAZELCAST_PORT);
        self::$cache = new Cache(new HazelcastAdapter($memcached)->setMaxRetries(3));
        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        Services::compose('stop', 'hazelcast');
        sleep(3);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Hazelcast connection should have failed');
        } catch (MemcachedException) {
        }

        Services::compose('up', '-d', '--wait', 'hazelcast');
        // Reconnecting is what the adapter is on the hook for; serving the
        // first write afterwards is Hazelcast's own start-up.
        Services::waitUntil(fn(): bool => self::$cache->save('test:ready', 'ready') !== false);

        $this->assertSame('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }

    #[\Override]
    public function testFlush(): void
    {
        //not implemented as Hazelcast doesn't support flush functionality
        $result = self::$cache->flush();

        $this->assertEquals(false, $result);
    }
}
