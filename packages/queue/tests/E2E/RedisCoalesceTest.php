<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Connection\RedisCluster;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Outcome;
use Utopia\Queue\Queue;

final class RedisCoalesceTest extends RedisTestCase
{
    private const string QUEUE = 'coalesce';
    private const string KEY = 'project-1';

    private Redis $broker;
    private Queue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new Redis($this->connection, $this->connection);
        $this->queue = new Queue(self::QUEUE, $this->namespace);
    }

    private function marker(string $key = self::KEY, string $queue = self::QUEUE): string
    {
        return $this->namespace . '.pending.' . $queue . '.' . bin2hex($key);
    }

    private function receive(): Message
    {
        $message = $this->broker->receive($this->queue, 0)[0] ?? null;
        $this->assertInstanceOf(Message::class, $message);

        return $message;
    }

    public function testCoalescesWhilePending(): void
    {
        $first = $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $second = $this->broker->coalesce($this->queue, ['n' => 2], self::KEY);

        $this->assertSame(Outcome::Published, $first);
        $this->assertSame(Outcome::Coalesced, $second);
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));
        $message = $this->receive();
        $this->assertSame(['n' => 1], $message->getPayload());
        $this->assertSame(self::KEY, $message->getKey());
        $this->assertSame($message->getPid(), $this->redis->get($this->marker()));
        $this->assertGreaterThan(0, $this->redis->ttl($this->marker()));
    }

    public function testCommitFreesTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $message = $this->receive();
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY), 'a running message still holds its key');

        $this->broker->commit($this->queue, $message);

        $this->assertSame(0, $this->redis->exists($this->marker()));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 3], self::KEY));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function rejections(): iterable
    {
        yield 'ordinary' => [false];
        yield 'terminal' => [true];
    }

    #[DataProvider('rejections')]
    public function testRejectFreesTheKey(bool $terminal): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $message = $this->receive();

        $this->broker->reject($this->queue, $message->terminal($terminal));

        $this->assertSame(0, $this->redis->exists($this->marker()));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
    }

    public function testReleaseKeepsTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $message = $this->receive();

        $this->broker->release($this->queue, $message);

        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
        $released = $this->receive();
        $this->assertSame($message->getPid(), $released->getPid());
        $this->assertSame(self::KEY, $released->getKey());
        $this->broker->commit($this->queue, $released);
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 3], self::KEY));
    }

    public function testReapRequeueKeepsTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $stranded = $this->receive();
        $this->expire('.claims.*');

        $requeued = $this->broker->reap($this->queue, olderThan: 0);

        $this->assertSame(1, $requeued);
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
        $message = $this->receive();
        $this->assertNotSame($stranded->getPid(), $message->getPid());
        $this->assertSame($message->getPid(), $this->redis->get($this->marker()), 'the hold follows the requeued copy');
        $this->assertSame(self::KEY, $message->getKey());
        $this->assertSame(1, $message->getAttempts());
        $this->assertGreaterThan(0, $this->redis->ttl($this->marker()), 'the hold keeps its expiry');
        $this->broker->commit($this->queue, $message);
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 3], self::KEY));
    }

    public function testReapToDeadFreesTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $this->receive();
        $this->expire('.claims.*');

        $requeued = $this->broker->reap($this->queue, olderThan: 0, maxAttempts: 0);

        $this->assertSame(0, $requeued);
        $this->assertSame(1, $this->connection->listSize($this->namespace . '.dead.' . self::QUEUE));
        $this->assertSame(0, $this->redis->exists($this->marker()));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
    }

    public function testRecoveredReservationKeepsTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $receive = new CrashingConnection(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379));
        try {
            $crashed = new Redis($receive, $receive);
            try {
                $crashed->receive($this->queue, 0);
                self::fail('Expected simulated crash');
            } catch (\RuntimeException) {
            }
            $registry = $this->namespace . '.reservations.' . self::QUEUE;
            foreach ($this->redis->zRange($registry, 0, -1) as $reservation) {
                $this->redis->zAdd($registry, 0, $reservation);
            }

            $crashed->maintain();
        } finally {
            $receive->close();
        }

        $this->assertSame(1, $this->broker->getQueueSize($this->queue), 'the reservation is back on the queue');
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
        $message = $this->receive();
        $this->assertSame(['n' => 1], $message->getPayload());
        $this->assertSame($message->getPid(), $this->redis->get($this->marker()));
    }

    public function testExpiredMarkerFreesTheKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);

        $this->expire('.pending.*');

        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
        $this->assertSame(2, $this->broker->getQueueSize($this->queue));
    }

    public function testStaleSettleDoesNotFreeANewerHolder(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $stale = $this->receive();
        $this->expire('.pending.*');
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));

        $this->broker->commit($this->queue, $stale);

        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 3], self::KEY));
        $this->assertSame(1, $this->broker->getQueueSize($this->queue));
    }

    public function testQueueKeyTtlBoundsTheHold(): void
    {
        $queue = new Queue(self::QUEUE, $this->namespace, keyTtl: 5);

        $this->broker->coalesce($queue, ['n' => 1], self::KEY);

        $ttl = $this->redis->ttl($this->marker());
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(5, $ttl);
    }

    public function testReapAfterTheHoldExpiredDoesNotRecreateIt(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $this->receive();
        $this->expire('.claims.*');
        $this->expire('.pending.*');

        $requeued = $this->broker->reap($this->queue, olderThan: 0);

        $this->assertSame(1, $requeued);
        $this->assertSame(0, $this->redis->exists($this->marker()), 'the requeued copy does not take a hold');
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
        $this->assertSame(2, $this->broker->getQueueSize($this->queue));
    }

    public function testLateCommitAfterReapKeepsTheRequeuedHold(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $stranded = $this->receive();
        $this->expire('.claims.*');
        $this->assertSame(1, $this->broker->reap($this->queue, olderThan: 0));
        $holder = $this->redis->get($this->marker());

        try {
            $this->broker->commit($this->queue, $stranded);
            self::fail('A reaped delivery must not settle');
        } catch (\RuntimeException) {
        }

        $this->assertSame($holder, $this->redis->get($this->marker()), 'the hold still belongs to the requeued copy');
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
    }

    public function testMixedSettlementsShareOneFlush(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $this->broker->coalesce($this->queue, ['n' => 2], self::KEY);
        $this->broker->publish($this->queue, ['n' => 3]);
        $messages = $this->broker->receive($this->queue, 0, 3);
        $this->assertSame([null, self::KEY, null], array_map(fn (Message $message): ?string => $message->getKey(), $messages));
        $commands = new SlowConnection(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379));
        $results = [];
        try {
            $broker = new Redis($commands, $commands);
            \Swoole\Coroutine\run(function () use ($broker, $messages, &$results): void {
                foreach ($messages as $index => $message) {
                    \Swoole\Coroutine::create(function () use ($broker, $message, $index, &$results): void {
                        $broker->commit($this->queue, $message);
                        $results[$index] = true;
                    });
                }
            });
        } finally {
            $commands->close();
        }

        ksort($results);
        $this->assertSame([true, true, true], $results);
        $this->assertSame([8, 16], $commands->keys, 'the keyed and the trailing unkeyed settlement share the second flush');
        $this->assertSame(0, $this->redis->exists($this->marker()), 'the keyed settlement freed its hold');
        $this->assertSame(0, $this->connection->listSize($this->namespace . '.processing.' . self::QUEUE), 'the unkeyed settlements completed');
        $this->assertSame('3', $this->redis->get($this->namespace . '.stats.' . self::QUEUE . '.success'));
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 4], self::KEY));
    }

    public function testDottedKeysDoNotCollideAcrossQueues(): void
    {
        $first = $this->broker->coalesce(new Queue('a', $this->namespace), ['n' => 1], 'b.c');
        $second = $this->broker->coalesce(new Queue('a.b', $this->namespace), ['n' => 2], 'c');

        $this->assertSame(Outcome::Published, $first);
        $this->assertSame(Outcome::Published, $second);
    }

    public function testKeysAreIndependent(): void
    {
        $first = $this->broker->coalesce($this->queue, ['n' => 1], 'project-1');
        $second = $this->broker->coalesce($this->queue, ['n' => 2], 'project-2');
        $other = $this->broker->coalesce(new Queue('other', $this->namespace), ['n' => 3], 'project-1');

        $this->assertSame(Outcome::Published, $first);
        $this->assertSame(Outcome::Published, $second);
        $this->assertSame(Outcome::Published, $other);
        $this->assertSame(2, $this->broker->getQueueSize($this->queue));
    }

    public function testUnkeyedPublishIsUnchanged(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);

        $this->assertTrue($this->broker->publish($this->queue, ['n' => 2]));
        $this->assertTrue($this->broker->publishMany($this->queue, [['n' => 3]]));

        $this->assertSame(3, $this->broker->getQueueSize($this->queue), 'unkeyed publishes queue while a key is held');
        $raw = $this->connection->listRange($this->namespace . '.queue.' . self::QUEUE, 1, 0)[0];
        $this->assertIsString($raw);
        $envelope = new Json()->decode($raw);
        $this->assertIsArray($envelope);
        $this->assertSame(['pid', 'queue', 'timestamp', 'payload'], array_keys($envelope));
        $this->assertSame(['n' => 3], $envelope['payload']);
        $keys = array_map(fn (Message $message): ?string => $message->getKey(), $this->broker->receive($this->queue, 0, 3));
        $this->assertSame([self::KEY, null, null], $keys);
    }

    public function testRetriedMessageDoesNotHoldItsKey(): void
    {
        $this->broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $message = $this->receive();
        $this->broker->reject($this->queue, $message);
        $job = $this->namespace . '.jobs.' . self::QUEUE . '.' . $message->getPid();
        $codec = new Json();
        $stored = $this->redis->get($job);
        $this->assertIsString($stored);
        $envelope = $codec->decode($stored);
        $this->assertIsArray($envelope);
        $this->assertIsInt($envelope['timestamp']);
        $envelope['timestamp'] -= 60;
        $this->redis->set($job, $codec->encode($envelope));

        $this->broker->retry($this->queue);

        $retried = $this->receive();
        $this->assertSame(['n' => 1], $retried->getPayload());
        $this->assertNull($retried->getKey());
        $this->assertSame(Outcome::Published, $this->broker->coalesce($this->queue, ['n' => 2], self::KEY));
        $this->broker->commit($this->queue, $retried);
        $this->assertSame(Outcome::Coalesced, $this->broker->coalesce($this->queue, ['n' => 3], self::KEY), 'settling the retried copy leaves the new holder alone');
    }

    public function testCoalescesWithIgbinary(): void
    {
        if (!\function_exists('igbinary_serialize')) {
            self::markTestSkipped('igbinary is not installed');
        }
        $broker = new Redis($this->connection, $this->connection, new Igbinary());

        $first = $broker->coalesce($this->queue, ['n' => 1], self::KEY);
        $second = $broker->coalesce($this->queue, ['n' => 2], self::KEY);

        $this->assertSame(Outcome::Published, $first);
        $this->assertSame(Outcome::Coalesced, $second);
        $message = $broker->receive($this->queue, 0)[0];
        $this->assertSame(self::KEY, $message->getKey());
        $broker->commit($this->queue, $message);
        $this->assertSame(Outcome::Published, $broker->coalesce($this->queue, ['n' => 3], self::KEY));
    }

    public function testEmptyKeyIsRefused(): void
    {
        $connection = new Connection('127.0.0.1', 1);
        $broker = new Redis($connection, $connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot coalesce with an empty key.');

        $broker->coalesce($this->queue, ['n' => 1], '');
    }

    public function testCoalescesOnACluster(): void
    {
        $connection = new RedisCluster(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);
        $broker = new Redis($connection, $connection);
        $queue = new Queue(self::QUEUE, '{c-' . bin2hex(random_bytes(6)) . '}');
        $prefix = $queue->namespace;
        $marker = "{$prefix}.pending.{$queue->name}." . bin2hex(self::KEY);
        $pids = [];
        $receive = function () use ($broker, $queue, &$pids): Message {
            $message = $broker->receive($queue, 0)[0];
            $pids[] = $message->getPid();
            return $message;
        };
        try {
            $this->assertSame(Outcome::Published, $broker->coalesce($queue, ['n' => 1], self::KEY));
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($queue, ['n' => 2], self::KEY));

            $released = $receive();
            $this->assertSame(self::KEY, $released->getKey());
            $broker->release($queue, $released);
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($queue, ['n' => 3], self::KEY), 'release keeps the hold');

            $broker->reject($queue, $receive());
            $this->assertNull($connection->get($marker), 'reject frees the hold');

            $this->assertSame(Outcome::Published, $broker->coalesce($queue, ['n' => 4], self::KEY));
            $connection->remove("{$prefix}.claims.{$queue->name}." . $receive()->getPid());
            $this->assertSame(1, $broker->reap($queue, olderThan: 0));
            $this->assertSame(Outcome::Coalesced, $broker->coalesce($queue, ['n' => 5], self::KEY), 'reap moves the hold to the requeued copy');

            $requeued = $receive();
            $this->assertSame($requeued->getPid(), $connection->get($marker));
            $connection->remove("{$prefix}.claims.{$queue->name}.{$requeued->getPid()}");
            $this->assertSame(0, $broker->reap($queue, olderThan: 0, maxAttempts: 0));
            $this->assertNull($connection->get($marker), 'reap to dead frees the hold');

            $this->assertSame(Outcome::Published, $broker->coalesce($queue, ['n' => 6], self::KEY));
            $broker->commit($queue, $receive());
            $this->assertSame(Outcome::Published, $broker->coalesce($queue, ['n' => 7], self::KEY), 'commit frees the hold');
        } finally {
            $keys = [$marker, "{$prefix}.reap-cursor.{$queue->name}"];
            foreach (['queue', 'processing', 'reservations', 'failed', 'dead'] as $list) {
                $keys[] = "{$prefix}.{$list}.{$queue->name}";
            }
            foreach (['total', 'processing', 'success', 'failed'] as $stat) {
                $keys[] = "{$prefix}.stats.{$queue->name}.{$stat}";
            }
            foreach ($pids as $pid) {
                foreach (['jobs', 'claims', 'owners'] as $kind) {
                    $keys[] = "{$prefix}.{$kind}.{$queue->name}.{$pid}";
                }
            }
            foreach ($keys as $key) {
                $connection->remove($key);
            }
            $connection->close();
        }
    }
}
