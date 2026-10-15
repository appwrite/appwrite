<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\NoTimer;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

/**
 * An in-process transport for Utopia\Mqtt\Server: the test plays the network by handing it
 * whole packets for an fd, and reads back every packet the server wrote to each fd.
 */
final class RecordingAdapter extends Adapter
{
    /** @var array<int, list<string>> fd => packets written, in order */
    public array $sent = [];

    /** @var list<int> fds closed, in order */
    public array $closed = [];

    /** @var callable|null */
    private $onOpen = null;

    /** @var callable|null */
    private $onReceive = null;

    /** @var callable|null */
    private $onClose = null;

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

    /**
     * The reason codes of the CONNACK packets written to $fd.
     *
     * @return list<int>
     */
    public function connacks(int $fd): array
    {
        $reasonCodes = [];
        foreach ($this->sent[$fd] ?? [] as $packet) {
            $parsed = Packet::parse($packet);
            if ($parsed->type === Packet::CONNACK) {
                $reasonCodes[] = Packet::readByte($parsed->body, 1)[0];
            }
        }

        return $reasonCodes;
    }

    /**
     * The DISCONNECT packets written to $fd.
     *
     * @return list<Disconnect>
     */
    public function disconnects(int $fd): array
    {
        $disconnects = [];
        foreach ($this->sent[$fd] ?? [] as $packet) {
            $parsed = Packet::parse($packet);
            if ($parsed->type !== Packet::DISCONNECT) {
                continue;
            }
            if ($parsed->body === '') {
                $disconnects[] = Disconnect::normal();
                continue;
            }

            [$reasonCode, $offset] = Packet::readByte($parsed->body, 0);
            [$properties] = Properties::parse($parsed->body, $offset);
            $reason = $properties->get(Property::REASON_STRING);
            $disconnects[] = Disconnect::refuse($reasonCode, \is_string($reason) ? $reason : null);
        }

        return $disconnects;
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

    public function send(int $connection, string $message): void
    {
        $this->sent[$connection][] = $message;
    }

    public function close(int $connection): void
    {
        $this->closed[] = $connection;

        if ($this->onClose !== null) {
            ($this->onClose)($connection);
        }
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
