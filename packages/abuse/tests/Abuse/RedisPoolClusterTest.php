<?php

namespace Utopia\Tests;

use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Abuse\Adapters\TimeLimit\RedisPool as AdapterRedisPool;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

class RedisPoolClusterTest extends Base
{
    /**
     * @var Pool<\RedisCluster>|null
     */
    protected static ?Pool $pool = null;

    public static function setUpBeforeClass(): void
    {
        if (isset(self::$pool)) {
            return;
        }

        self::$pool = new Pool(new Stack(), 'abuse-redis-cluster', 2, function (): \RedisCluster {
            return new \RedisCluster(null, [
                'redis-cluster-0:6379',
                'redis-cluster-1:6379',
                'redis-cluster-2:6379',
                'redis-cluster-3:6379',
            ]);
        }, timeout: 0.0);
    }

    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\RedisCluster> $pool */
        return new AdapterRedisPool('redis-cluster-pool-' . $key, $limit, $seconds, $pool);
    }

    public function testGetLogsSupportsNullableLimit(): void
    {
        $adapter = $this->getAdapter('logs-null-limit', 1, 60);
        $abuse = new \Utopia\Abuse\Abuse($adapter);

        $this->assertSame(false, $abuse->check());
        $this->assertNotEmpty($adapter->getLogs(null, null));
    }

    public function testGetLogsAppliesOffset(): void
    {
        $this->clearRedisClusterPoolLogs();
        $adapter = $this->getAdapter('logs-offset', 1, 60);

        $this->setRedisClusterPoolLog('a', '1');
        $this->setRedisClusterPoolLog('b', '2');
        $this->setRedisClusterPoolLog('c', '3');

        $logs = $adapter->getLogs(1, 1);

        $this->assertSame(['abuse__redis-cluster-pool-logs-offset-b__1' => '2'], $logs);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pool)) {
            return;
        }

        self::$pool->use(function (mixed $redis): void {
            if ($redis instanceof \RedisCluster) {
                $redis->close();
            }
        });
        self::$pool = null;
    }

    private function clearRedisClusterPoolLogs(): void
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        $pool->use(function (\RedisCluster $redis): void {
            foreach ($redis->_masters() as $master) {
                $cursor = null;
                do {
                    /** @phpstan-ignore-next-line */
                    $keys = $redis->scan($cursor, $master, 'abuse__*', 100);
                    /** @phpstan-ignore-next-line */
                    if ($keys === false) {
                        continue;
                    }

                    foreach ($keys as $key) {
                        $redis->del($key);
                    }
                } while ($cursor > 0);
            }
        });
    }

    private function setRedisClusterPoolLog(string $key, string $value): void
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        $pool->use(function (\RedisCluster $redis) use ($key, $value): void {
            $redis->set('abuse__redis-cluster-pool-logs-offset-' . $key . '__1', $value);
        });
    }
}
