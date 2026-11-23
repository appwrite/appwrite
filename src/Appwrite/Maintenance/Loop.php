<?php

namespace Appwrite\Maintenance;

use Utopia\Schedule\Clock;
use Utopia\Schedule\Clock\System as SystemClock;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Store;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

/**
 * One replica's scheduler, publishing slots until stopped. A stop lets the running tick finish, then the scheduler
 * releases its claim, so a standby takes over on its next tick instead of waiting out the lease.
 */
final class Loop
{
    private bool $stopped = false;

    private ?Scheduler $scheduler = null;

    /**
     * @param string $token kept across runs: the release at the end of a run can itself fail, and a new token would
     *                      then sit out the stale claim as a follower instead of renewing it
     */
    public function __construct(
        private readonly Cycle $cycle,
        private readonly Dispatcher $dispatcher,
        private readonly string $token,
        private readonly Telemetry $telemetry = new NoTelemetry(),
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    /**
     * Publish slots from $store's claim until stopped, or until a failure, which propagates.
     */
    public function run(Store $store): void
    {
        if ($this->stopped) {
            return;
        }

        $this->scheduler = $this->cycle->scheduler($store, $this->token, $this->telemetry, $this->clock);

        try {
            $this->scheduler->run($this->dispatcher);
        } finally {
            $this->scheduler = null;
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->scheduler?->stop();
    }

    /**
     * Wait before running again, a second at a time, so a stop ends the wait instead of outlasting the grace period.
     */
    public function pause(int $seconds): void
    {
        for ($waited = 0; $waited < $seconds && !$this->stopped; $waited++) {
            $this->clock->sleep(1);
        }
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }
}
