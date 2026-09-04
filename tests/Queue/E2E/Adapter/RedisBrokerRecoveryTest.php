<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

/**
 * Recovery paths for the Redis broker: reap() reclaims claims stranded by a
 * dead worker, retry() requeues the failed list, and both park messages on the
 * dead queue once their attempt count is exhausted. Runs on a bare host
 * against InMemoryConnection.
 */
final class RedisBrokerRecoveryTest extends TestCase
{
    private const string QUEUE = 'recovery';
    private const string NAMESPACE = 'tests';

    private InMemoryConnection $connection;
    private Redis $broker;
    private Queue $queue;

    protected function setUp(): void
    {
        $this->connection = new InMemoryConnection();
        $this->broker = new Redis($this->connection, $this->connection);
        $this->queue = new Queue(self::QUEUE, self::NAMESPACE);
    }

    private function processingSize(): int
    {
        return $this->connection->listSize('tests.processing.recovery');
    }

    private function deadSize(): int
    {
        return $this->connection->listSize('tests.dead.recovery');
    }

    /**
     * retry() treats same-second timestamps as its own sweep wrapping around;
     * age the payload so a just-rejected test message looks like real backlog.
     */
    private function backdate(string $pid, int $seconds = 60): void
    {
        $key = 'tests.jobs.recovery.' . $pid;
        $job = $this->connection->get($key);
        $job['timestamp'] -= $seconds;
        $this->connection->setArray($key, $job);
    }

    public function testReapRequeuesAStrandedClaim(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->assertSame(1, $this->processingSize(), 'the claim is on the processing list');

        $requeued = $this->broker->reap($this->queue, olderThan: 0);

        $this->assertSame(1, $requeued);
        $this->assertSame(0, $this->processingSize(), 'the stranded claim is reclaimed');
        $this->assertSame(1, $this->broker->getQueueSize($this->queue), 'the message is back on the queue');

        $retried = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $retried);
        $this->assertSame(['n' => 1], $retried->getPayload(), 'the payload survives the requeue');
        $this->assertSame(1, $retried->getAttempts(), 'the requeue is counted');
    }

    public function testReapLeavesClaimsYoungerThanTheCutoff(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $this->broker->receive($this->queue, 0);

        $requeued = $this->broker->reap($this->queue, olderThan: 3600);

        $this->assertSame(0, $requeued, 'a possibly in-flight claim is left alone');
        $this->assertSame(1, $this->processingSize());
    }

    public function testReapDropsClaimsWhosePayloadExpired(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->connection->remove('tests.jobs.recovery.' . $claimed->getPid());

        $requeued = $this->broker->reap($this->queue, olderThan: 0);

        $this->assertSame(0, $requeued);
        $this->assertSame(0, $this->processingSize(), 'the unrecoverable claim is pruned');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue));
    }

    public function testReapParksExhaustedClaimsOnTheDeadQueue(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);

        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertSame(0, $claimed->getAttempts());

        foreach ([1, 2] as $attempt) {
            $this->broker->reap($this->queue, olderThan: 0);
            $claimed = $this->broker->receive($this->queue, 0);
            $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
            $this->assertSame($attempt, $claimed->getAttempts());
        }

        $requeued = $this->broker->reap($this->queue, olderThan: 0, maxAttempts: 2);

        $this->assertSame(0, $requeued);
        $this->assertSame(0, $this->processingSize());
        $this->assertSame(1, $this->deadSize(), 'the exhausted claim is parked, not looped');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue));
    }

    public function testRetryRequeuesARejectedMessageWithItsAttemptCount(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->broker->reject($this->queue, $claimed);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->backdate($claimed->getPid());
        $this->assertSame(1, $this->broker->getQueueSize($this->queue, failedJobs: true));

        $this->broker->retry($this->queue);

        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true));
        $retried = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $retried);
        $this->assertSame(['n' => 1], $retried->getPayload());
        $this->assertSame(1, $retried->getAttempts());
    }

    public function testRetryParksExhaustedMessagesOnTheDeadQueue(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $claimed->setAttempts(3);
        $this->connection->setArray('tests.jobs.recovery.' . $claimed->getPid(), $claimed->asArray());
        $this->broker->reject($this->queue, $claimed);
        $this->backdate($claimed->getPid());

        $this->broker->retry($this->queue, maxAttempts: 3);

        $this->assertSame(0, $this->broker->getQueueSize($this->queue), 'nothing is requeued');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true));
        $this->assertSame(1, $this->deadSize(), 'the exhausted message is parked');
    }

    public function testRetrySkipsEntriesWhosePayloadExpired(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $this->broker->publish($this->queue, ['n' => 2]);
        $first = $this->broker->receive($this->queue, 0);
        $second = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $first);
        $this->broker->reject($this->queue, $first);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $second);
        $this->broker->reject($this->queue, $second);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $first);
        $this->connection->remove('tests.jobs.recovery.' . $first->getPid());
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $second);
        $this->backdate($second->getPid());

        $this->broker->retry($this->queue);

        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true), 'the expired entry does not block the sweep');
        $this->assertSame(1, $this->broker->getQueueSize($this->queue), 'the recoverable entry is requeued');
    }
    public function testRetryParksEntriesOlderThanTheAgeGate(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->broker->reject($this->queue, $claimed);
        $this->backdate($claimed->getPid(), 3600);

        $this->broker->retry($this->queue, newerThan: 600);

        $this->assertSame(0, $this->broker->getQueueSize($this->queue), 'ancient work is not resurrected');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true));
        $this->assertSame(1, $this->deadSize(), 'the ancient entry is parked for inspection');
    }

    public function testReapParksClaimsOlderThanTheAgeGate(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->backdate($claimed->getPid(), 3600);

        $requeued = $this->broker->reap($this->queue, olderThan: 0, newerThan: 600);

        $this->assertSame(0, $requeued);
        $this->assertSame(0, $this->processingSize());
        $this->assertSame(1, $this->deadSize(), 'the ancient claim is parked, not re-run');
    }
}
