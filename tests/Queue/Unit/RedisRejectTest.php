<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Where a rejected message is parked, which decides whether it runs again.
 *
 * The failed list is a retry queue in all but name: retry() pops it and
 * re-enqueues, so a payload that fails identically on every attempt circulates
 * until maxAttempts or newerThan finally parks it on the dead list. A handler
 * that already knows the answer should not have to wait for that circuit.
 */
final class RedisRejectTest extends TestCase
{
    private function message(): Message
    {
        return new Message([
            'pid' => 'p1',
            'queue' => 'audits',
            'timestamp' => time(),
            'payload' => ['task' => 'a'],
        ]);
    }

    public function testAnOrdinaryFailureIsParkedForTheRetrySweep(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Redis($connection, $connection);

        $broker->reject(new Queue('audits', 'tests'), $this->message());

        $this->assertSame([['leftPush', 'tests.failed.audits']], $connection->calls);
    }

    public function testATerminalFailureSkipsTheRetrySweep(): void
    {
        $connection = new PushRecordingConnection();
        $broker = new Redis($connection, $connection);

        $broker->reject(new Queue('audits', 'tests'), $this->message()->terminal());

        // The dead list is where an exhausted message ends up anyway; a terminal
        // one gets there on the first failure instead of after N of them.
        $this->assertSame([['leftPush', 'tests.dead.audits']], $connection->calls);
    }
}
