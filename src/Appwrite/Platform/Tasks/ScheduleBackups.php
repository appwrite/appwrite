<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Migration;
use Cron\CronExpression;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Pools\Group;

class ScheduleBackups extends ScheduleBase
{
    public const UPDATE_TIMER = 10; // seconds
    public const ENQUEUE_TIMER = 60; // seconds

    private ?float $lastEnqueueUpdate = null;

    public static function getName(): string
    {
        return 'schedule-backups';
    }

    public static function getSupportedResource(): string
    {
        return 'backup';
    }

    protected function enqueueResources(Group $pools, Database $dbForConsole): void
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
            \go(function () use ($delay, $scheduleKeys, $pools, $dbForConsole) {
                \sleep($delay); // in seconds

                $queue = $pools->get('queue')->pop();
                $connection = $queue->getResource();

                foreach ($scheduleKeys as $scheduleKey) {
                    // Ensure schedule was not deleted
                    if (!\array_key_exists($scheduleKey, $this->schedules)) {
                        return;
                    }

                    $schedule = $this->schedules[$scheduleKey];

                    $backups = $dbForConsole->createDocument('backups', new Document([
                        'policyId' => $schedule['project'],
                        'policyInternalId' => $function->getInternalId(),

                    ]));


                    $queueForMigrations = new Migration($connection);



//                    $queueForMigrations
//                        ->setType('schedule')
//                        ->setFunction($schedule['resource'])
//                        ->setMethod('POST')
//                        ->setPath('/')
//                        ->setProject($schedule['project'])
//                        ->trigger();
                }

                $queue->reclaim();
            });
        }

        $timerEnd = \microtime(true);

        // TODO: This was a bug before because it wasn't passed by reference, enabling it breaks scheduling
        //$this->lastEnqueueUpdate = $timerStart;

        Console::log("Enqueue tick: {$total} executions were enqueued in " . ($timerEnd - $timerStart) . " seconds");
    }
}
