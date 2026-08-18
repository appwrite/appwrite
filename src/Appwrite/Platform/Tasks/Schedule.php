<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Span\Span;
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
            ->inject('pools')
            ->callback($this->action(...));
    }

    public function action(
        FunctionPublisher $publisherForFunctions,
        MessagingPublisher $publisherForMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        Group $pools,
    ): never {
        // Loading runs serially, so the three do not contend for the shared
        // console and cache pools, and only then do the loops start.
        $loops = [
            (new ScheduleFunctions())->boot($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools),
            (new ScheduleExecutions())->boot($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools),
            (new ScheduleMessages())->boot($publisherForMessaging, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools),
        ];

        foreach ($loops as $loop) {
            Co::create(function () use ($loop): void {
                Span::init('schedule.combined.loop');
                $error = null;

                try {
                    $loop();
                } catch (\Throwable $th) {
                    $error = $th;
                } finally {
                    Span::current()?->finish(error: $error);
                }
            });
        }

        while (true) {
            sleep(3600);
        }
    }
}
