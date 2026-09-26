<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Queue;

final class SwooleConcurrencyTest extends TestCase
{
    private const string QUEUE = 'concurrency';
    private const string NAMESPACE = 'tests';

    public function testProcessesUpToConfiguredCoroutinesAtOnce(): void
    {
        [$processed, $maxActive] = $this->runWorker(messages: 9, coroutines: 3);

        $this->assertSame(9, $processed);
        $this->assertSame(3, $maxActive, 'concurrency is bounded by coroutines');
    }

    public function testOneCoroutineNeverOverlaps(): void
    {
        [$processed, $maxActive] = $this->runWorker(messages: 5, coroutines: 1);

        $this->assertSame(5, $processed);
        $this->assertSame(1, $maxActive);
    }

    /** Default prefetch leaves excess work available to other workers. */
    public function testDefaultPrefetchLeavesExcessWorkInBroker(): void
    {
        $broker = new MemoryConsumer();
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $processed = 0;
        $pendingDuringFirstMessage = null;

        \Swoole\Coroutine\run(function () use ($broker, $queue, &$processed, &$pendingDuringFirstMessage): void {
            $broker->add($queue, ['n' => 0]);
            $broker->add($queue, ['n' => 1]);

            $adapter = new Swoole($broker, 1, self::NAMESPACE);

            $adapter->consume(
                function () use ($adapter, $broker, &$processed, &$pendingDuringFirstMessage): void {
                    if ($processed === 0) {
                        \Swoole\Coroutine::sleep(0.1);
                        $pendingDuringFirstMessage = \count($broker->pending);
                    }

                    if (++$processed === 2) {
                        $adapter->stop();
                    }
                },
                fn(): null => null,
                fn(): null => null,
                [
                    ['queue' => $queue, 'coroutines' => 1],
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
    private function runWorker(int $messages, int $coroutines): array
    {
        $broker = new MemoryConsumer();
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        $active = 0;
        $maxActive = 0;
        $processed = 0;

        \Swoole\Coroutine\run(function () use ($broker, $queue, $messages, $coroutines, &$active, &$maxActive, &$processed): void {
            for ($i = 0; $i < $messages; $i++) {
                $broker->add($queue, ['n' => $i]);
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
                    ['queue' => $queue, 'coroutines' => $coroutines],
                ],
            );
        });

        return [$processed, $maxActive];
    }
}
