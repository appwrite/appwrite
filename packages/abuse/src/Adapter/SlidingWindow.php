<?php

namespace Utopia\Abuse\Adapter;

use Utopia\Abuse\Adapter;
use Utopia\Abuse\Result;

abstract readonly class SlidingWindow extends Adapter
{
    public function __construct(
        string $key,
        protected int $limit,
        protected int $windowSize,
    ) {
        if ($windowSize <= 0) {
            throw new \InvalidArgumentException('windowSize must be greater than 0');
        }

        parent::__construct($key);
    }

    abstract protected function hit(string $key, int $window, float $elapsed): int;

    abstract protected function count(string $key, int $window, float $elapsed): int;

    abstract protected function clear(string $key, int $window): void;

    protected function now(): int
    {
        return \time();
    }

    /**
     * @return array{0: int, 1: float}
     */
    final protected function window(int $now): array
    {
        $start = $now - ($now % $this->windowSize);

        return [$start, ($now - $start) / (float) $this->windowSize];
    }

    #[\Override]
    final public function check(): Result
    {
        [$window, $elapsed] = $this->window($this->now());

        if ($this->limit === 0) {
            return new Result(false, 0, 0, $window + $this->windowSize);
        }

        return $this->result($this->hit($this->key(), $window, $elapsed), $window);
    }

    #[\Override]
    final public function peek(): Result
    {
        [$window, $elapsed] = $this->window($this->now());

        if ($this->limit === 0) {
            return new Result(false, 0, 0, $window + $this->windowSize);
        }

        return $this->result($this->count($this->key(), $window, $elapsed), $window);
    }

    #[\Override]
    final public function reset(): void
    {
        [$window] = $this->window($this->now());

        $this->clear($this->key(), $window);
    }

    private function result(int $used, int $window): Result
    {
        return new Result(
            limited: $used >= $this->limit,
            limit: $this->limit,
            remaining: \max(0, $this->limit - $used - 1),
            reset: $window + $this->windowSize,
        );
    }
}
