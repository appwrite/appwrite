<?php

namespace Utopia\Mqtt\Adapter\Swoole\Timers;

use Utopia\Mqtt\Adapter\Swoole\Timer;

class NoTimer implements Timer
{
    public function onTick(callable $callback): self
    {
        return $this;
    }

    public function onClear(callable $callback): self
    {
        return $this;
    }

    public function schedule(int $id, int $keepAlive): void
    {
    }

    public function clear(int $id): void
    {
    }
}
