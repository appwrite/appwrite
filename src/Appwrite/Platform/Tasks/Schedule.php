<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Publisher;
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
            ->inject('publisherForFunctions')
            ->inject('publisherMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(
        Publisher $publisher,
        Publisher $publisherMigrations,
        FunctionPublisher $publisherForFunctions,
        Publisher $publisherMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
    ): never {
        Console::title('Scheduler V1 (combined)');
        Console::success(APP_NAME . ' combined scheduler v1 has started');

        /** @var list<ScheduleBase> $tasks */
        $tasks = [
            new ScheduleFunctions(),
            new ScheduleExecutions(),
            new ScheduleMessages(),
        ];

        $names = \array_map(
            static fn (ScheduleBase $task): string => $task::getSupportedResource(),
            $tasks,
        );

        Console::info('Mode: combined — functions, executions, and messages in one process');
        Console::info('Resource types: ' . \implode(', ', $names));
        Console::info('Bootstrap runs serially (shared console/cache pools), then enqueue loops in parallel');

        foreach ($tasks as $task) {
            $resource = $task::getSupportedResource();
            Console::info("Bootstrapping {$resource}…");
            $task->setup(
                $publisher,
                $publisherMigrations,
                $publisherForFunctions,
                $publisherMessaging,
                $telemetry,
            );
            $task->start($dbForPlatform, $getProjectDB, $getIsResourceBlocked);
            Console::success("Bootstrapped {$resource} (" . \count($task->getSchedules()) . ' active)');
        }

        Console::success('All schedulers loaded; starting enqueue loops…');

        foreach ($tasks as $task) {
            Co::create(function () use ($task, $dbForPlatform, $getProjectDB): void {
                $task->listen($dbForPlatform, $getProjectDB);
            });
        }

        while (true) {
            sleep(3600);
        }
    }
}
