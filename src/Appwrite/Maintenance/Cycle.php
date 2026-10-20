<?php

namespace Appwrite\Maintenance;

/**
 * How a period is split into slices: every inactive project is maintained once per period, one slice per interval.
 *
 * Periods under two intervals give a single slot, so each run reaches every inactive project.
 */
final readonly class Cycle
{
    public const int INTERVAL = 300;

    public const int TICK = 60;

    public int $interval;

    public int $slots;

    public int $tick;

    /**
     * @throws \InvalidArgumentException when the period is under one second
     */
    public function __construct(int $period)
    {
        if ($period < 1) {
            throw new \InvalidArgumentException('Maintenance period must be at least 1 second, got ' . $period);
        }

        $this->interval = \min(self::INTERVAL, $period);
        $this->slots = \intdiv($period, $this->interval);
        $this->tick = \min(self::TICK, $this->interval);
    }

    /**
     * @throws \InvalidArgumentException when the value is not a whole number of seconds of at least 1
     */
    public static function parse(string $period): self
    {
        $seconds = \filter_var($period, FILTER_VALIDATE_INT);
        if ($seconds === false) {
            throw new \InvalidArgumentException('Maintenance period must be a whole number of seconds, got "' . $period . '"');
        }

        return new self($seconds);
    }

    /**
     * Seconds until the slots come round again.
     */
    public function seconds(): int
    {
        return $this->slots * $this->interval;
    }

}
