<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer;
use Utopia\Mqtt\Packet;

/**
 * An in-process transport for Utopia\Mqtt\Server: the test plays the network by handing it
 * whole packets for an fd, and reads back every packet the server wrote to each fd.
 */
final class RecordingAdapter extends Adapter
{
    /** @var array<int, list<string>> fd => packets written, in order */
    public array $sent = [];

    /** @var callable|null */
    private $onOpen = null;

    /** @var callable|null */
    private $onReceive = null;

    /** Open a connection on $fd and feed it the given packets, as the transport would. */
    public function deliver(int $fd, string ...$packets): void
    {
        ($this->onOpen)($fd);
        foreach ($packets as $packet) {
            ($this->onReceive)($fd, $packet);
        }
    }

    /**
     * The PUBLISH packets written to $fd since the last call.
     *
     * @return list<Packet>
     */
    public function publishes(int $fd): array
    {
        $publishes = [];
        foreach ($this->sent[$fd] ?? [] as $packet) {
            $parsed = Packet::parse($packet);
            if ($parsed->type === Packet::PUBLISH) {
                $publishes[] = $parsed;
            }
        }
        $this->sent[$fd] = [];

        return $publishes;
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
        return $this;
    }

    public function send(int $connection, string $message): void
    {
        $this->sent[$connection][] = $message;
    }

    public function close(int $connection): void
    {
    }

    public function timer(): Timer
    {
        return new NoTimer();
    }

    public function start(): void
    {
    }

    public function shutdown(): void
    {
    }
}
