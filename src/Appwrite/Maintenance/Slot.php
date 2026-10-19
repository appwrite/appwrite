<?php

namespace Appwrite\Maintenance;

/**
 * One of $count shares of a cohort. $cycle counts full passes over the cohort since the anchor, so consecutive
 * cycles can start at a different block.
 */
final readonly class Slot
{
    public function __construct(
        public int $index,
        public int $count,
        public int $cycle = 0,
    ) {
    }

    /**
     * The slot of the tick starting at $tickStart. Derived from the clock alone, so instances agree without shared
     * state.
     */
    public static function at(int $tickStart, int $anchor, int $tick, int $count): self
    {
        $tick = \max(1, $tick);
        $count = \max(1, $count);
        $ticks = self::floorDiv($tickStart - $anchor, $tick);

        return new self(
            index: $ticks - self::floorDiv($ticks, $count) * $count,
            count: $count,
            cycle: self::floorDiv($ticks, $count),
        );
    }

    public static function full(): self
    {
        return new self(0, 1);
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        return \intdiv($dividend, $divisor) - (($dividend % $divisor !== 0 && ($dividend < 0) !== ($divisor < 0)) ? 1 : 0);
    }
}
