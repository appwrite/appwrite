<?php

namespace Utopia\Abuse\Adapters;

use Throwable;
use Utopia\Abuse\Adapter;

// counter based sliding window
abstract class SlidingWindow extends Adapter
{
    /**
     * @var int
     */
    protected int $limit = 0;

    /**
     * @var int
     */
    protected int $timestamp;

    /**
     * Count
     *
     * Read-only weighted estimate of hits in the current sliding window.
     *
     * @param  string  $key
     * @param  int  $timestamp
     * @return int
     *
     * @throws \Exception
     */
    abstract protected function count(string $key, int $timestamp): int;

    /**
     * Check
     *
     * Atomically evaluates the sliding-window estimate and records the request
     * if it is under the limit. Storage backends MUST implement this as a single
     * atomic operation so the read-decide-increment sequence cannot race between
     * concurrent requests. Returns true when the request is abuse (limit reached).
     * limit 0 is equal to unlimited.
     *
     * @return bool
     *
     * @throws \Exception|Throwable
     */
    abstract public function check(): bool;

    /**
     * Remaining
     *
     * Returns the number of current remaining counts
     *
     * @return int
     *
     * @throws \Exception
     */
    public function remaining(): int
    {
        $left = $this->limit - ($this->count($this->parseKey(), $this->timestamp) + 1);

        return (0 > $left) ? 0 : $left;
    }

    /**
     * Limit
     *
     * Return the limit integer
     *
     * @return int
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Time
     *
     * Return the timestamp
     *
     * @return int
     */
    public function time(): int
    {
        return $this->timestamp;
    }

    /**
     * Reset
     *
     * Clear the counters for the current key so the limit starts fresh.
     * Implementations must clear both the current and previous window buckets.
     *
     * @return void
     *
     * @throws \Exception
     */
    abstract public function reset(): void;
}
