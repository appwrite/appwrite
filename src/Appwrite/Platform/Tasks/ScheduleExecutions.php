<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Swoole\Coroutine as Co;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Queue;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Histogram;

/**
 * ScheduleExecutions
 *
 * Runs one-off executions at the moment they were scheduled for. The stored
 * schedule is a fixed time rather than a recurrence, so utopia-php/schedule
 * retires each one after it has been handed over.
 */
class ScheduleExecutions extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations
    public const ENQUEUE_TIMER = 4; // seconds between ticks

    /** Handed over a tick early so the dispatch can sleep to the exact second. */
    public const ENQUEUE_LOOKAHEAD = 4; // seconds

    public const ENQUEUE_LOOKBACK = 300; // seconds

    protected BrokerPool $publisherFunctions;

    private ?FunctionPublisher $publisherForFunctions = null;

    private ?Histogram $enqueueDelay = null;

    private ?Telemetry $telemetry = null;

    private ?ScheduleSource $source = null;

    private ?Scheduler $scheduler = null;

    private ?Database $dbForPlatform = null;

    public function __construct()
    {
        $this
            ->desc('Execute executions scheduled in Appwrite')
            ->inject('publisherFunctions')
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

    public function action(BrokerPool $publisherFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        Console::title('Execution scheduler V1');
        Console::success(APP_NAME . ' execution scheduler v1 has started');

        $this->setup($publisherFunctions, $telemetry);
        $this->start($dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
        $this->listen();

        // The loop returns only when something asks it to stop, and nothing
        // here does. Exiting non-zero has the supervisor restart the task
        // rather than leaving a live process that schedules nothing.
        Console::error('Scheduler loop returned unexpectedly');
        exit(1);
    }

    /**
     * Wire the publisher and telemetry. Safe to call once before start().
     */
    public function setup(BrokerPool $publisherFunctions, Telemetry $telemetry): void
    {
        $this->publisherFunctions = $publisherFunctions;
        $this->enqueueDelay = $telemetry->createHistogram('task.schedule.enqueue_delay', 's');
        $this->telemetry = $telemetry;
    }

    /**
     * Build the scheduler and load the schedules once. Combined mode runs this
     * serially per resource type so they do not contend for the shared
     * console and cache pools.
     */
    public function start(Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): void
    {
        $source = new ScheduleSource(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            resource: $this->resource(...),
            entry: fn (array $schedule): Entry => new Entry($this->trigger($schedule), $schedule),
            recency: self::UPDATE_TIMER * 3,
        );

        $scheduler = new Scheduler(
            source: $source,
            // The claim carries leadership and the committed window in one
            // record, so replicas elect a single dispatcher and a replacement
            // resumes coverage where its predecessor stopped.
            store: new ScheduleStore($pools, 'utopia-schedule-' . self::getName()),
            interval: self::ENQUEUE_TIMER,
            sync: self::UPDATE_TIMER,
            // A change feed cannot report a hard delete, so a full snapshot
            // still runs periodically to converge removals.
            relist: self::UPDATE_TIMER * 30,
            lookahead: self::ENQUEUE_LOOKAHEAD,
            lookback: self::ENQUEUE_LOOKBACK,
            lease: 60,
            telemetry: $this->telemetry ?? new \Utopia\Telemetry\Adapter\None(),
            onError: function (\Throwable $error): void {
                // A failed sync leaves the last good view dispatching: stale
                // schedules beat a stopped scheduler.
                Console::error('Failed to reconcile execution schedules: ' . $error->getMessage());
            },
        );

        $scheduler->reconcile();

        $this->source = $source;
        $this->scheduler = $scheduler;
        $this->dbForPlatform = $dbForPlatform;
    }

    /**
     * How many active schedules the last full snapshot reported.
     */
    public function scheduleCount(): int
    {
        return $this->source?->snapshotted() ?? 0;
    }

    /**
     * Run the loop. Blocks, so combined mode gives each task its own
     * coroutine.
     */
    public function listen(): void
    {
        $scheduler = $this->scheduler;
        $dbForPlatform = $this->dbForPlatform;

        if ($scheduler === null || $dbForPlatform === null) {
            throw new \LogicException('start() must run before listen()');
        }

        Console::success('Starting execution scheduler at ' . DateTime::now());

        $scheduler->run(function (array $occurrences) use ($dbForPlatform): void {
            $this->dispatch($occurrences, $dbForPlatform);
        });
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    protected function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
    }

    /**
     * Executions are not persisted; the schedule carries what the worker
     * needs. Schedules from before the executions collection was dropped can
     * still resolve their document for the functionId their data lacks.
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
    private function dispatch(array $occurrences, Database $dbForPlatform): void
    {
        $source = $this->source ?? throw new \LogicException('start() must run before dispatch()');

        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;
            $delay = $occurrence->due->getTimestamp() - \time();

            \go(function () use ($schedule, $occurrence, $delay, $dbForPlatform, $source) {
                try {
                    if ($delay > 0) {
                        Co::sleep($delay);
                    }

                    // Cancelled while this coroutine slept.
                    if (!$source->isLive((string) $schedule['$sequence'], (string) $schedule['resourceUpdatedAt'])) {
                        return;
                    }

                    $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                    $this->publisher()->enqueue(new FunctionMessage(
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
                    Console::error("Failed to enqueue scheduled execution {$schedule['resourceId']}: {$th->getMessage()}");
                }
            });
        }
    }

    private function publisher(): FunctionPublisher
    {
        return $this->publisherForFunctions ??= new FunctionPublisher(
            $this->publisherFunctions,
            new Queue(System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', Event::FUNCTIONS_QUEUE_TTL)
        );
    }
}
