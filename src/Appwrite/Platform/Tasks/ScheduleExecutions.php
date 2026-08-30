<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Schedule\Source;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Span\Span;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleExecutions extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations

    public function __construct()
    {
        $this
            ->desc('Execute executions scheduled in Appwrite')
            ->inject('publisherForFunctions')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'schedule-executions';
    }

    public function action(FunctionPublisher $publisherForFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        $source = new Source\Executions($dbForPlatform, $getProjectDB, $getIsResourceBlocked);

        $scheduler = new Scheduler(
            source: $source,
            syncSeconds: self::UPDATE_TIMER,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.executions.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForFunctions));

        Span::init('schedule.executions.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, FunctionPublisher $publisherForFunctions): null
    {
        $batch = \count($occurrences);

        foreach (\array_values($occurrences) as $index => $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.executions.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');
                Span::add('execution.id', (string) ($schedule['resourceId'] ?? ''));
                Span::add('function.id', $schedule['resource']->getAttribute('resourceId', ''));
                Span::add('occurrence.due', $occurrence->due->format('c'));
                Span::add('occurrence.late', \round(\microtime(true) - (float) $occurrence->due->format('U.u'), 3));
                Span::add('occurrence.batch', $batch);
                Span::add('occurrence.index', $index);

                $publisherForFunctions->enqueue(new FunctionMessage(
                    project: $schedule['project'],
                    functionId: $schedule['resource']->getAttribute('resourceId', ''),
                    execution: new Document([
                        '$id' => $schedule['resourceId'],
                        'scheduleId' => $schedule['$id'],
                    ]),
                    type: 'schedule',
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
