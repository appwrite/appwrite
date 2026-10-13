<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use Utopia\Lock\Exception\Contention;
use Utopia\Lock\Lock;

/**
 * Held by another request for longer than any caller is willing to wait.
 */
final class HeldLock implements Lock
{
    public function acquire(float $timeout = 0.0): bool
    {
        if ($timeout > 0.0) {
            \usleep((int) ($timeout * 1_000_000));
        }

        return false;
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
        throw new Contention('Held by another request');
    }
}
