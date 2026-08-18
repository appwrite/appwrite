<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * A single due run of a registered schedule.
 */
final readonly class Occurrence
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $due,
        public mixed $payload = null,
        /** The definition this run was selected against; see {@see Scheduler::isCurrent()}. */
        public string $version = '',
    ) {}

    /**
     * Stable identity of this run: the schedule and the moment it was due.
     *
     * Delivery is at-least-once, so a handover or a retried tick can hand
     * the same run over twice. Deriving the downstream identity from this
     * key — the row id, the message id, the job name — turns that second
     * delivery into a conflict the consumer can ignore, which is how
     * at-least-once transport becomes effectively-once work. Consumers
     * with a constrained id format should hash it rather than reshape it.
     */
    public function key(): string
    {
        return $this->id . '@' . $this->due->format('U.u');
    }
}
