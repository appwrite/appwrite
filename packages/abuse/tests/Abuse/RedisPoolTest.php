<?php

namespace Utopia\Tests;

use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Abuse\Adapters\TimeLimit\RedisPool as AdapterRedisPool;
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

        self::$pool = new Pool(new Stack(), 'abuse-redis', 2, function (): \Redis {
            $redis = new \Redis();
            $redis->connect('redis', 6379);

            return $redis;
        }, timeout: 0.0);
    }

    public function getAdapter(string $key, int $limit, int $seconds): TimeLimit
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        /** @var Pool<\Redis> $pool */
        return new AdapterRedisPool('redis-pool-' . $key, $limit, $seconds, $pool);
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
        $this->clearRedisPoolLogs();
        $adapter = $this->getAdapter('logs-offset', 1, 60);

        $this->setRedisPoolLog('a', '1');
        $this->setRedisPoolLog('b', '2');
        $this->setRedisPoolLog('c', '3');

        $logs = $adapter->getLogs(1, 1);

        $this->assertSame(['abuse__redis-pool-logs-offset-b__1' => '2'], $logs);
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

    private function clearRedisPoolLogs(): void
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        $pool->use(function (\Redis $redis): void {
            $cursor = null;
            do {
                $keys = $redis->scan($cursor, 'abuse__*', 100);
                if ($keys === false) {
                    continue;
                }

                foreach ($keys as $key) {
                    $redis->del($key);
                }
            } while ($cursor > 0);
        });
    }

    private function setRedisPoolLog(string $key, string $value): void
    {
        $pool = self::$pool;
        $this->assertInstanceOf(Pool::class, $pool);

        $pool->use(function (\Redis $redis) use ($key, $value): void {
            $redis->set('abuse__redis-pool-logs-offset-' . $key . '__1', $value);
        });
    }
}
