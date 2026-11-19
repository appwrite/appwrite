<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Transport\SwooleTransport;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

final class PrefetchTest extends TestCase
{
    public function testStreamingPullProgressesThroughBrokerBackpressure(): void
    {
        $received = $attempts = [];
        $remaining = null;
        \Swoole\Coroutine\run(static function () use (&$received, &$attempts, &$remaining): void {
            $broker = new Nats(fn (): NatsConnection => NatsConnection::connect(new ConnectionOptions(
                servers: 'nats://127.0.0.1:14225',
                transportFactory: fn (): SwooleTransport => new SwooleTransport(),
            )), ackWait: 0.2, maxAckPending: 2);
            $queue = new Queue('streaming_' . bin2hex(random_bytes(6)));
            $broker->publishMany($queue, array_map(static fn (int $id): array => ['id' => $id], range(1, 5)));

            // The broker cannot deliver this whole pull before earlier messages are acked.
            $broker->receiveEach($queue, 1, 5, static function (Message $message) use ($broker, $queue, &$received, &$attempts): void {
                $received[] = $message->getPayload()['id'];
                $attempts[] = $message->getAttempts();
                $broker->commit($queue, $message);
            });

            $remaining = $broker->getQueueSize($queue);
            $broker->close();
        });
        $this->assertSame(range(1, 5), $received);
        $this->assertSame(array_fill(0, 5, 0), $attempts);
        $this->assertSame(0, $remaining);
    }

    public function testSharedConnectionCallbackCanAcknowledge(): void
    {
        $received = [];
        $remaining = null;
        \Swoole\Coroutine\run(static function () use (&$received, &$remaining): void {
            $broker = new Nats(NatsConnection::connect(new ConnectionOptions(
                servers: 'nats://127.0.0.1:14225',
                transportFactory: fn (): SwooleTransport => new SwooleTransport(),
            )));
            $queue = new Queue('shared_' . bin2hex(random_bytes(6)));
            try {
                $broker->publishMany($queue, [['id' => 1], ['id' => 2]]);
                $broker->receiveEach($queue, 1, 2, static function (Message $message) use ($broker, $queue, &$received): void {
                    $broker->commit($queue, $message);
                    $received[] = $message->getPayload()['id'];
                });
                $remaining = $broker->getQueueSize($queue);
            } finally {
                $broker->close();
            }
        });
        $this->assertSame([1, 2], $received);
        $this->assertSame(0, $remaining);
    }

    /** @return \Iterator<string, array{bool, class-string<\RuntimeException>}> */
    public static function callbackFailures(): \Iterator
    {
        foreach ([false, true] as $factory) {
            foreach ([ConnectionException::class, ProtocolException::class, \RuntimeException::class] as $exception) {
                yield ($factory ? 'factory ' : 'shared ') . $exception => [$factory, $exception];
            }
        }
    }

    /** @param class-string<\RuntimeException> $exception */
    #[DataProvider('callbackFailures')]
    public function testCallbackFailurePropagates(bool $factory, string $exception): void
    {
        $failure = new $exception('callback failed');
        $caught = $next = null;
        $calls = 0;
        \Swoole\Coroutine\run(static function () use ($factory, $failure, &$caught, &$next, &$calls): void {
            $connect = fn (): NatsConnection => NatsConnection::connect(new ConnectionOptions(
                servers: 'nats://127.0.0.1:14225',
                transportFactory: fn (): SwooleTransport => new SwooleTransport(),
            ));
            $broker = new Nats($factory ? $connect : $connect());
            $queue = new Queue('callback_' . bin2hex(random_bytes(6)));
            $received = null;
            try {
                $broker->publish($queue, ['id' => 1]);
                try {
                    $broker->receiveEach($queue, 1, 1, static function (Message $message) use ($failure, &$received, &$calls): never {
                        $received = $message;
                        $calls++;
                        throw $failure;
                    });
                } catch (\Throwable $error) {
                    $caught = $error;
                }
                if ($received !== null) {
                    $broker->commit($queue, $received);
                }
                // Failure must release the receive lock and leave the connection usable.
                $broker->publish($queue, ['id' => 2]);
                foreach ($broker->receive($queue, 1) as $message) {
                    $next = $message->getPayload()['id'];
                    $broker->commit($queue, $message);
                }
            } finally {
                $broker->close();
            }
        });
        $this->assertSame($failure, $caught);
        $this->assertSame(1, $calls);
        $this->assertSame(2, $next);
    }

