<?php

declare(strict_types=1);

namespace Utopia\Lock;

interface Lock
{
    /**
     * Block up to $timeout seconds trying to acquire the lock.
     *
     * Passing 0.0 means do not wait; a negative value means wait forever.
     *
     * @return bool true if the lock was acquired
     */
    public function acquire(float $timeout = 0.0): bool;

    /**
     * Non-blocking acquire attempt.
     *
     * @return bool true if the lock was acquired
     */
    public function tryAcquire(): bool;

    /**
     * Release the lock. Safe to call when the lock is not held.
     */
    public function release(): void;

    /**
     * Acquire the lock, run $callback, then release the lock.
     *
     * The lock is always released, including when $callback throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws \Utopia\Lock\Exception\Contention when acquire times out
     */
    public function withLock(callable $callback, float $timeout = 0.0): mixed;
}
