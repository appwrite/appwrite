<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\Queue\Adapter;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Consumer;
use Utopia\Queue\Consumer\Bounded;
use Utopia\Queue\Consumer\Exclusive;
use Utopia\Queue\Job;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

final class ServerJobsTest extends TestCase
{
    public function testJobRegistersIndependentCoroutineCaps(): void
    {
        $server = new Server(new RecordingAdapter());

        $functions = $server->job('v1-functions', 8);
        $databases = $server->job('database_db_main', 1);

        $this->assertInstanceOf(Job::class, $functions);
        $this->assertInstanceOf(Job::class, $databases);
        $this->assertNotSame($functions, $databases);
        $this->assertSame(8, $server->coroutines('v1-functions'));
        $this->assertSame(1, $server->coroutines('database_db_main'));
        $this->assertCount(2, $server->jobs());
    }

    public function testOmittedCoroutineCapDefaultsToOne(): void
    {
        $server = new Server(new RecordingAdapter());

        $server->job('v1-mails');

        $this->assertSame(1, $server->coroutines('v1-mails'));
    }

    public function testEmptyQueueNameIsRejected(): void
    {
        $server = new Server(new RecordingAdapter());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Queue name is required');

        $server->job('');
    }

    public function testStartRequiresAtLeastOneJob(): void
    {
        $server = new Server(new RecordingAdapter());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('At least one job() must be registered');

        $server->start();
    }

    public function testConsumeKeepsPerQueueCaps(): void
    {
        $adapter = new RecordingAdapter();

        $adapter->consume(
            static fn(): null => null,
            static fn(): null => null,
            static fn(): null => null,
            [
                [
                    'queue' => new Queue('database_db_main'),
                    'maxCoroutines' => 1,
                ],
                [
                    'queue' => new Queue('v1-functions'),
                    'maxCoroutines' => 8,
                ],
            ],
        );

        $this->assertSame(
            [
                ['queue' => 'database_db_main', 'maxCoroutines' => 1, 'batch' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1],
            ],
            $adapter->consumed,
        );
    }

