<?php

namespace Utopia\WebSocket\Adapter;

use Utopia\WebSocket\Adapter;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

class Workerman extends Adapter
{
    protected Worker $server;

    protected string $host;

    protected int $port;

    private mixed $callbackOnStart;

    private mixed $callbackOnMessage;

    private mixed $callbackOnRequest;

    public function __construct(string $host = '0.0.0.0', int $port = 80)
    {
        parent::__construct($host, $port);

        $this->server = new Worker("websocket://{$this->host}:{$this->port}");
    }

    public function start(): void
    {
        Worker::runAll();
        $callable = ($this->callbackOnStart);
        if (!\is_callable($callable)) {
            throw new \Exception();
        }
        \call_user_func($callable);
    }

    public function shutdown(): void
    {
        Worker::stopAll();
    }

    public function send(array $connections, string $message): void
    {
        foreach ($connections as $connection) {
            TcpConnection::$connections[$connection]->send($message);
        }
    }

    public function close(int $connection, int $code): void
    {
        TcpConnection::$connections[$connection]->close();
    }

    public function onStart(callable $callback): self
    {
        $this->callbackOnStart = $callback;

        return $this;
    }

    public function onWorkerStart(callable $callback): self
    {
        $this->server->onWorkerStart = function (Worker $worker) use ($callback): void {
            \call_user_func($callback, $worker->id);
        };
        return $this;
    }

    public function onWorkerStop(callable $callback): Adapter
    {
        $this->server->onWorkerStop = function (Worker $worker) use ($callback): void {
            \call_user_func($callback, $worker->id);
        };

        return $this;
    }

    public function onOpen(callable $callback): self
    {
        $this->server->onConnect = function ($connection) use ($callback): void {
            $connection->onWebSocketConnect = function (TcpConnection $connection) use ($callback): void {
                /** @var array<string> $_SERVER */
                \call_user_func($callback, $connection->id, $_SERVER);
            };
        };

        return $this;
    }

    public function onMessage(callable $callback): self
    {
        $this->callbackOnMessage = $callback;
        $this->setupMessageHandler();

        return $this;
    }

    public function onClose(callable $callback): self
    {
        $this->server->onClose = function (TcpConnection $connection) use ($callback): void {
            \call_user_func($callback, $connection->id);
        };

        return $this;
    }

    public function onRequest(callable $callback): self
    {
        $this->callbackOnRequest = $callback;
        $this->setupMessageHandler();

        return $this;
    }

    /**
     * Sets up the unified message handler that routes between WebSocket messages and HTTP requests
     */
    private function setupMessageHandler(): void
    {
        $this->server->onMessage = function (TcpConnection $connection, $data): void {
            if ($data instanceof Request) {
                if (\is_callable($this->callbackOnRequest)) {
                    \call_user_func($this->callbackOnRequest, $connection, $data);
                }
            } elseif (\is_string($data)) {
                if (\is_callable($this->callbackOnMessage)) {
                    \call_user_func($this->callbackOnMessage, $connection->id, $data);
                }
            }
        };
    }

    public function setPackageMaxLength(int $bytes): self
    {
        return $this;
    }

    public function setCompressionEnabled(bool $enabled): self
    {
        return $this;
    }

    public function setWorkerNumber(int $num): self
    {
        $this->server->count = $num;

        return $this;
    }

    public function getNative(): Worker
    {
        return $this->server;
    }
}
