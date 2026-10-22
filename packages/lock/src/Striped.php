<?php

declare(strict_types=1);

namespace Utopia\Lock;

use Utopia\Lock\Exception\Contention;

/**
 * A fixed pool of mutexes keyed by hash. The number of underlying locks stays constant no matter how
 * many distinct keys are used, so callers that would otherwise allocate a lock per key (per socket,
 * per id, ...) keep memory bounded. Keys that hash to the same stripe serialise against each other.
 */
final class Striped
{
    private const DEFAULT_STRIPES = 256;

    /** @var array<int, Mutex> */
    private array $stripes = [];

    private int $size;

    public function __construct(int $size = self::DEFAULT_STRIPES)
    {
        $this->size = \max(1, $size);

        for ($i = 0; $i < $this->size; $i++) {
            $this->stripes[$i] = new Mutex();
        }
    }

    public function acquire(int|string $key, float $timeout = 0.0): bool
    {
        return $this->lockFor($key)->acquire($timeout);
    }

    public function tryAcquire(int|string $key): bool
    {
        return $this->lockFor($key)->tryAcquire();
    }

    public function release(int|string $key): void
    {
        $this->lockFor($key)->release();
    }

    /**
     * Acquire the stripe for $key, run $callback, then release it. Released even when $callback throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws Contention when acquire times out
     */
    public function withLock(int|string $key, callable $callback, float $timeout = 0.0): mixed
    {
        return $this->lockFor($key)->withLock($callback, $timeout);
    }

    public function lockFor(int|string $key): Lock
    {
        $hash = \is_int($key) ? $key : \crc32($key);

        return $this->stripes[\abs($hash % $this->size)];
    }
}
