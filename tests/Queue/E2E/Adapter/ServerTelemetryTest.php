<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;
use Utopia\Queue\Adapter;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

final class ServerTelemetryTest extends TestCase
{
    public function testRecordsJobWaitTimeAsMonotonicWhenPublisherClockRunsAhead(): void
    {
        // A publisher whose clock runs ahead of this consumer's stamps a
        // timestamp in the consumer's future. Every recorded wait has to stay
        // >= 0, or the cumulative histogram sum decreases and a Prometheus
        // reader takes the drop for a counter reset.
        $consumer = new ServerTelemetryMultiMessageConsumer([
            new Message([
                'pid' => 'skewed-pid',
                'queue' => 'emails',
                'timestamp' => time() + 60,
                'payload' => [],
            ]),
            new Message([
                'pid' => 'normal-pid',
                'queue' => 'emails',
                'timestamp' => time() - 1,
                'payload' => [],
            ]),
        ]);
        $adapter = new ServerTelemetryAdapter($consumer, 1, 'appwrite');
        $telemetry = new TestTelemetry();

        $server = new Server($adapter);
        $server->setTelemetry($telemetry);
        $server
            ->job('emails')
            ->inject('message')
            ->action(fn(Message $message): null => null);

        $server->start();

        /** @var object{values: array<int, float|int>} $histogram */
        $histogram = $telemetry->histograms['messaging.process.wait.duration'];

        $this->assertCount(2, $histogram->values);
        $this->assertEqualsWithDelta(0.0, $histogram->values[0], PHP_FLOAT_EPSILON);
        $this->assertGreaterThan(0.0, $histogram->values[1]);

        // The property that matters downstream: the sum a cumulative exporter
        // publishes never goes backwards, whatever the two clocks say.
        $sum = 0.0;
        foreach ($histogram->values as $value) {
            $previous = $sum;
            $sum += $value;
            $this->assertGreaterThanOrEqual($previous, $sum);
        }
    }

    public function testRecordsQueueDepth(): void
    {
        $consumer = new ServerTelemetryPublisherConsumer([3, 2], [1, 0]);
        $adapter = new ServerTelemetryAdapter($consumer, 1, 'appwrite');
        $telemetry = new TestTelemetry();

        $server = new Server($adapter);
        $server->setTelemetry($telemetry);
        $server
            ->job('emails')
            ->inject('message')
            ->action(fn(Message $message): null => null);

        $server->start();

        $this->assertArrayHasKey('messaging.queue.depth', $telemetry->observableGauges);
        $this->assertSame([3], $this->collectObservations($telemetry, 'messaging.queue.depth'));
        $this->assertSame([2], $this->collectObservations($telemetry, 'messaging.queue.depth'));

        $this->assertArrayHasKey('messaging.queue.failed.depth', $telemetry->observableGauges);
        $this->assertSame([1], $this->collectObservations($telemetry, 'messaging.queue.failed.depth'));
        $this->assertSame([0], $this->collectObservations($telemetry, 'messaging.queue.failed.depth'));
    }

    public function testSkipsQueueDepthWhenConsumerCannotReportSize(): void
    {
        $consumer = new ServerTelemetryConsumer();
        $adapter = new ServerTelemetryAdapter($consumer, 1, 'appwrite');
        $telemetry = new TestTelemetry();

        $server = new Server($adapter);
        $server->setTelemetry($telemetry);
        $server
            ->job('emails')
            ->inject('message')
            ->action(fn(Message $message): null => null);

        $server->start();

        $this->assertArrayHasKey('messaging.queue.depth', $telemetry->observableGauges);
        $this->assertSame([], $this->collectObservations($telemetry, 'messaging.queue.depth'));
        $this->assertSame([], $this->collectObservations($telemetry, 'messaging.queue.failed.depth'));
    }

    public function testSkipsQueueDepthWhenConsumerCannotReadSize(): void
    {
        $consumer = new ServerTelemetryFailingPublisherConsumer();
        $adapter = new ServerTelemetryAdapter($consumer, 1, 'appwrite');
        $telemetry = new TestTelemetry();

        $server = new Server($adapter);
        $server->setTelemetry($telemetry);
        $server
            ->job('emails')
            ->inject('message')
            ->action(fn(Message $message): null => null);

        $server->start();

        $this->assertArrayHasKey('messaging.queue.depth', $telemetry->observableGauges);
        $this->assertSame([], $this->collectObservations($telemetry, 'messaging.queue.depth'));
        $this->assertSame([], $this->collectObservations($telemetry, 'messaging.queue.failed.depth'));
        $this->assertArrayNotHasKey('messaging.queue.depth.errors', $telemetry->counters);
    }

