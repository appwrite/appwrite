<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Cron\CronExpression;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * ScheduleFunctions
 *
 * Handles cron job related executions by processing cron expressions
 * and scheduling function executions based on recurring schedules.
 */
class ScheduleFunctions extends ScheduleBase
{
    public const UPDATE_TIMER = 10; // seconds
    public const ENQUEUE_TIMER = 60; // seconds

    /**
     * How far back, in seconds, the persisted enqueue window is trusted.
     * Bounds the catch-up burst after downtime: an every-minute schedule
     * replays at most this window's worth of missed occurrences.
     */
    public const ENQUEUE_LOOKBACK = self::ENQUEUE_TIMER * 5;

    protected ?Cache $cache = null;

    public function __construct()
    {
        parent::__construct();

        $this->inject('cache');
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

    public function action(BrokerPool $publisher, BrokerPool $publisherMigrations, BrokerPool $publisherFunctions, BrokerPool $publisherMessaging, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, ?Cache $cache = null): never
    {
        $this->cache = $cache;

        parent::action($publisher, $publisherMigrations, $publisherFunctions, $publisherMessaging, $getIsResourceBlocked, $dbForPlatform, $getProjectDB, $telemetry);
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

    /**
     * Cron occurrences of $schedule inside ($windowStart, $timeFrame),
     * oldest first. Anchoring on the window instead of "now" keeps the
     * selection stable no matter how long the caller's loop has already
     * been running: an occurrence on a minute boundary the loop crosses
     * mid-pass stays selectable, and one that fell between two ticks is
     * still returned by the tick whose window covers it.
     *
     * @return array<\DateTime>
     */
    public static function occurrencesWithin(string $schedule, \DateTime $windowStart, string $timeFrame): array
    {
        $occurrences = [];

        try {
            $cron = new CronExpression($schedule);
            $next = $cron->getNextRunDate($windowStart);

            while (DateTime::format($next) < $timeFrame) {
                $occurrences[] = $next;
                $next = $cron->getNextRunDate($next);
            }
        } catch (\InvalidArgumentException | \RuntimeException) {
            // invalid or impossible cron expressions have no occurrences
        }

        return $occurrences;
    }

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $timerStart = \microtime(true);
        $tickStart = new \DateTime();
        $time = DateTime::format($tickStart);

        $spread = (int) System::getEnv('_APP_FUNCTIONS_SCHEDULE_SPREAD', '0');

        // The window opens where the previous tick's window closed (persisted
        // in cache), so occurrences missed between ticks — a process restart,
        // or wall time passing them while the previous pass was still running
        // — are enqueued late instead of never. A window end older than
        // ENQUEUE_LOOKBACK is discarded to cap the catch-up burst after a
        // long outage.
        $windowStart = $this->loadWindowEnd() ?? $tickStart;
        $timeFrame = DateTime::addSeconds(clone $tickStart, static::ENQUEUE_TIMER);

        Console::log("Enqueue tick: started at: $time (window start " . DateTime::format($windowStart) . ", spread {$spread}s)");

        $total = 0;

        $delayedExecutions = []; // Group executions with same delay to share one coroutine

        foreach ($this->schedules as $key => $schedule) {
            foreach (self::occurrencesWithin($schedule['schedule'], $windowStart, $timeFrame) as $nextDate) {
                $total++;

                $offset = self::spreadOffset($schedule['resourceId'], $this->spreadWindow($schedule, $dbForPlatform));
                // Recovered past-due occurrences enqueue immediately.
                $delay = \max(0, $nextDate->getTimestamp() - \time() + $offset);

                // nextDate carries the offset so enqueue-delay telemetry measures
                // lateness against the intended (spread) enqueue time.
                $delayedExecutions[$delay][] = ['key' => $key, 'nextDate' => $nextDate->modify("+{$offset} seconds")];
            }
        }

        foreach ($delayedExecutions as $delay => $schedules) {
            \go(function () use ($delay, $schedules, $dbForPlatform) {
                \sleep($delay); // in seconds

                foreach ($schedules as $delayConfig) {
                    $scheduleKey = $delayConfig['key'];
                    // Ensure schedule was not deleted
                    if (!\array_key_exists($scheduleKey, $this->schedules)) {
                        continue;
                    }

                    $schedule = $this->schedules[$scheduleKey];

                    $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                    $publisherForFunctions = new FunctionPublisher(
                        $this->publisherFunctions,
                        new \Utopia\Queue\Queue(\Utopia\System\System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', \Appwrite\Event\Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', \Appwrite\Event\Event::FUNCTIONS_QUEUE_TTL)
                    );

                    Span::init('schedule.functions.enqueue');
                    try {
                        Span::add('project.id', $schedule['project']->getId());
                        Span::add('function.id', $schedule['resource']->getId());
                        Span::add('schedule.id', $schedule['$id'] ?? '');

                        $publisherForFunctions->enqueue(new FunctionMessage(
                            project: $schedule['project'],
                            function: $schedule['resource'],
                            type: 'schedule',
                            method: 'POST',
                            path: '/',
                        ));

                        $this->recordEnqueueDelay($delayConfig['nextDate']);
                    } finally {
                        Span::current()?->finish();
                    }
                }
            });
        }

        $this->saveWindowEnd($timeFrame);

        $timerEnd = \microtime(true);

        Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
    }

    private function loadWindowEnd(): ?\DateTime
    {
        try {
            $value = $this->cache?->load(static::getName() . '-enqueue-window-end', self::ENQUEUE_LOOKBACK);

            return \is_string($value) ? new \DateTime($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveWindowEnd(string $windowEnd): void
    {
        try {
            $this->cache?->save(static::getName() . '-enqueue-window-end', $windowEnd);
        } catch (\Throwable $th) {
            Console::error('Failed to persist enqueue window end: ' . $th->getMessage());
        }
    }
}
