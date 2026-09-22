<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;
use Utopia\System\System;

/**
 * Runtime proof that the databases queue never overlaps handlers.
 *
 * Lives in e2e (not unit): real Swoole coroutines destabilise the shared unit
 * process — see MessagingFanoutTest. These cases mirror utopia-php/queue's
 * SwooleConcurrencyTest for Appwrite's databases + combined-worker wiring.
 *
 * The databases queue is serialised within each worker (coroutines=1). That is
 * how Appwrite prevents parallel schema mutations that deadlock adapters —
 * not a per-databaseId lock inside the worker.
 */
final class WorkerConcurrencyTest extends TestCase
{
    private string $namespace;

    private \Redis $redis;

    private int $hookFlags;

    protected function setUp(): void
    {
        if (!\extension_loaded('swoole') && !\extension_loaded('openswoole')) {
            $this->markTestSkipped('Swoole extension required');
        }

        $this->hookFlags = \Swoole\Runtime::getHookFlags();
        \Swoole\Runtime::enableCoroutine();
        $this->namespace = 'appwrite-concurrency-' . bin2hex(random_bytes(8));
        $this->redis = new \Redis();
        $this->redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));
        $password = System::getEnv('_APP_REDIS_PASS', '');
        if ($password !== '') {
            $user = System::getEnv('_APP_REDIS_USER', '');
            $this->redis->auth($user !== '' ? [$user, $password] : $password);
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }
        $keys = $this->redis->keys($this->namespace . '.*');
        if ($keys !== []) {
            $this->redis->del(...$keys);
        }
        $this->redis->close();
        \Swoole\Runtime::enableCoroutine($this->hookFlags);
    }

    private function broker(): Redis
    {
        $connection = [
            System::getEnv('_APP_REDIS_HOST', 'redis'),
            (int) System::getEnv('_APP_REDIS_PORT', '6379'),
            System::getEnv('_APP_REDIS_USER', ''),
            System::getEnv('_APP_REDIS_PASS', ''),
        ];

        return new Redis(new Connection(...$connection), new Locking(new Connection(...$connection)));
    }

    public function testDatabasesQueueNeverOverlaps(): void
    {
        [$processed, $maxActive] = $this->runQueues(
            queues: [
                ['name' => 'database_db_main', 'messages' => 5, 'coroutines' => 1],
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
                ['name' => 'database_db_main', 'messages' => 6, 'coroutines' => 1],
                ['name' => 'v1-functions', 'messages' => 9, 'coroutines' => 3],
            ],
        );

        $this->assertSame(6, $processed['database_db_main']);
        $this->assertSame(9, $processed['v1-functions']);
        $this->assertSame(1, $maxActive['database_db_main'], 'databases stays serial in combined mode');
        $this->assertSame(3, $maxActive['v1-functions'], 'sibling queues keep their own higher caps');
    }

    /**
     * A batched sibling must not cost the databases queue its serialisation.
     *
     * Batching changes how many messages one receive claims, not how many run at
     * once -- the cap still decides that. The way it could go wrong is at the
     * boundary between queues: a batch claimed for one queue must not occupy the
     * slots another one is serialised by, and must not lose or duplicate the
     * messages it claimed. So this is the combined-mode case with the parallel
     * sibling batching, asserted on what came out rather than on how it was
     * fetched.
     */
    public function testABatchedSiblingLeavesTheDatabasesQueueSerial(): void
    {
        [$processed, $maxActive] = $this->runQueues(
            queues: [
                ['name' => 'database_db_main', 'messages' => 6, 'coroutines' => 1],
                ['name' => 'v1-functions', 'messages' => 9, 'coroutines' => 3, 'prefetch' => 9],
            ],
        );

        $this->assertSame(6, $processed['database_db_main']);
        $this->assertSame(9, $processed['v1-functions'], 'a batch must deliver every message it claimed, exactly once');
        $this->assertSame(1, $maxActive['database_db_main'], 'databases stays serial beside a batching sibling');
        $this->assertSame(3, $maxActive['v1-functions'], 'prefetch above concurrency does not increase the handler cap');
    }

    public function testMessageWithoutFreeDatabasesSlotStaysInBroker(): void
    {
        $broker = $this->broker();
        $queue = new Queue('database_db_main', $this->namespace);

        $processed = 0;
        $pendingDuringFirstMessage = null;
        $errors = [];

        \Swoole\Coroutine\run(function () use ($broker, $queue, &$processed, &$pendingDuringFirstMessage, &$errors): void {
            $broker->publish($queue, ['n' => 0]);
            $broker->publish($queue, ['n' => 1]);

            $adapter = new Swoole($this->broker(...), 1, $this->namespace);

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
                function ($error) use ($adapter, &$errors): void {
                    $errors[] = $error;
                    $adapter->stop();
                },
                [
                    ['queue' => $queue, 'coroutines' => 1],
                ],
            );
        });

        $this->assertSame([], $errors);
        $this->assertSame(2, $processed);
        $this->assertSame(
            1,
            $pendingDuringFirstMessage,
            'the second databases job must wait in the broker, not captive in the consume loop',
        );
    }

    public function testShutdownReturnsUnstartedMessagesWithoutRetrying(): void
    {
        $broker = $this->broker();
        $queue = new Queue('v1-functions', $this->namespace);
        $handled = [];
        $errors = [];

        \Swoole\Coroutine\run(function () use ($broker, $queue, &$handled, &$errors): void {
            for ($i = 0; $i < 8; $i++) {
                $broker->publish($queue, ['n' => $i]);
            }
            $adapter = new Swoole($this->broker(...), 1, $this->namespace);
            $adapter->consume(
                function ($message) use ($adapter, &$handled): void {
                    $handled[] = $message->getPayload()['n'];
                    $adapter->stop();
                },
                fn (): null => null,
                function ($error) use ($adapter, &$errors): void {
                    $errors[] = $error;
                    $adapter->stop();
                },
                [['queue' => $queue, 'coroutines' => 1, 'prefetch' => 8]],
            );

            $this->assertSame([], $errors);
            $this->assertSame([0], $handled);
            $this->assertSame(7, $broker->getQueueSize($queue));
            $messages = $broker->receive($queue, 1, 8);
            $this->assertCount(7, $messages);
            $remaining = [];
            foreach ($messages as $message) {
                $remaining[] = $message->getPayload()['n'];
                $this->assertSame(0, $message->getAttempts());
                $broker->commit($queue, $message);
            }
            sort($remaining);
            $this->assertSame(range(1, 7), $remaining);
            $this->assertSame(0, $broker->getQueueSize($queue));
        });
    }

    /**
     * @param list<array{name: string, messages: int, coroutines: int, prefetch?: int}> $queues
     * @return array{0: array<string, int>, 1: array<string, int>} [processedByQueue, maxActiveByQueue]
     */
    private function runQueues(array $queues): array
    {
        $broker = $this->broker();

        $active = [];
        $maxActive = [];
        $processed = [];
        $total = 0;
        $errors = [];

        foreach ($queues as $spec) {
            $active[$spec['name']] = 0;
            $maxActive[$spec['name']] = 0;
            $processed[$spec['name']] = 0;
            $total += $spec['messages'];
        }

        \Swoole\Coroutine\run(function () use ($broker, $queues, $total, &$active, &$maxActive, &$processed, &$errors): void {
            $specs = [];
            $done = 0;

            foreach ($queues as $spec) {
                $queue = new Queue($spec['name'], $this->namespace);
                for ($i = 0; $i < $spec['messages']; $i++) {
                    $broker->publish($queue, ['n' => $i]);
                }
                $specs[] = [
                    'queue' => $queue,
                    'coroutines' => $spec['coroutines'],
                    'prefetch' => $spec['prefetch'] ?? $spec['coroutines'],
                    'consumer' => $this->broker(),
                ];
            }

            $adapter = new Swoole($this->broker(...), 1, $this->namespace);

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
                function ($error) use ($adapter, &$errors): void {
                    $errors[] = $error;
                    $adapter->stop();
                },
                $specs,
            );
        });

        $this->assertSame([], $errors);

        return [$processed, $maxActive];
    }
}
