<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Broker\Pool;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Ack extension through Broker\Pool.
 *
 * The Swoole adapter probes the consumer it was handed for extend()/
 * extendInterval(), and for a pooled worker that consumer is Broker\Pool, not
 * the broker inside the pool. With the two methods missing the probe found
 * nothing and the heartbeat quietly did not run — so ackWait went back to being
 * a hard ceiling on job duration in the one wiring the pooled deployment uses,
 * and a job that overran was redelivered while its first attempt was still
 * going. Silent, because "no extension" and "extension not supported" look
 * identical from the outside.
 */
final class PooledAckExtensionTest extends TestCase
{
    private function pooled(Consumer $broker): Pool
    {
        // Size 1, matching the documented wiring: the same broker answers a
        // message's receive() and its extend(), which is what makes the
        // in-flight lookup behind extend() find anything.
        $pool = new UtopiaPool(new Stack(), 'test', 1, fn(): Consumer => $broker, timeout: 0.0);

        return new Pool($pool, $pool);
    }

    /**
     * The probe itself, asserted directly.
     *
     * {@see \Utopia\Queue\Adapter\Swoole::withAckExtension()} decides whether
     * to run a heartbeat with exactly this pair of is_callable() checks against
     * the consumer it was given. Both returned false for Broker\Pool, and the
     * adapter's response to that is to run the handler with no extension at
     * all — so this is the single condition the whole defect reduced to.
     */
    public function testTheAdaptersCapabilityProbeSucceedsOnAPooledConsumer(): void
    {
        // Probed through a Consumer-typed parameter, because that is how the
        // adapter holds it: withAckExtension() takes a Consumer, and neither
        // method is on that interface — so whether they are there is a real
        // runtime question at the call site rather than a static one here.
        $probe = static fn(Consumer $consumer): bool => \is_callable([$consumer, 'extend'])
            && \is_callable([$consumer, 'extendInterval']);

        $this->assertTrue($probe($this->pooled(new ExtendableBroker())));
    }

    public function testExtendReachesTheBrokerInThePool(): void
    {
        $broker = new ExtendableBroker();
        $pooled = $this->pooled($broker);
        $queue = new Queue('test');
        $message = new Message(['pid' => 'p1', 'queue' => 'test', 'timestamp' => 0]);

        $pooled->extend($queue, $message);
        $pooled->extend($queue, $message);

        $this->assertSame(['p1', 'p1'], $broker->extended);
    }

    public function testExtendIntervalIsTheBrokersOwnCadence(): void
    {
        $pooled = $this->pooled(new ExtendableBroker());

        // Not a number this class invents: an interval longer than the real
        // ackWait would extend nothing while looking like it did.
        $this->assertEqualsWithDelta(2.5, $pooled->extendInterval(), PHP_FLOAT_EPSILON);
    }

    public function testABrokerThatCannotExtendReportsNoIntervalRatherThanFailing(): void
    {
        // Redis has no notion of extending a delivery deadline, and Broker\Pool
        // is the consumer for those workers too. Null is how the capability is
        // reported absent, so the caller skips the heartbeat instead of calling
        // into a method that is not there.
        $pooled = $this->pooled(new PlainBroker());

        $this->assertNull($pooled->extendInterval());
    }

    public function testExtendingAnUnsupportedBrokerIsANoOpNotAFatal(): void
    {
        $pooled = $this->pooled(new PlainBroker());

        $pooled->extend(new Queue('test'), new Message(['pid' => 'p1', 'queue' => 'test', 'timestamp' => 0]));

        $this->expectNotToPerformAssertions();
    }

    public function testWithNoConsumerPoolThereIsNoIntervalToReport(): void
    {
        $pooled = new Pool();

        $this->assertNull($pooled->extendInterval());
        $pooled->extend(new Queue('test'), new Message(['pid' => 'p1', 'queue' => 'test', 'timestamp' => 0]));
    }

    /**
     * The lease is returned, not held. extend() runs on every heartbeat for the
     * whole life of a job, so a lease it failed to give back would drain a
     * size-1 pool on the first beat and deadlock the handler it was extending.
     */
    public function testExtendingDoesNotStrandThePoolsOneResource(): void
    {
        $broker = new ExtendableBroker();
        $pool = new UtopiaPool(new Stack(), 'test', 1, fn(): Consumer => $broker, timeout: 0.0);
        $pooled = new Pool($pool, $pool);
        $queue = new Queue('test');
        $message = new Message(['pid' => 'p1', 'queue' => 'test', 'timestamp' => 0]);

        for ($beat = 0; $beat < 5; $beat++) {
            $pooled->extend($queue, $message);
        }

        $this->assertCount(5, $broker->extended);
        $this->assertSame(1, $pool->count(), 'The resource must be back in the pool after each beat');
    }
}

class PlainBroker implements Consumer
{
    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class ExtendableBroker extends PlainBroker
{
    /** @var list<string> */
    public array $extended = [];

    public function extend(Queue $queue, Message $message): void
    {
        $this->extended[] = $message->getPid();
    }

    public function extendInterval(): float
    {
        return 2.5;
    }
}
