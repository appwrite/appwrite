<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\RedisCluster;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

final class SwooleRedisClusterTest extends Base
{
    private function getConnection(): RedisCluster
    {
        return new RedisCluster([
            '127.0.0.1:17000',
            '127.0.0.1:17001',
            '127.0.0.1:17002',
        ]);
    }

    protected function getPublisher(): Synchronous
    {
        return new Redis($this->getConnection(), $this->getConnection());
    }

    protected function getQueue(): Queue
    {
        return new Queue('swoole-redis-cluster', '{utopia-queue}');
    }


}
