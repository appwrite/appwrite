<?php

namespace Utopia\Mqtt\Adapter\Swoole\Timers;

use Swoole\Timer as SwooleTimer;
use Utopia\Mqtt\Adapter\Swoole\Timer;
use Utopia\Mqtt\Keepalive;

class TimingWheel implements Timer
{
    private int $sequence = 0;

    private ?int $heartbeat = null;

    /** @var array<int, array{callback: callable, interval: int, recurring: bool}> */
    private array $timers = [];

    public function __construct(private readonly Keepalive $wheel = new Keepalive(interval: 1))
    {
    }

    public function tick(int $seconds, callable $callback): int
    {
        return $this->add($seconds, $callback, true);
    }

    public function after(int $seconds, callable $callback): int
    {
        return $this->add($seconds, $callback, false);
    }

    public function clear(int $id): void
    {
        $this->wheel->remove($id);
        unset($this->timers[$id]);
    }

    private function add(int $seconds, callable $callback, bool $recurring): int
    {
        $id = ++$this->sequence;
        $this->timers[$id] = ['callback' => $callback, 'interval' => $seconds, 'recurring' => $recurring];
        $this->wheel->schedule($id, \microtime(true) + $seconds);
        $this->heartbeat ??= SwooleTimer::tick(1000, $this->drain(...));

        return $id;
    }

    private function drain(): void
    {
        foreach ($this->wheel->drain((int) \microtime(true)) as $id) {
            $timer = $this->timers[$id] ?? null;
            if ($timer === null) {
                continue;
            }

            if ($timer['recurring']) {
                $this->wheel->schedule($id, \microtime(true) + $timer['interval']);
            } else {
                unset($this->timers[$id]);
            }

            \call_user_func($timer['callback']);
        }
    }
}
