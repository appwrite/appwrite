<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
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
                ['queue' => 'database_db_main', 'maxCoroutines' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
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
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
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
                ['queue' => 'database_db_main', 'maxCoroutines' => 1],
                ['queue' => 'v1-functions', 'maxCoroutines' => 8],
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

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 1]], $adapter->consumed);
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

        $this->assertSame([['queue' => 'v1-functions', 'maxCoroutines' => 8]], $adapter->consumed);
    }
}

final class FakeConsumer implements Consumer
{
    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class ExclusiveFakeConsumer implements Consumer, Exclusive
{
    public function receive(Queue $queue, int $timeout): ?Message
    {
        return null;
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

    public function __construct(string $namespace = 'utopia-queue', bool $shared = false, bool $exclusive = false)
    {
        if ($shared) {
            parent::__construct($exclusive ? new ExclusiveFakeConsumer() : new FakeConsumer(), 1, $namespace);
        } else {
            parent::__construct(
                static fn(string $q): Consumer => $exclusive ? new ExclusiveFakeConsumer() : new FakeConsumer(),
                1,
                $namespace,
            );
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
    ): void {
        $this->consumed[] = [
            'queue' => $queue->name,
            'maxCoroutines' => $maxCoroutines,
        ];
    }
}
