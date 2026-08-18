<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Tests\Queue\InMemoryConnection;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

/**
 * Runtime proof that the databases queue never overlaps handlers.
 *
 * Lives in e2e (not unit): real Swoole coroutines destabilise the shared unit
 * process — see MessagingFanoutTest. These cases mirror utopia-php/queue's
 * SwooleConcurrencyTest for Appwrite's databases + combined-worker wiring.
 *
 * The databases Redis queue is globally serialised (maxCoroutines=1). That is
 * how Appwrite prevents parallel schema mutations that deadlock adapters —
 * not a per-databaseId lock inside the worker.
 */
final class WorkerConcurrencyTest extends TestCase
{
    private const string NAMESPACE = 'appwrite-concurrency';

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole') && !\extension_loaded('openswoole')) {
            $this->markTestSkipped('Swoole extension required');
        }
    }

    public function testDatabasesQueueNeverOverlaps(): void
    {
        [$processed, $maxActive] = $this->runQueues(
            queues: [
                ['name' => 'database_db_main', 'messages' => 5, 'maxCoroutines' => 1],
            ],
        );

        $this->assertSame(5, $processed['database_db_main']);
        $this->assertSame(
            1,
            $maxActive['database_db_main'],
            'databases must process exactly one job at a time to avoid schema deadlocks',
        );
    }

    public function testCombinedModeKeepsDatabasesSerialWhileOthersParallelize(): void
    {
        [$processed, $maxActive] = $this->runQueues(
            queues: [
                ['name' => 'database_db_main', 'messages' => 6, 'maxCoroutines' => 1],
                ['name' => 'v1-functions', 'messages' => 9, 'maxCoroutines' => 3],
            ],
        );

        $this->assertSame(6, $processed['database_db_main']);
        $this->assertSame(9, $processed['v1-functions']);
        $this->assertSame(1, $maxActive['database_db_main'], 'databases stays serial in combined mode');
        $this->assertSame(3, $maxActive['v1-functions'], 'sibling queues keep their own higher caps');
    }

    public function testMessageWithoutFreeDatabasesSlotStaysInBroker(): void
    {
        $connection = new InMemoryConnection();
        $broker = new Redis($connection, $connection);
        $queue = new Queue('database_db_main', self::NAMESPACE);

        $processed = 0;
        $pendingDuringFirstMessage = null;

        \Swoole\Coroutine\run(function () use ($broker, $queue, &$processed, &$pendingDuringFirstMessage): void {
            $broker->enqueue($queue, ['n' => 0]);
            $broker->enqueue($queue, ['n' => 1]);

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
                fn (): null => null,
                fn (): null => null,
                [
                    ['queue' => $queue, 'maxCoroutines' => 1],
                ],
            );
        });

        $this->assertSame(2, $processed);
        $this->assertSame(
            1,
            $pendingDuringFirstMessage,
            'the second databases job must wait in the broker, not captive in the consume loop',
        );
    }

    /**
     * @param list<array{name: string, messages: int, maxCoroutines: int}> $queues
     * @return array{0: array<string, int>, 1: array<string, int>} [processedByQueue, maxActiveByQueue]
     */
    private function runQueues(array $queues): array
    {
        $connection = new InMemoryConnection();
        $broker = new Redis($connection, $connection);

        $active = [];
        $maxActive = [];
        $processed = [];
        $total = 0;

        foreach ($queues as $spec) {
            $active[$spec['name']] = 0;
            $maxActive[$spec['name']] = 0;
            $processed[$spec['name']] = 0;
            $total += $spec['messages'];
        }

        \Swoole\Coroutine\run(function () use ($broker, $queues, $total, &$active, &$maxActive, &$processed): void {
            $specs = [];
            $done = 0;

            foreach ($queues as $spec) {
                $queue = new Queue($spec['name'], self::NAMESPACE);
                for ($i = 0; $i < $spec['messages']; $i++) {
                    $broker->enqueue($queue, ['n' => $i]);
                }
                $specs[] = ['queue' => $queue, 'maxCoroutines' => $spec['maxCoroutines']];
            }

            $adapter = new Swoole($broker, 1, self::NAMESPACE);

            $adapter->consume(
                function ($message) use ($adapter, $total, &$active, &$maxActive, &$processed, &$done): void {
                    $name = $message->getQueue();

                    $active[$name]++;
                    $maxActive[$name] = max($maxActive[$name], $active[$name]);
                    \Swoole\Coroutine::sleep(0.02);
                    $active[$name]--;

                    $processed[$name]++;
                    if (++$done === $total) {
                        $adapter->stop();
                    }
                },
                fn (): null => null,
                fn (): null => null,
                $specs,
            );
        });

        return [$processed, $maxActive];
    }
}
