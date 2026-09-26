<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

final class PrefetchTest extends TestCase
{
    public function testUnconfirmedMessagesCountAgainstPrefetch(): void
    {
        $consumer = new class implements Consumer {
            public int $outstanding = 0;
            public int $peak = 0;
            public int $confirmed = 0;
            private int $received = 0;

            public function receive(Queue $queue, int $timeout, int $n = 1): array
            {
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

            public function close(): void {}
        };
        $running = $peak = $handled = 0;
        $confirmationsAtFourthHandler = null;
        $errors = [];
        Coroutine\run(function () use ($consumer, &$running, &$peak, &$handled, &$confirmationsAtFourthHandler, &$errors): void {
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
            $server->job('jobs', coroutines: 1, prefetch: 4)->action(function () use ($consumer, &$running, &$peak, &$handled, &$confirmationsAtFourthHandler): void {
                $peak = max($peak, ++$running);
                if (++$handled === 4) {
                    $confirmationsAtFourthHandler = $consumer->confirmed;
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
        $this->assertSame(4, $consumer->peak, 'Waiting, running and unconfirmed messages share one limit');
        $this->assertSame(0, $consumer->outstanding);
        $this->assertSame(1, $peak);
        $this->assertSame(0, $confirmationsAtFourthHandler, 'Handlers continue while confirmations are pending');
    }
}
