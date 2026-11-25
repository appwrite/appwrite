<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PrefetchTest extends TestCase
{
    public function testReceiveBufferRefillsBeforeAcknowledgement(): void
    {
        $consumer = null;
        $confirmed = 0;
        $errors = [];
        Coroutine\run(function () use (&$consumer, &$confirmed, &$errors): void {
            $consumer = new class () implements Consumer {
                /** @var list<int> */
                public array $batches = [];
                public int $received = 0;
                public int $confirmed = 0;
                public ?int $confirmedAtRefill = null;
                public Coroutine\Channel $refilled;
                public Coroutine\Channel $confirmations;

                public function __construct()
                {
                    $this->confirmations = new Coroutine\Channel(4);
                    $this->refilled = new Coroutine\Channel(1);
                }

                public function receive(Queue $queue, int $timeout, int $n = 1): array
                {
                    if ($this->received === 8) {
                        // The broker is empty; allow remaining confirmations to finish.
                        new Coroutine\Channel(1)->pop(0.1);
                        return [];
                    }
                    $messages = [];
                    while ($n-- > 0 && $this->received < 8) {
                        $messages[] = new Message(['pid' => (string) ++$this->received, 'queue' => $queue->name, 'timestamp' => time()]);
                    }
                    $this->batches[] = \count($messages);
                    if ($this->received === 8) {
                        $this->confirmedAtRefill = $this->confirmed;
                        $this->refilled->push(true);
                    }
                    return $messages;
                }

                public function commit(Queue $queue, Message $message): void
                {
                    $reply = new Coroutine\Channel(1);
                    $this->confirmations->push($reply);
                    if ($reply->pop(3.0) !== true) {
                        throw new \RuntimeException('Confirmation did not arrive');
                    }
                    $this->confirmed++;
                }

                public function reject(Queue $queue, Message $message): never
                {
                    throw new \LogicException('No handler should fail');
                }

                public function close(): void
                {
                }
            };
            Coroutine::create(function () use ($consumer): void {
                for ($batch = 0; $batch < 2; $batch++) {
                    $replies = [];
                    for ($i = 0; $i < 4; $i++) {
                        $reply = $consumer->confirmations->pop(1.0);
                        $this->assertInstanceOf(Coroutine\Channel::class, $reply);
                        $replies[] = $reply;
                    }
                    if ($batch === 0) {
                        // A refill must not depend on completing this acknowledgement batch.
                        $consumer->refilled->pop(1.0);
                    }
                    foreach ($replies as $reply) {
                        $reply->push(true);
                    }
                }
            });
            $adapter = new Swoole($consumer, 1);
            $adapter->consume(
                static fn (): null => null,
                function () use ($adapter, &$confirmed): void {
                    if (++$confirmed === 8) {
                        $adapter->stop();
                    }
                },
                function (?Message $message, \Throwable $error) use ($adapter, &$errors): void {
                    $errors[] = $error->getMessage();
                    $adapter->stop();
                },
                [['queue' => new Queue('confirmations'), 'coroutines' => 4]],
            );
        });
        $this->assertSame([], $errors);
        $this->assertSame(8, $confirmed);
        $this->assertNotNull($consumer);
        $this->assertSame([4, 4], $consumer->batches);
        $this->assertSame(0, $consumer->confirmedAtRefill, 'Handing work to a handler releases buffer capacity before acknowledgement');
    }

    public static function limits(): \Iterator
    {
        yield 'overlapping confirmations' => [1, 2, 4];
        yield 'buffer smaller than concurrency' => [4, 1, 8];
        yield 'in-flight limit smaller than buffer' => [2, 8, 3];
    }

    #[DataProvider('limits')]
    public function testUnconfirmedMessagesCountAgainstInFlightLimit(int $coroutines, int $prefetch, int $maxInFlight): void
    {
        $consumer = new class () implements Consumer {
            public int $outstanding = 0;
            public int $largestRequest = 0;
            public int $peak = 0;
            public int $confirmed = 0;
            private int $received = 0;

            public function receive(Queue $queue, int $timeout, int $n = 1): array
            {
                $this->largestRequest = max($this->largestRequest, $n);
                if ($this->received === 20) {
                    Coroutine::sleep(0.001);
                    return [];
                }
                $messages = [];
                for ($i = 0; $i < $n && $this->received < 20; $i++) {
                    $messages[] = new Message(['pid' => (string) ++$this->received, 'queue' => $queue->name, 'timestamp' => time()]);
                    $this->outstanding++;
                }
                $this->peak = max($this->peak, $this->outstanding);
                return $messages;
            }

            public function commit(Queue $queue, Message $message): void
            {
                Coroutine::sleep(0.02);
                $this->outstanding--;
                $this->confirmed++;
            }

            public function reject(Queue $queue, Message $message): never
            {
                throw new \LogicException('No handler should fail');
            }

            public function close(): void
            {
            }
        };
        $running = $peak = $handled = 0;
        $confirmationsAtLimit = null;
        $errors = [];
        Coroutine\run(function () use ($consumer, $coroutines, $prefetch, $maxInFlight, &$running, &$peak, &$handled, &$confirmationsAtLimit, &$errors): void {
            $adapter = new class ($consumer, 1) extends Swoole {
                public function start(): self
                {
                    foreach ($this->onWorkerStart as $callback) {
                        $callback('0');
                    }
                    return $this;
                }
            };
            $server = new Server($adapter);
            $server->job('jobs', coroutines: $coroutines, prefetch: $prefetch, maxInFlight: $maxInFlight)->action(function () use ($consumer, $maxInFlight, &$running, &$peak, &$handled, &$confirmationsAtLimit): void {
                $peak = max($peak, ++$running);
                if (++$handled === $maxInFlight) {
                    $confirmationsAtLimit = $consumer->confirmed;
                }
                Coroutine::sleep(0.001);
                $running--;
            });
            $server->shutdown()->action(function () use ($consumer, $adapter): void {
                if ($consumer->confirmed === 20) {
                    $adapter->stop();
                }
            });
            $server->error()->inject('error')->action(function ($error) use (&$errors, $adapter): void {
                $errors[] = $error->getMessage();
                $adapter->stop();
            });
            $server->start();
        });
        $this->assertSame([], $errors);
        $this->assertSame(20, $handled);
        $this->assertSame(20, $consumer->confirmed);
        $this->assertSame($maxInFlight, $consumer->peak, 'The independent in-flight limit includes buffered, running and unconfirmed messages');
        $this->assertSame(0, $consumer->outstanding);
        $this->assertLessThanOrEqual($coroutines, $peak);
        $this->assertLessThanOrEqual($prefetch, $consumer->largestRequest);
        $this->assertSame(0, $confirmationsAtLimit, 'Handlers continue while confirmations are pending');
    }

    /**
     * Long handlers holding more than half the slots must not stop the worker
     * from fetching for the slots that are still idle.
     */
    public function testIdleCoroutinesFetchWhileLongHandlersHoldMostSlots(): void
    {
        $consumer = new class () implements Consumer {
            /** @var list<string> */
            public array $backlog = ['long-1', 'long-2', 'long-3'];
            public int $confirmed = 0;
            public int $longRunning = 0;
            public ?int $longRunningWhenShortRan = null;

            public function receive(Queue $queue, int $timeout, int $n = 1): array
            {
                if ($this->backlog === []) {
                    Coroutine::sleep(0.01);
                    return [];
                }
                $messages = [];
                while ($n-- > 0 && $this->backlog !== []) {
                    $messages[] = new Message(['pid' => array_shift($this->backlog), 'queue' => $queue->name, 'timestamp' => time()]);
                }
                return $messages;
            }

            public function commit(Queue $queue, Message $message): void
            {
                $this->confirmed++;
            }

            public function reject(Queue $queue, Message $message): never
            {
                throw new \LogicException('No handler should fail');
            }

            public function close(): void
            {
            }
        };
        $errors = [];
        Coroutine\run(function () use ($consumer, &$errors): void {
            $adapter = new class ($consumer, 1) extends Swoole {
                public function start(): self
                {
                    foreach ($this->onWorkerStart as $callback) {
                        $callback('0');
                    }
                    return $this;
                }
            };
            $release = new Coroutine\Channel(3);
            $server = new Server($adapter);
            $server->job('jobs', coroutines: 4, maxInFlight: 4)->inject('message')->action(function (Message $message) use ($consumer, $release): void {
                if ($message->getPid() === 'short') {
                    $consumer->longRunningWhenShortRan = $consumer->longRunning;
                    for ($i = 0; $i < 3; $i++) {
                        $release->push(true);
                    }
                    return;
                }
                if (++$consumer->longRunning === 3) {
                    // Three of the four slots are now busy and one is idle.
                    $consumer->backlog[] = 'short';
                }
                // Bounded, so a stalled loop fails the assertion below instead of hanging the suite.
                $release->pop(3.0);
                $consumer->longRunning--;
            });
            $server->shutdown()->action(function () use ($consumer, $adapter): void {
                if ($consumer->confirmed === 4) {
                    $adapter->stop();
                }
            });
            $server->error()->inject('error')->action(function (\Throwable $error) use (&$errors, $adapter): void {
                $errors[] = $error->getMessage();
                $adapter->stop();
            });
            $server->start();
        });
        $this->assertSame([], $errors);
        $this->assertSame(4, $consumer->confirmed);
        $this->assertSame(3, $consumer->longRunningWhenShortRan, 'The idle slot runs the next message while the long handlers still hold theirs');
    }

    public function testRefillOverlapsHandlersAndBufferedDispatch(): void
    {
        $errors = [];
        $consumer = null;
        Coroutine\run(function () use (&$consumer, &$errors): void {
            $consumer = new class () implements Consumer {
                public int $received = 0;
                public int $confirmed = 0;
                public int $longRunning = 0;
                public ?int $longRunningAtRefill = null;
                public bool $dispatchedDuringReceive = false;
                public Coroutine\Channel $refilling;
                public Coroutine\Channel $dispatched;

                public function __construct()
                {
                    $this->refilling = new Coroutine\Channel(2);
                    $this->dispatched = new Coroutine\Channel(1);
                }

                public function receive(Queue $queue, int $timeout, int $n = 1): array
                {
                    if ($this->received === 8) {
                        Coroutine::sleep(0.001);
                        return [];
                    }
                    if ($this->received === 6) {
                        $this->longRunningAtRefill = $this->longRunning;
                        $this->refilling->push(true);
                        $this->refilling->push(true);
                        // A slow receive must not stop dispatch of already buffered work.
                        $this->dispatchedDuringReceive = $this->dispatched->pop(1.0) === true;
                    }
                    $messages = [];
                    while ($n-- > 0 && $this->received < 8) {
                        $messages[] = new Message(['pid' => (string) ++$this->received, 'queue' => $queue->name, 'timestamp' => time()]);
                    }
                    return $messages;
                }

                public function commit(Queue $queue, Message $message): void
                {
                    $this->confirmed++;
                }

                public function reject(Queue $queue, Message $message): never
                {
                    throw new \LogicException('No handler should fail');
                }

                public function close(): void
                {
                }
            };
            $adapter = new Swoole($consumer, 1);
            $adapter->consume(
                function (Message $message) use ($consumer): void {
                    if (\in_array($message->getPid(), ['3', '4'], true)) {
                        $consumer->longRunning++;
                        $consumer->refilling->pop(2.0);
                        $consumer->longRunning--;
                    }
                    if ($message->getPid() === '5') {
                        // The test only counts dispatch after the refill has begun.
                        if ($consumer->longRunningAtRefill !== null) {
                            $consumer->dispatched->push(true);
                        }
                    }
                },
                function () use ($consumer, $adapter): void {
                    if ($consumer->confirmed === 8) {
                        $adapter->stop();
                    }
                },
                function (?Message $message, \Throwable $error) use (&$errors, $adapter): void {
                    $errors[] = $error->getMessage();
                    $adapter->stop();
                },
                [['queue' => new Queue('buffered'), 'coroutines' => 2, 'prefetch' => 6]],
            );
        });
        $this->assertSame([], $errors);
        $this->assertNotNull($consumer);
        $this->assertSame(8, $consumer->confirmed);
        $this->assertSame(2, $consumer->longRunningAtRefill, 'Refill is driven by the receive buffer, even with every handler busy');
        $this->assertTrue($consumer->dispatchedDuringReceive, 'Buffered jobs run while the broker receive is still waiting');
    }

}
