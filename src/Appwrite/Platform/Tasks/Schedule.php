<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Maintenance as MaintenancePublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Usage\Connection;
use Swoole\Coroutine as Co;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

class Schedule extends Action
{
    public static function getName(): string
    {
        return 'schedule';
    }

    public function __construct()
    {
        $this
            ->desc('Run the function, execution, message, inactive project maintenance and, when self-hosted, resource stats schedulers in one process')
            ->inject('publisherForFunctions')
            ->inject('publisherForMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->inject('locks')
            ->inject('publisherForMaintenance')
            ->inject('pools');

        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            $this
                ->inject('publisherForStatsResources')
                ->inject('usageConnection')
                ->callback($this->actionWithUsage(...));
            return;
        }

        $this->callback($this->action(...));
    }

    public function action(
        FunctionPublisher $publisherForFunctions,
        MessagingPublisher $publisherForMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        callable $locks,
        MaintenancePublisher $publisherForMaintenance,
        Group $pools,
    ): never {
        $this->start($publisherForFunctions, $publisherForMessaging, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $locks, $publisherForMaintenance, $pools);
    }

    public function actionWithUsage(
        FunctionPublisher $publisherForFunctions,
        MessagingPublisher $publisherForMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        callable $locks,
        MaintenancePublisher $publisherForMaintenance,
        Group $pools,
        StatsResourcesPublisher $publisherForStatsResources,
        Connection $usageConnection,
    ): never {
        $this->start(
            $publisherForFunctions,
            $publisherForMessaging,
            $getIsResourceBlocked,
            $dbForPlatform,
            $getProjectDB,
            $telemetry,
            $locks,
            $publisherForMaintenance,
            $pools,
            $publisherForStatsResources,
            $usageConnection,
        );
    }

    private function start(
        FunctionPublisher $publisherForFunctions,
        MessagingPublisher $publisherForMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        callable $locks,
        MaintenancePublisher $publisherForMaintenance,
        Group $pools,
        ?StatsResourcesPublisher $publisherForStatsResources = null,
        ?Connection $usageConnection = null,
    ): never {
        // Parsed before any loop starts: a loop that throws only ends its own coroutine, so an invalid interval
        // would otherwise leave the process healthy with inactive projects never maintained.
        $cycle = ScheduleMaintenance::cycle();

        $this->loop(ScheduleFunctions::getName(), fn () => (new ScheduleFunctions())->action($publisherForFunctions, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry));
        $this->loop(ScheduleExecutions::getName(), fn () => (new ScheduleExecutions())->action($publisherForFunctions, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry));
        $this->loop(ScheduleMessages::getName(), fn () => (new ScheduleMessages())->action($publisherForMessaging, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $locks));
        $this->loop(ScheduleMaintenance::getName(), fn () => (new ScheduleMaintenance())->run(ScheduleMaintenance::loop($cycle, $publisherForMaintenance, $telemetry), $pools));

        if ($publisherForStatsResources !== null && $usageConnection !== null) {
            $this->loop(StatsResources::getName(), fn () => (new StatsResources())->action($dbForPlatform, $publisherForStatsResources, $usageConnection, $telemetry));
        }

        while (true) {
            sleep(3600);
        }
    }

    private function loop(string $name, \Closure $task): void
    {
        Co::create(function () use ($name, $task): void {
            Span::init('schedule.combined.loop');
            Span::add('loop', $name);
            $error = null;

            try {
                $task();
            } catch (\Throwable $th) {
                $error = $th;
                Console::error('schedule: ' . $name . ' loop stopped: ' . $th->getMessage());
            } finally {
                Span::current()?->finish(error: $error);
            }
        });
    }
}
