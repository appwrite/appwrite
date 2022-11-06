<?php

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
->task('schedule-new')
->desc('Function scheduler task')
->action(function () use ($register) {
    Console::title('Scheduler V1');
    Console::success(APP_NAME . ' Scheduler v1 has started');

    $createQueue = function () use (&$functions, &$queue) {
        /**
         * Creating smaller functions list containing 5-min timeframe.
         */
        $timeFrame = DateTime::addSeconds(new \DateTime(), ENQUEUE_TIME_FRAME);
        foreach ($functions as $function) {
            $cron = new CronExpression($function['schedule']);
            $next = DateTime::format($cron->getNextRunDate());
            if ($next < $timeFrame) {
                $queue[$next][$function['scheduleId']] = $function;
            }
        }
    };

    $removeFromQueue = function ($scheduleId) use (&$queue) {
        foreach ($queue as $slot => $schedule) {
            foreach ($schedule as $function) {
                if ($scheduleId === $function['scheduleId']) {
                    unset($queue[$slot][$function['scheduleId']]);
                }
            }
        }
    };


    $dbForConsole = getConsoleDB();
    $count = 0;
    $limit = 50;
    $sum = $limit;
    $functions = [];
    $queue = [];

    /**
     * Initial run fill $functions list
     */
    while ($sum === $limit) {
        $results = $dbForConsole->find('schedules', [
            Query::equal('region', [App::getEnv('_APP_REGION')]),
            Query::equal('type', ['function']),
            Query::equal('active', [true]),
            Query::limit($limit)
        ]);

        $sum = count($results);
        foreach ($results as $document) {
            $functions[$document['scheduleId']] = $document;
            $count++;
        }
    }

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
                while ($sum === $limit) {
                    $results = $dbForConsole->find('schedules', [
                        Query::equal('region', [App::getEnv('_APP_REGION')]),
                        Query::equal('type', ['function']),
                        Query::greaterThan('scheduleUpdatedAt', $lastUpdate),
                        Query::limit($limit)
                    ]);
                    $sum = count($results);
                    foreach ($results as $document) {
                        $org = isset($functions[$document['scheduleId']]) ? strtotime($functions[$document['scheduleId']]['scheduleUpdatedAt']) : null;
                        $new = strtotime($document['scheduleUpdatedAt']);
                        if ($document['active'] === false) {
                            Console::error("Removing:  {$document['scheduleId']}");
                            unset($functions[$document['scheduleId']]);
                        } elseif ($new > $org) {
                            Console::error("Updating:  {$document['scheduleId']}");
                            $functions[$document['scheduleId']] =  $document;
                        }
                        $removeFromQueue($document['scheduleId']);
                        $count++;
                    }
                }

                $lastUpdate = DateTime::now();
                $createQueue();
            });

            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, function () use ($dbForConsole, &$functions, &$queue) {
                $time = DateTime::now();
                $timeFrame =  DateTime::addSeconds(new \DateTime(), ENQUEUE_TIME_FRAME); /** 5 min */
                $now = (new \DateTime())->format('Y-m-d H:i:00.000');

                Console::info("Enqueue proc run at: $time");

                /**
                 * Lopping time slots
                 */
                foreach ($queue as $slot => $schedule) {
                    if ($now === $slot) {
                        foreach ($schedule as $function) {
                        /**
                         * Enqueue function
                         */
                            Console::warning("Enqueueing :{$function['scheduleId']}");
                            $cron = new CronExpression($function['schedule']);
                            $next = DateTime::format($cron->getNextRunDate());
                            /**
                             * If next schedule is in 5-min timeframe
                             * and it was not removed re-enqueue the function.
                             */
                            if (
                                $next < $timeFrame &&
                                !empty($functions[$function['scheduleId']])
                            ) {
                                Console::warning("re-enqueueing :{$function['scheduleId']}");
                                $queue[$next][$function['scheduleId']] = $function;
                            }
                            unset($queue[$slot][$function['scheduleId']]); /** removing function from slot */
                        }
                        unset($queue[$slot]); /** removing slot */
                    }
                }
            });
        }
    );
});
