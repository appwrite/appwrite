<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Queue\Broker\Background;
use Utopia\Queue\Publisher\BufferFullException;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class BackgroundTest extends TestCase
{
    public function testPublishDelegatesSynchronously(): void
    {
        $published = [];
        $background = new Background($this->recordingPublisher($published));

        $result = $background->publish(new Queue('emails'), ['id' => 1]);

        $this->assertTrue($result);
        $this->assertSame([['id' => 1]], $published);
    }

    public function testEnqueueFallsBackToSyncWhenNotStarted(): void
    {
        $published = [];
        $background = new Background($this->recordingPublisher($published));

        $background->enqueue(new Queue('emails'), ['id' => 1]);

        $this->assertSame([['id' => 1]], $published, 'no reader loop running → publish synchronously');
    }

    public function testReaderDrainsChannelIntoPublisher(): void
    {
        $published = [];
        $background = new Background($this->recordingPublisher($published));

        Coroutine\run(function () use ($background): void {
            $background->start();

            for ($i = 1; $i <= 5; $i++) {
                $background->enqueue(new Queue('emails'), ['id' => $i]);
            }

            $background->shutdown(); // drains queued publishes, then waits for the reader
        });

        $this->assertSame([1, 2, 3, 4, 5], array_column($published, 'id'));
    }

    public function testBackPressureBoundDeliversEveryMessageInOrder(): void
    {
        $published = [];
        // Capacity 1 forces enqueue() to block on nearly every push, so the
        // producer only advances as the reader drains — exercising back pressure.
        $background = new Background($this->recordingPublisher($published), capacity: 1);

        Coroutine\run(function () use ($background): void {
            $background->start();

            for ($i = 1; $i <= 20; $i++) {
                $background->enqueue(new Queue('emails'), ['id' => $i]);
            }

            $background->shutdown();
        });

        $this->assertSame(range(1, 20), array_column($published, 'id'));
    }

    public function testConcurrentCoroutinesDeliverEveryMessage(): void
    {
        $published = [];
        $background = new Background($this->recordingPublisher($published), coroutines: 4);

        Coroutine\run(function () use ($background): void {
            $background->start();

            for ($i = 1; $i <= 20; $i++) {
                $background->enqueue(new Queue('emails'), ['id' => $i]);
            }

            $background->shutdown();
        });

        // Four readers dispatch concurrently, so order isn't guaranteed — but
        // every message must land exactly once.
        $ids = array_column($published, 'id');
        sort($ids);
        $this->assertSame(range(1, 20), $ids);
    }

    public function testBatchFlushesAtMaximumMessageCount(): void
    {
        $batches = [];
        $background = new Background(
            $this->batchRecordingPublisher($batches),
            maxBatchInterval: 1,
            maxBatchSize: 3,
        );

        Coroutine\run(function () use ($background): void {
            $background->start();
            $background->enqueue(new Queue('emails'), ['id' => 1]);
            $background->enqueue(new Queue('emails'), ['id' => 2]);
            $background->enqueue(new Queue('emails'), ['id' => 3]);
            $background->shutdown();
        });

        $this->assertSame([[1, 2, 3]], array_map(
            static fn(array $batch): array => array_column($batch['payloads'], 'id'),
            $batches,
        ));
    }

    public function testBatchFlushesAtMaximumInterval(): void
    {
        $batches = [];
        $background = new Background(
            $this->batchRecordingPublisher($batches),
            maxBatchInterval: 0.02,
            maxBatchSize: 10,
        );

        Coroutine\run(function () use ($background): void {
            $background->start();
            $background->enqueue(new Queue('emails'), ['id' => 1]);
            $background->enqueue(new Queue('emails'), ['id' => 2]);
            Coroutine::sleep(0.05);
            $background->shutdown();
        });

        $this->assertSame([[1, 2]], array_map(
            static fn(array $batch): array => array_column($batch['payloads'], 'id'),
            $batches,
        ));
    }

    public function testBatchPreservesQueueAndPriorityBoundaries(): void
    {
        $batches = [];
        $background = new Background(
            $this->batchRecordingPublisher($batches),
            maxBatchInterval: 1,
            maxBatchSize: 10,
        );

        Coroutine\run(function () use ($background): void {
            $background->start();
            $background->enqueue(new Queue('emails'), ['id' => 1]);
            $background->enqueue(new Queue('sms'), ['id' => 2]);
            $background->enqueue(new Queue('sms'), ['id' => 3], priority: true);
            $background->shutdown();
        });

        $this->assertSame([
            ['queue' => 'emails', 'priority' => false, 'ids' => [1]],
            ['queue' => 'sms', 'priority' => false, 'ids' => [2]],
            ['queue' => 'sms', 'priority' => true, 'ids' => [3]],
        ], array_map(static fn(array $batch): array => [
            'queue' => $batch['queue'],
            'priority' => $batch['priority'],
            'ids' => array_column($batch['payloads'], 'id'),
        ], $batches));
    }

    public function testShutdownWaitsForEnqueuesAlreadyBlockedByBackPressure(): void
    {
        $gate = new Channel(3);
        $publisher = new class ($gate) implements Synchronous {
            /** @var list<array<string, mixed>> */
            private array $published = [];

            public function __construct(private readonly Channel $gate) {}

            public function publish(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->gate->pop();
                $this->published[] = $payload;

                return true;
            }

            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                return true;
            }

            public function retry(Queue $queue, ?int $limit = null): void {}

            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }

            /** @return list<array<string, mixed>> */
            public function published(): array
            {
                return $this->published;
            }
        };
        $background = new Background($publisher, capacity: 1);

        Coroutine\run(function () use ($background, $gate): void {
            $background->start();
            $background->enqueue(new Queue('emails'), ['id' => 1]);
            $background->enqueue(new Queue('emails'), ['id' => 2]);

            Coroutine::create(function () use ($background): void {
                $background->enqueue(new Queue('emails'), ['id' => 3]);
            });
            Coroutine::sleep(0.01);

            Coroutine::create(function () use ($gate): void {
                Coroutine::sleep(0.01);
                $gate->push(true);
                $gate->push(true);
                $gate->push(true);
            });

            $background->shutdown();
        });

        $this->assertSame([1, 2, 3], array_column($publisher->published(), 'id'));
    }

    public function testStartRequiresCoroutineRuntime(): void
    {
        $published = [];
        $background = new Background($this->recordingPublisher($published));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be started inside a coroutine runtime');

        $background->start();
    }

    public function testDelegatesManagementCalls(): void
    {
        $published = [['id' => 1], ['id' => 2]];
        $background = new Background($this->recordingPublisher($published));

        $this->assertSame(2, $background->getQueueSize(new Queue('emails')));
    }

    public function testEnqueueThrowsWhenBufferStaysFull(): void
    {
        // A publisher that parks in publish() until the test releases the gate,
        // so the single reader can't drain the channel.
        $gate = new Channel(2);
        $publisher = new readonly class ($gate) implements Synchronous {
            public function __construct(private Channel $gate) {}

            public function publish(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->gate->pop();

                return true;
            }

            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                return true;
            }

            public function retry(Queue $queue, ?int $limit = null): void {}

            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }
        };

        $background = new Background($publisher, capacity: 1, timeout: 0.05);
        $threw = false;

        Coroutine\run(function () use ($background, $gate, &$threw): void {
            $background->start();

            $background->enqueue(new Queue('emails'), ['id' => 1]); // reader pops it, parks in publish()
            $background->enqueue(new Queue('emails'), ['id' => 2]); // fills the one slot

            try {
                $background->enqueue(new Queue('emails'), ['id' => 3]); // full + parked → back pressure
            } catch (BufferFullException) {
                $threw = true;
            }

            $gate->push(true); // release the parked publishes so the run can finish
            $gate->push(true);
            $background->shutdown();
        });

        $this->assertTrue($threw, 'a full buffer past the timeout throws BufferFullException');
    }

    public function testReportsBufferDepthGauge(): void
    {
        $telemetry = new TestTelemetry();
        $buffer = [];
        new Background($this->recordingPublisher($buffer), telemetry: $telemetry);

        // An idle buffer observes a depth of zero; the gauge is wired to the channel.
        $this->assertArrayHasKey('messaging.publisher.buffer.depth', $telemetry->observableGauges);
        $this->assertSame([0], $this->collectObservations($telemetry, 'messaging.publisher.buffer.depth'));
    }

    /**
     * Reads an observable gauge by invoking its registered callbacks.
     *
     * @return array<int, float|int>
     */
    private function collectObservations(TestTelemetry $telemetry, string $name): array
    {
        /** @var object{callbacks: array<int, \Closure>} $gauge */
        $gauge = $telemetry->observableGauges[$name];

        $values = [];
        foreach ($gauge->callbacks as $callback) {
            $callback(function (float|int $value, iterable $attributes = []) use (&$values): void {
                $values[] = $value;
            });
        }

        return $values;
    }

    /**
     * A synchronous publisher that records published payloads into the buffer.
     *
     * @param array<int, array<mixed>> $buffer
     */
    private function recordingPublisher(array &$buffer): Synchronous
    {
        return new class ($buffer) implements Synchronous {
            /**
             * @param array<int, array<mixed>> $buffer
             */
            public function __construct(private array &$buffer) {}

            public function publish(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->buffer[] = $payload;

                return true;
            }

            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                foreach ($payloads as $payload) {
                    $this->buffer[] = $payload;
                }

                return true;
            }

            public function retry(Queue $queue, ?int $limit = null): void {}

            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return \count($this->buffer);
            }
        };
    }

    /**
     * @param list<array{queue: string, priority: bool, payloads: list<array<string, mixed>>}> $batches
     */
    private function batchRecordingPublisher(array &$batches): Synchronous
    {
        return new class ($batches) implements Synchronous {
            /**
             * @param list<array{queue: string, priority: bool, payloads: list<array<string, mixed>>}> $batches
             */
            public function __construct(private array &$batches) {}

            public function publish(Queue $queue, array $payload, bool $priority = false): bool
            {
                return $this->enqueueMany($queue, [$payload], $priority);
            }

            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                $this->batches[] = [
                    'queue' => $queue->name,
                    'priority' => $priority,
                    'payloads' => $payloads,
                ];

                return true;
            }

            public function retry(Queue $queue, ?int $limit = null): void {}

            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return \count($this->batches);
            }
        };
    }
}
