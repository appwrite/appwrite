<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

/**
 * Unit coverage for the run-to-completion KubernetesJob adapter: it drains the
 * queue and returns (so a Kubernetes Job completes) rather than blocking like
 * the long-running adapters. Uses a Consumer fake without broker storage.
 */
final class KubernetesJobAdapterTest extends TestCase
{
    private const string QUEUE = 'keda-unit';
    private const string NAMESPACE = 'tests';

    private function server(Consumer $broker, callable $action): Server
    {
        $server = new Server(new KubernetesJob($broker, 1, self::NAMESPACE));
        $server->job(self::QUEUE)->inject('message')->action($action);

        return $server;
    }

    public function testDrainsQueueThenReturns(): void
    {
        $broker = new MemoryConsumer();
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        foreach (range(1, 5) as $n) {
            $broker->add($queue, ['n' => $n]);
        }

        $processed = [];
        $this->server($broker, function (Message $message) use (&$processed): void {
            $processed[] = $message->getPayload()['n'];
        })->start();

        $this->assertSame([1, 2, 3, 4, 5], $processed, 'every queued message is processed once, in order');
        $this->assertCount(5, $broker->committed);
        $this->assertCount(0, $broker->pending, 'the queue is drained');
    }

    public function testReturnsImmediatelyWhenQueueEmpty(): void
    {
        $broker = new MemoryConsumer();

        $processed = 0;
        $this->server($broker, function () use (&$processed): void {
            $processed++;
        })->start();

        $this->assertSame(0, $processed, 'an empty queue processes nothing and the worker exits');
    }

    public function testFailedMessageIsRejectedAndDrainContinues(): void
    {
        $broker = new MemoryConsumer();
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $broker->add($queue, ['ok' => false]);
        $broker->add($queue, ['ok' => true]);

        $succeeded = 0;
        $this->server($broker, function (Message $message) use (&$succeeded): void {
            if ($message->getPayload()['ok'] === false) {
                throw new \RuntimeException('boom');
            }
            $succeeded++;
        })->start();

        $this->assertSame(1, $succeeded, 'the drain continues past a failing message');
        $this->assertCount(1, $broker->committed);
        $this->assertCount(0, $broker->pending, 'the main queue is drained');
        $this->assertCount(1, $broker->rejected, 'the failed message lands on the failed queue');
    }

    public function testProcessesEachMessageInAFreshCoroutine(): void
    {
        $broker = new MemoryConsumer();
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $broker->add($queue, ['n' => 1]);
        $broker->add($queue, ['n' => 2]);

        $lifecycleCid = null;
        $handlerCids = [];

        $adapter = new KubernetesJob($broker, 1, self::NAMESPACE);
        $adapter->workerStart(function () use ($adapter, $queue, &$lifecycleCid, &$handlerCids): void {
            $lifecycleCid = Coroutine::getCid();
            $adapter->consume(
                function () use (&$handlerCids): void {
                    $handlerCids[] = Coroutine::getCid();
                },
                fn (): null => null,
                fn (): null => null,
                [
                    ['queue' => $queue, 'coroutines' => 1],
                ],
            );
        });
        $adapter->start();

        $this->assertCount(2, $handlerCids, 'both messages are processed');
        $this->assertNotContains($lifecycleCid, $handlerCids, 'handlers must not share the drain loop coroutine stack');
        $this->assertSame(array_unique($handlerCids), $handlerCids, 'each message gets its own coroutine');
    }

    public function testCancelsStragglerCoroutinesSoTheWorkerExits(): void
    {
        $broker = new MemoryConsumer();

        $queue = new Queue(self::QUEUE, self::NAMESPACE);
        $adapter = new KubernetesJob($broker, 1, self::NAMESPACE);
        $adapter->workerStart(function () use ($adapter, $queue): void {
            Coroutine::create(function (): void {
                new Channel(1)->pop(5.0);
            });
            $adapter->consume(
                fn (): null => null,
                fn (): null => null,
                fn (): null => null,
                [
                    ['queue' => $queue, 'coroutines' => 1],
                ],
            );
        });

        $startedAt = microtime(true);
        $adapter->start();
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(2.0, $elapsed, 'a coroutine parked on a read that never returns must be cancelled, not awaited');
    }

    public function testExtendsTheMessageWhileItsHandlerRuns(): void
    {
        $broker = new HeartbeatConsumer();

        $this->server($broker, function (): void {
            Coroutine::sleep(0.3);
        })->start();

        $beats = \count($broker->extended);
        $this->assertGreaterThanOrEqual(2, $beats, 'a handler outlasting the interval keeps its message extended');
        $this->assertCount(1, $broker->committed);
        $this->assertSame([$broker->committed[0]->getPid()], array_values(array_unique($broker->extended)), 'the beats name the message being handled');

        Coroutine\run(static fn () => Coroutine::sleep(0.15));
        $this->assertCount($beats, $broker->extended, 'no beat is sent once the handler has returned');
    }

    public function testRunsTheJobWhenTheHeartbeatCannotStart(): void
    {
        $broker = new HeartbeatConsumer();
        $ran = false;

        // Room for the worker's coroutine and the handler's, none for the heartbeat.
        // Swoole reports the refused coroutine as a warning, which is the failure under test.
        Coroutine::set(['max_coroutine' => 2]);
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $this->server($broker, function () use (&$ran): void {
                $ran = true;
            })->start();
        } finally {
            restore_error_handler();
            Coroutine::set(['max_coroutine' => 100000]);
        }

        $this->assertTrue($ran, 'the handler runs without a heartbeat');
        $this->assertSame([], $broker->extended);
        $this->assertCount(1, $broker->committed, 'and the worker returns to ack it instead of waiting on a coroutine that never started');
    }

    public function testFailedBeatDoesNotFailTheJob(): void
    {
        $broker = new HeartbeatConsumer(failing: true);

        $this->server($broker, function (): void {
            Coroutine::sleep(0.15);
        })->start();

        $this->assertGreaterThanOrEqual(1, \count($broker->extended), 'the beat was attempted');
        $this->assertCount(1, $broker->committed, 'the job still commits');
        $this->assertCount(0, $broker->rejected);
    }
}

/** Hands out one message and records the extensions it is asked for. */
final class HeartbeatConsumer implements Consumer
{
    /** @var list<Message> */
    public array $committed = [];
    /** @var list<Message> */
    public array $rejected = [];
    /** @var list<string> */
    public array $extended = [];
    private bool $delivered = false;

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function receive(Queue $queue, int $timeout, int $n = 1): array
    {
        if ($this->delivered) {
            return [];
        }
        $this->delivered = true;

        return [new Message(['pid' => 'beat-1', 'queue' => $queue->name, 'timestamp' => time(), 'payload' => []])];
    }

    public function commit(Queue $queue, Message $message): void
    {
        $this->committed[] = $message;
    }

    public function reject(Queue $queue, Message $message): void
    {
        $this->rejected[] = $message;
    }

    public function extendInterval(): float
    {
        return 0.05;
    }

    public function extend(Queue $queue, Message ...$messages): void
    {
        foreach ($messages as $message) {
            $this->extended[] = $message->getPid();
        }
        if ($this->failing) {
            throw new \RuntimeException('broker unreachable');
        }
    }

    public function close(): void
    {
    }
}
