<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * Time source and sleeper for the scheduler.
 *
 * Isolating the clock is what makes timing defects testable: a scheduler
 * driven by {@see Clock\Test} can reproduce a tick phase sitting
 * milliseconds before a minute boundary — the kind of state a wall clock
 * only reaches in production.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;

    public function sleep(float $seconds): void;
}
