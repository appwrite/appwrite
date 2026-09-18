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

        $batch = $broker->receiveBatch($queue, 0, 4);

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

        $this->assertCount(1, $broker->receiveBatch($queue, 0, 16));
    }

    public function testAnEmptyQueueYieldsAnEmptyBatch(): void
    {
        $broker = $this->broker(new InMemoryConnection());

        $this->assertSame([], $broker->receiveBatch($this->queue(), 0, 8));
    }

    /**
     * The single receive is the batch of one, so it cannot drift from it.
     */
    public function testReceiveIsTheBatchOfOne(): void
    {
        $connection = new InMemoryConnection();
        $broker = $this->broker($connection);
        $queue = $this->queue();

        $broker->publish($queue, ['n' => 1]);
        $broker->publish($queue, ['n' => 2]);

        $message = $broker->receive($queue, 0);

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

        $batch = $broker->receiveBatch($queue, 0, 5);

        $this->assertSame(5, $connection->listSize(self::NAMESPACE . '.processing.' . self::QUEUE));
        foreach ($batch as $message) {
            $this->assertIsString(
                $connection->get(self::NAMESPACE . '.jobs.' . self::QUEUE . '.' . $message->getPid()),
                'each message is recoverable by reap() on its own',
            );
        }

        foreach ($batch as $message) {
            $broker->commit($queue, $message);
        }

        $this->assertSame(0, $connection->listSize(self::NAMESPACE . '.processing.' . self::QUEUE));
    }

    /**
     * The whole point of the change: a batch of N costs N + 3 writes to claim,
     * not 4N.
     */
    public function testTheClaimCostsThreeCommandsRegardlessOfBatchSize(): void
    {
        $connection = new CountingConnection();
        $broker = new Broker($connection, $connection);
        $queue = $this->queue();

        foreach (range(1, 8) as $n) {
            $broker->publish($queue, ['n' => $n]);
        }

        $connection->reset();
        $batch = $broker->receiveBatch($queue, 0, 8);

        $this->assertCount(8, $batch);
        $this->assertSame(0, $connection->commands('rightPop'), 'the blocking pop is the batch pop');
        $this->assertSame(1, $connection->commands('rightPopMany'), 'one BLMPOP for the wait and the whole batch');
        $this->assertSame(8, $connection->commands('set'), 'one job key each -- a TTL cannot be shared');
        $this->assertSame(1, $connection->commands('leftPushMany'), 'one command for every claim');
        $this->assertSame(2, $connection->commands('incrementBy'), 'two counters, moved once each');
        $this->assertSame(0, $connection->commands('leftPush'), 'the per-message writes are gone');
        $this->assertSame(0, $connection->commands('increment'));

        $this->assertSame(
            12,
            array_sum($connection->counts),
            'N + 4 commands for a batch of 8, where one at a time costs 5N = 40',
        );
    }

    /**
     * Between the pop and the write to the processing list, the batch exists
     * nowhere else. A failure there has to put it back.
     */
    public function testAFailedClaimPutsTheWholeBatchBack(): void
    {
        $connection = new CountingConnection();
        $connection->failOn = 'leftPushMany';
        $broker = new Broker($connection, $connection);
        $queue = $this->queue();

        foreach (range(1, 4) as $n) {
            $broker->publish($queue, ['n' => $n]);
        }

        try {
            $broker->receiveBatch($queue, 0, 4);
            $this->fail('the claim failure must reach the caller');
        } catch (\RuntimeException) {
        }

        $this->assertSame(4, $broker->getQueueSize($queue), 'nothing is lost');

        $connection->failOn = null;
        $batch = $broker->receiveBatch($queue, 0, 4);

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

        $batch = $broker->receiveBatch($queue, 0, 8);

        $this->assertSame([1, 3], array_map(static fn(Message $m): int => $m->getPayload()['n'], $batch));
        $this->assertSame(1, $connection->listSize(self::NAMESPACE . '.poison.' . self::QUEUE));
    }
}

/**
 * Counts the commands the broker issues, and can be told to fail one of them.
 *
 * Only the outermost call is counted. The in-memory fake implements its own
 * batch pushes by looping over the single ones, and counting those would
 * measure the fake rather than the broker -- which is the whole subject here.
 */
final class CountingConnection extends InMemoryConnection
{
    /** @var array<string, int> */
    public array $counts = [];

    public ?string $failOn = null;

    private bool $inside = false;

    public function reset(): void
    {
        $this->counts = [];
    }

    public function commands(string $method): int
    {
        return $this->counts[$method] ?? 0;
    }

    private function count(string $method): void
    {
        if (!$this->inside) {
            $this->counts[$method] = ($this->counts[$method] ?? 0) + 1;
        }

        if ($this->failOn === $method) {
            throw new \RuntimeException("{$method} failed");
        }
    }

    /**
     * @template T
     * @param callable(): T $command
     * @return T
     */
    private function outermost(string $method, callable $command): mixed
    {
        $this->count($method);
        $outer = !$this->inside;
        $this->inside = true;

        try {
            return $command();
        } finally {
            $this->inside = !$outer;
        }
    }

    #[\Override]
    public function rightPop(string $queue, int $timeout): string|false
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::rightPop($queue, $timeout));
    }

    #[\Override]
    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::rightPopMany($queue, $count, $timeout));
    }

    #[\Override]
    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::set($key, $value, $ttl));
    }

    #[\Override]
    public function leftPush(string $queue, string $payload): bool
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::leftPush($queue, $payload));
    }

    #[\Override]
    public function leftPushMany(string $queue, array $payloads): bool
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::leftPushMany($queue, $payloads));
    }

    #[\Override]
    public function rightPushMany(string $queue, array $payloads): bool
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::rightPushMany($queue, $payloads));
    }

    #[\Override]
    public function increment(string $key): int
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::increment($key));
    }

    #[\Override]
    public function incrementBy(string $key, int $by): int
    {
        return $this->outermost(__FUNCTION__, fn(): mixed => parent::incrementBy($key, $by));
    }
}
