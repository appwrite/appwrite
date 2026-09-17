<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Server;

final class ServerTest extends TestCase
{
    /** A recording transport that can be told to throw on send/close/start/shutdown. */
    private function adapter(bool $throws = false): Adapter
    {
        return new class ($throws) extends Adapter {
            /** @var array<int, array{int, string}> */
            public array $sent = [];
            /** @var array<int, int> */
            public array $closed = [];
            public bool $started = false;
            /** @var array<string, callable> */
            public array $hooks = [];

            public function __construct(private bool $throws)
            {
            }

            private function guard(string $action): void
            {
                if ($this->throws) {
                    throw new \RuntimeException("boom:{$action}");
                }
            }

            public function start(): void
            {
                $this->guard('start');
                $this->started = true;
            }

            public function shutdown(): void
            {
                $this->guard('shutdown');
            }

            public function send(int $connection, string $message): void
            {
                $this->guard('send');
                $this->sent[] = [$connection, $message];
            }

            public function close(int $connection): void
            {
                $this->guard('close');
                $this->closed[] = $connection;
            }

            public function onStart(callable $callback): Adapter
            {
                $this->hooks['start'] = $callback;
                return $this;
            }

            public function onWorkerStart(callable $callback): Adapter
            {
                $this->hooks['workerStart'] = $callback;
                return $this;
            }

            public function onReceive(callable $callback): Adapter
            {
                $this->hooks['receive'] = $callback;
                return $this;
            }

            public function onClose(callable $callback): Adapter
            {
                $this->hooks['close'] = $callback;
                return $this;
            }

            public function timer(): Timer
            {
                return new class () implements Timer {
                    public function onTick(callable $callback): Timer
                    {
                        return $this;
                    }

                    public function onClear(callable $callback): Timer
                    {
                        return $this;
                    }

                    public function schedule(int $id, int $keepAlive): void
                    {
                    }

                    public function clear(int $id): void
                    {
                    }
                };
            }
        };
    }

    public function testDelegatesWritesToAdapter(): void
    {
        $adapter = $this->adapter();
        $server = new Server($adapter);

        $server->start();
        $server->send(42, 'hello');
        $server->close(42);

        $this->assertTrue($adapter->started);
        $this->assertSame([[42, 'hello']], $adapter->sent);
        $this->assertSame([42], $adapter->closed);
    }

    public function testRegistersLifecycleHooksOnAdapter(): void
    {
        $adapter = $this->adapter();
        $server = new Server($adapter);

        $noop = fn () => null;
        $server->onStart($noop)->onWorkerStart($noop)->onReceive($noop)->onClose($noop);

        $this->assertSame(['start', 'workerStart', 'receive', 'close'], array_keys($adapter->hooks));
    }

    public function testTransportErrorsAreRoutedToErrorCallbacks(): void
    {
        $adapter = $this->adapter(throws: true);
        $server = new Server($adapter);

        /** @var array<int, array{\Throwable, string}> $errors */
        $errors = [];
        $server->error(function (\Throwable $error, string $action) use (&$errors): void {
            $errors[] = [$error, $action];
        });

        // None of these should let the exception escape.
        $server->start();
        $server->send(1, 'x');
        $server->close(1);
        $server->shutdown();

        $actions = array_map(fn ($e) => $e[1], $errors);
        $this->assertSame(['start', 'send', 'close', 'shutdown'], $actions);
        $this->assertSame('boom:send', $errors[1][0]->getMessage());
    }

    public function testErrorWithoutCallbackIsSwallowed(): void
    {
        $server = new Server($this->adapter(throws: true));

        // No error callback registered: the throw must still not escape.
        $server->send(1, 'x');

        $this->assertTrue(true);
    }
}
