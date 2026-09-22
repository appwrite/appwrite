<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

/**
 * What getFailedCount() counts -- and the flag that still spells it -- which
 * decides whether an incident is visible at all.
 *
 * A message leaves the work queue for three different reasons and lands on three
 * different lists: failed (rejected with attempts left), dead (terminal, or out
 * of attempts) and poison (bytes no codec here could read). Counting the failed
 * list alone reported zero through exactly the two incidents the other lists
 * exist to record, while Broker\Nats answered the same call with its dead
 * stream -- so the number went flat where it was needed and disagreed with the
 * other transport everywhere else.
 */
final class RedisQueueSizeTest extends TestCase
{
    public function testPendingCountsOnlyTheWorkQueue(): void
    {
        $connection = new PushRecordingConnection();
        $connection->sizes = [
            'tests.queue.audits' => 7,
            'tests.failed.audits' => 3,
            'tests.dead.audits' => 2,
            'tests.poison.audits' => 1,
        ];
        $broker = new Redis($connection, $connection);

        // Depth is what is still waiting to be worked. Nothing on the other three
        // lists is: a scaler reading this must not be handed a backlog that no
        // worker will ever take.
        $this->assertSame(7, $broker->getQueueSize(new Queue('audits', 'tests')));
    }

    public function testFailedSumsTheRetrySweepTheDeadListAndTheParkedBytes(): void
    {
        $connection = new PushRecordingConnection();
        $connection->sizes = [
            'tests.queue.audits' => 7,
            'tests.failed.audits' => 3,
            'tests.dead.audits' => 2,
            'tests.poison.audits' => 1,
        ];
        $broker = new Redis($connection, $connection);

        $this->assertSame(6, $broker->getQueueSize(new Queue('audits', 'tests'), failedJobs: true));
    }

    public function testTheNamedReadAndTheOlderFlagAnswerTogether(): void
    {
        $connection = new PushRecordingConnection();
        $connection->sizes = [
            'tests.queue.audits' => 7,
            'tests.failed.audits' => 3,
            'tests.dead.audits' => 2,
            'tests.poison.audits' => 1,
        ];
        $broker = new Redis($connection, $connection);
        $queue = new Queue('audits', 'tests');

        // getQueueSize(failedJobs: true) is kept for callers that already spell it
        // that way and delegates here, so the two must never diverge -- a caller
        // reading one number and an operator reading the other are watching the
        // same queue.
        $this->assertSame(6, $broker->getFailedCount($queue));
        $this->assertSame($broker->getFailedCount($queue), $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame(7, $broker->getQueueSize($queue), 'the pending read is untouched by the new method');
    }

    public function testATerminalRejectIsVisibleWithNothingOnTheFailedList(): void
    {
        // The regression this exists for. A handler that declares its work
        // permanently impossible parks the message on the dead list and never
        // touches the failed one, so the old count answered zero for a queue
        // dead-lettering everything it was handed.
        $connection = new PushRecordingConnection();
        $connection->sizes = ['tests.dead.audits' => 4];
        $broker = new Redis($connection, $connection);

        $this->assertSame(4, $broker->getQueueSize(new Queue('audits', 'tests'), failedJobs: true));
    }

    public function testParkedBytesAreVisibleWithNothingElseWrong(): void
    {
        // The other silent one: a codec change can leave envelopes nothing on
        // this worker can decode. They are set aside rather than dropped, and
        // this is the only number that reports they are there.
        $connection = new PushRecordingConnection();
        $connection->sizes = ['tests.poison.audits' => 9];
        $broker = new Redis($connection, $connection);

        $this->assertSame(9, $broker->getQueueSize(new Queue('audits', 'tests'), failedJobs: true));
    }

    public function testAHealthyQueueStillReportsNothingFailed(): void
    {
        // Three reads instead of one must not invent a number: a queue with
        // nothing wrong answers zero, or the gauge alerts on every queue.
        $connection = new PushRecordingConnection();
        $connection->sizes = ['tests.queue.audits' => 12];
        $broker = new Redis($connection, $connection);

        $this->assertSame(0, $broker->getQueueSize(new Queue('audits', 'tests'), failedJobs: true));
    }
}
