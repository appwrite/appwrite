<?php

namespace Utopia\Abuse\Adapters;

use Throwable;
use Utopia\Abuse\Adapter;

// token bucket rate limiter
abstract class TokenBucket extends Adapter
{
    /**
     * Bucket capacity (max tokens). 0 is equal to unlimited.
     *
     * @var int
     */
    protected int $tokens = 0;

    /**
     * @var int
     */
    protected int $timestamp;

    /**
     * Count
     *
     * Read-only estimate of the tokens already consumed from the bucket
     * (capacity minus the tokens available after refilling for the elapsed time).
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
     * Atomically refills the bucket for the elapsed time and consumes a single
     * token if one is available. Storage backends MUST implement this as a single
     * atomic operation so the refill-decide-consume sequence cannot race between
     * concurrent requests. Returns true when the request is abuse (bucket empty).
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
        $left = $this->tokens - ($this->count($this->parseKey(), $this->timestamp) + 1);

        return (0 > $left) ? 0 : $left;
    }

    /**
     * Limit
     *
     * Return the bucket capacity
     *
     * @return int
     */
    public function limit(): int
    {
        return $this->tokens;
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
     * Clear the bucket for the current key so it starts full again.
     *
     * @return void
     *
     * @throws \Exception
     */
    abstract public function reset(): void;
}
