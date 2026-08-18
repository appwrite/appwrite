<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
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
        Console::title('Scheduler V1 (combined)');
        Console::success(APP_NAME . ' combined scheduler v1 has started');
        Console::info('Bootstrap runs serially (shared console/cache pools), then enqueue loops in parallel');

        $tasks = [
            [new ScheduleFunctions(), $publisherForFunctions, ScheduleFunctions::getSupportedResource()],
            [new ScheduleExecutions(), $publisherForFunctions, ScheduleExecutions::getSupportedResource()],
            [new ScheduleMessages(), $publisherForMessaging, ScheduleMessages::getSupportedResource()],
        ];

        foreach ($tasks as [$task, $publisher, $resource]) {
            Console::info("Bootstrapping {$resource}…");
            $task->start($publisher, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
            Console::success("Bootstrapped {$resource} ({$task->scheduleCount()} active)");
        }

        Console::success('All schedulers loaded; starting enqueue loops…');

        foreach ($tasks as [$task, , $resource]) {
            // Each loop blocks on its own cadence, so each gets a coroutine;
            // the library's sleeps yield under the runtime hooks enabled in
            // app/cli.php.
            Co::create(function () use ($task, $resource): void {
                try {
                    $task->listen();
                } catch (\Throwable $th) {
                    Console::error("The {$resource} scheduler stopped: {$th->getMessage()}");
                }
            });
        }

        while (true) {
            sleep(3600);
        }
    }
}
