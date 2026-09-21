<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Utopia\Queue\Adapter\KubernetesJob;
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

    private function server(MemoryConsumer $broker, callable $action): Server
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
                fn(): null => null,
                fn(): null => null,
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
                fn(): null => null,
                fn(): null => null,
                fn(): null => null,
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
}
