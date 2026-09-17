<?php

namespace Utopia\Mqtt;

abstract class Adapter
{
    abstract public function onStart(callable $callback): self;

    abstract public function onWorkerStart(callable $callback): self;

    abstract public function onReceive(callable $callback): self;

    abstract public function onClose(callable $callback): self;

    abstract public function send(int $connection, string $message): void;

    abstract public function close(int $connection): void;

    abstract public function tick(int $seconds, callable $callback): int;

    abstract public function after(int $seconds, callable $callback): int;

    abstract public function clear(int $id): void;

    abstract public function start(): void;

    abstract public function shutdown(): void;
}
