<?php

namespace Utopia\Mqtt\Adapter\Swoole;

use Swoole\Timer as SwooleTimer;
use Utopia\Mqtt\Timer as TimerContract;

class Timer implements TimerContract
{
    public function tick(int $seconds, callable $callback): int
    {
        return SwooleTimer::tick($seconds * 1000, $callback);
    }

    public function after(int $seconds, callable $callback): int
    {
        return SwooleTimer::after($seconds * 1000, $callback);
    }

    public function clear(int $id): void
    {
        SwooleTimer::clear($id);
    }
}
