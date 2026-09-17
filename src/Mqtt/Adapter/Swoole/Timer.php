<?php

namespace Utopia\Mqtt\Adapter\Swoole;

interface Timer
{
    public function tick(int $seconds, callable $callback): int;

    public function after(int $seconds, callable $callback): int;

    public function clear(int $id): void;
}
