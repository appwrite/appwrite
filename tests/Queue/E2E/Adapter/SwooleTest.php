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

    public function testPriorityJobIsConsumedBeforeNormalJobs(): void
    {
        $connection = $this->getConnection();

        // Priority ordering is purely a property of enqueue, so use a queue no
        // worker consumes — otherwise the live consumer drains these jobs before
        // we can observe their order.
        $queue = new Queue($this->getQueue()->name . '-priority', $this->getQueue()->namespace);
        $key = "{$queue->namespace}.queue.{$queue->name}";

        // Flush any leftover state from previous runs.
        while ($connection->rightPopArray($key, 1) !== false) {
            // drain
        }

        // Enqueue three normal jobs (pushed to head/left).
        $this->getPublisher()->publish($queue, ['order' => 'normal-1']);
        $this->getPublisher()->publish($queue, ['order' => 'normal-2']);
        $this->getPublisher()->publish($queue, ['order' => 'normal-3']);

        // Enqueue one priority job (pushed to tail/right — same end BRPOP reads from).
        $this->getPublisher()->publish($queue, ['order' => 'priority'], priority: true);

        // The first pop should yield the priority job.
        $first = $connection->rightPopArray($key, 1);
        $this->assertNotFalse($first, 'Expected a job but queue was empty');
        $this->assertSame('priority', $first['payload']['order'], 'Priority job should be consumed first');

        // The remaining three should be normal jobs (consumed oldest-first).
        $second = $connection->rightPopArray($key, 1);
        $this->assertNotFalse($second, 'Expected a job but queue was empty');
        $this->assertSame('normal-1', $second['payload']['order']);

        $third = $connection->rightPopArray($key, 1);
        $this->assertNotFalse($third, 'Expected a job but queue was empty');
        $this->assertSame('normal-2', $third['payload']['order']);

        $fourth = $connection->rightPopArray($key, 1);
        $this->assertNotFalse($fourth, 'Expected a job but queue was empty');
        $this->assertSame('normal-3', $fourth['payload']['order']);

        // Queue should now be empty.
        $this->assertFalse($connection->rightPopArray($key, 1));
    }
}
