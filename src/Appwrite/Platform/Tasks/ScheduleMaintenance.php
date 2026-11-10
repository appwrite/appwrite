<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Maintenance\Cohort;
use Appwrite\Maintenance\Cycle;
use Appwrite\Maintenance\Dispatcher;
use Appwrite\Maintenance\Loop;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
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

    private const string PERIOD = '86400';

    private const string INACTIVE_PERIOD = '604800';

    private const int BACKOFF = 60;

    private const array SIGNALS = [SIGTERM, SIGINT];

    public static function getName(): string
    {
        return 'schedule-maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedule maintenance for every project, spread across each cohort\'s cycle')
            ->inject('publisherForMaintenance')
            ->inject('pools')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    /**
     * Active projects come round once per _APP_MAINTENANCE_INTERVAL, inactive ones once per
     * _APP_MAINTENANCE_INACTIVE_INTERVAL.
     *
     * @throws \InvalidArgumentException when the interval is not a whole number of seconds of at least 1
     */
    public static function cycle(Cohort $cohort): Cycle
    {
        return Cycle::parse(match ($cohort) {
            Cohort::Active => System::getEnv('_APP_MAINTENANCE_INTERVAL', self::PERIOD),
            Cohort::Inactive => System::getEnv('_APP_MAINTENANCE_INACTIVE_INTERVAL', self::INACTIVE_PERIOD),
        });
    }

    public function action(MaintenancePublisher $publisherForMaintenance, Group $pools, Telemetry $telemetry): void
    {
        $loops = [];
        foreach (Cohort::cases() as $cohort) {
            $loops[] = [$cohort, self::loop($cohort, self::cycle($cohort), $publisherForMaintenance, $telemetry)];
        }

        // Stopping releases the claims once the running ticks commit, so a rollout does not pause publishing for a lease.
        foreach (self::SIGNALS as $signal) {
            Process::signal($signal, function () use ($loops): void {
                foreach ($loops as [, $loop]) {
                    $loop->stop();
                }
            });
        }

        $group = new WaitGroup();
        foreach ($loops as [$cohort, $loop]) {
            $group->add();
            Coroutine::create(function () use ($group, $cohort, $loop, $pools): void {
                try {
                    $this->run($loop, $cohort, $pools);
                } finally {
                    $group->done();
                }
            });
        }
        $group->wait();

        // A listener keeps the event loop, and with it the process, alive.
        foreach (self::SIGNALS as $signal) {
            Process::signal($signal, null);
        }
    }

    public static function loop(Cohort $cohort, Cycle $cycle, MaintenancePublisher $publisherForMaintenance, Telemetry $telemetry): Loop
    {
        $dispatcher = new Dispatcher($publisherForMaintenance, $cycle, System::getEnv('_APP_REGION', 'default'), $cohort);

        return new Loop($cycle, $dispatcher, \gethostname() . ':' . \getmypid() . ':' . \bin2hex(\random_bytes(4)), $telemetry);
    }

    public function run(Loop $loop, Cohort $cohort, Group $pools): void
    {
        // Each cohort keeps its own watermark and leader claim. The inactive cohort keeps the key it had alone.
        $key = self::KEY . ($cohort === Cohort::Inactive ? '' : $cohort->value . '-') . System::getEnv('_APP_REGION', 'default');

        while (!$loop->stopped()) {
            try {
                $pools->get(self::POOL)->use(fn (\Redis $redis) => $loop->run(new RedisStore($redis, $key)));
            } catch (\Throwable $error) {
                Span::init('schedule.maintenance.error');
                Span::add('cohort', $cohort->value);
                Span::current()?->finish(error: $error);

                Console::error('schedule maintenance (' . $cohort->value . '): ' . $error->getMessage());
            }

            $loop->pause(self::BACKOFF);
        }
    }
}