    public function testInjectsAdapterResourcesAndContext(): void
    {
        $consumer = new ServerTelemetryConsumer();
        $adapter = new ServerTelemetryAdapter($consumer, 1, 'appwrite');
        $server = new Server($adapter);
        $injections = [];

        $server->resources()->set('resourceValue', fn(): string => 'resource');

        $server
            ->init()
            ->inject('message')
            ->action(function (Message $message) use ($server): void {
                $server->context()->set('contextValue', fn(): string => $message->getPid());
            });

        $server
            ->job('emails')
            ->inject('message')
            ->inject('resourceValue')
            ->inject('contextValue')
            ->action(function (Message $message, string $resourceValue, string $contextValue) use (&$injections): void {
                $injections = [$message->getPid(), $resourceValue, $contextValue];
            });

        $server->start();

        $this->assertSame(['test-pid', 'resource', 'test-pid'], $injections);
    }

    public function testContextDoesNotLeakBetweenMessages(): void
    {
        $consumer = new ServerTelemetryMultiMessageConsumer([
            new Message([
                'pid' => 'first-pid',
                'queue' => 'emails',
                'timestamp' => time() - 1,
                'payload' => [],
            ]),
            new Message([
                'pid' => 'second-pid',
                'queue' => 'emails',
                'timestamp' => time() - 1,
                'payload' => [],
            ]),
        ]);
        $adapter = new ServerTelemetryAdapter($consumer, 1, 'appwrite');
        $server = new Server($adapter);
        $contextValues = [];

        $server
            ->init()
            ->inject('message')
            ->action(function (Message $message) use ($server): void {
                if ($message->getPid() === 'first-pid') {
                    $server->context()->set('contextValue', fn(): string => $message->getPid());
                }
            });

        $server
            ->job('emails')
            ->action(function () use ($server, &$contextValues): void {
                $contextValues[] = $server->context()->has('contextValue')
                    ? $server->context()->get('contextValue')
                    : null;
            });

        $server->start();

        $this->assertSame(['first-pid', null], $contextValues);
    }

    /**
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
}

final class ServerTelemetryAdapter extends Adapter
{
    /**
     * @var callable[]
     */
    private array $onWorkerStart = [];

    /**
     * @var callable[]
     */
    private array $onWorkerStop = [];

    public function __construct(
        Consumer $consumer,
        int $workerNum,
        string $namespace = 'utopia-queue',
        Container $resources = new Container(),
    ) {
        parent::__construct($consumer, $workerNum, $namespace, $resources);
    }

    public function start(): self
    {
        foreach ($this->onWorkerStart as $callback) {
            $callback('0');
        }

        foreach ($this->onWorkerStop as $callback) {
            $callback('0');
        }

        return $this;
    }

    public function stop(): self
    {
        return $this;
    }

    /** Drain every message the consumer offers, then return (bounded for tests). */
    #[\Override]
    public function consume(
        callable $messageCallback,
        callable $successCallback,
        callable $errorCallback,
        array $queues,
    ): void {
        foreach ($queues as $spec) {
            $queue = $spec['queue'];
            $this->queue = $queue;
            while (($message = $this->consumer->receive($queue, 0)) instanceof Message) {
                $this->context = new Container($this->resources());
                $this->process($message, $messageCallback, $successCallback, $errorCallback);
            }
        }
    }

    public function workerStart(callable $callback): self
    {
        $this->onWorkerStart[] = $callback;
        return $this;
    }

    public function workerStop(callable $callback): self
    {
        $this->onWorkerStop[] = $callback;
        return $this;
    }
}

class ServerTelemetryConsumer implements Consumer
{
    private bool $delivered = false;

    public function receive(Queue $queue, int $timeout): ?Message
    {
        if ($this->delivered) {
            return null;
        }

        $this->delivered = true;

        return new Message([
            'pid' => 'test-pid',
            'queue' => $queue->name,
            'timestamp' => time() - 1,
            'payload' => [],
        ]);
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class ServerTelemetryMultiMessageConsumer implements Consumer
{
    /**
     * @param Message[] $messages
     */
    public function __construct(private array $messages) {}

    public function receive(Queue $queue, int $timeout): ?Message
    {
        $message = array_shift($this->messages);

        return $message instanceof Message ? $message : null;
    }

    public function commit(Queue $queue, Message $message): void {}

    public function reject(Queue $queue, Message $message): void {}

    public function close(): void {}
}

final class ServerTelemetryPublisherConsumer extends ServerTelemetryConsumer implements Synchronous
{
    /**
     * @param int[] $queueSizes
     * @param int[] $failedQueueSizes
     */
    public function __construct(private array $queueSizes, private array $failedQueueSizes = []) {}

    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        return true;
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void {}

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        if ($failedJobs) {
            return array_shift($this->failedQueueSizes) ?? 0;
        }

        return array_shift($this->queueSizes) ?? 0;
    }
}

final class ServerTelemetryFailingPublisherConsumer extends ServerTelemetryConsumer implements Synchronous
{
    public function publish(Queue $queue, array $payload, bool $priority = false): bool
    {
        return true;
    }

    public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
    {
        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void {}

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        throw new \RuntimeException('Queue size unavailable.');
    }
}
