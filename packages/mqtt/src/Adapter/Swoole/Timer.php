<?php

namespace Utopia\Mqtt\Adapter\Swoole;

interface Timer
{
    public function onTick(callable $callback): self;

    public function onClear(callable $callback): self;

    public function schedule(int $id, int $keepAlive): void;

    public function clear(int $id): void;
}
