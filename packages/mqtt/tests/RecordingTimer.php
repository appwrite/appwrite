<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests;

use Utopia\Mqtt\Adapter\Swoole\Timer;

/** Records the keep-alive each connection was last scheduled with, so a test can tell when it would be reaped. */
final class RecordingTimer implements Timer
{
    /** @var array<int, int> id => keep-alive in seconds; 0 is never reaped */
    public array $scheduled = [];

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
        $this->scheduled[$id] = $keepAlive;
    }

    public function clear(int $id): void
    {
        unset($this->scheduled[$id]);
    }
}
