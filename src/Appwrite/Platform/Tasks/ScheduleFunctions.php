<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Schedule\FunctionSchedule;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
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
        $source = new FunctionSchedule(
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

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForFunctions, $dbForPlatform));

        Span::init('schedule.functions.stopped');
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
     * @param array<string, mixed> $schedule
     */
    protected function spreadWindow(array $schedule, Database $dbForPlatform): int
    {
        return (int) System::getEnv('_APP_FUNCTIONS_SCHEDULE_SPREAD', '0');
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, FunctionPublisher $publisherForFunctions, Database $dbForPlatform): null
    {
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.functions.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('function.id', $schedule['resource']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');

                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

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
