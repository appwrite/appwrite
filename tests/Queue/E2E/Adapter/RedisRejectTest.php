<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

/**
 * Where a rejected message is parked, which decides whether it runs again.
 *
 * The failed list is a retry queue in all but name: retry() pops it and
 * re-enqueues, so a payload that fails identically on every attempt circulates
 * until maxAttempts or newerThan finally parks it on the dead list. A handler
 * that already knows the answer should not have to wait for that circuit.
 */
final class RedisRejectTest extends RedisTestCase
{
    public function testAnOrdinaryFailureIsParkedForTheRetrySweep(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a']);
        $message = $broker->receive($queue, 0)[0];
        $broker->reject($queue, $message);

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame([], $broker->receive($queue, 0));
        $this->assertSame(0, $connection->listSize($this->namespace . '.dead.audits'));
    }

    public function testATerminalFailureSkipsTheRetrySweep(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a']);
        $message = $broker->receive($queue, 0)[0];
        $broker->reject($queue, $message->terminal());

        // The dead list is where an exhausted message ends up anyway; a terminal
        // one gets there on the first failure instead of after N of them.
        $this->assertSame(0, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame([], $broker->receive($queue, 0));
        $this->assertSame([$message->getPid()], $connection->listRange($this->namespace . '.dead.audits', 10, 0));
    }
}
