<?php

declare(strict_types=1);

namespace Utopia\Schedule;

/**
 * When a schedule should run, expressed as pure occurrence math.
 *
 * A trigger never consults the current time: callers hand it an explicit
 * half-open window and it answers which occurrences fall inside. This is
 * the contract that makes a scheduler immune to evaluation-time races —
 * an occurrence belongs to exactly one window no matter how long the
 * caller's loop has been running when the trigger is evaluated.
 *
 * Implementations live alongside this interface: {@see Trigger\Cron},
 * {@see Trigger\Interval}, {@see Trigger\At} and {@see Trigger\Shifted},
 * which moves another trigger's occurrences by a fixed offset.
 */
interface Trigger
{
    /**
     * Occurrences inside the half-open window [$start, $end), ascending.
     *
     * An occurrence exactly at $start belongs to this window; one exactly
     * at $end belongs to the next. Consecutive windows that share a
     * boundary therefore select every occurrence exactly once.
     *
     * @return list<\DateTimeImmutable>
     */
    public function occurrencesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array;

    /**
     * Whether this trigger keeps producing occurrences. One-shot triggers
     * return false and are retired by the scheduler once delivered.
     */
    public function recurring(): bool;
}
