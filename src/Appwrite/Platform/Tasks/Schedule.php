<?php

namespace Appwrite\Platform\Tasks;

use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
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
            ->inject('publisherFunctions')
            ->inject('publisherMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->inject('pools')
            ->callback($this->action(...));
    }

    public function action(
        BrokerPool $publisherFunctions,
        BrokerPool $publisherMessaging,
        callable $getIsResourceBlocked,
        Database $dbForPlatform,
        callable $getProjectDB,
        Telemetry $telemetry,
        Group $pools,
    ): never {
        Console::title('Scheduler V1 (combined)');
        Console::success(APP_NAME . ' combined scheduler v1 has started');

        $functions = new ScheduleFunctions();
        $executions = new ScheduleExecutions();
        $messages = new ScheduleMessages();

        Console::info('Mode: combined — functions, executions, and messages in one process');
        Console::info('Bootstrap runs serially (shared console/cache pools), then enqueue loops in parallel');

        $functions->setup($publisherFunctions, $telemetry);
        $executions->setup($publisherFunctions, $telemetry);
        $messages->setup($publisherMessaging, $telemetry);

        /** @var list<array{0: string, 1: \Closure(): void, 2: \Closure(): int}> $tasks */
        $tasks = [
            [ScheduleFunctions::getSupportedResource(), $functions->listen(...), $functions->scheduleCount(...)],
            [ScheduleExecutions::getSupportedResource(), $executions->listen(...), $executions->scheduleCount(...)],
            [ScheduleMessages::getSupportedResource(), $messages->listen(...), $messages->scheduleCount(...)],
        ];

        foreach ([$functions, $executions, $messages] as $index => $task) {
            $resource = $tasks[$index][0];
            Console::info("Bootstrapping {$resource}…");
            $task->start($dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
            Console::success("Bootstrapped {$resource} (" . $tasks[$index][2]() . ' active)');
        }

        Console::success('All schedulers loaded; starting enqueue loops…');

        foreach ($tasks as [$resource, $listen]) {
            // Each loop blocks on its own cadence, so each gets a coroutine;
            // the library's sleeps yield under the runtime hooks enabled in
            // app/cli.php.
            Co::create(function () use ($listen, $resource): void {
                try {
                    $listen();
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
