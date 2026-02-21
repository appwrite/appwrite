<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Cron\CronExpression;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;

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

        $enqueueDiff = $this->lastEnqueueUpdate === null ? 0 : $timerStart - $this->lastEnqueueUpdate;
        $timeFrame = DateTime::addSeconds(new \DateTime(), static::ENQUEUE_TIMER - $enqueueDiff);

        Console::log("Enqueue tick: started at: $time (with diff $enqueueDiff)");

        $total = 0;

        $delayedExecutions = []; // Group executions with same delay to share one coroutine

        foreach ($this->schedules as $key => $schedule) {
            try {
                $cron = new CronExpression($schedule['schedule']);
            } catch (\InvalidArgumentException) {
                // ignore invalid cron expressions
                continue;
            }

            $nextDate = $cron->getNextRunDate();
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
                        return;
                    }

                    $schedule = $this->schedules[$scheduleKey];

                    $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                    $queueForFunctions = new Func($this->publisherFunctions);

                    $queueForFunctions
                        ->setType('schedule')
                        ->setFunction($schedule['resource'])
                        ->setMethod('POST')
                        ->setPath('/')
                        ->setProject($schedule['project'])
                        ->trigger();

                    $this->recordEnqueueDelay($delayConfig['nextDate']);
                }
            });
        }

        $timerEnd = \microtime(true);

        // TODO: This was a bug before because it wasn't passed by reference, enabling it breaks scheduling
        //$this->lastEnqueueUpdate = $timerStart;

        Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
    }
}
