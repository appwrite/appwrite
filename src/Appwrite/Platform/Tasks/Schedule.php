<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Platform\Action;
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
            ->inject('getRedisForLocks')
            ->callback($this->action(...));
    }

    /**
     * @param callable(): \Redis $getRedisForLocks
     */
    public function action(
        FunctionPublisher $publisherForFunctions,
        MessagingPublisher $publisherForMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        callable $getRedisForLocks,
    ): never {
        $this->loop(fn () => (new ScheduleFunctions())->action($publisherForFunctions, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $getRedisForLocks));
        $this->loop(fn () => (new ScheduleExecutions())->action($publisherForFunctions, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $getRedisForLocks));
        $this->loop(fn () => (new ScheduleMessages())->action($publisherForMessaging, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry, $getRedisForLocks));

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
