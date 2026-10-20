<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Backlog;
use Appwrite\Maintenance\Sweep;
use Appwrite\Schedule\Source;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Store\Redis as RedisStore;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleMaintenance extends Action
{
    public static function getName(): string
    {
        return 'schedule-maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedule maintenance for projects not accessed in the last 30 days')
            ->inject('dbForPlatform')
            ->inject('publisherForDeletes')
            ->inject('pools')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, DeletePublisher $publisherForDeletes, Group $pools, Telemetry $telemetry): never
    {
        // Every inactive project is maintained once per period, one slice per interval.
        $period = \max(1, (int) System::getEnv('_APP_MAINTENANCE_INACTIVE_INTERVAL', '604800'));
        $interval = \min(Sweep::INTERVAL, $period);
        $slots = \max(1, \intdiv($period, $interval));
        $tick = \min(60, $interval);
        $region = System::getEnv('_APP_REGION', 'default');

        $sweep = new Sweep($dbForPlatform, $publisherForDeletes, $region, $interval, $slots);

        // Stable across retries, so a restarted loop renews its own claim instead of waiting it out as a follower.
        $token = \gethostname() . ':' . \getmypid();

        while (true) {
            try {
                $pools->get('lock')->use(function (\Redis $redis) use ($interval, $region, $tick, $telemetry, $token, $sweep): void {
                    $scheduler = new Scheduler(
                        source: new Source\Maintenance($interval),
                        store: new RedisStore($redis, 'schedule-maintenance-' . $region),
                        tickSeconds: $tick,
                        recoverSeconds: 3600,
                        leaseSeconds: 600,
                        token: $token,
                        telemetry: $telemetry,
                    );

                    // Caught here so the pool keeps a healthy connection; the slice is redelivered on the next run.
                    try {
                        $scheduler->run($sweep);
                    } catch (Backlog $backlog) {
                        Span::init('schedule.maintenance.backpressure');
                        Span::add('queue.pending', $backlog->pending);
                        Span::current()?->finish();

                        Console::info('schedule maintenance: ' . $backlog->getMessage() . ', retrying in ' . $tick . ' seconds');
                    }
                });
            } catch (\Throwable $error) {
                Span::init('schedule.maintenance.error');
                Span::current()?->finish(error: $error);

                Console::error('schedule maintenance: ' . $error->getMessage());
            }

            \sleep($tick);
        }
    }
}
