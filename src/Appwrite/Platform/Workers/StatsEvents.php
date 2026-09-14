<?php

namespace Appwrite\Platform\Workers;

/**
 * Same worker as {@see StatsCalculations}, bound to the gauge-only queue.
 *
 * Real-time gauges arrive every 60s. The calculations queue also carries the
 * hourly per-project full-count fan-out, whose backlog outlives its own
 * interval, so a shared queue starves gauges instead of merely delaying them.
 */
class StatsEvents extends StatsCalculations
{
    public static function getName(): string
    {
        return 'stats-events';
    }

    public function __construct()
    {
        parent::__construct();
        $this->desc('Stats events worker');
    }
}
