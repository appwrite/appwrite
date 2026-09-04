<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

final class SwooleConcurrencyTest extends TestCase
{
    private const string QUEUE = 'concurrency';
    private const string NAMESPACE = 'tests';

    public function testProcessesUpToMaxCoroutinesAtOnce(): void
    {
        [$processed, $maxActive] = $this->runWorker(messages: 9, maxCoroutines: 3);

        $this->assertSame(9, $processed);
        $this->assertSame(3, $maxActive, 'concurrency is bounded by maxCoroutines');
    }

    public function testOneCoroutineNeverOverlaps(): void
    {
        [$processed, $maxActive] = $this->runWorker(messages: 5, maxCoroutines: 1);

        $this->assertSame(5, $processed);
        $this->assertSame(1, $maxActive);
    }

    /**
     * A message the consumer has no free slot to run must stay in the broker,
     * where an idle sibling consumer can take it. Receiving it first and then
     * waiting for a slot held it captive in the consume loop for as long as the
     * in-flight handler ran — unprocessed, invisible, and lost outright on a
     * non-graceful stop. A dedicated-database update sat exactly there for the
     * length of a 22-minute edge rebuild while a second worker process idled,
     * leaving the database stuck `scaling` past every test deadline.
     */
    public function testMessageWithoutFreeSlotStaysInBroker(): void
    {
        $connection = new InMemoryConnection();
        $broker = new Redis($connection, $connection);
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $processed = 0;
        $pendingDuringFirstMessage = null;

        \Swoole\Coroutine\run(function () use ($broker, $queue, &$processed, &$pendingDuringFirstMessage): void {
            $broker->publish($queue, ['n' => 0]);
            $broker->publish($queue, ['n' => 1]);

            $adapter = new Swoole($broker, 1, self::NAMESPACE);

            $adapter->consume(
                function () use ($adapter, $broker, $queue, &$processed, &$pendingDuringFirstMessage): void {
                    if ($processed === 0) {
                        \Swoole\Coroutine::sleep(0.1);
                        $pendingDuringFirstMessage = $broker->getQueueSize($queue);
                    }

                    if (++$processed === 2) {
                        $adapter->stop();
                    }
                },
                fn(): null => null,
                fn(): null => null,
                [
                    ['queue' => $queue, 'maxCoroutines' => 1],
                ],
            );
        });

        $this->assertSame(2, $processed);
        $this->assertSame(1, $pendingDuringFirstMessage, 'the second message must wait in the broker, not in the consume loop');
    }

    /**
     * Run the consume loop until $messages are processed; return the count and
     * the peak concurrency observed.
     *
     * @return array{0: int, 1: int} [processed, maxActive]
     */
    private function runWorker(int $messages, int $maxCoroutines): array
    {
        $connection = new InMemoryConnection();
        $broker = new Redis($connection, $connection);
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $active = 0;
        $maxActive = 0;
        $processed = 0;

        \Swoole\Coroutine\run(function () use ($broker, $queue, $messages, $maxCoroutines, &$active, &$maxActive, &$processed): void {
            for ($i = 0; $i < $messages; $i++) {
                $broker->publish($queue, ['n' => $i]);
            }

            $adapter = new Swoole($broker, 1, self::NAMESPACE);

            $adapter->consume(
                function () use ($adapter, $messages, &$active, &$maxActive, &$processed): void {
                    $active++;
                    $maxActive = max($maxActive, $active);
                    \Swoole\Coroutine::sleep(0.02);
                    $active--;

                    if (++$processed === $messages) {
                        $adapter->stop();
                    }
                },
                fn(): null => null,
                fn(): null => null,
                [
                    ['queue' => $queue, 'maxCoroutines' => $maxCoroutines],
                ],
            );
        });

        return [$processed, $maxActive];
    }
}
