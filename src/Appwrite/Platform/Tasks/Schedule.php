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
        $functions = (new ScheduleFunctions())->boot($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
        $executions = (new ScheduleExecutions())->boot($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
        $messages = (new ScheduleMessages())->boot($publisherForMessaging, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);

        $this->loop($functions);
        $this->loop($executions);
        $this->loop($messages);

        while (true) {
            sleep(3600);
        }
    }

    private function loop(\Closure $dispatch): void
    {
        Co::create(function () use ($dispatch): void {
            Span::init('schedule.combined.loop');
            $error = null;

            try {
                $dispatch();
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        });
    }
}
