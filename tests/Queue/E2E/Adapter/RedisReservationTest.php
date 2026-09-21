<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

final class RedisReservationTest extends TestCase
{
    private \Redis $redis;
    private Queue $queue;
    private Broker $broker;

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379));
        $this->queue = new Queue('reservation', '{test-' . bin2hex(random_bytes(8)) . '}');
        $this->broker = new Broker(new Connection('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)), new Connection('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)));
    }

    protected function tearDown(): void
    {
        $keys = $this->redis->keys($this->queue->namespace . '*');
        if ($keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
    }

    public function testBatchClaimsAndIndependentOutcomes(): void
    {
        foreach (range(1, 100) as $n) {
            $this->broker->publish($this->queue, ['n' => $n]);
        }
        $messages = $this->broker->receive($this->queue, 0, 100);
        $this->assertCount(100, $messages);
        $this->assertSame(range(1, 100), array_map(fn(\Utopia\Queue\Message $m) => $m->getPayload()['n'], $messages));
        $this->broker->extend($this->queue, ...$messages);
        $this->broker->reject($this->queue, array_shift($messages));
        foreach ($messages as $message) {
            $this->broker->commit($this->queue, $message);
        }
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
        $this->assertSame(1, $this->broker->getQueueSize($this->queue, true));
        $this->assertSame('99', $this->redis->get($this->key('stats') . '.success'));
        try {
            $this->broker->commit($this->queue, $messages[0]);
            self::fail('Duplicate completion must not succeed');
        } catch (\RuntimeException) {
        }
        $this->assertSame('99', $this->redis->get($this->key('stats') . '.success'));
    }

    public function testDecodeCrashLeavesRecoverableReservation(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $receive = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public function execute(string $script, array $keys, array $args): mixed
            {
                if (str_starts_with($script, '-- KEYS: reservations, reservation,')) {
                    throw new \RuntimeException('Process lost before finalization');
                }
                return parent::execute($script, $keys, $args);
            }
        };
        $broker = new Broker($receive, $receive);
        try {
            $broker->receive($this->queue, 0, 100);
            self::fail('Expected simulated crash');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $broker->getQueueSize($this->queue));
        $reservations = $this->redis->zRange($this->key('reservations'), 0, -1);
        $this->assertCount(1, $reservations);
        $this->assertSame(1, $this->redis->lLen($reservations[0]));
        $this->redis->zAdd($this->key('reservations'), 0, $reservations[0]);
        $broker->maintain();
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(['n' => 1], $this->broker->receive($this->queue, 0)[0]->getPayload());
    }

    public function testPoisonAndStaleOwnership(): void
    {
        $this->redis->lPush($this->key('queue'), 'invalid');
        $this->broker->publish($this->queue, ['n' => 1]);
        $messages = $this->broker->receive($this->queue, 0, 100);
        $this->assertCount(1, $messages);
        $this->assertSame(1, $this->redis->lLen($this->key('poison')));
        $this->redis->set($this->key('owners') . '.' . $messages[0]->getPid(), 'new-owner');
        $this->broker->extend($this->queue, ...$messages);
        $this->assertSame('new-owner', $this->redis->get($this->key('owners') . '.' . $messages[0]->getPid()));
        $this->expectException(\RuntimeException::class);
        $this->broker->commit($this->queue, $messages[0]);
    }

    public function testKilledBlockingReceiverLeavesRecoverableBytes(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires pcntl');
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            $receive = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
                public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
                {
                    $raw = parent::rightPopLeftPush($queue, $destination, $timeout);
                    posix_kill(getmypid(), SIGKILL);
                    return $raw;
                }
            };
            new Broker($receive, $receive)->receive($this->queue, 3, 100);
            exit(1);
        }
        usleep(100_000);
        $this->broker->publish($this->queue, ['survives' => true]);
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifsignaled($status));
        $reservations = $this->redis->zRange($this->key('reservations'), 0, -1);
        $this->assertCount(1, $reservations);
        $this->assertSame(1, $this->redis->lLen($reservations[0]));
        $this->redis->zAdd($this->key('reservations'), 0, $reservations[0]);
        // Register the queue without consuming its reservation, then recover it.
        $this->broker->receive($this->queue, 0);
        $this->broker->maintain();
        $message = $this->broker->receive($this->queue, 0)[0];
        $this->assertSame(['survives' => true], $message->getPayload());
        $this->broker->commit($this->queue, $message);
    }

    public function testLostSettlementReplyDoesNotDoubleCountOrReject(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $message = $this->broker->receive($this->queue, 0)[0];
        $commands = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public function execute(string $script, array $keys, array $args): mixed
            {
                parent::execute($script, $keys, $args);
                throw new \RuntimeException('Reply lost after server applied settlement');
            }
        };
        try {
            new Broker($commands, $commands)->commit($this->queue, $message);
            self::fail('Expected ambiguous reply');
        } catch (\RuntimeException) {
        }
        try {
            $this->broker->commit($this->queue, $message);
        } catch (\RuntimeException) {
        }
        $this->assertSame('1', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, true));
    }

    public function testClusterRejectsUnsafePlacementBeforeRemovingWork(): void
    {
        $connection = new \Utopia\Queue\Connection\RedisCluster(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);
        $broker = new Broker($connection, $connection);
        $queue = new Queue('cluster', 'unsafe-' . bin2hex(random_bytes(6)));
        $broker->publish($queue, ['n' => 1]);
        try {
            $broker->receive($queue, 0, 100);
            self::fail('Cross-slot claiming must fail before moving messages');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('shared hash tag', $error->getMessage());
        }
        $this->assertSame(1, $broker->getQueueSize($queue));
        $connection->remove($queue->namespace . '.queue.' . $queue->name);
        $connection->close();
    }

    public function testGroupedSettlementPreservesHealthyResultsWhenOneClaimIsInvalid(): void
    {
        $this->broker->publishMany($this->queue, array_fill(0, 3, ['n' => 1]));
        $messages = $this->broker->receive($this->queue, 0, 3);
        $claim = $this->key('owners') . '.' . $messages[1]->getPid();
        $this->redis->del($claim);
        $this->redis->lPush($claim, 'invalid');
        $commands = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public array $sizes = [];
            public function execute(string $script, array $keys, array $args): mixed
            {
                $this->sizes[] = \count($keys) / 7;
                // Let other completions arrive while the first request is in flight.
                \Swoole\Coroutine::sleep(0.01);
                return parent::execute($script, $keys, $args);
            }
        };
        $broker = new Broker($commands, $commands);
        $results = [];
        \Swoole\Coroutine\run(function () use ($broker, $messages, &$results): void {
            foreach ($messages as $index => $message) {
                \Swoole\Coroutine::create(function () use ($broker, $message, $index, &$results): void {
                    try {
                        $broker->commit($this->queue, $message);
                        $results[$index] = true;
                    } catch (\RedisException) {
                        $results[$index] = false;
                    }
                });
            }
        });
        ksort($results);
        $this->assertSame([true, false, true], $results);
        $this->assertSame(3, array_sum($commands->sizes));
        $this->assertLessThan(3, \count($commands->sizes));
        $this->assertSame('2', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame(1, $this->redis->lLen($this->key('processing')));
    }

    public function testScriptCacheLossReloadsWithoutRepeatingCompletions(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $first = $this->broker->receive($this->queue, 0)[0];
        $this->broker->commit($this->queue, $first);
        $this->broker->publish($this->queue, ['n' => 2]);
        $second = $this->broker->receive($this->queue, 0)[0];
        $this->redis->script('flush');
        $this->broker->commit($this->queue, $second);
        $this->assertSame('2', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
    }

    public function testReleaseRetainsPayloadBeyondJobTtl(): void
    {
        $queue = new Queue($this->queue->name, $this->queue->namespace, jobTtl: 1);
        $this->broker->publish($queue, ['survives' => true]);
        $message = $this->broker->receive($queue, 0)[0];
        sleep(2);
        $this->broker->extend($queue, $message);
        $this->broker->release($queue, $message);
        $again = $this->broker->receive($queue, 0)[0];
        $this->assertSame($message->getPayload(), $again->getPayload());
        $this->broker->commit($queue, $again);
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
    }

    public function testRejectedPayloadStillExpires(): void
    {
        $queue = new Queue($this->queue->name, $this->queue->namespace, jobTtl: 1);
        $this->broker->publish($queue, ['n' => 1]);
        $message = $this->broker->receive($queue, 0)[0];
        $this->broker->reject($queue, $message);
        sleep(2);
        $this->assertFalse($this->redis->get($this->key('jobs') . '.' . $message->getPid()));
        $this->assertSame(1, $this->broker->getQueueSize($queue, true));
    }

    public function testHeartbeatExpiryDoesNotPreventSettlementButTakeoverDoes(): void
    {
        foreach (['commit', 'reject'] as $operation) {
            $this->broker->publish($this->queue, ['operation' => $operation]);
            $message = $this->broker->receive($this->queue, 0)[0];
            $this->redis->del($this->key('claims') . '.' . $message->getPid());
            $this->broker->$operation($this->queue, $message);
        }
        $this->assertSame('1', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame('1', $this->redis->get($this->key('stats') . '.failed'));
        $this->broker->publish($this->queue, ['takeover' => true]);
        $old = $this->broker->receive($this->queue, 0)[0];
        $this->redis->del($this->key('claims') . '.' . $old->getPid());
        $this->assertSame(1, $this->broker->reap($this->queue, olderThan: 0));
        $new = $this->broker->receive($this->queue, 0)[0];
        try {
            $this->broker->commit($this->queue, $old);
            self::fail('Reclaimed owner must not settle');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('no longer owned', $error->getMessage());
        }
        $this->broker->extend($this->queue, $old);
        $this->broker->commit($this->queue, $new);
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
    }

    public function testClusterSettlementsAcrossNamespaces(): void
    {
        $connection = new class (['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']) extends \Utopia\Queue\Connection\RedisCluster {
            public function execute(string $script, array $keys, array $args): mixed
            {
                if (\Swoole\Coroutine::getCid() >= 0) {
                    \Swoole\Coroutine::sleep(0.01);
                }
                return parent::execute($script, $keys, $args);
            }
        };
        $broker = new Broker($connection, $connection);
        $queues = [new Queue('jobs', '{a-' . uniqid() . '}'), new Queue('jobs', '{b-' . uniqid() . '}')];
        $pending = $results = [];
        try {
            foreach ($queues as $queue) {
                $broker->publishMany($queue, array_fill(0, 3, ['ok' => true]));
                foreach ($broker->receive($queue, 0, 3) as $message) {
                    $pending[] = [$queue, $message];
                }
            }
            \Swoole\Coroutine\run(function () use ($broker, $pending, &$results): void {
                foreach ($pending as [$queue, $message]) {
                    \Swoole\Coroutine::create(function () use ($broker, $queue, $message, &$results): void {
                        try {
                            $broker->commit($queue, $message);
                            $results[] = true;
                        } catch (\Throwable $error) {
                            $results[] = $error->getMessage();
                        }
                    });
                }
            });
            $this->assertSame(array_fill(0, 6, true), $results);
            foreach ($queues as $queue) {
                $this->assertSame('3', $connection->get($queue->namespace . '.stats.jobs.success'));
            }
        } finally {
            foreach ($pending as [$queue, $message]) {
                foreach (['jobs', 'owners', 'claims'] as $kind) {
                    $connection->remove($queue->namespace . '.' . $kind . '.jobs.' . $message->getPid());
                }
            }
            foreach ($queues as $queue) {
                foreach (['queue', 'processing', 'stats', 'reservations'] as $kind) {
                    foreach (['', '.total', '.processing', '.success'] as $suffix) {
                        $connection->remove($queue->namespace . '.' . $kind . '.jobs' . $suffix);
                    }
                }
            }
            $connection->close();
        }
    }

    public function testKubernetesJobSettlesAfterHeartbeatExpiry(): void
    {
        $this->broker->publish($this->queue, ['long' => true]);
        $adapter = new \Utopia\Queue\Adapter\KubernetesJob($this->broker, 1, $this->queue->namespace);
        $success = 0;
        $errors = [];
        \Swoole\Coroutine\run(function () use ($adapter, &$success, &$errors): void {
            $adapter->consume(
                function (\Utopia\Queue\Message $message): void {
                    $this->redis->del($this->key('claims') . '.' . $message->getPid());
                },
                static function () use (&$success): void {
                    $success++;
                },
                static function ($message, \Throwable $error) use (&$errors): void {
                    $errors[] = $error->getMessage();
                },
                [['queue' => $this->queue, 'coroutines' => 1]],
            );
        });
        $this->assertSame([], $errors);
        $this->assertSame(1, $success);
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
    }

    public function testMissingReleasePayloadDoesNotDiscardOwnership(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $message = $this->broker->receive($this->queue, 0)[0];
        $this->redis->del($this->key('jobs') . '.' . $message->getPid());
        try {
            $this->broker->release($this->queue, $message);
            self::fail('Missing payload must not report successful release');
        } catch (\RedisException) {
        }
        $this->assertSame(1, $this->redis->lLen($this->key('processing')));
        $this->assertSame($message->getReceipt(), $this->redis->get($this->key('owners') . '.' . $message->getPid()));
    }

    public static function reclaimRace(): iterable
    {
        yield 'completion wins' => ['commit'];
        yield 'renewal wins' => ['extend'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reclaimRace')]
    public function testReclaimRechecksOwnershipAfterConcurrentOperation(string $operation): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $message = $this->broker->receive($this->queue, 0)[0];
        $this->redis->del($this->key('claims') . '.' . $message->getPid());
        $connection = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public ?\Closure $before = null;
            public function execute(string $script, array $keys, array $args): mixed
            {
                if (str_starts_with($script, '-- KEYS: owner,') && $this->before instanceof \Closure) {
                    ($this->before)();
                }
                return parent::execute($script, $keys, $args);
            }
        };
        $connection->before = fn() => $this->broker->$operation($this->queue, $message);
        $this->assertSame(0, new Broker($connection, $connection)->reap($this->queue, olderThan: 0));
        $this->assertSame(0, $this->broker->getQueueSize($this->queue));
        if ($operation === 'extend') {
            $this->broker->commit($this->queue, $message);
        }
        $this->assertSame('1', $this->redis->get($this->key('stats') . '.success'));
    }

    private function key(string $kind): string
    {
        return $this->queue->namespace . '.' . $kind . '.' . $this->queue->name;
    }
}
