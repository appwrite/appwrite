<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests\E2E;

use PHPUnit\Framework\Attributes\Depends;
use Redis;
use RedisCluster as RedisCluster;
use RedisClusterException;
use Utopia\Cache\Adapter\RedisCluster as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Cache\Tests\Base;
use Utopia\Cache\Tests\Scope\EmptyObjectFidelity;
use Utopia\Cache\Tests\Services;

final class RedisClusterTest extends Base
{
    use EmptyObjectFidelity;

    private const float TIMEOUT = 1.5;

    protected static RedisCluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = new RedisCluster(null, Services::CLUSTER_SEEDS, self::TIMEOUT, self::TIMEOUT);
        self::$cache = new Cache(new RedisAdapter(self::$redis, Services::CLUSTER_SEEDS));
    }

    public function testGetSize(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::$cache->save("test:file$i", "file$i", "test:file$i");
        }

        $this->assertSame(20, self::$cache->getSize());
    }

    #[Depends('testGetSize')]
    public function testCacheReconnect(): void
    {
        self::$redis = new RedisCluster(null, Services::CLUSTER_SEEDS, self::TIMEOUT, self::TIMEOUT);
        self::$cache = new Cache(new RedisAdapter(self::$redis, Services::CLUSTER_SEEDS)->setMaxRetries(3));

        self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect');

        // The container must be recreated: grokzen/redis-cluster does not
        // persist cluster state across a stop and start.
        Services::compose('rm', '-sf', 'redis-cluster');
        sleep(1);

        try {
            self::$cache->load('test:reconnect', 5);
            $this->fail('Redis connection should have failed');
        } catch (RedisClusterException) {
        }

        Services::compose('up', '-d', '--force-recreate', '--wait', 'redis-cluster');
        $this->waitForClusterState();

        $this->assertSame('reconnect', self::$cache->save('test:reconnect', 'reconnect', 'test:reconnect'));
        $this->assertEquals('reconnect', self::$cache->load('test:reconnect', 5));
    }

    public function testAPurgeTheServerRejectsThrowsInsteadOfReportingNothingToPurge(): void
    {
        self::$redis->set('test:plain', 'value');

        try {
            self::$cache->purge('test:plain', 'field');
            $this->fail('A purge Redis rejected must not read as a purge of a missing key');
        } catch (RedisClusterException $error) {
            $this->assertStringContainsString('WRONGTYPE', $error->getMessage());
        } finally {
            self::$redis->del('test:plain');
        }

        $this->assertFalse(self::$cache->purge('test:missing'));
    }

    /**
     * A healthy container can still answer CLUSTERDOWN until every node has
     * its slots assigned, so later tests would see the cluster half formed.
     */
    private function waitForClusterState(): void
    {
        Services::waitUntil(static function (): bool {
            foreach (Services::CLUSTER_SEEDS as $seed) {
                [$host, $port] = explode(':', $seed);
                $node = new Redis();
                $node->connect($host, (int) $port, self::TIMEOUT);
                $info = $node->rawCommand('CLUSTER', 'INFO');
                $node->close();

                if (! \is_string($info) || ! str_contains($info, 'cluster_state:ok')) {
                    return false;
                }
            }

            return true;
        });
    }
}
