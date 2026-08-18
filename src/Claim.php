<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * The scheduler's shared record: who leads, until when, how far coverage
 * has been committed, and how much of the source had been read when it
 * got there.
 *
 * Leadership and coverage share one lifecycle — the leader advances both
 * on every commit — so they share one record, and one compare-and-swap
 * makes the commit fenced: a deposed leader's late write no longer
 * matches the stored token and cannot rewind coverage.
 *
 * `syncedUntil` is what lets a successor tell the difference between a
 * schedule its predecessor had already covered and one the predecessor
 * never saw. Without it a cold start has to choose between replaying
 * history for every schedule it loads and losing the runs of schedules
 * created in the predecessor's last unsynced moments.
 */
final readonly class Claim
{
    /**
     * Every moment is Unix seconds with microsecond precision, the same
     * unit {@see Clock} works in. The two watermarks are null until the
     * first ever commit; a claim always has an expiry.
     *
     * @param string $token the holder's identity; empty when the claim was released
     * @param float $expiresAt at or past this moment, any instance may take over
     * @param float|null $coveredUntil how far committed coverage reaches
     * @param float|null $syncedUntil when the source was last read successfully. Every
     *                                schedule that existed then was known, so the coverage
     *                                above accounts for it; anything created later was
     *                                invisible and is owed its own coverage
     */
    public function __construct(
        public string $token,
        public float $expiresAt,
        public ?float $coveredUntil,
        public ?float $syncedUntil = null,
    ) {}
}
