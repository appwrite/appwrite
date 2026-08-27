<?php

namespace Utopia\Mqtt;

abstract class Adapter
{
    protected string $host;

    protected int $port;

    /** @var array<int|string, bool|int|string> */
    protected array $config = [];

    public function __construct(string $host = '0.0.0.0', int $port = 1883)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Starts the server.
     */
    abstract public function start(): void;

    /**
     * Shuts down the server.
     */
    abstract public function shutdown(): void;

    /**
     * Sends raw bytes to a single connection.
     *
     * @param int $connection Connection ID.
     * @param string $message Encoded MQTT packet.
     */
    abstract public function send(int $connection, string $message): void;

    /**
     * Closes a connection.
     *
     * @param int $connection Connection ID.
     */
    abstract public function close(int $connection): void;

    /**
     * Is called when the server starts.
     */
    abstract public function onStart(callable $callback): self;

    /**
     * Is called when a worker starts.
     */
    abstract public function onWorkerStart(callable $callback): self;

    /**
     * Is called when a framed MQTT packet is received. The callback is invoked
     * with (int $connection, string $data).
     */
    abstract public function onReceive(callable $callback): self;

    /**
     * Is called when a connection is closed. The callback is invoked with
     * (int $connection).
     */
    abstract public function onClose(callable $callback): self;

    /**
     * Sets the maximum packet length in bytes.
     */
    abstract public function setPackageMaxLength(int $bytes): self;

    /**
     * Sets the number of workers.
     */
    abstract public function setWorkerNumber(int $num): self;

    /**
     * Returns the native server object from the adapter.
     */
    abstract public function getNative(): mixed;

    /**
     * Returns all connections.
     *
     * @return array<mixed>
     */
    abstract public function getConnections(): array;
}
