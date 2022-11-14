<?php

global $cli;
global $register;

use Cron\CronExpression;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Swoole\Timer;

const FUNCTION_UPDATE_TIMER = 10; //seconds
const FUNCTION_ENQUEUE_TIMER = 10; //seconds

/**
 * 1. Load all documents from 'schedules' collection to create local copy
 * 2. Create timer that sync all changes from 'schedules' collection to local copy. Only reading changes thanks to 'resourceUpdatedAt' attribute
 * 3. Create timer that prepares coroutines for soon-to-execute schedules. When it's ready, coroutime sleeps until exact time before sending request to worker.
 */
$cli
->task('schedule')
->desc('Function scheduler task')
->action(function () {
    Console::title('Scheduler V1');
    Console::success(APP_NAME . ' Scheduler v1 has started');

    $dbForConsole = getConsoleDB();

    /**
     * Extract only nessessary attributes to lower memory used.
     * 
     * @var Document $schedule
     * @return  array
     */
    $getSchedule = function (Document $schedule) use ($dbForConsole): array {
        $project = $dbForConsole->getDocument('projects', $schedule->getAttribute('projectId'));
        $function = getProjectDB($project)->getDocument('functions', $schedule->getAttribute('resourceId'));

        return [
            'resourceId' => $schedule->getAttribute('resourceId'),
            'schedule' => $schedule->getAttribute('schedule'),
            'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
            'project' => $project,
            'function' => $function,
        ];
    };

    $schedules = []; // Local copy of 'schedules' collection
    $lastSyncUpdate = DateTime::now();

    $limit = 10000;
    $sum = $limit;
    $total = 0;
    $loadStart = \microtime(true);
    $latestDocument = null;

    while ($sum === $limit) {
        $paginationQueries = [Query::limit($limit)];
        if ($latestDocument !== null) {
            $paginationQueries[] =  Query::cursorAfter($latestDocument);
        }
        $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
            Query::equal('region', [App::getEnv('_APP_REGION')]),
            Query::equal('resourceType', ['function']),
            Query::equal('active', [true]),
        ]));

        $sum = count($results);
        $total = $total + $sum;
        foreach ($results as $document) {
            $schedules[$document['resourceId']] = $getSchedule($document);
        }

        $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
    }

    $loadEnd = \microtime(true);
    Console::success("{$total} schedules where loaded in " . ($loadEnd - $loadStart) . " seconds");

    $time = DateTime::now();
    Console::success("Starting timers at {$time}");

    Co\run(
        function () use ($dbForConsole, &$schedules, &$lastSyncUpdate, $getSchedule) {
            /**
             * The timer synchronize $schedules copy with database collection.
             */
            Timer::tick(FUNCTION_UPDATE_TIMER * 1000, function () use ($dbForConsole, &$schedules, &$lastSyncUpdate, $getSchedule) {
                $time = DateTime::now();
                $timerStart = \microtime(true);

                $limit = 1000;
                $sum = $limit;
                $total = 0;
                $latestDocument = null;

                Console::log("Sync tick: Running at $time");

                while ($sum === $limit) {
                    $paginationQueries = [Query::limit($limit)];
                    if ($latestDocument !== null) {
                        $paginationQueries[] =  Query::cursorAfter($latestDocument);
                    }
                    $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
                        Query::equal('region', [App::getEnv('_APP_REGION')]),
                        Query::equal('resourceType', ['function']),
                        Query::greaterThanEqual('resourceUpdatedAt', $lastSyncUpdate),
                    ]));

                    $sum = count($results);
                    $total = $total + $sum;
                    foreach ($results as $document) {
                        $localDocument = $schedules[$document['resourceId']] ?? null;

                        $org = $localDocument !== null ? strtotime($localDocument['resourceUpdatedAt']) : null;
                        $new = strtotime($document['resourceUpdatedAt']);

                        if ($document['active'] === false) {
                            Console::info("Removing:  {$document['resourceId']}");
                            unset($schedules[$document['resourceId']]);
                        } elseif ($new !== $org) {
                            Console::info("Updating:  {$document['resourceId']}");
                            $schedules[$document['resourceId']] = $getSchedule($document);
                        }
                    }
                    $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
                }

                $lastSyncUpdate = $time;
                $timerEnd = \microtime(true);

                Console::log("Sync tick: {$total} schedules where updates in " . ($timerEnd - $timerStart) . " seconds");
            });

            /**
             * The timer to prepare soon-to-execute schedules.
             */
            $lastEnqueueUpdate = null;
            $enqueueFunctions = function () use (&$schedules, $lastEnqueueUpdate) {
                $timerStart = \microtime(true);
                $time = DateTime::now();

                $enqueueDiff = $lastEnqueueUpdate === null ? 0 : $timerStart - $lastEnqueueUpdate;
                $timeFrame = DateTime::addSeconds(new \DateTime(), FUNCTION_ENQUEUE_TIMER - $enqueueDiff);

                Console::log("Enqueue tick: started at: $time (with diff $enqueueDiff)");

                $total = 0;

                foreach ($schedules as $key => $schedule) {
                    $cron = new CronExpression($schedule['schedule']);
                    $nextDate = $cron->getNextRunDate();
                    $next = DateTime::format($nextDate);

                    $currentTick = $next < $timeFrame;

                    if(!$currentTick) {
                        continue;
                    }

                    $total++;

                    $promiseStart = \microtime(true); // in seconds
                    $executionStart = $nextDate->getTimestamp(); // in seconds
                    $executionSleep = $executionStart - $promiseStart; // Time to wait from now until execution needs to be queued

                    \go(function() use ($executionSleep, $key, $schedules) {
                        \usleep($executionSleep * 1000000); // in microseconds

                        // Ensure schedule was not deleted
                        if(!isset($schedules[$key])) {
                            return;
                        }

                        Console::success("Executing function at " . DateTime::now()); // TODO: Send to worker queue
                    });
                }

                $timerEnd = \microtime(true);
                $lastEnqueueUpdate = $timerStart;
                Console::log("Enqueue tick: {$total} executions where enqueued in " . ($timerEnd - $timerStart) . " seconds");
            };

            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, fn() => $enqueueFunctions());
            $enqueueFunctions();
        }
    );
});
