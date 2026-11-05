<?php

declare(strict_types=1);

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;

class Stack extends Adapter
{
    /** @var array<mixed> */
    protected array $pool = [];

    /**
     * Initialize the stack-based pool.
     *
     * Note:
     * - `$size` is accepted for API compatibility with other pool adapters.
     * - The stack adapter does NOT enforce capacity limits.
     * - `$size` is ignored because the pool is backed by a simple array.
     *
     * @param  int  $size  Ignored by the stack adapter.
     */
    public function initialize(int $size): static
    {
        $this->pool = [];

        return $this;
    }

    public function push(mixed $connection): static
    {
        // Push connection to pool
        $this->pool[] = $connection;

        return $this;
    }

    /**
     * Pop an item from the stack.
     *
     * Note: The stack adapter does not support blocking operations.
     * The `$timeout` parameter is ignored.
     *
     * @param  float  $timeout  Ignored by the stack adapter.
     * @return mixed|null Returns the popped item, or null if the stack is empty.
     */
    public function pop(float $timeout): mixed
    {
        return array_pop($this->pool);
    }

    public function count(): int
    {
        return \count($this->pool);
    }

    /**
     * Executes the callback without acquiring a lock.
     *
     * This implementation does not provide mutual exclusion.
     *
     * @param  callable  $callback  Callback to execute.
     * @return mixed The value returned by the callback.
     */
    public function synchronized(callable $callback): mixed
    {
        return $callback();
    }
}
