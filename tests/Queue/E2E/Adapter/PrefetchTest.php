<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection as NatsConnection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Transport\SwooleTransport;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Queue;

final class PrefetchTest extends TestCase
{
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
                : new Nats(fn(): \Utopia\NATS\Connection => NatsConnection::connect(new ConnectionOptions(servers: 'nats://127.0.0.1:14225', transportFactory: fn(): \Utopia\NATS\Transport\SwooleTransport => new SwooleTransport())), ackWait: 0.3);
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
                : new Nats(fn(): \Utopia\NATS\Connection => NatsConnection::connect(new ConnectionOptions(servers: 'nats://127.0.0.1:14225', transportFactory: fn(): \Utopia\NATS\Transport\SwooleTransport => new SwooleTransport())));
            $queue = new Queue('shutdown_' . bin2hex(random_bytes(6)));
            $broker->enqueueMany($queue, array_fill(0, 100, ['n' => 1]));
            $adapter = new Swoole($broker, 1);
            $adapter->consume(
                function () use ($adapter, &$handled): void {
                    $handled++;
                    $adapter->stop();
                },
                fn(): null => null,
                static function ($message, $error): never {
                    throw $error;
                },
                [['queue' => $queue, 'coroutines' => 1, 'prefetch' => 100]],
            );
            \Swoole\Coroutine::sleep(0.05);
            $remaining = $broker->receive($queue, 1, 100);
            $this->assertCount(99, $remaining);
            foreach ($remaining as $message) {
                $broker->commit($queue, $message);
            }
            $broker->close();
        });
        $this->assertSame(1, $handled);
    }

}
