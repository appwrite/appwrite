<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Cron\CronExpression;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Span\Span;

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

    private ?float $lastEnqueueUpdate = null;

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

    protected function enqueueResources(Database $dbForPlatform, callable $getProjectDB): void
    {
        $timerStart = \microtime(true);
        $time = DateTime::now();

        // Widen the look-ahead window by how much the cycle ran late so that
        // schedules cannot fall into the gap between two consecutive windows.
        // Using max(0, ...) ensures an early wake-up never shrinks the window.
        $elapsed = $this->lastEnqueueUpdate === null ? 0.0 : $timerStart - $this->lastEnqueueUpdate;
        $enqueueDiff = max(0.0, $elapsed - static::ENQUEUE_TIMER);
        $this->lastEnqueueUpdate = $timerStart;
        $timeFrame = DateTime::addSeconds(new \DateTime(), static::ENQUEUE_TIMER + (int)ceil($enqueueDiff));

        Console::log("Enqueue tick: started at: $time (with diff $enqueueDiff)");

        $total = 0;

        $delayedExecutions = []; // Group executions with same delay to share one coroutine

        foreach ($this->schedules as $key => $schedule) {
            try {
                $cron = new CronExpression($schedule['schedule']);
                $nextDate = $cron->getNextRunDate();
            } catch (\InvalidArgumentException) {
                // ignore invalid cron expressions
                continue;
            } catch (\RuntimeException) {
                // ignore impossible cron expressions
                continue;
            }

            $next = DateTime::format($nextDate);

            $currentTick = $next < $timeFrame;

            if (!$currentTick) {
                continue;
            }

            $total++;

            $promiseStart = \time(); // in seconds
            $executionStart = $nextDate->getTimestamp(); // in seconds
            $delay = $executionStart - $promiseStart; // Time to wait from now until execution needs to be queued

            if (!isset($delayedExecutions[$delay])) {
                $delayedExecutions[$delay] = [];
            }

            $delayedExecutions[$delay][] = ['key' => $key, 'nextDate' => $nextDate];
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

        $timerEnd = \microtime(true);

        Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
    }
}
