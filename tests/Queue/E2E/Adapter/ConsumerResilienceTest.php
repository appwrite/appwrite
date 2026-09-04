<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * A broker outage must not cost the worker. `receive()` was called unguarded
 * from the consume loop, so anything it threw unwound out of consume() and ended
 * the process. Nothing retried at that level: the connection pool's internal
 * reconnect attempts happened to delay each failure by tens of seconds, which
 * looked like backoff but was only hiding the missing guard.
 *
 * The failure is reported through $errorCallback with a null message, which is
 * what its nullable signature is for, so a consumer logs it through the same
 * Server::error() hook it already uses for handler failures.
 */
final class ConsumerResilienceTest extends TestCase
{
    private const string QUEUE = 'resilience';

    private const string NAMESPACE = 'tests';

    public function testConsumeSurvivesBrokerFailuresAndResumes(): void
    {
        $connection = new InMemoryConnection();
        $broker = new Redis($connection, $connection);
        $queue = new Queue(self::QUEUE, self::NAMESPACE);

        // Fails the first two receives, then delegates to the working broker.
        $flaky = new class ($broker) implements Consumer {
            public int $failures = 0;

            public function __construct(private readonly Redis $inner) {}

            public function receive(Queue $queue, int $timeout): ?Message
            {
                if ($this->failures < 2) {
                    ++$this->failures;

                    throw new \RuntimeException('broker unreachable');
                }

                return $this->inner->receive($queue, $timeout);
            }

            public function commit(Queue $queue, Message $message): void
            {
                $this->inner->commit($queue, $message);
            }

            public function reject(Queue $queue, Message $message): void
            {
                $this->inner->reject($queue, $message);
            }

            public function close(): void
            {
                $this->inner->close();
            }
        };

        $processed = 0;
        /** @var list<string> $reported */
        $reported = [];
        $reportedMessages = [];

        \Swoole\Coroutine\run(function () use ($broker, $flaky, $queue, &$processed, &$reported, &$reportedMessages): void {
            $broker->publish($queue, ['n' => 1]);

            $adapter = new class ($flaky, 1, self::NAMESPACE) extends Swoole {
                // Keep the test quick; the production pause is RECEIVE_BACKOFF seconds.
                protected const int RECEIVE_BACKOFF = 0;
            };

            $adapter->consume(
                function () use ($adapter, &$processed): void {
                    ++$processed;
                    $adapter->stop();
                },
                fn(): null => null,
                function (?Message $message, \Throwable $error) use (&$reported, &$reportedMessages): void {
                    $reported[] = $error->getMessage();
                    $reportedMessages[] = $message;
                },
                [
                    ['queue' => $queue, 'maxCoroutines' => 1],
                ],
            );
        });

        $this->assertSame(2, $flaky->failures, 'both failures were absorbed rather than escaping');
        $this->assertSame(1, $processed, 'the loop resumed and drained the queue');
        $this->assertSame(['broker unreachable', 'broker unreachable'], $reported, 'each failure was reported');
        $this->assertSame([null, null], $reportedMessages, 'reported without a message, since none was obtained');
    }


    public function testAFailedErrorReportStillLeavesATrace(): void
    {
        $connection = new InMemoryConnection();
        $broker = new Redis($connection, $connection);
        $queue = new Queue(self::QUEUE, self::NAMESPACE);
        $broker->publish($queue, ['n' => 1]);

        $adapter = new class ($broker, 1, self::NAMESPACE) extends Swoole {
            /** @var resource */
            public $sink;

            public function drain(Queue $queue, callable $messageCallback, callable $errorCallback): void
            {
                $message = $this->consumer->receive($queue, 0);
                $this->queue = $queue;
                $this->process($message, $messageCallback, fn(): null => null, $errorCallback);
            }

            #[\Override]
            protected function trace(): mixed
            {
                return $this->sink;
            }
        };
        $adapter->sink = fopen('php://memory', 'a+');

        \Swoole\Coroutine\run(function () use ($adapter, $queue): void {
            $adapter->drain(
                $queue,
                fn() => throw new \RuntimeException('the database is gone'),
                // The reporting hook needs the same resources the handler did,
                // so the outage that failed the message fails its report too.
                fn() => throw new \RuntimeException('reporting needs the database too'),
            );
        });

        rewind($adapter->sink);
        $trace = stream_get_contents($adapter->sink);

        $this->assertStringContainsString('the database is gone', (string) $trace, 'the original failure must reach a sink that needs nothing working');
        $this->assertStringContainsString('reporting needs the database too', (string) $trace, 'the reporting failure is named too, so the gap is obvious');
        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true), 'the message is still rejected exactly once');
    }
}