    public static function brokers(): \Iterator
    {
        yield ['redis'];
        yield ['nats'];
    }

    #[DataProvider('brokers')]
    public function testPrefetchOfOneHundredWithOneCoroutine(string $name): void
    {
        $errors = [];
        $done = $active = $peak = 0;
        \Swoole\Coroutine\run(function () use ($name, &$errors, &$done, &$active, &$peak): void {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
            $broker = $name === 'redis'
                ? new Redis(new RedisConnection('127.0.0.1', 16379), new Locking(new RedisConnection('127.0.0.1', 16379)))
                : new Nats(fn (): \Utopia\NATS\Connection => NatsConnection::connect(new ConnectionOptions(servers: 'nats://127.0.0.1:14225', transportFactory: fn (): \Utopia\NATS\Transport\SwooleTransport => new SwooleTransport())), ackWait: 0.3);
            $queue = new Queue('prefetch_' . bin2hex(random_bytes(6)));
            foreach (range(1, 100) as $n) {
                $broker->publish($queue, ['n' => $n]);
            }
            $adapter = new Swoole($broker, 1);
            $adapter->consume(function () use (&$active, &$peak): void {
                $peak = max($peak, ++$active);
                \Swoole\Coroutine::sleep(0.005);
                $active--;
            }, function () use (&$done, $adapter): void {
                if (++$done === 100) {
                    $adapter->stop();
                }
            }, function ($message, $error) use (&$errors, $adapter): void {
                $errors[] = $error->getMessage();
                $adapter->stop();
            }, [['queue' => $queue, 'coroutines' => 1, 'prefetch' => 100]]);
            $this->assertSame([], $broker->receive($queue, 0, 100), 'prefetched messages were renewed and acknowledged, not redelivered');
            $broker->close();
        });
        $this->assertSame([], $errors);
        $this->assertSame(100, $done);
        $this->assertSame(1, $peak);
    }
    #[DataProvider('brokers')]
    public function testShutdownReturnsWorkThatNeverStarted(string $name): void
    {
        $handled = 0;
        \Swoole\Coroutine\run(function () use ($name, &$handled): void {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
            $broker = $name === 'redis'
                ? new Redis(new RedisConnection('127.0.0.1', 16379), new Locking(new RedisConnection('127.0.0.1', 16379)))
                // One delivery allowed: a hand-back that spent an attempt would
                // dead-letter all 99 on their next arrival instead of running them.
                : new Nats(fn (): \Utopia\NATS\Connection => NatsConnection::connect(new ConnectionOptions(servers: 'nats://127.0.0.1:14225', transportFactory: fn (): \Utopia\NATS\Transport\SwooleTransport => new SwooleTransport())), maxDeliver: 1);
            $queue = new Queue('shutdown_' . bin2hex(random_bytes(6)));
            $broker->publishMany($queue, array_fill(0, 100, ['n' => 1]));
            $adapter = new Swoole($broker, 1);
            $adapter->consume(
                function () use ($adapter, &$handled): void {
                    $handled++;
                    $adapter->stop();
                },
                fn (): null => null,
                static function ($message, $error): never {
                    throw $error;
                },
                [['queue' => $queue, 'coroutines' => 1, 'prefetch' => 100]],
            );
            \Swoole\Coroutine::sleep(0.3);
            $remaining = $broker->receive($queue, 1, 100);
            $this->assertCount(99, $remaining);
            foreach ($remaining as $message) {
                $this->assertSame(0, $message->getAttempts(), 'handing back work that never started spends no attempt');
                $broker->commit($queue, $message);
            }
            $this->assertSame(0, $broker->getQueueSize($queue, true), 'nothing that never started was dead-lettered');
            $broker->close();
        });
        $this->assertSame(1, $handled);
    }

}
