<?php
ini_set('memory_limit', -1);
ini_set('max_execution_time', -1);
global $cli;
global $register;

use Cron\CronExpression;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Swoole\Timer;

const FUNCTION_VALIDATION_TIMER = 180; //seconds
const FUNCTION_ENQUEUE_TIMER = 60; //seconds
const ENQUEUE_TIME_FRAME = 60 * 5; // 5 min
sleep(4); // Todo prevent PDOException



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
        Console::error("Queue was built in " . ($loadEnd - $loadStart) . " seconds");

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
    $limit = 200;
    $sum = $limit;
    $functions = [];
    $queue = [];
    $count = 0;
    $loadStart = \microtime(true);
    $total = 0;
    /**
     * Initial run fill $functions list
     */
    while ($sum === $limit) {
        $results = $dbForConsole->find('schedules', [
            Query::equal('region', [App::getEnv('_APP_REGION')]),
            Query::equal('resourceType', ['function']),
            Query::equal('active', [true]),
            Query::offset($count * $limit),
            Query::limit($limit),
        ]);

        $sum = count($results);

        $total = $total + $sum;
        foreach ($results as $document) {
            $functions[$document['resourceId']] = $document;
        }
        $count++;
    }

    $loadEnd = \microtime(true);
    Console::error("{$total} functions where loaded in " . ($loadEnd - $loadStart) . " seconds");

    $createQueue();

    $lastUpdate =  DateTime::addSeconds(new \DateTime(), -FUNCTION_VALIDATION_TIMER);

    Co\run(
        function () use ($removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
            Timer::tick(FUNCTION_VALIDATION_TIMER * 1000, function () use ($removeFromQueue, $createQueue, $dbForConsole, &$functions, &$queue, &$lastUpdate) {
                $time = DateTime::now();
                $count = 0;
                $limit = 50;
                $sum = $limit;

                Console::info("Update proc run at: $time last update was at $lastUpdate");
                /**
                 * Updating functions list from DB.
                 */
                while (!empty($sum)) {
                    $results = $dbForConsole->find('schedules', [
                        Query::equal('region', [App::getEnv('_APP_REGION')]),
                        Query::equal('resourceType', ['function']),
                        Query::greaterThan('resourceUpdatedAt', $lastUpdate),
                        Query::limit($limit),
                        Query::offset($count * $limit),
                    ]);
                    $sum = count($results);
                    foreach ($results as $document) {
                        $org = isset($functions[$document['resourceId']]) ? strtotime($functions[$document['resourceId']]['resourceUpdatedAt']) : null;
                        $new = strtotime($document['resourceUpdatedAt']);
                        if ($document['active'] === false) {
                            Console::error("Removing:  {$document['resourceId']}");
                            unset($functions[$document['resourceId']]);
                        } elseif ($new > $org) {
                            Console::error("Updating:  {$document['resourceId']}");
                            $functions[$document['resourceId']] =  $document;
                        }
                        $removeFromQueue($document['resourceId']);
                    }
                    $count++;
                }

                $lastUpdate = DateTime::now();
                $createQueue();
            });

            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, function () use ($dbForConsole, &$functions, &$queue) {
                $time = DateTime::now();
                $timeFrame =  DateTime::addSeconds(new \DateTime(), ENQUEUE_TIME_FRAME); /** 5 min */
                $now = (new \DateTime())->format('Y-m-d H:i:00.000');

                Console::info("Enqueue proc run at: $time");
                // Debug
                foreach ($queue as $slot => $schedule) {
                    Console::log("Slot: $slot");
                    foreach ($schedule as $function) {
                            Console::log("{$function['resourceId']} {$function['schedule']}");
                    }
                }

                /**
                 * Lopping time slots
                 */

                foreach ($queue as $slot => $schedule) {
                    if ($now === $slot) {
                        foreach ($schedule as $function) {
                        /**
                         * Enqueue function
                         */
                            Console::warning("Enqueueing :{$function['resourceId']}");
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
                                Console::warning("re-enqueueing :{$function['resourceId']}");
                                $queue[$next][$function['resourceId']] = $function;
                            }
                            unset($queue[$slot][$function['resourceId']]); /** removing function from slot */
                        }
                        unset($queue[$slot]); /** removing slot */
                    }
                }
            });
        }
    );
});
