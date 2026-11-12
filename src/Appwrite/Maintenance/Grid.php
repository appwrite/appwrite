<?php

namespace Appwrite\Maintenance;

/**
 * When maintenance runs: the start time of a day plus whole intervals, so a restart waits at most one interval
 * instead of until the next start time.
 */
final readonly class Grid
{
    /**
     * @param string $start time of day the runs line up on, as hour:minutes
     * @param int $interval seconds between runs
     */
    public function __construct(
        private string $start,
        private int $interval,
    ) {
    }

    /**
     * The first run after $now, in $now's timezone: never $now itself, and at most one interval away.
     */
    public function next(\DateTime $now): \DateTime
    {
        $anchor = new \DateTime($now->format("Y-m-d {$this->start}"), $now->getTimezone());
        $elapsed = $now->getTimestamp() - $anchor->getTimestamp();
        $steps = (int) \floor($elapsed / $this->interval) + 1;

        return $anchor->setTimestamp($anchor->getTimestamp() + $steps * $this->interval);
    }
}
