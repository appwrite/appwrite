<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use Utopia\Lock\Exception\Contention;
use Utopia\Lock\Lock;

/**
 * Held by another request at the moment it is asked for, and released by that
 * request within any wait longer than zero.
 */
final class BrieflyHeldLock implements Lock
{
    public function acquire(float $timeout = 0.0): bool
    {
        return $timeout > 0.0;
    }

    public function tryAcquire(): bool
    {
        return false;
    }

    public function release(): void
    {
    }

    public function withLock(callable $callback, float $timeout = 0.0): mixed
    {
        if (! $this->acquire($timeout)) {
            throw new Contention('Held by another request');
        }

        return $callback();
    }
}
