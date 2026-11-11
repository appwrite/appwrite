<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Support;

final class Wait
{
    /**
     * Whether the condition holds within the timeout, checked every millisecond.
     *
     * @param \Closure(): bool $condition
     */
    public static function until(\Closure $condition, float $timeout = 2.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(1_000);
        }

        return true;
    }
}
