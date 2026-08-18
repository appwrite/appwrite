<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Schedule\ExecutionSchedule;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
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
        $source = new ExecutionSchedule($dbForPlatform, $getProjectDB, $getIsResourceBlocked);

        $scheduler = new Scheduler(
            source: $source,
            syncSeconds: self::UPDATE_TIMER,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.executions.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForFunctions, $dbForPlatform));

        Span::init('schedule.executions.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return;
        }

        $accessedAt = $project->getAttribute('accessedAt', 0);
        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
            $now = DateTime::now();
            $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'accessedAt' => $now
            ]));
            $project->setAttribute('accessedAt', $now);
        }
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, FunctionPublisher $publisherForFunctions, Database $dbForPlatform): null
    {
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.executions.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');

                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

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
