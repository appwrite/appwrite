<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Broker\Redis as RedisPublisher;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

final class WorkermanTest extends Base
{
    protected function getPublisher(): Synchronous
    {
        return new RedisPublisher(new Redis('127.0.0.1', 16379), new Redis('127.0.0.1', 16379));
    }

    protected function getQueue(): Queue
    {
        return new Queue('workerman');
    }
}
