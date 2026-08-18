<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Histogram;

/**
 * Runs one-off executions at the moment they were scheduled for. The stored
 * schedule is a fixed time rather than a recurrence, so each one is retired
 * after it has been handed over.
 */
class ScheduleExecutions extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations
    public const ENQUEUE_TIMER = 4; // seconds between ticks
    public const ENQUEUE_LOOKAHEAD = 4; // handed over a tick early, to sleep to the exact second

    private ?Schedules $schedules = null;

    private ?FunctionPublisher $publisher = null;

    private ?Histogram $enqueueDelay = null;

    private ?Database $dbForPlatform = null;

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
        Console::title('Execution scheduler V1');
        Console::success(APP_NAME . ' execution scheduler v1 has started');

        $this->start($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
        $this->listen();

        // Nothing here stops the loop, so a return means the supervisor
        // should restart the task rather than leave it scheduling nothing.
        Console::error('Scheduler loop returned unexpectedly');
        exit(1);
    }

    public function start(FunctionPublisher $publisherForFunctions, Telemetry $telemetry, Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): void
    {
        $this->publisher = $publisherForFunctions;
        $this->enqueueDelay = $telemetry->createHistogram('task.schedule.enqueue_delay', 's');
        $this->dbForPlatform = $dbForPlatform;
        $this->schedules = new Schedules(
            name: self::getName(),
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            sync: self::UPDATE_TIMER,
            tick: self::ENQUEUE_TIMER,
            lookahead: self::ENQUEUE_LOOKAHEAD,
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            pools: $pools,
            trigger: $this->trigger(...),
            resource: $this->resource(...),
            telemetry: $telemetry,
        );
        $this->schedules->load();
    }

    public function scheduleCount(): int
    {
        return $this->schedules?->count() ?? 0;
    }

    public function listen(): void
    {
        $schedules = $this->schedules ?? throw new \LogicException('start() must run before listen()');

        Console::success('Starting execution scheduler at ' . DateTime::now());

        $schedules->run($this->dispatch(...));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    public function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
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
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences): void
    {
        $dbForPlatform = $this->dbForPlatform ?? throw new \LogicException('start() must run before dispatch()');
        $schedules = $this->schedules ?? throw new \LogicException('start() must run before dispatch()');

        // Publishing runs here, one execution at a time, in the order the tick
        // selected them — oldest first. A coroutine per execution let a later
        // one overtake an earlier one on the queue, and two ticks overlapping
        // let them interleave, which is what the ordering fix on main removed.
        // No re-entrancy guard is needed: the scheduler's loop is sequential
        // and cannot call this again until it returns.
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;
            $delay = $occurrence->due->getTimestamp() - \time();

            if ($delay > 0) {
                Co::sleep($delay);
            }

            if (!$schedules->isLive($schedule)) {
                continue;
            }

            try {
                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $this->publisher?->enqueue(new FunctionMessage(
                    project: $schedule['project'],
                    functionId: $schedule['resource']->getAttribute('resourceId', ''),
                    execution: new Document([
                        '$id' => $schedule['resourceId'],
                        'scheduleId' => $schedule['$id'],
                    ]),
                    type: 'schedule',
                ));

                $this->enqueueDelay?->record(
                    \time() - $occurrence->due->getTimestamp(),
                    ['resourceType' => self::getSupportedResource()]
                );
            } catch (\Throwable $th) {
                // Stop rather than skip: everything behind this is later than
                // it, and publishing those now would reorder the queue.
                Console::error("Failed to enqueue scheduled execution {$schedule['resourceId']}: {$th->getMessage()}");

                break;
            }
        }
    }
}
