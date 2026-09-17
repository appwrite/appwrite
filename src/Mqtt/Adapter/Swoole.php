<?php

namespace Utopia\Mqtt\Adapter;

use Swoole\Server;
use Swoole\Server\Port;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Adapter\Swoole\Timers\TimingWheel;
use Utopia\Mqtt\Adapter\Swoole\Transport;
use Utopia\Mqtt\Adapter\Swoole\WebSocket;
use Utopia\Mqtt\Packet;

class Swoole extends Adapter
{
    private const MAX_CONNECTIONS = 100_000;

    protected Server $server;

    private Timer $timer;

    /** @var array<int, string> per-fd MQTT bytes decoded out of WebSocket messages, awaiting whole packets */
    private array $stream = [];

    /** @var callable|null */
    private $onStart = null;

    /** @var callable|null */
    private $onWorkerStart = null;

    /** @var callable|null */
    private $onReceive = null;

    /** @var callable|null */
    private $onClose = null;

    /** @param list<Transport> $transports */
    public function __construct(array $transports, private int $workers = 1, ?Timer $timer = null)
    {
        if ($transports === []) {
            throw new \InvalidArgumentException('At least one transport is required.');
        }

        $this->timer = $timer ?? new TimingWheel();
        $this->timer->onTick(fn (int $fd) => $this->close($fd));

        // A WebSocket\Server dispatches its 'message' event only on its own primary port,
        // so a WebSocket transport must be the master; raw MQTT is added as a TCP listener.
        \usort($transports, fn (Transport $a, Transport $b): int => ($b instanceof WebSocket) <=> ($a instanceof WebSocket));

        $master = $transports[0];
        $this->server = $master instanceof WebSocket
            ? new WebSocketServer($master->host, $master->port, SWOOLE_BASE, $master->getSockType())
            : new Server($master->host, $master->port, SWOOLE_BASE, $master->getSockType());
        $this->server->set($master->getSettings() + [
            'worker_num' => $this->workers,
            'max_connection' => self::MAX_CONNECTIONS,
        ]);

        foreach (\array_slice($transports, 1) as $transport) {
            $port = $this->server->addListener($transport->host, $transport->port, $transport->getSockType());
            if (!$port instanceof Port) {
                throw new \RuntimeException(\sprintf('Could not listen on %s:%d.', $transport->host, $transport->port));
            }

            $settings = $transport->getSettings();
            if ($settings !== []) {
                $port->set($settings);
            }
        }
    }

    public function start(): void
    {
        $this->server->on('receive', function (Server $server, int $fd, int $reactorId, string $data): void {
            if ($this->onReceive !== null) {
                \call_user_func($this->onReceive, $fd, $data);
            }
        });

        $this->server->on('close', function (Server $server, int $fd): void {
            unset($this->stream[$fd]);
            if ($this->onClose !== null) {
                \call_user_func($this->onClose, $fd);
            }
        });

        if ($this->server instanceof WebSocketServer) {
            // A WebSocket message may hold several or partial MQTT packets, so its payload
            // is buffered and split into whole packets before dispatch (Packet::frames),
            // matching the one-packet-per-onReceive the raw-TCP path gets from framing.
            $this->server->on('message', function (Server $server, Frame $frame): void {
                $this->stream[$frame->fd] = ($this->stream[$frame->fd] ?? '') . $frame->data;
                [$packets, $this->stream[$frame->fd]] = Packet::frames($this->stream[$frame->fd]);

                foreach ($packets as $packet) {
                    if ($this->onReceive !== null) {
                        \call_user_func($this->onReceive, $frame->fd, $packet);
                    }
                }
            });
        }

        if ($this->onStart !== null) {
            $callback = $this->onStart;
            $this->server->on('start', function () use ($callback): void {
                \call_user_func($callback);
            });
        }

        if ($this->onWorkerStart !== null) {
            $callback = $this->onWorkerStart;
            $this->server->on('workerStart', function (Server $server, int $workerId) use ($callback): void {
                \call_user_func($callback, $workerId);
            });
        }

        $this->server->start();
    }

    public function shutdown(): void
    {
        $this->server->shutdown();
    }

    public function send(int $connection, string $message): void
    {
        if ($this->server instanceof WebSocketServer && $this->server->isEstablished($connection)) {
            $this->server->push($connection, $message, WEBSOCKET_OPCODE_BINARY);

            return;
        }

        $this->server->send($connection, $message);
    }

    public function close(int $connection): void
    {
        $this->server->close($connection);
    }

    public function onStart(callable $callback): Adapter
    {
        $this->onStart = $callback;

        return $this;
    }

    public function onWorkerStart(callable $callback): Adapter
    {
        $this->onWorkerStart = $callback;

        return $this;
    }

    public function onReceive(callable $callback): Adapter
    {
        $this->onReceive = $callback;

        return $this;
    }

    public function onClose(callable $callback): Adapter
    {
        $this->onClose = $callback;

        return $this;
    }

    public function timer(): Timer
    {
        return $this->timer;
    }
}
