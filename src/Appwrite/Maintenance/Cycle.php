<?php

namespace Appwrite\Maintenance;

use Appwrite\Schedule\Source\Maintenance;
use Utopia\Schedule\Clock;
use Utopia\Schedule\Clock\System as SystemClock;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Store;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * How a period is split into slices: every inactive project is maintained once per period, one slice per interval.
 *
 * Periods under two intervals give a single slot, so each run reaches every inactive project.
 */
final readonly class Cycle
{
    public const int INTERVAL = 300;

    /**
     * The scheduler's schedule.lag gauge reads how far a window start trails "now", so it reads one tick at rest. A
     * one-second tick keeps it reporting real delay; slots still come once per interval from the trigger.
     */
    public const int TICK = 1;

    /**
     * How long a crashed leader holds the claim: a stopped one releases it. Well above a tick and a catch-up burst.
     */
    public const int LEASE = 60;

    public int $interval;

    public int $slots;

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

    /**
     * The slot a due moment owns. Slots follow the epoch grid, so every replica agrees on it.
     */
    public function slot(int $due): int
    {
        return \intdiv($due, $this->interval) % $this->slots;
    }

    /**
     * Moments missed while no replica could publish are caught up for a whole cycle: each one is a slot that would
     * otherwise wait for its next turn, and a shorter horizon would skip the same slots whenever outages recur at the
     * same hour. The worker drops a moment a newer one for its slot has superseded.
     */
    public function scheduler(Store $store, ?string $token = null, Telemetry $telemetry = new NoTelemetry(), Clock $clock = new SystemClock()): Scheduler
    {
        return new Scheduler(
            source: new Maintenance($this->interval),
            store: $store,
            tickSeconds: self::TICK,
            recoverSeconds: $this->seconds(),
            leaseSeconds: self::LEASE,
            token: $token,
            clock: $clock,
            telemetry: $telemetry,
        );
    }
}
