<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Utopia\Schedule\Clock;
use Utopia\Schedule\Clock\Test as TestClock;

/**
 * The test clock with a signal on it: the first sleep that reaches $at runs $signal once, as a SIGTERM arrives while the
 * scheduler sleeps between ticks. A sleep past $deadline throws, so a loop the signal fails to stop ends the test
 * instead of running forever.
 */
final class SignalClock implements Clock
{
    public ?\Closure $signal = null;

    public function __construct(
        private readonly TestClock $clock,
        private readonly float $at,
        private readonly float $deadline,
    ) {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->clock->advance($seconds);
        $now = (float) $this->clock->now()->format('U.u');

        if ($this->signal instanceof \Closure && $now >= $this->at) {
            $signal = $this->signal;
            $this->signal = null;
            $signal();
        }

        if ($now > $this->deadline) {
            throw new \RuntimeException('Still running ' . ($now - $this->at) . ' seconds after the signal');
        }
    }
}
