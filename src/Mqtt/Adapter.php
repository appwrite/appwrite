<?php

namespace Utopia\Mqtt;

use Utopia\Mqtt\Adapter\Swoole\Timer;

abstract class Adapter
{
    abstract public function onStart(callable $callback): self;

    abstract public function onWorkerStart(callable $callback): self;

    abstract public function onReceive(callable $callback): self;

    abstract public function onClose(callable $callback): self;

    abstract public function send(int $connection, string $message): void;

    abstract public function close(int $connection): void;

    abstract public function timer(): Timer;

    abstract public function start(): void;

    abstract public function shutdown(): void;
}
