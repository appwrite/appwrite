<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Schedule\Source;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleFunctions extends Action
{
    public function __construct()
    {
        $this
            ->desc('Execute functions scheduled in Appwrite')
            ->inject('publisherForFunctions')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'schedule-functions';
    }

    public function action(FunctionPublisher $publisherForFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        $source = new Source\Functions(
            $dbForPlatform,
            $getProjectDB,
            $getIsResourceBlocked,
            fn (array $schedule): int => $this->spreadWindow($schedule, $dbForPlatform),
        );

        $scheduler = new Scheduler(
            source: $source,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.functions.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForFunctions));

        Span::init('schedule.functions.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    protected function spreadWindow(array $schedule, Database $dbForPlatform): int
    {
        return (int) System::getEnv('_APP_FUNCTIONS_SCHEDULE_SPREAD', '0');
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, FunctionPublisher $publisherForFunctions): null
    {
        $batch = \count($occurrences);

        foreach (\array_values($occurrences) as $index => $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.functions.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('function.id', $schedule['resource']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');
                Span::add('schedule.cron', (string) ($schedule['schedule'] ?? ''));
                Span::add('occurrence.due', $occurrence->due->format('c'));
                Span::add('occurrence.late', \round(\microtime(true) - (float) $occurrence->due->format('U.u'), 3));
                Span::add('occurrence.batch', $batch);
                Span::add('occurrence.index', $index);

                $publisherForFunctions->enqueue(new FunctionMessage(
                    project: $schedule['project'],
                    function: $schedule['resource'],
                    type: 'schedule',
                    method: 'POST',
                    path: '/',
                ));
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        }

        return null;
    }
}
