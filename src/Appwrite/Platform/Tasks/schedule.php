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

const FUNCTION_UPDATE_TIMER = 60; //seconds
const FUNCTION_ENQUEUE_TIMER = 60; //seconds
const FUNCTION_ENQUEUE_TIMEFRAME = 60 * 5; // 5 min

sleep(4);

/**
 * 1. first load from db with limit+offset
 * 2. creating a 5-min offset array ($queue)
 * 3. First timer runs every minute, looping over $queue time slots (each slot is 1-min delta)
 *    if the function matches the current minute it should be dispatched to the functions worker.
 *    Then another translation is made to the cron pattern if it is in the next 5-min window
 *    it is assigned again to the  $queue. .
 * 4. Second timer  runs every X min and updates the $functions (large) list.
 *    The query fetches only functions that [resourceUpdatedAt] attr changed from the
 *    last time the timer that was fired (X min)
 *    If the function was deleted it is unsets from the list ($functions) and the $queue.
 *    In the end of the timer the $queue is created again.
 *
 */
$cli
->task('schedule')
->desc('Function scheduler task')
->action(function () use ($register) {
    Console::title('Scheduler V1');
    Console::success(APP_NAME . ' Scheduler v1 has started');

    $dbForConsole = getConsoleDB();

    /**
     * @return  void
     */
    $createQueue = function () use (&$functions, &$queue): void {
        $loadStart = \microtime(true);
        /**
         * Creating smaller functions list containing 5-min timeframe.
         */
        $timeFrame = DateTime::addSeconds(new \DateTime(), FUNCTION_ENQUEUE_TIMEFRAME);
        foreach ($functions as $function) {
            $cron = new CronExpression($function['schedule']);
            $next = DateTime::format($cron->getNextRunDate());

            if ($next < $timeFrame) {
                $queue[$next][$function['resourceId']] = $function;
            }
        }

        Console::success("Queue was built in " . (microtime(true) - $loadStart) . " seconds");
    };

    /**
     * @param string $id
     * @param string $resourceId
     * @return  void
     */
    $removeFromQueue = function (string $resourceId) use (&$queue, &$functions, $dbForConsole) {
        if (array_key_exists($resourceId, $functions)) {
            unset($functions[$resourceId]);
            Console::error("Removing :{$resourceId} from functions list");
        }

        foreach ($queue as $slot => $schedule) {
            if (array_key_exists($resourceId, $schedule)) {
                unset($queue[$slot][$resourceId]);
                Console::error("Removing :{$resourceId} from queue slot $slot");
            }
        }
    };

    /**
     * @param string $resourceId
     * @param array $update
     * @return  void
     */
    $updateQueue = function (string $resourceId, array $update) use (&$queue, &$functions): void {

        $functions[$resourceId] = $update;
        Console::error("Updating :{$resourceId} in functions list");

        foreach ($queue as $slot => $schedule) {
            if (array_key_exists($resourceId, $schedule)) {
                $queue[$slot][$resourceId] = $update;
                Console::error("Updating :{$resourceId} in queue slot $slot");
            }
        }
    };

    /**
     * @var Document $schedule
     * @return  array
     */
    $getSchedule = function (Document $schedule) use ($dbForConsole): array {
        $project = $dbForConsole->getDocument('projects', $schedule->getAttribute('schedule'));

        return [
            'resourceId' => $schedule->getAttribute('resourceId'),
            'schedule' => $schedule->getAttribute('schedule'),
            'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
            'project' => $project,
            //'function' => getProjectDB($project)->getDocument('functions', $schedule->getAttribute('resourceId'))
        ];
    };

    $limit = 10000;
    $sum = $limit;
    $functions = [];
    $queue = [];
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
            $functions[$document['resourceId']] = $getSchedule($document);
        }

        $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
    }

    Console::success("{$total} functions where loaded in " . (microtime(true) - $loadStart) . " seconds");
    $createQueue();
    $lastUpdate =  DateTime::addSeconds(new \DateTime(), -600); // 10 min

    Co\run(
        function () use ($getSchedule, $updateQueue, $removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
            Timer::tick(FUNCTION_UPDATE_TIMER * 1000, function () use ($getSchedule, $updateQueue, $removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
                $time = DateTime::now();
                $limit = 1000;
                $sum = $limit;
                $total = 0;
                $latestDocument = null;
                $timerStart = \microtime(true);

                Console::warning("Update proc started at: $time last update was at $lastUpdate");

                while ($sum === $limit) {
                    $paginationQueries = [Query::limit($limit)];
                    if ($latestDocument !== null) {
                        $paginationQueries[] =  Query::cursorAfter($latestDocument);
                    }
                    $results = $dbForConsole->find('schedules', \array_merge($paginationQueries, [
                        Query::equal('region', [App::getEnv('_APP_REGION')]),
                        Query::equal('resourceType', ['function']),
                        Query::greaterThanEqual('resourceUpdatedAt', $lastUpdate),
                    ]));

                    $sum = count($results);
                    $total = $total + $sum;
                    foreach ($results as $document) {
                        $org = isset($functions[$document['resourceId']]) ? strtotime($functions[$document['resourceId']]['resourceUpdatedAt']) : null;
                        $new = strtotime($document['resourceUpdatedAt']);
                        if ($document['active'] === false) {
                            $removeFromQueue($document['resourceId']);
                        } elseif ($new > $org) {
                            $updateQueue($document['resourceId'], $getSchedule($document));
                        }
                    }
                    $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
                }

                $lastUpdate = DateTime::now();
                $createQueue();
                Console::warning("Update timer: {$total} functions where updated in " . (microtime(true) - $timerStart) . " seconds");
            });

            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, function () use ($dbForConsole, &$functions, &$queue) {
                $timerStart = \microtime(true);
                $timeFrame =  DateTime::addSeconds(new \DateTime(), FUNCTION_ENQUEUE_TIMEFRAME);
                $slot = (new \DateTime())->format('Y-m-d H:i:00.000');

                Console::info("Enqueue proc started at: " . DateTime::now());

                $count = 0;
                if (array_key_exists($slot, $queue)) {
                    $schedule = $queue[$slot];

                    foreach ($schedule as $function) {
                        if (empty($functions[$function['resourceId']])) {
                            continue;
                        }

                        $cron = new CronExpression($function['schedule']);
                        $next = DateTime::format($cron->getNextRunDate());

                        /**
                        * If next schedule is in 5-min timeframe
                        * and it was not removed or changed, re-enqueue the function.
                        */
                        if (
                            $next < $timeFrame &&
                            $function['schedule'] ?? [] === $functions[$function['resourceId']]['schedule']
                        ) {
                            $queue[$next][$function['resourceId']] = $function;
                        }
                        unset($queue[$slot][$function['resourceId']]); /** removing function from slot */
                        $count++;
                    }
                    unset($queue[$slot]); /** removing slot */
                }

                $timerEnd = \microtime(true);
                Console::info("Queue timer: finished in " . ($timerEnd - $timerStart) . " seconds with {$count} functions");
            });
        }
    );
});
