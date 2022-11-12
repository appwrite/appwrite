<?php

global $cli;
global $register;

use Appwrite\Event\Event;
use Cron\CronExpression;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Swoole\Timer;
use Utopia\Queue\Client as worker;

const FUNCTION_UPDATE_TIMER = 60; //seconds
const FUNCTION_ENQUEUE_TIMER = 60; //seconds
const ENQUEUE_TIME_FRAME = 60 * 5; // 5 min
sleep(4); // Todo prevent PDOException


/**
 * 1. first load from db with limit+offset --line 82--
 * 2. creating a 5-min offset array ($queue) --line 102--
 * 3. First timer runs every minute, looping over $queue time slots (each slot is 1-min delta)
 *    if the function matches the current minute it should be dispatched to the functions worker.
 *    Then another translation is made to the cron pattern if it is in the next 5-min window
 *    it is assigned again to the  $queue. --line 172--.
 * 4. Second timer  runs every X min and updates the $functions (large) list.
 *    The query fetches only functions that [resourceUpdatedAt] attr changed from the
 *    last time the timer that was fired (X min) --line 120--
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

    $createQueue = function () use (&$functions, &$queue) {
        $loadStart = \microtime(true);
        /**
         * Creating smaller functions list containing 5-min timeframe.
         */
        $timeFrame = DateTime::addSeconds(new \DateTime(), ENQUEUE_TIME_FRAME);
        foreach ($functions as $function) {
            $cron = new CronExpression($function['schedule']);
            $next = DateTime::format($cron->getNextRunDate());
            if ($next < $timeFrame) {
                $queue[$next][$function['resourceId']] = $function;
            }
        }
        $loadEnd = \microtime(true);
        Console::success("Queue was built in " . ($loadEnd - $loadStart) . " seconds");
    };

    $removeFromQueue = function ($scheduleId) use (&$queue) {
        foreach ($queue as $slot => $schedule) {
            foreach ($schedule as $function) {
                if ($scheduleId === $function['resourceId']) {
                    Console::error("Unsetting :{$function['resourceId']} from queue slot $slot");
                    unset($queue[$slot][$function['resourceId']]);
                }
            }
        }
    };

    $dbForConsole = getConsoleDB();
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
            $functions[$document['resourceId']] = [
                'resourceId' => $document->getAttribute('resourceId'),
                'schedule' => $document->getAttribute('schedule'),
                'resourceUpdatedAt' => $document->getAttribute('resourceUpdatedAt'),
                'projectId' => $document->getAttribute('projectId')
            ];
        }

        $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
    }

    $loadEnd = \microtime(true);
    Console::success("{$total} functions where loaded in " . ($loadEnd - $loadStart) . " seconds");
    $createQueue();
    $lastUpdate =  DateTime::addSeconds(new \DateTime(), -FUNCTION_UPDATE_TIMER);

    /**
     * The timer updates $functions from db on last resourceUpdatedAt attr in X-min.
     */
    Co\run(
        function () use ($register, $removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
            Timer::tick(FUNCTION_UPDATE_TIMER * 1000, function () use ($removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
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
                        Query::greaterThan('resourceUpdatedAt', $lastUpdate),
                    ]));

                    $sum = count($results);
                    $total = $total + $sum;
                    foreach ($results as $document) {
                        $org = isset($functions[$document['resourceId']]) ? strtotime($functions[$document['resourceId']]['resourceUpdatedAt']) : null;
                        $new = strtotime($document['resourceUpdatedAt']);
                        if ($document['active'] === false) {
                            Console::warning("Removing:  {$document['resourceId']}");
                            unset($functions[$document['resourceId']]);
                        } elseif ($new > $org) {
                            Console::warning("Updating:  {$document['resourceId']}");
                            $functions[$document['resourceId']] =  [
                                'resourceId' => $document->getAttribute('resourceId'),
                                'schedule' => $document->getAttribute('schedule'),
                                'resourceUpdatedAt' => $document->getAttribute('resourceUpdatedAt'),
                            ];
                        }
                        $removeFromQueue($document['resourceId']);
                    }

                    $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
                }

                $lastUpdate = DateTime::now();
                $createQueue();
                $timerEnd = \microtime(true);

                Console::warning("Update timer: {$total} functions where updated in " . ($timerEnd - $timerStart) . " seconds");
            });

            /**
             * The timer sends to worker every 1 min and re-enqueue matched functions.
             */
            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, function () use ($register, $dbForConsole, &$functions, &$queue) {
                $timerStart = \microtime(true);
                $time = DateTime::now();
                $timeFrame =  DateTime::addSeconds(new \DateTime(), ENQUEUE_TIME_FRAME); /** 5 min */
                $slot = (new \DateTime())->format('Y-m-d H:i:00.000');

                Console::info("Enqueue proc started at: $time");

                if (array_key_exists($slot, $queue)) {
                    $schedule = $queue[$slot];
                    console::info(count($schedule) . "  functions sent to worker  for time slot " . $slot);

                    foreach ($schedule as $function) {
                        $pools = $register->get('pools');
                        $worker = new worker(Event::FUNCTIONS_QUEUE_NAME, $pools->get('queue')->pop()->getResource());
                        $project = $dbForConsole->getDocument('projects', $function['projectId']);
                        $worker
                            ->enqueue([
                                'type' => 'schedule',
                                'value' => [
                                    'project' =>  $project,
                                    'function' => getProjectDB($project)->getDocument('functions', $function['projectId']),
                                ]
                            ]);
                        //Console::warning("Enqueueing :{$function['resourceId']}");
                        $cron = new CronExpression($function['schedule']);
                        $next = DateTime::format($cron->getNextRunDate());

                    /**
                    * If next schedule is in 5-min timeframe
                    * and it was not removed or changed, re-enqueue the function.
                    */
                        if (
                            $next < $timeFrame &&
                            !empty($functions[$function['resourceId']] &&
                            $function['schedule'] === $functions[$function['resourceId']]['schedule'])
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
