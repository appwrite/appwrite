<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Swoole\Coroutine as Co;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Store\Redis as ClaimStore;
use Utopia\Schedule\Trigger\At;
use Utopia\Span\Span;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleExecutions extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations
    public const ENQUEUE_TIMER = 4; // seconds between ticks
    public const ENQUEUE_LOOKAHEAD = 4; // seconds of lead time, so a dispatch can sleep to the second
    public const ENQUEUE_LOOKBACK = 300; // seconds of missed runs a restart recovers

    public function __construct()
    {
        $this
            ->desc('Execute executions scheduled in Appwrite')
            ->inject('publisherForFunctions')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->inject('pools')
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'schedule-executions';
    }

    public static function getSupportedResource(): string
    {
        return SCHEDULE_RESOURCE_TYPE_EXECUTION;
    }

    public static function getCollectionId(): string
    {
        return RESOURCE_TYPE_EXECUTIONS;
    }

    public function action(FunctionPublisher $publisherForFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        ($this->boot($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools))();

        Span::init('schedule.executions.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));

        exit(1);
    }

    /**
     * Load the schedules and return the loop that dispatches them, so the
     * combined task can load all three before running any.
     */
    public function boot(FunctionPublisher $publisher, Telemetry $telemetry, Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): \Closure
    {
        Span::init('schedule.executions.boot');

        $source = new ScheduleSource(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            resource: $this->resource(...),
            entry: fn (array $schedule): Entry => new Entry(new At(new \DateTimeImmutable((string) $schedule['schedule'])), $schedule),
            recency: self::UPDATE_TIMER * 3,
        );

        $scheduler = new Scheduler(
            source: $source,
            store: new ClaimStore($pools->get('lock')->pop()->resource, 'utopia-schedule-' . self::getName()),
            tickSeconds: self::ENQUEUE_TIMER,
            syncSeconds: self::UPDATE_TIMER,
            leadSeconds: self::ENQUEUE_LOOKAHEAD,
            recoverSeconds: self::ENQUEUE_LOOKBACK,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.executions.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->reconcile();

        Span::add('schedule.executions.loaded', $source->snapshotted());
        Span::current()?->finish();

        return fn (): null => $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $source, $publisher, $dbForPlatform));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    /**
     * Executions are not persisted; the schedule carries what the worker
     * needs. Schedules from before the executions collection was dropped can
     * still resolve their document for the functionId their data lacks.
     *
     * @param array<string, mixed> $schedule
     */
    protected function resource(Database $projectDB, array $schedule): Document
    {
        try {
            $resource = $projectDB->getDocument(self::getCollectionId(), $schedule['resourceId']);
        } catch (\Throwable) {
            $resource = new Document();
        }

        return $resource->isEmpty()
            ? new Document(['$id' => $schedule['resourceId']])
            : $resource;
    }

    /**
     * Published one at a time, in the order the tick selected them: a
     * coroutine per execution let a later one overtake an earlier one on the
     * queue.
     *
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, ScheduleSource $source, FunctionPublisher $publisher, Database $dbForPlatform): null
    {
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;
            $delay = $occurrence->due->getTimestamp() - \time();

            if ($delay > 0) {
                Co::sleep($delay);
            }

            if (!$source->isLive((string) $schedule['$sequence'], (string) $schedule['resourceUpdatedAt'])) {
                continue;
            }

            Span::init('schedule.executions.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');

                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $publisher->enqueue(new FunctionMessage(
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
