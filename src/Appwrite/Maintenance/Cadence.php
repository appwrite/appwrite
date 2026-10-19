<?php

namespace Appwrite\Maintenance;

/**
 * How often the sweep ticks and how many slots each cohort is spread over. A cohort with N slots covers every one
 * of its projects once per N ticks.
 */
final readonly class Cadence
{
    public const int HOUR = 3600;

    public const int DAY = 86400;

    public const int WEEK = 604800;

    public function __construct(
        public int $tick,
        public int $activeSlots,
        public int $inactiveSlots,
    ) {
    }

    /**
     * Ticks at most hourly, and faster when the active interval is shorter than an hour.
     */
    public static function fromIntervals(int $active, int $inactive): self
    {
        $active = $active > 0 ? $active : self::DAY;
        $inactive = $inactive > 0 ? $inactive : self::WEEK;
        $tick = \min(self::HOUR, $active);

        return new self(
            tick: $tick,
            activeSlots: \max(1, \intdiv($active, $tick)),
            inactiveSlots: \max(1, \intdiv($inactive, $tick)),
        );
    }

    /**
     * $startTime on 1970-01-01, so the schedule does not depend on when the process booted.
     */
    public static function anchor(string $startTime, \DateTimeZone $timezone): int
    {
        return (new \DateTimeImmutable('1970-01-01 ' . $startTime, $timezone))->getTimestamp();
    }

    /**
     * The first tick boundary after $now, and never one at or before $last, the boundary that ran previously.
     */
    public function next(float $now, ?int $last, int $anchor): int
    {
        $boundary = $anchor + ((int) \floor(($now - $anchor) / $this->tick) + 1) * $this->tick;

        return $last === null ? $boundary : \max($boundary, $last + $this->tick);
    }

    /**
     * The first tick boundary in ($from, $to] whose slot out of $slots is $owner, or null when none is.
     */
    public function reaches(int $owner, int $slots, int $anchor, int $from, int $to): ?int
    {
        for ($boundary = $this->next($from, null, $anchor); $boundary <= $to; $boundary += $this->tick) {
            if (Slot::at($boundary, $anchor, $this->tick, $slots)->index === $owner) {
                return $boundary;
            }
        }

        return null;
    }

    public function slots(Cohort $cohort): int
    {
        return match ($cohort) {
            Cohort::Active => $this->activeSlots,
            Cohort::Inactive => $this->inactiveSlots,
        };
    }
}
