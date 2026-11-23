<?php

declare(strict_types=1);

namespace Utopia\Pools\Adapter;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Lock;
use Utopia\Pools\Adapter;

class Swoole extends Adapter
{
    /**
     * @var Channel<mixed>
     */
    protected Channel $pool;

    protected Lock $lock;

    /**
     * @var Channel<bool> Coalesced release signals. Kept apart from $pool so a
     * signal is never mistaken for, or counted as, an idle resource.
     */
    private Channel $signals;

    /** Shortest wait Swoole will honour without treating it as unbounded. */
    private const float POLL = 0.001;

    public function initialize(int $size): static
    {

        $this->pool = new Channel($size);
        $this->lock = new Lock();
        $this->signals = new Channel(1);

        return $this;
    }

    public function push(mixed $connection): static
    {
        // Push connection to channel
        $this->pool->push($connection);
        // A returned resource changes what a parked caller is waiting on, which
        // is exactly what unblock() is for. Waiters park on the signal rather
        // than on $pool so one primitive releases them however the state moved.
        $this->unblock();

        return $this;
    }

    /**
     * Pop an item from the pool, waiting up to $timeout seconds.
     *
     * Returns as soon as a resource arrives OR the wait is released by
     * {@see unblock()}; the caller decides what to do with an empty-handed
     * return, which is why this reports only "here is one" or "not now".
     *
     * @return mixed|false The pooled value, or false if nothing was handed over.
     */
    public function pop(float $timeout): mixed
    {
        // Swoole reads a non-positive channel timeout as "wait forever", the exact
        // opposite of a zero budget, so clamp to a single short poll instead.
        $budget = $timeout > 0.0 ? $timeout : self::POLL;

        if ($this->count() > 0) {
            return $this->take();
        }

        // Park on the release signal rather than on the resource queue, so one
        // primitive releases a caller however the state moved — a resource came
        // back, or capacity was freed without one.
        $this->signals->pop($budget);

        return $this->count() > 0 ? $this->take() : false;
    }

    /**
     * Take one idle resource and pass the baton on if more remain.
     *
     * Signals are coalesced to one, so a burst of returns that arrives while
     * nobody is parked would otherwise release a single caller and leave the
     * rest waiting beside resources that are already idle.
     */
    private function take(): mixed
    {
        $connection = $this->pool->pop(self::POLL);

        if ($this->count() > 0) {
            $this->unblock();
        }

        return $connection;
    }

    #[\Override]
    public function unblock(): static
    {
        // Coalesced, and remembered for a caller that has not parked yet: one
        // signal is enough, because a released caller re-reads the real state.
        if ($this->signals->length() === 0) {
            $this->signals->push(true);
        }

        return $this;
    }

    /**
     * Another coroutine can change the idle contents during a blocking pop().
     * @phpstan-impure
     */
    public function count(): int
    {
        return (int) $this->pool->length();
    }

    /**
     * Executes a callback while holding a lock.
     *
     * The lock is acquired before invoking the callback and is always released
     * afterward, even if the callback throws an exception.
     *
     * @param  callable  $callback  Callback to execute within the critical section.
     * @return mixed The value returned by the callback.
     *
     * @throws \RuntimeException If the lock cannot be acquired within the timeout.
     */
    public function synchronized(callable $callback): mixed
    {
        $acquired = $this->lock->lock();

        if (! $acquired) {
            throw new \RuntimeException('Failed to acquire lock');
        }

        try {
            return $callback();
        } finally {
            $this->lock->unlock();
        }
    }
}
