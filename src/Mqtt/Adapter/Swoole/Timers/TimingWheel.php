<?php

namespace Utopia\Mqtt\Adapter\Swoole\Timers;

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Keepalive;

class TimingWheel implements Timer
{
    /** @var callable|null */
    private $onExpire = null;

    public function __construct(private readonly Keepalive $wheel = new Keepalive())
    {
    }

    public function schedule(int $id, float $expiresAt): void
    {
        $this->wheel->schedule($id, $expiresAt);
    }

    public function remove(int $id): void
    {
        $this->wheel->remove($id);
    }

    public function onExpire(callable $callback): void
    {
        $this->onExpire = $callback;
    }

    public function start(Adapter $adapter): void
    {
        $adapter->tick($this->wheel->interval, function (): void {
            foreach ($this->wheel->drain((int) \microtime(true)) as $id) {
                if ($this->onExpire !== null) {
                    \call_user_func($this->onExpire, $id);
                }
            }
        });
    }
}
