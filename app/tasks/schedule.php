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
const FUNCTION_RESET_TIMER_TO = 50; // seconds

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
        $loadEnd = \microtime(true);
        Console::success("Queue was built in " . ($loadEnd - $loadStart) . " seconds");
        //var_dump($queue);
    };

    /**
     * @param string $id
     * @param string $resourceId
     * @return  void
     */
    $removeFromQueue = function (string $id, string $resourceId) use (&$queue, &$functions, $dbForConsole) {
        if (array_key_exists($resourceId, $functions)) {
            unset($functions[$resourceId]);
            $dbForConsole->deleteDocument('schedules', $id);
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
    function getsSheduleAttributes(Document $schedule): array
    {
        return [
            'resourceId' => $schedule->getAttribute('resourceId'),
            'schedule' => $schedule->getAttribute('schedule'),
            'resourceUpdatedAt' => $schedule->getAttribute('resourceUpdatedAt'),
        ];
    }

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
            $functions[$document['resourceId']] = getsSheduleAttributes($document);
        }

        $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
    }

    $loadEnd = \microtime(true);
    Console::success("{$total} functions where loaded in " . ($loadEnd - $loadStart) . " seconds");
    $createQueue();
    $lastUpdate =  DateTime::addSeconds(new \DateTime(), -FUNCTION_UPDATE_TIMER);

    do {
        $second = time() % 60;
    } while ($second < FUNCTION_RESET_TIMER_TO);

    $time = DateTime::now();
    Console::success("Starting timers at  {$time}");


    /**
     * The timer updates $functions from db on last resourceUpdatedAt attr in X-min.
     */
    Co\run(
        function () use ($updateQueue, $removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
            Timer::tick(FUNCTION_UPDATE_TIMER * 1000, function () use ($updateQueue, $removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
                $time = DateTime::now();
                $limit = 1000;
                $sum = $limit;
                $total = 0;
                $latestDocument = null;
                $timerStart = \microtime(true);

                //Console::warning("Update proc started at: $time last update was at $lastUpdate");

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
                            //Console::warning("Removing:  {$document['resourceId']}");
                            $removeFromQueue($document->getId(), $document['resourceId']);
                        } elseif ($new > $org) {
                            //Console::warning("Updating:  {$document['resourceId']}");
                            $updateQueue($document['resourceId'], getsSheduleAttributes($document));
                        }
                    }

                    $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
                }

                $lastUpdate = DateTime::now();
                $createQueue();
                $timerEnd = \microtime(true);

                //Console::warning("Update timer: {$total} functions where updated in " . ($timerEnd - $timerStart) . " seconds");
            });

            /**
             * The timer sends to worker every 1 min and re-enqueue matched functions.
             */
            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, function () use ($dbForConsole, &$functions, &$queue) {
                $timerStart = \microtime(true);
                $time = DateTime::now();
                $timeFrame =  DateTime::addSeconds(new \DateTime(), FUNCTION_ENQUEUE_TIMEFRAME);
                $slot = (new \DateTime())->format('Y-m-d H:i:00.000');
                $prepareStart = time();

                Console::info("Enqueue proc started at: $time");

                if (array_key_exists($slot, $queue)) {
                    $schedule = $queue[$slot];
                    console::info(count($schedule) . "  functions sent to worker  for time slot " . $slot);
                    $totalPreparation = time() - $prepareStart;

                    $wait = ((60 - FUNCTION_RESET_TIMER_TO) - $totalPreparation);
                    Console::info("Waiting for : {$wait} seconds");
                    sleep($wait);

                    $time = DateTime::now();
                    Console::info("Start enqueueing at  {$time}");

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
                    }
                    unset($queue[$slot]); /** removing slot */
                }
                $timerEnd = \microtime(true);
                Console::info("Queue timer: finished in " . ($timerEnd - $timerStart) . " seconds");
            });
        }
    );
});
