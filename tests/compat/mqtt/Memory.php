<?php

namespace Tests\Compat\Mqtt;

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer;

/**
 * An in-process adapter, the twin of utopia_mqtt::Memory: the caller opens
 * connections, delivers packets and disconnects clients; what the broker
 * sends and closes is recorded. close() of an open connection calls the
 * close hook at once, as Swoole does; the timer's tick closes the
 * connection, as the Swoole adapter wires it.
 */
final class Memory extends Adapter
{
    public bool $started = false;

    /** @var array<int, true> */
    public array $open = [];

    /** @var list<array{0: int, 1: string}> */
    public array $sent = [];

    /** @var list<int> */
    public array $closed = [];

    /** @var array<string, string> operation => message */
    public array $failures = [];

    /** @var callable|null */
    private $onStart = null;

    /** @var callable|null */
    private $onWorkerStart = null;

    /** @var callable|null */
    private $onOpen = null;

    /** @var callable|null */
    private $onReceive = null;

    /** @var callable|null */
    private $onClose = null;

    public function __construct(private Timer $timer = new NoTimer())
    {
        $this->timer->onTick(fn (int $fd) => $this->close($fd));
    }

    public function fail(string $operation, string $message): void
    {
        $this->failures[$operation] = $message;
    }

    public function open(int $fd): void
    {
        $this->open[$fd] = true;
        if ($this->onOpen !== null) {
            \call_user_func($this->onOpen, $fd);
        }
    }

    public function receive(int $fd, string $data): void
    {
        if ($this->onReceive !== null) {
            \call_user_func($this->onReceive, $fd, $data);
        }
    }

    public function disconnect(int $fd): bool
    {
        if (!isset($this->open[$fd])) {
            return false;
        }
        unset($this->open[$fd]);
        if ($this->onClose !== null) {
            \call_user_func($this->onClose, $fd);
        }

        return true;
    }

    private function failure(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            throw new \RuntimeException($this->failures[$operation]);
        }
    }

    public function send(int $connection, string $message): void
    {
        $this->failure('send');
        $this->sent[] = [$connection, $message];
    }

    public function close(int $connection): void
    {
        $this->failure('close');
        $this->closed[] = $connection;
        if (!isset($this->open[$connection])) {
            return;
        }
        unset($this->open[$connection]);
        if ($this->onClose !== null) {
            \call_user_func($this->onClose, $connection);
        }
    }

    public function start(): void
    {
        $this->failure('start');
        $this->started = true;
        if ($this->onStart !== null) {
            \call_user_func($this->onStart);
        }
        if ($this->onWorkerStart !== null) {
            \call_user_func($this->onWorkerStart, 0);
        }
    }

    public function shutdown(): void
    {
        $this->failure('shutdown');
        $this->started = false;
    }

    public function onStart(callable $callback): self
    {
        $this->onStart = $callback;

        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->onWorkerStart = $callback;

        return $this;
    }

    public function onOpen(callable $callback): self
    {
        $this->onOpen = $callback;

        return $this;
    }

    public function onReceive(callable $callback): self
    {
        $this->onReceive = $callback;

        return $this;
    }

    public function onClose(callable $callback): self
    {
        $this->onClose = $callback;

        return $this;
    }

    public function timer(): Timer
    {
        return $this->timer;
    }
}
