<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
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
use Utopia\Schedule\Trigger\Cron;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Histogram;

/**
 * ScheduleFunctions
 *
 * Runs functions on their cron schedules. Selection, the tiling windows it
 * runs over and the committed watermark all belong to utopia-php/schedule;
 * this task says what a stored cron expression means and what to publish
 * when one falls due.
 */
class ScheduleFunctions extends Action
{
    public const UPDATE_TIMER = 10; // seconds between reconciliations
    public const ENQUEUE_TIMER = 60; // seconds between ticks

    /**
     * Occurrences arrive a tick before they fall due so the dispatch below
     * can sleep to the exact second, spread offset included.
     */
    public const ENQUEUE_LOOKAHEAD = 60; // seconds

    /**
     * How far back a committed window is trusted. Bounds the catch-up burst
     * after downtime rather than replaying everything since it began.
     */
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
            ->desc('Execute functions scheduled in Appwrite')
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
        return 'schedule-functions';
    }

    public static function getSupportedResource(): string
    {
        return SCHEDULE_RESOURCE_TYPE_FUNCTION;
    }

    public static function getCollectionId(): string
    {
        return RESOURCE_TYPE_FUNCTIONS;
    }

    /**
     * Deterministic per-resource offset, in seconds, within [0, $window).
     *
     * Schedules sharing a slot are spread across the window instead of all
     * being dispatched in the same second, while each resource keeps a stable
     * slot so run intervals stay exact.
     */
    public static function spreadOffset(string $resourceId, int $window): int
    {
        return $window <= 1 ? 0 : \abs(\crc32($resourceId)) % $window;
    }

    public function action(BrokerPool $publisherFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        Console::title('Function scheduler V1');
        Console::success(APP_NAME . ' function scheduler v1 has started');

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
            resource: fn (Database $projectDB, array $schedule): Document => $projectDB->getDocument(self::getCollectionId(), $schedule['resourceId']),
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
            lease: self::ENQUEUE_TIMER * 4,
            telemetry: $this->telemetry ?? new \Utopia\Telemetry\Adapter\None(),
            onError: function (\Throwable $error): void {
                // A failed sync leaves the last good view dispatching: stale
                // schedules beat a stopped scheduler.
                Console::error('Failed to reconcile function schedules: ' . $error->getMessage());
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

        Console::success('Starting function scheduler at ' . DateTime::now());

        $scheduler->run(function (array $occurrences) use ($dbForPlatform): void {
            $this->dispatch($occurrences, $dbForPlatform);
        });
    }

    /**
     * Spread window, in seconds, for a given schedule. The default applies
     * _APP_FUNCTIONS_SCHEDULE_SPREAD to every schedule; override to scope
     * or vary the window per schedule (e.g. by project or plan).
     */
    protected function spreadWindow(array $schedule, Database $dbForPlatform): int
    {
        return (int) System::getEnv('_APP_FUNCTIONS_SCHEDULE_SPREAD', '0');
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    protected function trigger(array $schedule): Trigger
    {
        // Invalid and impossible expressions are rejected here, once, rather
        // than silently matching nothing on every tick.
        return new Cron((string) $schedule['schedule']);
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, Database $dbForPlatform): void
    {
        $source = $this->source ?? throw new \LogicException('start() must run before dispatch()');

        $timerStart = \microtime(true);

        $delayed = []; // Group runs sharing a delay so they share one coroutine

        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;
            $offset = self::spreadOffset($schedule['resourceId'], $this->spreadWindow($schedule, $dbForPlatform));

            // A run recovered from a gap is already past due, so it clamps to
            // zero and goes out immediately instead of sleeping backwards.
            $delay = \max(0, $occurrence->due->getTimestamp() - \time() + $offset);

            // The due time carries the offset so enqueue-delay telemetry
            // measures lateness against the intended (spread) time.
            $delayed[$delay][] = ['schedule' => $schedule, 'dueAt' => $occurrence->due->modify("+{$offset} seconds")];
        }

        foreach ($delayed as $delay => $batch) {
            \go(function () use ($delay, $batch, $dbForPlatform, $source) {
                if ($delay > 0) {
                    \sleep($delay); // in seconds
                }

                foreach ($batch as $due) {
                    $schedule = $due['schedule'];

                    // Disabled, deleted or edited while this coroutine slept:
                    // the run belongs to a definition that no longer exists.
                    if (!$source->isLive((string) $schedule['$sequence'], (string) $schedule['resourceUpdatedAt'])) {
                        continue;
                    }

                    Span::init('schedule.functions.enqueue');
                    try {
                        Span::add('project.id', $schedule['project']->getId());
                        Span::add('function.id', $schedule['resource']->getId());
                        Span::add('schedule.id', $schedule['$id'] ?? '');

                        $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                        $this->publisher()->enqueue(new FunctionMessage(
                            project: $schedule['project'],
                            function: $schedule['resource'],
                            type: 'schedule',
                            method: 'POST',
                            path: '/',
                        ));

                        $this->enqueueDelay?->record(
                            \time() - $due['dueAt']->getTimestamp(),
                            ['resourceType' => self::getSupportedResource()]
                        );
                    } catch (\Throwable $th) {
                        Console::error("Failed to enqueue scheduled function {$schedule['resourceId']}: {$th->getMessage()}");
                    } finally {
                        Span::current()?->finish();
                    }
                }
            });
        }

        Console::log('Enqueue tick: ' . \count($occurrences) . ' executions were enqueued in ' . (\microtime(true) - $timerStart) . ' seconds');
    }

    private function publisher(): FunctionPublisher
    {
        return $this->publisherForFunctions ??= new FunctionPublisher(
            $this->publisherFunctions,
            new Queue(System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', Event::FUNCTIONS_QUEUE_TTL)
        );
    }
}
