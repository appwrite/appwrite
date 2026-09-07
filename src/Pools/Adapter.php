<?php

declare(strict_types=1);

namespace Utopia\Pools;

/**
 * Storage and synchronisation for a pool's idle resources.
 *
 * The pool owns capacity accounting; an adapter only holds what is idle and
 * serialises access to the pool's bookkeeping.
 */
abstract class Adapter
{
    /**
     * Prepare to hold up to $size idle resources. The pool enforces the limit,
     * so $size is a sizing hint rather than a cap the adapter must police.
     */
    abstract public function initialize(int $size): static;

    abstract public function push(mixed $connection): static;

    /**
     * Take an idle resource, waiting up to $timeout seconds for one to arrive.
     *
     * Adapters without concurrency return immediately: with a single execution
     * context nothing can hand a resource back while the caller waits, so
     * waiting cannot change the answer.
     *
     * @return mixed The resource, or null/false when none is available.
     */
    abstract public function pop(float $timeout): mixed;

    abstract public function count(): int;

    /**
     * Release anyone currently blocked in {@see pop()}, without handing over a
     * resource. They will find out for themselves whether the state they were
     * waiting on has changed; this says only that it is worth looking again.
     *
     * Adapters with no way to block satisfy this by construction: a caller that
     * never waits has nothing to release.
     */
    public function unblock(): static
    {
        return $this;
    }

    /**
     * Run $callback atomically with respect to other pool operations.
     *
     * Adapters without concurrency satisfy this by construction and may call
     * $callback directly.
     *
     * @return mixed The callback's return value.
     */
    abstract public function synchronized(callable $callback): mixed;
}
