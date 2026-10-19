<?php

namespace Appwrite\Maintenance;

use Utopia\Schedule\Clock;

/**
 * Sleeps until each tick boundary in turn. It never runs a boundary twice and never wakes before the boundary it
 * returns, even when the clock steps backwards.
 */
final class Ticker
{
    private ?int $last = null;

    public function __construct(
        private readonly Cadence $cadence,
        private readonly int $anchor,
        private readonly Clock $clock,
    ) {
    }

    public function next(): Tick
    {
        $now = (float) $this->clock->now()->format('U.u');
        $boundary = $this->cadence->next($now, $this->last, $this->anchor);
        $skipped = $this->last === null ? 0 : \intdiv($boundary - $this->last, $this->cadence->tick) - 1;

        $this->clock->sleep($boundary - $now);
        $this->last = $boundary;

        return new Tick($boundary, $skipped);
    }
}
