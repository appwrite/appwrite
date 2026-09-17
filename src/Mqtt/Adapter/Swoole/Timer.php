<?php

namespace Utopia\Mqtt\Adapter\Swoole;

use Utopia\Mqtt\Adapter;

interface Timer
{
    public function schedule(int $id, float $expiresAt): void;

    public function remove(int $id): void;

    public function onExpire(callable $callback): void;

    public function start(Adapter $adapter): void;
}