    public function testStartDrivesConsumeFromJobs(): void
    {
        $adapter = new RecordingAdapter();
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame(
            [
                ['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1],
            ],
            $adapter->consumed,
        );
    }

    public function testStartWithMultipleJobsUsesConsume(): void
    {
        $adapter = new RecordingAdapter();
        $server = new Server($adapter);
        $server->job('database_db_main', 1);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame(
            [
                ['queue' => 'database_db_main', 'maxCoroutines' => 1, 'batch' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1],
            ],
            $adapter->consumed,
        );
    }

    public function testStartWithMultipleJobsRejectsSharedConsumer(): void
    {
        $server = new Server(new RecordingAdapter(shared: true));
        $server->job('database_db_main', 1);
        $server->job('v1-functions', 8);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('callable factory to the Adapter constructor');

        $server->start();
    }

    /**
     * An exclusive consumer drives one socket. Swoole kills the worker the first
     * time the parked receive read overlaps a commit from a handler coroutine, so
     * the configuration has to be refused before any loop runs.
     */
    public function testStartRefusesConcurrencyOnAnExclusiveConsumer(): void
    {
        $adapter = new RecordingAdapter(exclusive: true);
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $refusal = null;

        try {
            $server->start();
        } catch (\Exception $error) {
            $refusal = $error;
        }

        $this->assertInstanceOf(\Exception::class, $refusal, 'the concurrency must be refused');
        $this->assertStringContainsString("job('v1-functions', 8)", $refusal->getMessage());

        // The refusal has to land before the loops start, not after one has been
        // handed a cap it cannot survive.
        $this->assertSame([], $adapter->consumed);
    }

    public function testStartAcceptsOneCoroutineOnAnExclusiveConsumer(): void
    {
        $adapter = new RecordingAdapter(exclusive: true);
        $server = new Server($adapter);
        $server->job('v1-functions');

        $server->start();

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 1, 'batch' => 1]], $adapter->consumed);
    }

    /**
     * Broker\Nats serialises its own connection behind a lock rather than claiming
     * the socket exclusively, so the guard must not fire for it: the cap a NATS job
     * is registered with has to reach the consume loop intact. The factory throws if
     * it is called, because the guard runs before any connection is opened.
     *
     * This is also what guards the marker itself. Asserting the broker does not
     * implement Consumer\Exclusive is a tautology PHPStan reads straight off the
     * class declaration; refusing the cap is the consequence worth pinning, and it
     * fails here the moment the marker comes back.
     */
    public function testStartKeepsConcurrencyOnTheNatsBroker(): void
    {
        $adapter = new RecordingAdapter();
        $server = new Server($adapter);
        $server->consumer(static fn(): Consumer => new Nats(
            static fn(): NatsConnection => throw new \LogicException('the guard must not connect'),
        ));
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1]], $adapter->consumed);
    }

    /**
     * A consumer that hands out at most N messages before it waits for an ack
     * cannot keep N handlers fed once any of them fail: a message sleeping in
     * backoff holds one of those N slots, so the handlers themselves fill the
     * ceiling and the queue stops being delivered into. Refuse it at boot.
     */
    public function testStartRefusesACoroutineCapAtTheInFlightCeiling(): void
    {
        $adapter = new RecordingAdapter(ceiling: 8);
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $refusal = null;

        try {
            $server->start();
        } catch (\Exception $error) {
            $refusal = $error;
        }

        $this->assertInstanceOf(\Exception::class, $refusal, 'the cap must be refused');
        $this->assertStringContainsString("job('v1-functions', 8)", $refusal->getMessage());
        $this->assertStringContainsString('at most 8 message(s) in flight', $refusal->getMessage());
        $this->assertSame([], $adapter->consumed, 'the refusal must land before the loops start');
    }

    public function testStartAcceptsACoroutineCapBelowTheInFlightCeiling(): void
    {
        $adapter = new RecordingAdapter(ceiling: 16);
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1]], $adapter->consumed);
    }

    /**
     * An unbounded consumer refuses nothing: null is "this broker sets no ceiling
     * of its own", not "a ceiling of zero".
     */
    public function testStartKeepsConcurrencyOnAnUnboundedConsumer(): void
    {
        $adapter = new RecordingAdapter(bounded: true);
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1]], $adapter->consumed);
    }

    /**
     * The guard is carried by the consumer, not by the concurrency. A consumer
     * without the marker (Redis serialises its shared connection) keeps running
     * above one coroutine.
     */
    public function testStartKeepsConcurrencyOnAConsumerWithoutTheMarker(): void
    {
        $adapter = new RecordingAdapter();
        $server = new Server($adapter);
        $server->job('v1-functions', 8);

        $server->start();

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 8, 'batch' => 1]], $adapter->consumed);
    }

    public function testStartCarriesTheBatchToTheConsumeLoop(): void
    {
        $adapter = new RecordingAdapter();
        $server = new Server($adapter);
        $server->job('v1-stats-usage', 16, 8);

        $server->start();

        $this->assertSame([['queue' => 'v1-stats-usage', 'maxCoroutines' => 16, 'batch' => 8]], $adapter->consumed);
    }

    /**
     * A batch larger than the handler slots waiting for it would claim messages
     * this worker cannot start -- out of the broker, and invisible to the idle
     * replica that could have run them.
     */
    public function testStartRefusesABatchLargerThanTheCoroutineCap(): void
    {
        $adapter = new RecordingAdapter();
        $server = new Server($adapter);
        $server->job('v1-stats-usage', 4, 16);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/batch cannot exceed the handler slots/');

        try {
            $server->start();
        } finally {
            $this->assertSame([], $adapter->consumed, 'the refusal lands before any loop starts');
        }
    }

    public function testBatchDefaultsToOneAndIsFloored(): void
    {
        $server = new Server(new RecordingAdapter());
        $server->job('a');
        $server->job('b', 4, 0);

        $this->assertSame(1, $server->batch('a'));
        $this->assertSame(1, $server->batch('b'), 'a batch below one is a batch of one, like the coroutine cap');
    }
}

final class FakeConsumer implements Consumer
{
    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        return [];
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final readonly class BoundedFakeConsumer implements Consumer, Bounded
{
    public function __construct(private ?int $ceiling) {}

    public function inFlightCeiling(): ?int
    {
        return $this->ceiling;
    }

    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        return [];
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class ExclusiveFakeConsumer implements Consumer, Exclusive
{
    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        return [];
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class RecordingAdapter extends Adapter
{
    /**
     * @var list<array{queue: string, maxCoroutines: int}>
     */
    public array $consumed = [];

    /** @var callable[] */
    private array $onWorkerStart = [];

    public function __construct(
        string $namespace = 'utopia-queue',
        bool $shared = false,
        bool $exclusive = false,
        ?int $ceiling = null,
        bool $bounded = false,
    ) {
        $make = static function () use ($exclusive, $ceiling, $bounded): Consumer {
            if ($exclusive) {
                return new ExclusiveFakeConsumer();
            }

            if ($bounded || $ceiling !== null) {
                return new BoundedFakeConsumer($ceiling);
            }

            return new FakeConsumer();
        };

        if ($shared) {
            parent::__construct($make(), 1, $namespace);
        } else {
            parent::__construct(static fn(string $q): Consumer => $make(), 1, $namespace);
        }
    }

    public function start(): self
    {
        foreach ($this->onWorkerStart as $callback) {
            $callback('0');
        }

        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    public function workerStart(callable $callback): self
    {
        $this->onWorkerStart[] = $callback;

        return $this;
    }

    public function workerStop(callable $callback): self
    {
        return $this;
    }

    #[\Override]
    protected function run(
        Queue $queue,
        int $maxCoroutines,
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        Consumer $consumer,
        int $batch = 1,
    ): void {
        $this->consumed[] = [
            'queue' => $queue->name,
            'maxCoroutines' => $maxCoroutines,
            'batch' => $batch,
        ];
    }
}
