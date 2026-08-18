<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Runs functions on their cron schedules. Selection, the tiling windows it
 * runs over and the committed watermark all belong to utopia-php/schedule;
 * this task says what a stored cron expression means and what to publish when
 * one falls due.
 */
class ScheduleFunctions extends Action
{
    public const UPDATE_TIMER = 10; // seconds between reconciliations
    public const ENQUEUE_TIMER = 60; // seconds between ticks

    /** Handed over a tick early, so the dispatch can sleep to the exact second, spread offset included. */
    public const ENQUEUE_LOOKAHEAD = 60; // seconds

    private ?Schedules $schedules = null;

    private ?FunctionPublisher $publisher = null;

    private ?Database $dbForPlatform = null;

    public function __construct()
    {
        $this
            ->desc('Execute functions scheduled in Appwrite')
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
     * Spreads a minute's worth of due functions so thousands do not land in
     * the same second, while each resource keeps a stable position.
     */
    public static function spreadOffset(string $resourceId, int $window): int
    {
        return $window <= 1 ? 0 : \abs(\crc32($resourceId)) % $window;
    }

    public function action(FunctionPublisher $publisherForFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        Console::title('Functions scheduler V1');
        Console::success(APP_NAME . ' functions scheduler v1 has started');

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

        Console::success('Starting functions scheduler at ' . DateTime::now());

        $schedules->run($this->dispatch(...));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    /**
     * The window a function's spread offset is drawn from. Cloud overrides
     * this per plan; here it is one setting for the whole instance.
     *
     * @param array<string, mixed> $schedule
     */
    protected function spreadWindow(array $schedule, Database $dbForPlatform): int
    {
        return (int) System::getEnv('_APP_FUNCTIONS_SCHEDULE_SPREAD', '0');
    }

    /**
     * Invalid and impossible expressions are rejected here, once, rather than
     * silently matching nothing on every tick.
     *
     * @param array<string, mixed> $schedule
     */
    public function trigger(array $schedule): Trigger
    {
        return new Cron((string) $schedule['schedule']);
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences): void
    {
        $dbForPlatform = $this->dbForPlatform ?? throw new \LogicException('start() must run before dispatch()');
        $schedules = $this->schedules ?? throw new \LogicException('start() must run before dispatch()');

        $timerStart = \microtime(true);
        $delayed = []; // Runs sharing a delay share one coroutine

        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;
            $offset = self::spreadOffset($schedule['resourceId'], $this->spreadWindow($schedule, $dbForPlatform));

            // A run recovered from a gap is already past due, so it clamps to
            // zero and goes out immediately instead of sleeping backwards.
            $delay = \max(0, $occurrence->due->getTimestamp() - \time() + $offset);

            $delayed[$delay][] = $schedule;
        }

        foreach ($delayed as $delay => $batch) {
            \go(function () use ($delay, $batch, $dbForPlatform, $schedules): void {
                if ($delay > 0) {
                    \sleep($delay); // in seconds
                }

                foreach ($batch as $schedule) {
                    if (!$schedules->isLive($schedule)) {
                        continue;
                    }

                    Span::init('schedule.functions.enqueue');
                    try {
                        Span::add('project.id', $schedule['project']->getId());
                        Span::add('function.id', $schedule['resource']->getId());
                        Span::add('schedule.id', $schedule['$id'] ?? '');

                        $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                        $this->publisher?->enqueue(new FunctionMessage(
                            project: $schedule['project'],
                            function: $schedule['resource'],
                            type: 'schedule',
                            method: 'POST',
                            path: '/',
                        ));
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
}
