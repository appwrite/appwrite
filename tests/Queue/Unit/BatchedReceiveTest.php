<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Adapter\InMemoryConnection;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class BatchedReceiveTest extends TestCase
{
    private const string NAMESPACE = 'tests';
    private const string QUEUE = 'batched';

    private function broker(InMemoryConnection $connection): Broker
    {
        return new Broker($connection, $connection);
    }

    private function queue(): Queue
    {
        return new Queue(self::QUEUE, self::NAMESPACE);
    }

    public function testClaimsUpToTheRequestedMaximum(): void
    {
        $connection = new InMemoryConnection();
        $broker = $this->broker($connection);
        $queue = $this->queue();

        foreach (range(1, 10) as $n) {
            $broker->publish($queue, ['n' => $n]);
        }

        $batch = $broker->receive($queue, 0, 4);

        $this->assertCount(4, $batch);
        $this->assertSame([1, 2, 3, 4], array_map(static fn(Message $m): int => $m->getPayload()['n'], $batch), 'a batch is FIFO, like the single receive');
        $this->assertSame(6, $broker->getQueueSize($queue), 'only what was asked for leaves the queue');
    }

    public function testReturnsWhatIsThereRatherThanWaitingForTheBatchToFill(): void
    {
        $connection = new InMemoryConnection();
        $broker = $this->broker($connection);
        $queue = $this->queue();

        $broker->publish($queue, ['n' => 1]);

        $this->assertCount(1, $broker->receive($queue, 0, 16));
    }

    public function testAnEmptyQueueYieldsAnEmptyBatch(): void
    {
        $broker = $this->broker(new InMemoryConnection());

        $this->assertSame([], $broker->receive($this->queue(), 0, 8));
    }

    /**
     * Omitting the count claims exactly one message.
     */
    public function testReceiveDefaultsToOne(): void
    {
        $connection = new InMemoryConnection();
        $broker = $this->broker($connection);
        $queue = $this->queue();

        $broker->publish($queue, ['n' => 1]);
        $broker->publish($queue, ['n' => 2]);

        $batch = $broker->receive($queue, 0);
        $this->assertCount(1, $batch);
        $message = $batch[0];

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(['n' => 1], $message->getPayload());
        $this->assertSame(1, $broker->getQueueSize($queue), 'the second message stays put');
    }

    public function testEveryMessageInTheBatchIsClaimed(): void
    {
        $connection = new InMemoryConnection();
        $broker = $this->broker($connection);
        $queue = $this->queue();

        foreach (range(1, 5) as $n) {
            $broker->publish($queue, ['n' => $n]);
        }

        $batch = $broker->receive($queue, 0, 5);

        $broker->reject($queue, array_shift($batch));
        foreach ($batch as $message) {
            $broker->commit($queue, $message);
        }
        $connection->advanceToNextExpiry();

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame(0, $broker->reap($queue, olderThan: 0));
        $this->assertSame([], $broker->receive($queue, 0, 5));
    }

    public function testNonPositiveCountsReceiveOneAndClosedConsumersReturnNothing(): void
    {
        $broker = $this->broker(new InMemoryConnection());
        $queue = $this->queue();
        foreach ([0, -1] as $n) {
            $broker->publish($queue, ['n' => $n]);
            $this->assertCount(1, $broker->receive($queue, 0, n: $n));
        }
        $this->assertSame([], $broker->receive($queue, 0));
        $broker->publish($queue, ['n' => 1]);
        $broker->close();
        $this->assertSame([], $broker->receive($queue, 0));
        $this->assertSame(1, $broker->getQueueSize($queue));
    }

    public function testPooledConsumersHonorDefaultAndExplicitCounts(): void
    {
        $broker = $this->broker(new InMemoryConnection());
        $queue = $this->queue();
        $pool = new \Utopia\Pools\Pool(new \Utopia\Pools\Adapter\Stack(), 'consume', 1, fn(): Broker => $broker, timeout: 0.0);
        $consumer = new \Utopia\Queue\Broker\Pool(consumer: $pool);
        foreach (range(1, 4) as $n) {
            $broker->publish($queue, ['n' => $n]);
        }

        $this->assertCount(1, $consumer->receive($queue, 0));
        $this->assertCount(3, $consumer->receive($queue, 0, n: 3));
        $this->assertSame([], $consumer->receive($queue, 0));
        $this->assertSame([], new \Utopia\Queue\Broker\Pool()->receive($queue, 0));
    }

    /**
     * Between the pop and the write to the processing list, the batch exists
     * nowhere else. A failure there has to put it back.
     */
    public function testAFailedClaimPutsTheWholeBatchBack(): void
    {
        $connection = new FailingClaimConnection();
        $connection->failClaim = true;
        $broker = new Broker($connection, $connection);
        $queue = $this->queue();

        foreach (range(1, 4) as $n) {
            $broker->publish($queue, ['n' => $n]);
        }

        try {
            $broker->receive($queue, 0, 4);
            $this->fail('the claim failure must reach the caller');
        } catch (\RuntimeException) {
        }

        $this->assertSame(4, $broker->getQueueSize($queue), 'nothing is lost');

        $connection->failClaim = false;
        $batch = $broker->receive($queue, 0, 4);

        $this->assertSame(
            [1, 2, 3, 4],
            array_map(static fn(Message $m): int => $m->getPayload()['n'], $batch),
            'and the batch comes back in the order it left',
        );
    }

    /**
     * One unreadable message in a batch is parked on its own; the rest are
     * delivered, because nothing about a batch is acknowledged together.
     */
    public function testOnePoisonMessageDoesNotTakeTheBatchWithIt(): void
    {
        $connection = new InMemoryConnection();
        $broker = $this->broker($connection);
        $queue = $this->queue();

        $broker->publish($queue, ['n' => 1]);
        $connection->leftPush(self::NAMESPACE . '.queue.' . self::QUEUE, '{"truncated"');
        $broker->publish($queue, ['n' => 3]);

        $batch = $broker->receive($queue, 0, 8);

        $this->assertSame([1, 3], array_map(static fn(Message $m): int => $m->getPayload()['n'], $batch));
        $this->assertSame(1, $connection->listSize(self::NAMESPACE . '.poison.' . self::QUEUE));
    }
}

final class FailingClaimConnection extends InMemoryConnection
{
    public bool $failClaim = false;

    #[\Override]
    public function leftPushMany(string $queue, array $payloads): bool
    {
        if ($this->failClaim) {
            throw new \RuntimeException('claim failed');
        }

        return parent::leftPushMany($queue, $payloads);
    }
}
