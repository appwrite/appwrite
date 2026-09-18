<?php

namespace Utopia\Mqtt\Adapter;

use Swoole\Http\Request;
use Swoole\Http\Response;
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

    /** Cap for a WebSocket connection's reassembly buffer; a larger remainder can never complete. */
    private int $maxPacketLength = 0;

    /** @var array<int, string> per-fd MQTT bytes decoded out of WebSocket messages, awaiting whole packets */
    private array $stream = [];

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
        if ($master instanceof WebSocket) {
            $length = $master->getSettings()['package_max_length'] ?? 0;
            $this->maxPacketLength = \is_int($length) ? $length : 0;
        }

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

        // TCP connection opened. (WebSocket connections signal open from the handshake below,
        // since a custom handshake suppresses Swoole's automatic 'open' event.)
        $this->server->on('connect', function (Server $server, int $fd): void {
            if ($this->onOpen !== null) {
                \call_user_func($this->onOpen, $fd);
            }
        });

        if ($this->server instanceof WebSocketServer) {
            // Swoole's default handshake does not echo the `mqtt` subprotocol, which MQTT-over-
            // WebSocket clients (MQTT.js, Paho) send and require echoed back. Perform a compliant
            // handshake that echoes it (RFC 6455 accept key + Sec-WebSocket-Protocol: mqtt).
            $this->server->on('handshake', function (Request $request, Response $response): bool {
                $key = \is_string($request->header['sec-websocket-key'] ?? null) ? $request->header['sec-websocket-key'] : '';
                $accept = \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

                $response->header('Upgrade', 'websocket');
                $response->header('Connection', 'Upgrade');
                $response->header('Sec-WebSocket-Accept', $accept);
                $response->header('Sec-WebSocket-Version', '13');
                if (isset($request->header['sec-websocket-protocol'])) {
                    $response->header('Sec-WebSocket-Protocol', 'mqtt');
                }

                $response->status(101);
                $response->end();

                if ($this->onOpen !== null) {
                    \call_user_func($this->onOpen, $request->fd);
                }

                return true;
            });

            // A WebSocket message may hold several or partial MQTT packets, so its payload
            // is buffered and split into whole packets before dispatch (Packet::frames),
            // matching the one-packet-per-onReceive the raw-TCP path gets from framing.
            $this->server->on('message', function (Server $server, Frame $frame): void {
                [$packets, $remainder] = Packet::frames(($this->stream[$frame->fd] ?? '') . $frame->data);

                foreach ($packets as $packet) {
                    if ($this->onReceive !== null) {
                        \call_user_func($this->onReceive, $frame->fd, $packet);
                    }
                }

                // A peer streaming fragments that never complete a packet would otherwise grow
                // this buffer without bound; a remainder past one max-size packet can never
                // become a valid packet, so drop the connection instead of retaining it.
                if ($this->maxPacketLength > 0 && \strlen($remainder) > $this->maxPacketLength) {
                    unset($this->stream[$frame->fd]);
                    $this->close($frame->fd);

                    return;
                }

                $this->stream[$frame->fd] = $remainder;
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

    public function onOpen(callable $callback): Adapter
    {
        $this->onOpen = $callback;

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
