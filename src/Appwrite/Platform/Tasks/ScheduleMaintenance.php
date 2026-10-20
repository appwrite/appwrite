<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Backlog;
use Appwrite\Maintenance\Cycle;
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
    private const string POOL = 'lock';

    private const string KEY = 'schedule-maintenance-';

    private const int LEASE = 600;

    private const string PERIOD = '604800';

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
        $cycle = Cycle::parse(System::getEnv('_APP_MAINTENANCE_INACTIVE_INTERVAL', self::PERIOD));
        $region = System::getEnv('_APP_REGION', 'default');

        $sweep = new Sweep($dbForPlatform, $publisherForDeletes, $region, $cycle);

        // Kept across restarts of the loop: the release in run()'s finally can itself fail, and a new token would
        // then sit out the stale claim as a follower instead of renewing it.
        $token = \gethostname() . ':' . \getmypid() . ':' . \bin2hex(\random_bytes(4));

        $reported = null;

        while (true) {
            try {
                $pools->get(self::POOL)->use(function (\Redis $redis) use ($cycle, $region, $telemetry, $token, $sweep, &$reported): void {
                    $scheduler = new Scheduler(
                        source: new Source\Maintenance($cycle->interval),
                        store: new RedisStore($redis, self::KEY . $region),
                        tickSeconds: $cycle->tick,
                        recoverSeconds: 3600,
                        leaseSeconds: self::LEASE,
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

                        if ($reported === null || \abs($backlog->pending - $reported) >= Sweep::BACKLOG) {
                            Console::info('schedule maintenance: ' . $backlog->getMessage() . ', deferring slices until it drains');
                            $reported = $backlog->pending;
                        }
                    }
                });
            } catch (\Throwable $error) {
                Span::init('schedule.maintenance.error');
                Span::current()?->finish(error: $error);

                Console::error('schedule maintenance: ' . $error->getMessage());
            }

            \sleep($cycle->tick);
        }
    }
}
