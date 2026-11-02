<?php

namespace Utopia\Abuse\Adapter;

use Utopia\Abuse\Adapter;
use Utopia\Abuse\Result;

abstract readonly class TimeLimit extends Adapter
{
    /**
     * @param  string  $key  Key pattern, e.g. "ip:{ip}"; placeholders are filled by withParams()
     * @param  int  $limit  Maximum hits per window; 0 means unlimited
     * @param  int  $seconds  Window length in seconds
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $key, protected int $limit, protected int $seconds)
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('seconds must be greater than 0');
        }

        parent::__construct($key);
    }

    /**
     * Record a hit in the window unless the limit is reached.
     *
     * @return int  the count before this call
     */
    abstract protected function hit(string $key, int $window): int;

    abstract protected function count(string $key, int $window): int;

    abstract protected function set(string $key, int $window, int $value): void;

    /**
     * Take back one hit from the window, never below zero.
     */
    abstract protected function unhit(string $key, int $window): void;

    protected function now(): int
    {
        return \time();
    }

    final protected function window(int $now): int
    {
        return $now - ($now % $this->seconds);
    }

    #[\Override]
    final public function check(): Result
    {
        $window = $this->window($this->now());

        if ($this->limit === 0) {
            return $this->unlimited($window);
        }

        return $this->result($this->hit($this->key(), $window), $window);
    }

    #[\Override]
    final public function peek(): Result
    {
        $window = $this->window($this->now());

        if ($this->limit === 0) {
            return $this->unlimited($window);
        }

        return $this->result($this->count($this->key(), $window), $window);
    }

    #[\Override]
    final public function reset(): void
    {
        $this->set($this->key(), $this->window($this->now()), 0);
    }

    /**
     * Give back the hit recorded by the check() that returned $hit, for a request that turned out not to
     * count. Reserving with check() and releasing afterwards keeps the limit atomic under concurrent requests,
     * where reading on arrival and counting afterwards lets a burst all pass the read before any of them counts.
     * The hit is taken back from the window it was recorded in, so a request that outlives its window never
     * releases a hit another request reserved in the next one.
     */
    final public function release(Result $hit): void
    {
        if ($this->limit === 0 || $hit->limited) {
            return;
        }

        $this->unhit($this->key(), $hit->reset - $this->seconds);
    }

    private function result(int $used, int $window): Result
    {
        return new Result(
            limited: $used >= $this->limit,
            limit: $this->limit,
            remaining: \max(0, $this->limit - $used - 1),
            reset: $window + $this->seconds,
        );
    }

    private function unlimited(int $window): Result
    {
        return new Result(
            limited: false,
            limit: 0,
            remaining: 0,
            reset: $window + $this->seconds,
        );
    }
}
