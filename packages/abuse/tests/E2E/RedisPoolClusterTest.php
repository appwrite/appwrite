<?php

namespace Utopia\Abuse\Tests\E2E;

use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TimeLimit\RedisPool as AdapterRedisPool;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

class RedisPoolClusterTest extends Base
{
    /**
     * @var Pool<\RedisCluster>|null
     */
    protected static ?Pool $pool = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if (isset(self::$pool)) {
            return;
        }

        self::$pool = new Pool(new Stack(), 'abuse-redis-cluster', 2, fn (): \RedisCluster => new \RedisCluster(null, Services::CLUSTER_SEEDS), timeout: 0.0);
    }

    #[\Override]
    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\RedisCluster> $pool */
        return new AdapterRedisPool('redis-cluster-pool-' . $key, $limit, $seconds, $pool);
    }

    public function testGetLogsSupportsNullableLimit(): void
    {
        $adapter = $this->getAdapter('logs-null-limit-' . \uniqid(), 1, 60);

        $this->assertFalse($adapter->check()->limited);
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

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$pool)) {
            return;
        }

        self::$pool->use(function (\RedisCluster $redis): void {
            $redis->close();
        });
        self::$pool = null;
    }

    private function clearRedisClusterPoolLogs(): void
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        $pool->use(function (\RedisCluster $redis): void {
            foreach ($redis->_masters() as $master) {
                if (!\is_array($master)) {
                    continue;
                }

                $cursor = null;
                do {
                    $keys = $redis->scan($cursor, $master, 'abuse__*', 100);
                    if (!\is_array($keys)) {
                        continue;
                    }

                    foreach ($keys as $key) {
                        if (\is_string($key)) {
                            $redis->del($key);
                        }
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
