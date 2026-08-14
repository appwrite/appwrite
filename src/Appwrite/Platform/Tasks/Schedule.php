<?php

namespace Appwrite\Platform\Tasks;

use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Broker\Pool as BrokerPool;
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
            ->inject('publisher')
            ->inject('publisherMigrations')
            ->inject('publisherFunctions')
            ->inject('publisherMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(
        BrokerPool $publisher,
        BrokerPool $publisherMigrations,
        BrokerPool $publisherFunctions,
        BrokerPool $publisherMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
    ): never {
        Console::title('Scheduler V1');
        Console::success(APP_NAME . ' combined scheduler v1 has started');

        $tasks = [
            new ScheduleFunctions(),
            new ScheduleExecutions(),
            new ScheduleMessages(),
        ];

        foreach ($tasks as $task) {
            Co::create(function () use (
                $task,
                $publisher,
                $publisherMigrations,
                $publisherFunctions,
                $publisherMessaging,
                $getIsResourceBlocked,
                $dbForPlatform,
                $getProjectDB,
                $telemetry,
            ): void {
                $task->action(
                    $publisher,
                    $publisherMigrations,
                    $publisherFunctions,
                    $publisherMessaging,
                    $getIsResourceBlocked,
                    $dbForPlatform,
                    $getProjectDB,
                    $telemetry,
                );
            });
        }

        while (true) {
            sleep(3600);
        }
    }
}
