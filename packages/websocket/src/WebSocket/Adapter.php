<?php

declare(strict_types=1);

namespace Utopia\WebSocket;

abstract class Adapter
{
    /**
    * @var array<int|string,bool|int|float|string>
    */

    protected array $config = [];

    public function __construct(protected string $host = '0.0.0.0', protected int $port = 80) {}

    /**
     * Starts the Server.
     */
    abstract public function start(): void;

    /**
     * Shuts down the Server.
     */
    abstract public function shutdown(): void;

    /**
     * Sends a message to passed connections.
     * @param array<mixed,mixed> $connections Array of connection ID's.
     * @param string $message Message.
     */
    abstract public function send(array $connections, string $message): void;

    /**
     * Closes a connection.
     * @param int $connection Connection ID.
     * @param int $code Close Code.
     */
    abstract public function close(int $connection, int $code): void;

    /**
     * Is called when the Server starts.
     */
    abstract public function onStart(callable $callback): self;

    /**
     * Is called when a Worker starts.
     */
    abstract public function onWorkerStart(callable $callback): self;

    /**
     * Is called when a Worker stops.
     */
    abstract public function onWorkerStop(callable $callback): self;

    /**
     * Is called when a connection is established.
     */
    abstract public function onOpen(callable $callback): self;

    /**
     * Is called when a message is received.
     */
    abstract public function onMessage(callable $callback): self;

    /**
     * Is called when an HTTP request is received.
     */
    abstract public function onRequest(callable $callback): self;

    /**
     * Is called when a connection is closed.
     */
    abstract public function onClose(callable $callback): self;

    /**
     * Sets maximum package length in bytes.
     */
    abstract public function setPackageMaxLength(int $bytes): self;

    /**
     * Enables/Disables compression.
     */
    abstract public function setCompressionEnabled(bool $enabled): self;

    /**
     * Sets the number of workers.
     */
    abstract public function setWorkerNumber(int $num): self;

    /**
     * Returns the native server object from the Adapter.
     */
    abstract public function getNative(): mixed;
}
