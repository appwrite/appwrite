<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Usage\Connection;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Platform\Action;
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
            ->desc('Execute functions, executions, and messages scheduled in Appwrite')
            ->inject('publisherForFunctions')
            ->inject('publisherForMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->inject('locks');

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
    ): never {
        $this->start($publisherForFunctions, $publisherForMessaging, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $locks);
    }

    public function actionWithUsage(
        FunctionPublisher $publisherForFunctions,
        MessagingPublisher $publisherForMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        callable $locks,
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
        ?StatsResourcesPublisher $publisherForStatsResources = null,
        ?Connection $usageConnection = null,
    ): never {
        $this->loop(fn () => (new ScheduleFunctions())->action($publisherForFunctions, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry));
        $this->loop(fn () => (new ScheduleExecutions())->action($publisherForFunctions, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry));
        $this->loop(fn () => (new ScheduleMessages())->action($publisherForMessaging, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $locks));

        if ($publisherForStatsResources !== null && $usageConnection !== null) {
            $this->loop(fn () => (new StatsResources())->action($dbForPlatform, $publisherForStatsResources, $usageConnection));
        }

        while (true) {
            sleep(3600);
        }
    }

    private function loop(\Closure $task): void
    {
        Co::create(function () use ($task): void {
            Span::init('schedule.combined.loop');
            $error = null;

            try {
                $task();
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        });
    }
}
