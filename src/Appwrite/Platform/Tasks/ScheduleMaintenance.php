<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Dispatcher;
use Appwrite\Maintenance\Loop;
use Swoole\Process;
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

    private const int BACKOFF = 60;

    private const array SIGNALS = [SIGTERM, SIGINT];

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

    public function action(MaintenancePublisher $publisherForMaintenance, Group $pools, Telemetry $telemetry): void
    {
        $loop = self::loop(self::cycle(), $publisherForMaintenance, $telemetry);

        // Stopping releases the claim once the running tick commits, so a rollout does not pause publishing for a lease.
        foreach (self::SIGNALS as $signal) {
            Process::signal($signal, $loop->stop(...));
        }

        $this->run($loop, $pools);

        // A listener keeps the event loop, and with it the process, alive.
        foreach (self::SIGNALS as $signal) {
            Process::signal($signal, null);
        }
    }

    public static function loop(Cycle $cycle, MaintenancePublisher $publisherForMaintenance, Telemetry $telemetry): Loop
    {
        $dispatcher = new Dispatcher($publisherForMaintenance, $cycle, System::getEnv('_APP_REGION', 'default'));

        return new Loop($cycle, $dispatcher, \gethostname() . ':' . \getmypid() . ':' . \bin2hex(\random_bytes(4)), $telemetry);
    }

    public function run(Loop $loop, Group $pools): void
    {
        $key = self::KEY . System::getEnv('_APP_REGION', 'default');

        while (!$loop->stopped()) {
            try {
                $pools->get(self::POOL)->use(fn (\Redis $redis) => $loop->run(new RedisStore($redis, $key)));
            } catch (\Throwable $error) {
                Span::init('schedule.maintenance.error');
                Span::current()?->finish(error: $error);

                Console::error('schedule maintenance: ' . $error->getMessage());
            }

            $loop->pause(self::BACKOFF);
        }
    }
}
