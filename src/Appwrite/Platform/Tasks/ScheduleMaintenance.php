<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Dispatcher;
use Utopia\Console\Console;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Store\Redis as RedisStore;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleMaintenance extends Action
{
    private const string POOL = 'lock';

    private const string KEY = 'schedule-maintenance-';

    private const string PERIOD = '604800';

    public static function getName(): string
    {
        return 'schedule-maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedule maintenance for projects not accessed in the last 30 days')
            ->inject('publisherForMaintenance')
            ->inject('pools')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    /**
     * @throws \InvalidArgumentException when _APP_MAINTENANCE_INACTIVE_INTERVAL is not a whole number of seconds of at least 1
     */
    public static function cycle(): Cycle
    {
        return Cycle::parse(System::getEnv('_APP_MAINTENANCE_INACTIVE_INTERVAL', self::PERIOD));
    }

    public function action(MaintenancePublisher $publisherForMaintenance, Group $pools, Telemetry $telemetry): never
    {
        $this->run(self::cycle(), $publisherForMaintenance, $pools, $telemetry);
    }

    public function run(Cycle $cycle, MaintenancePublisher $publisherForMaintenance, Group $pools, Telemetry $telemetry): never
    {
        $region = System::getEnv('_APP_REGION', 'default');

        $dispatcher = new Dispatcher($publisherForMaintenance, $cycle, $region);

        // Kept across restarts of the loop: the release in run()'s finally can itself fail, and a new token would
        // then sit out the stale claim as a follower instead of renewing it.
        $token = \gethostname() . ':' . \getmypid() . ':' . \bin2hex(\random_bytes(4));

        while (true) {
            try {
                $pools->get(self::POOL)->use(function (\Redis $redis) use ($cycle, $region, $telemetry, $token, $dispatcher): void {
                    $cycle->scheduler(new RedisStore($redis, self::KEY . $region), $token, $telemetry)->run($dispatcher);
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
