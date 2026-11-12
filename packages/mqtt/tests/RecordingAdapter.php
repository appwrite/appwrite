<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer;

/**
 * A minimal Adapter that records send() and close() calls and can feed packets to a Server, so a
 * Connection's outbound behaviour can be asserted without a real transport.
 */
final class RecordingAdapter extends Adapter
{
    /** @var array<int, array{0: int, 1: string}> */
    public array $sent = [];

    /** @var array<int, int> */
    public array $closed = [];

    /** When true, send() throws — to prove disconnect() still closes the socket. */
    public bool $failSend = false;

    /** @var callable|null */
    private $onOpen = null;

    /** @var callable|null */
    private $onReceive = null;

    /** @var callable|null */
    private $onClose = null;

    public function __construct(private readonly Timer $timer = new NoTimer())
    {
    }

    /** Open a connection on $fd and feed it the given packets, as the transport would. */
    public function deliver(int $fd, string ...$packets): void
    {
        if ($this->onOpen === null || $this->onReceive === null) {
            throw new \LogicException('No server is attached to this adapter');
        }

        ($this->onOpen)($fd);
        foreach ($packets as $packet) {
            ($this->onReceive)($fd, $packet);
        }
    }

    public function send(int $connection, string $message): void
    {
        if ($this->failSend) {
            throw new \RuntimeException('transport gone');
        }

        $this->sent[] = [$connection, $message];
    }

    public function close(int $connection): void
    {
        $this->closed[] = $connection;

        if ($this->onClose !== null) {
            ($this->onClose)($connection);
        }
    }

    public function onStart(callable $callback): self
    {
        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
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

    public function start(): void
    {
    }

    public function shutdown(): void
    {
    }
}
