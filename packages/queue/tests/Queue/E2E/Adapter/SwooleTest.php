<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

final class SwooleTest extends Base
{
    private function getConnection(): Redis
    {
        return new Redis('127.0.0.1', 16379);
    }

    protected function getPublisher(): Synchronous
    {
        return new RedisBroker($this->getConnection(), $this->getConnection());
    }

    protected function getQueue(): Queue
    {
        return new Queue('swoole');
    }


}
