<?php

namespace Appwrite\Maintenance;

/**
 * What one sweep did. $reads counts project reads. $dropped counts the blocks the deadline left unreached in block
 * mode; a walk by pages cannot count what it did not read, so it only sets $truncated. $waited is seconds spent
 * behind the backlog.
 */
final readonly class Outcome
{
    public function __construct(
        public Cohort $cohort,
        public Slot $slot,
        public int $reads,
        public int $enqueued,
        public int $waited,
        public int $dropped,
        public bool $truncated,
    ) {
    }
}
