<?php

namespace Utopia\Abuse\Adapters;

use Throwable;
use Utopia\Abuse\Adapter;

abstract class TimeLimit extends Adapter
{
    /**
     * @var int
     */
    protected int $limit = 0;

    /**
     * @var int|null
     */
    protected ?int $count = null;

    /**
     * @var int
     */
    protected int $timestamp;

    /**
     * Check
     *
     * Checks if number of counts is bigger or smaller than current limit
     *
     * @param  string  $key
     * @param  int  $timestamp
     * @return int
     *
     * @throws \Exception
     */
    abstract protected function count(string $key, int $timestamp): int;

    abstract protected function hit(string $key, int $timestamp): void;

    abstract protected function set(string $key, int $timestamp, int $value): void;

    /**
     * Check
     *
     * Checks if number of counts is bigger or smaller than current limit. limit 0 is equal to unlimited
     *
     * @return bool
     *
     * @throws \Exception|Throwable
     */
    public function check(): bool
    {
        if (0 == $this->limit) {
            return false;
        }

        $key = $this->parseKey();

        if ($this->limit > $this->count($key, $this->timestamp)) {
            $this->hit($key, $this->timestamp);

            return false;
        }

        return true;
    }

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
        $left = $this->limit - ($this->count($this->parseKey(), $this->timestamp) + 1); // Add one because we need to say how many left not how many done

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
     * Reset the count to 0 for the current key and timestamp
     *
     * @return void
     *
     * @throws \Exception
     */
    public function reset(): void
    {
        $this->set($this->parseKey(), $this->timestamp, 0);
    }
}
