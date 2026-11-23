<?php

declare(strict_types=1);

namespace Utopia\Schedule\Clock;

use Utopia\Schedule\Clock;

/**
 * Wall clock. Under Swoole with runtime hooks enabled, usleep() yields
 * the coroutine instead of blocking the process.
 */
final class System implements Clock
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }
}
