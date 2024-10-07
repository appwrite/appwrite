<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Func;
use Cron\CronExpression;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Queue\Connection\Redis;

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
        return 'function';
    }

    public static function getCollectionId(): string
    {
        return 'functions';
    }

    protected function enqueueResources(array $pools, callable $getConsoleDB): void
    {
        $timerStart = \microtime(true);
        $time = DateTime::now();

        $enqueueDiff = $this->lastEnqueueUpdate === null ? 0 : $timerStart - $this->lastEnqueueUpdate;
        $timeFrame = DateTime::addSeconds(new \DateTime(), static::ENQUEUE_TIMER - $enqueueDiff);

        Console::log("Enqueue tick: started at: $time (with diff $enqueueDiff)");

        $total = 0;

        $delayedExecutions = []; // Group executions with same delay to share one coroutine

        foreach ($this->schedules as $key => $schedule) {
            $cron = new CronExpression($schedule['schedule']);
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

            $delayedExecutions[$delay][] = $key;
        }

        foreach ($delayedExecutions as $delay => $scheduleKeys) {
            \go(function () use ($delay, $scheduleKeys, $pools) {
                \sleep($delay); // in seconds

                $pool = $pools['pools-queue-queue']['pool'];
                $connection = $pool->get();
                $this->connections->add($connection, $pool);

                $queueConnection = new Redis($connection);

                foreach ($scheduleKeys as $scheduleKey) {
                    // Ensure schedule was not deleted
                    if (!\array_key_exists($scheduleKey, $this->schedules)) {
                        return;
                    }

                    $schedule = $this->schedules[$scheduleKey];

                    $queueForFunctions = new Func($queueConnection);

                    $queueForFunctions
                        ->setType('schedule')
                        ->setFunction($schedule['resource'])
                        ->setMethod('POST')
                        ->setPath('/')
                        ->setProject($schedule['project'])
                        ->trigger();
                }

                $this->connections->reclaim();
                // $queue->reclaim(); // TODO: Do in try/catch/finally, or add to connectons resource
            });
        }

        $timerEnd = \microtime(true);

        // TODO: This was a bug before because it wasn't passed by reference, enabling it breaks scheduling
        //$this->lastEnqueueUpdate = $timerStart;

        Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
    }
}
