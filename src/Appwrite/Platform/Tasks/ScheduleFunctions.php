<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Store\Redis as ClaimStore;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleFunctions extends Action
{
    public const UPDATE_TIMER = 10; // seconds between reconciliations
    public const ENQUEUE_TIMER = 60; // seconds between ticks
    public const ENQUEUE_LOOKAHEAD = 60; // seconds of lead time, so a dispatch can sleep to the second
    public const ENQUEUE_LOOKBACK = 300; // seconds of missed runs a restart recovers

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

    public static function spreadOffset(string $resourceId, int $window): int
    {
        return $window <= 1 ? 0 : \abs(\crc32($resourceId)) % $window;
    }

    public function action(FunctionPublisher $publisherForFunctions, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        ($this->boot($publisherForFunctions, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools))();

        Span::init('schedule.functions.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));

        exit(1);
    }

    /**
     * Load the schedules and return the loop that dispatches them, so the
     * combined task can load all three before running any.
     */
    public function boot(FunctionPublisher $publisher, Telemetry $telemetry, Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): \Closure
    {
        Span::init('schedule.functions.boot');

        $source = new ScheduleSource(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            resource: fn (Database $projectDB, array $schedule): Document => $projectDB->getDocument(self::getCollectionId(), $schedule['resourceId']),
            entry: fn (array $schedule): Entry => new Entry(new Cron((string) $schedule['schedule']), $schedule),
            recency: self::UPDATE_TIMER * 3,
        );

        $scheduler = new Scheduler(
            source: $source,
            store: new ClaimStore($pools->get('lock')->pop()->resource, 'utopia-schedule-' . self::getName()),
            interval: self::ENQUEUE_TIMER,
            sync: self::UPDATE_TIMER,
            relist: self::UPDATE_TIMER * 30,
            lookahead: self::ENQUEUE_LOOKAHEAD,
            lookback: self::ENQUEUE_LOOKBACK,
            lease: self::ENQUEUE_TIMER * 15,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.functions.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->reconcile();

        Span::add('schedule.functions.loaded', $source->snapshotted());
        Span::current()?->finish();

        return fn (): null => $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $source, $publisher, $dbForPlatform));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
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
    private function dispatch(array $occurrences, ScheduleSource $source, FunctionPublisher $publisher, Database $dbForPlatform): null
    {
        $delayed = [];

        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;
            $offset = self::spreadOffset($schedule['resourceId'], $this->spreadWindow($schedule, $dbForPlatform));

            // Clamped, so a run recovered from a gap goes out now instead of
            // sleeping backwards.
            $delayed[\max(0, $occurrence->due->getTimestamp() - \time() + $offset)][] = $schedule;
        }

        foreach ($delayed as $delay => $batch) {
            \go(function () use ($delay, $batch, $source, $publisher, $dbForPlatform): void {
                if ($delay > 0) {
                    \sleep($delay);
                }

                foreach ($batch as $schedule) {
                    if (!$source->isLive((string) $schedule['$sequence'], (string) $schedule['resourceUpdatedAt'])) {
                        continue;
                    }

                    Span::init('schedule.functions.enqueue');
                    $error = null;

                    try {
                        Span::add('project.id', $schedule['project']->getId());
                        Span::add('function.id', $schedule['resource']->getId());
                        Span::add('schedule.id', $schedule['$id'] ?? '');

                        $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                        $publisher->enqueue(new FunctionMessage(
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
            });
        }

        return null;
    }
}
