<?php

namespace Utopia\Mqtt\Adapter\Swoole\Timers;

use Swoole\Timer as SwooleTimer;
use Utopia\Mqtt\Adapter\Swoole\Timer;

class TimingWheel implements Timer
{
    /** @var array<int, array<int, true>> deadline second => set of ids */
    private array $buckets = [];

    private int $cursor;

    /** @var array<int, int> id => the bucket it currently occupies */
    private array $slots = [];

    private ?int $heartbeat = null;

    /** @var callable|null */
    private $onTick = null;

    /** @var callable|null */
    private $onClear = null;

    public function __construct(
        public readonly int $interval = 20,
        public readonly float $multiplier = 1.5,
        ?int $now = null,
    ) {
        $this->cursor = $now ?? \time();
    }

    public function onTick(callable $callback): self
    {
        $this->onTick = $callback;

        return $this;
    }

    public function onClear(callable $callback): self
    {
        $this->onClear = $callback;

        return $this;
    }

    public function schedule(int $id, int $keepAlive): void
    {
        $this->drop($id);

        if ($keepAlive <= 0) {
            return;
        }

        $slot = \max((int) \ceil(\microtime(true) + $keepAlive * $this->multiplier), $this->cursor + 1);
        $this->buckets[$slot][$id] = true;
        $this->slots[$id] = $slot;

        $this->heartbeat ??= SwooleTimer::tick($this->interval * 1000, function (): void {
            $this->drain((int) \microtime(true));
        });
    }

    public function clear(int $id): void
    {
        if (!isset($this->slots[$id])) {
            return;
        }

        $this->drop($id);

        if ($this->onClear !== null) {
            \call_user_func($this->onClear, $id);
        }
    }

    public function drain(int $now): void
    {
        for ($second = $this->cursor + 1; $second <= $now; $second++) {
            if (!isset($this->buckets[$second])) {
                continue;
            }

            foreach (\array_keys($this->buckets[$second]) as $id) {
                unset($this->slots[$id]);
                if ($this->onTick !== null) {
                    \call_user_func($this->onTick, $id);
                }
            }

            unset($this->buckets[$second]);
        }

        $this->cursor = \max($this->cursor, $now);
    }

    private function drop(int $id): void
    {
        $slot = $this->slots[$id] ?? null;
        if ($slot === null) {
            return;
        }

        unset($this->buckets[$slot][$id], $this->slots[$id]);

        if (($this->buckets[$slot] ?? null) === []) {
            unset($this->buckets[$slot]);
        }
    }
}
