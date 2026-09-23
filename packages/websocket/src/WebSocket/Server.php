<?php

namespace Utopia\WebSocket;

use Throwable;

class Server
{
    /**
     * Callbacks that will be executed when an error occurs
     * @var array<callable>
     */
    protected array $errorCallbacks = [];

    /**
     * Creates an instance of a WebSocket server.
     */
    public function __construct(protected Adapter $adapter) {}

    /**
     * Starts the WebSocket server.
     */
    public function start(): void
    {
        try {
            $this->adapter->start();
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'start');
            }
        }
    }

    /**
     * Shuts down the WebSocket server.
     */
    public function shutdown(): void
    {
        try {
            $this->adapter->shutdown();
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'shutdown');
            }
        }
    }

    /**
     * Sends a message to passed connections.
     * @param array<mixed, mixed> $connections Array of connection ID's.
     * @param string $message Message.
     */
    public function send(array $connections, string $message): void
    {
        try {
            $this->adapter->send($connections, $message);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'send');
            }
        }
    }

    /**
     * Closes a connection.
     *
     * @param  int  $connection Connection ID.
     * @param  int  $code Close Code.
     */
    public function close(int $connection, int $code): void
    {
        try {
            $this->adapter->close($connection, $code);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'close');
            }
        }
    }

    /**
     * Is called when the Server starts.
     */
    public function onStart(callable $callback): self
    {
        try {
            $this->adapter->onStart($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onStart');
            }
        }

        return $this;
    }

    /**
     * Is called when a Worker starts.
     */
    public function onWorkerStart(callable $callback): self
    {
        try {
            $this->adapter->onWorkerStart($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onWorkerStart');
            }
        }

        return $this;
    }

    /**
     * Is called when a Worker stops.
     */
    public function onWorkerStop(callable $callback): self
    {
        try {
            $this->adapter->onWorkerStop($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onWorkerStop');
            }
        }

        return $this;
    }

    /**
     * Is called when a connection is established.
     */
    public function onOpen(callable $callback): self
    {
        try {
            $this->adapter->onOpen($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onOpen');
            }
        }

        return $this;
    }

    /**
     * Is called when a message is received.
     */
    public function onMessage(callable $callback): self
    {
        try {
            $this->adapter->onMessage($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onMessage');
            }
        }

        return $this;
    }

    /**
     * Is called when a connection is closed.
     */
    public function onClose(callable $callback): self
    {
        try {
            $this->adapter->onClose($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onClose');
            }
        }

        return $this;
    }

    /**
     * Is called when an HTTP request is received.
     */
    public function onRequest(callable $callback): self
    {
        try {
            $this->adapter->onRequest($callback);
        } catch (Throwable $error) {
            foreach ($this->errorCallbacks as $errorCallback) {
                $errorCallback($error, 'onRequest');
            }
        }

        return $this;
    }

    /**
     * Register callback. Will be executed when error occurs.
     */
    public function error(callable $callback): self
    {
        $this->errorCallbacks[] = $callback;
        return $this;
    }
}
