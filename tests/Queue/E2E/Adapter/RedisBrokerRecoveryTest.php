<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Recovery paths for the Redis broker: reap() reclaims claims stranded by a
 * dead worker, retry() requeues the failed list, and both park messages on the
 * dead queue once their attempt count is exhausted. Runs against real Redis.
 */
final class RedisBrokerRecoveryTest extends RedisTestCase
{
    private const string QUEUE = 'recovery';

    private Redis $broker;
    private Queue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new Redis($this->connection, $this->connection);
        $this->queue = new Queue(self::QUEUE, $this->namespace);
    }

    private function processingSize(): int
    {
        return $this->connection->listSize($this->namespace . '.processing.recovery');
    }

    private function deadSize(): int
    {
        return $this->connection->listSize($this->namespace . '.dead.recovery');
    }

    /**
     * retry() treats same-second timestamps as its own sweep wrapping around;
     * age the payload so a just-rejected test message looks like real backlog.
     */
    private function backdate(string $pid, int $seconds = 60): void
    {
        $key = $this->namespace . '.jobs.recovery.' . $pid;
        $codec = new Json();
        $job = $codec->decode((string) $this->connection->get($key));
        $job['timestamp'] -= $seconds;
        $this->connection->set($key, $codec->encode($job));
    }

    public function testReapRequeuesAStrandedClaim(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->assertSame(1, $this->processingSize(), 'the claim is on the processing list');
        $this->expire('.claims.*');

        $requeued = $this->broker->reap($this->queue, olderThan: 0);

        $this->assertSame(1, $requeued);
        $this->assertSame(0, $this->processingSize(), 'the stranded claim is reclaimed');
        $this->assertSame(1, $this->broker->getQueueSize($this->queue), 'the message is back on the queue');

        $retried = $this->broker->receive($this->queue, 0)[0] ?? null;
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

    public function testReapDropsLegacyClaimsWhosePayloadExpired(): void
    {
        // Pre-ownership-record deliveries could expire while processing.
        $this->connection->leftPush($this->namespace . '.processing.recovery', 'expired-legacy-pid');
        $requeued = $this->broker->reap($this->queue, olderThan: 0);
        $this->assertSame(0, $requeued);
        $this->assertSame(0, $this->processingSize(), 'the unrecoverable legacy claim is pruned');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue));
    }

    public function testReapParksExhaustedClaimsOnTheDeadQueue(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);

        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertSame(0, $claimed->getAttempts());
        $this->expire('.claims.*');

        foreach ([1, 2] as $attempt) {
            $this->broker->reap($this->queue, olderThan: 0);
            $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
            $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
            $this->assertSame($attempt, $claimed->getAttempts());
            $this->expire('.claims.*');
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
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->broker->reject($this->queue, $claimed);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->backdate($claimed->getPid());
        $this->assertSame(1, $this->broker->getQueueSize($this->queue, failedJobs: true));

        $this->broker->retry($this->queue);

        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true));
        $retried = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $retried);
        $this->assertSame(['n' => 1], $retried->getPayload());
        $this->assertSame(1, $retried->getAttempts());
    }

    public function testRetryParksExhaustedMessagesOnTheDeadQueue(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $claimed->setAttempts(3);
        $this->connection->set($this->namespace . '.jobs.recovery.' . $claimed->getPid(), new Json()->encode($claimed->asArray()));
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
        $first = $this->broker->receive($this->queue, 0)[0] ?? null;
        $second = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $first);
        $this->broker->reject($this->queue, $first);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $second);
        $this->broker->reject($this->queue, $second);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $first);
        $this->connection->remove($this->namespace . '.jobs.recovery.' . $first->getPid());
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $second);
        $this->backdate($second->getPid());

        $this->broker->retry($this->queue);

        $this->assertSame(0, $this->broker->getQueueSize($this->queue, failedJobs: true), 'the expired entry does not block the sweep');
        $this->assertSame(1, $this->broker->getQueueSize($this->queue), 'the recoverable entry is requeued');
    }
    public function testRetryParksEntriesOlderThanTheAgeGate(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
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
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->backdate($claimed->getPid(), 3600);
        $this->expire('.claims.*');

        $requeued = $this->broker->reap($this->queue, olderThan: 0, newerThan: 600);

        $this->assertSame(0, $requeued);
        $this->assertSame(0, $this->processingSize());
        $this->assertSame(1, $this->deadSize(), 'the ancient claim is parked, not re-run');
    }

    public function testReapToleratesAClaimSettledMidSweep(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(Message::class, $claimed);

        // The worker commits the claim as the sweep starts inspecting it.
        $settle = fn() => $this->broker->commit($this->queue, $claimed);
        $racing = new class (getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379), $settle) extends Connection {
            public function __construct(string $host, int $port, private ?\Closure $settle)
            {
                parent::__construct($host, $port);
            }

            #[\Override]
            public function get(string $key): array|string|null
            {
                $value = parent::get($key);
                if ($this->settle instanceof \Closure) {
                    ($this->settle)();
                    $this->settle = null;
                }
                return $value;
            }
        };

        $requeued = new Redis($racing, $racing)->reap($this->queue, olderThan: 0);

        $this->assertSame(0, $requeued);
        $this->assertSame(0, $this->processingSize(), 'the commit stands');
        $this->assertSame(0, $this->broker->getQueueSize($this->queue), 'no duplicate is enqueued');
    }

    public function testAHeartbeatedClaimIsNeverReaped(): void
    {
        // The failure the heartbeat exists to prevent: a job published long
        // before the cutoff, claimed by a live worker still running it. The
        // timestamp alone reads it as stranded; the claim key says otherwise.
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->backdate($claimed->getPid(), 3600);

        $requeued = $this->broker->reap($this->queue, olderThan: 0);

        $this->assertSame(0, $requeued, 'the live claim is left with its worker');
        $this->assertSame(1, $this->processingSize());
        $this->assertSame(0, $this->broker->getQueueSize($this->queue), 'no duplicate is enqueued');
    }

    public function testExtendRenewsHeartbeatWhileStillOwned(): void
    {
        // Heartbeat expiry permits recovery, but ownership lasts until actual takeover.
        $this->broker->publish($this->queue, ['n' => 1]);
        $claimed = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->backdate($claimed->getPid(), 3600);
        $this->expire('.claims.*');

        $this->broker->extend($this->queue, $claimed);

        $this->assertSame(0, $this->broker->reap($this->queue, olderThan: 0));
        $this->broker->commit($this->queue, $claimed);
        $this->assertSame(0, $this->processingSize());
    }

    public function testMaintainReapsTheQueuesThisBrokerServed(): void
    {
        // reapAfter: 0 stands in for a fleet whose every worker heartbeats, so
        // a missing claim key alone marks the worker dead.
        $broker = new Redis($this->connection, $this->connection, reapAfter: 0);
        $broker->publish($this->queue, ['n' => 1]);
        $claimed = $broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $claimed);
        $this->expire('.claims.*');

        $broker->maintain();

        $this->assertSame(0, $this->processingSize(), 'the stranded claim is reclaimed by the sweep');
        $this->assertSame(1, $broker->getQueueSize($this->queue), 'the message is back on the queue');
    }

    public function testTheReapLockAdmitsOneSweepPerInterval(): void
    {
        $broker = new Redis($this->connection, $this->connection, reapAfter: 0);
        $broker->publish($this->queue, ['n' => 1]);
        $broker->receive($this->queue, 0);

        $broker->maintain();

        // The worker's claim expires while the fleet's sweep lock is held.
        $this->expire('.claims.*');
        $broker->maintain();
        $this->assertSame([], $broker->receive($this->queue, 0));

        $this->expire('.reap-lock.*');
        $broker->maintain();
        $this->assertCount(1, $broker->receive($this->queue, 0));
    }

    public function testMaintainSweepsNothingBeforeTheFirstConsume(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $this->broker->receive($this->queue, 0);
        $this->expire('.claims.*');
        $fresh = new Redis($this->connection, $this->connection, reapAfter: 0);

        $fresh->maintain();

        $this->assertSame([], $fresh->receive($this->queue, 0));
        $fresh->maintain();
        $this->assertCount(1, $fresh->receive($this->queue, 0));
    }

    public function testBoundedReapRecoversOldestClaimsFirst(): void
    {
        foreach (range(1, 6) as $n) {
            $this->broker->publish($this->queue, ['n' => $n]);
        }
        $this->broker->receive($this->queue, 0, 6);
        $this->expire('.claims.*');

        $this->assertSame(2, $this->broker->reap($this->queue, olderThan: 0, limit: 2, scan: 4));
        $recovered = $this->broker->receive($this->queue, 0, 6);
        $this->assertSame([1, 2], array_map(static fn(\Utopia\Queue\Message $m) => $m->getPayload()['n'], $recovered));
    }

    public function testMaintainTracksDistinctDottedQueueIdentities(): void
    {
        $broker = new Redis($this->connection, $this->connection, reapAfter: 0);
        $queues = [new Queue('c', $this->namespace . '.a.b'), new Queue('b.c', $this->namespace . '.a')];
        foreach ($queues as $queue) {
            $broker->publish($queue, ['queue' => $queue->name]);
            $broker->receive($queue, 0);
        }
        $this->expire('*.claims.*');

        $broker->maintain();

        foreach ($queues as $queue) {
            $messages = $broker->receive($queue, 0);
            $this->assertCount(1, $messages);
            $this->assertSame(['queue' => $queue->name], $messages[0]->getPayload());
        }
    }
    public function testPooledMaintenanceRecoversAnExpiredClaim(): void
    {
        $broker = new Redis($this->connection, $this->connection, reapAfter: 0);
        $pool = new \Utopia\Pools\Pool(new \Utopia\Pools\Adapter\Stack(), 'recovery', 1, fn(): Redis => $broker, timeout: 0.0);
        $consumer = new \Utopia\Queue\Broker\Pool(consumer: $pool);
        $broker->publish($this->queue, ['n' => 1]);
        $this->assertCount(1, $consumer->receive($this->queue, 0));
        $this->expire('.claims.*');

        $consumer->maintain();

        $messages = $consumer->receive($this->queue, 0);
        $this->assertCount(1, $messages);
        $this->assertSame(['n' => 1], $messages[0]->getPayload());
        $consumer->commit($this->queue, $messages[0]);
        $this->assertSame([], $consumer->receive($this->queue, 0));
    }

    public function testBoundedReapAdvancesPastLiveClaimsAcrossBrokers(): void
    {
        foreach (range(1, 5) as $n) {
            $this->broker->publish($this->queue, ['n' => $n]);
        }
        $claims = $this->broker->receive($this->queue, 0, 5);

        foreach (\array_slice($claims, 0, 3) as $live) {
            $this->broker->extend($this->queue, $live);
        }

        foreach (\array_slice($claims, 3) as $expired) {
            $this->expire('.claims.' . self::QUEUE . '.' . $expired->getPid());
        }
        // Each window can be scanned by a different winner of the fleet lock.
        foreach ([0, 1, 1] as $recovered) {
            $broker = new Redis($this->connection, $this->connection);
            $this->assertSame($recovered, $broker->reap($this->queue, olderThan: 0, limit: 1, scan: 2));
        }
        $messages = $this->broker->receive($this->queue, 0, 5);
        $this->assertSame([4, 5], array_map(static fn(\Utopia\Queue\Message $message) => $message->getPayload()['n'], $messages));

        // After reaching the head, wrap back to claims that were live before.
        foreach ($messages as $message) {
            $this->broker->commit($this->queue, $message);
        }
        $this->expire('.claims.*');
        $this->assertSame(2, $this->broker->reap($this->queue, olderThan: 0, scan: 2));
    }

    public function testGrowingQueueDoesNotPreventRevisitingExpiredClaims(): void
    {
        foreach (range(1, 4) as $n) {
            $this->broker->publish($this->queue, ['n' => $n]);
        }
        $this->broker->receive($this->queue, 0, 4);
        $this->assertSame(0, $this->broker->reap($this->queue, olderThan: 0, scan: 2));
        $this->expire('.claims.*');

        $recovered = [];
        // Add faster than each sweep can scan; the cycle must still finish.
        foreach (range(1, 3) as $round) {
            foreach (range(1, 4) as $n) {
                $this->broker->publish($this->queue, ['new' => $round * 4 + $n]);
            }
            $this->broker->receive($this->queue, 0, 4);
            $broker = new Redis($this->connection, $this->connection);
            $broker->reap($this->queue, olderThan: 0, scan: 2);
            foreach ($broker->receive($this->queue, 0, 4) as $message) {
                $recovered[] = $message->getPayload()['n'];
                $broker->commit($this->queue, $message);
            }
        }
        sort($recovered);
        $this->assertSame([1, 2, 3, 4], $recovered);
    }

}
