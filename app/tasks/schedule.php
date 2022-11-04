<?php

global $cli;
global $register;

use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Swoole\Timer;


const FUNCTION_VALIDATION_TIMER = 30; //seconds
const FUNCTION_ENQUEUE_TIMER = 10; //seconds

$cli
->task('schedule-new')
->desc('Function scheduler task')
->action(function () use ($register) {
    Console::title('Scheduler V1');
    Console::success(APP_NAME . ' Scheduler v1 has started');

    sleep(4);

    $dbForConsole = getConsoleDB();
    $count = 0;
    $limit = 50;
    $sum = $limit;
    $functions = [];
    while ($sum === $limit) {
        $results = $dbForConsole->find('schedules', [
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

    $lastValidationTime =  DateTime::format((new \DateTime())->sub(\DateInterval::createFromDateString(FUNCTION_VALIDATION_TIMER . ' seconds')));

    Co\run(
        function () use ($dbForConsole, &$functions, &$lastValidationTime) {
            Timer::tick(FUNCTION_VALIDATION_TIMER * 1000, function () use ($dbForConsole, &$functions, &$lastValidationTime) {
                $time = DateTime::now();
                Console::success("Validation proc run at :  $time");
                var_dump($lastValidationTime);
                $count = 0;
                $limit = 50;
                $sum = $limit;
                $tmp = [];
                while ($sum === $limit) {
                    var_dump($lastValidationTime);
                    $results = $dbForConsole->find('schedules', [
                        Query::equal('type', ['function']),
                        Query::greaterThan('scheduleUpdatedAt', $lastValidationTime),
                        Query::limit($limit)
                    ]);

                    $lastValidationTime = DateTime::now();

                    $sum = count($results);
                    foreach ($results as $document) {
                        $tmp['scheduleId'] = $document;
                        $count++;
                    }
                }

                foreach ($tmp as $document) {
                    $org = strtotime($functions[$document['scheduleId']]['scheduleUpdatedAt']);
                    $new = strtotime($document['scheduleUpdatedAt']);
                    var_dump($document['scheduleId']);
                    var_dump($document['active']);
                    if ($document['active'] === false) {
                        Console::error("Removing :  {$document['scheduleId']}");
                        unset($functions[$document['scheduleId']]);
                    } elseif (!isset($functions[$document['scheduleId']]) || $new > $org) {
                        Console::error("Updating :  {$document['scheduleId']}");
                        $functions[$document['scheduleId']] =  $document;
                    }
                    $count++;
                }
            });

            Timer::tick(FUNCTION_ENQUEUE_TIMER * 1000, function () use ($dbForConsole, $functions) {
                $time = DateTime::now();
                Console::success("Enqueue proc run at :  $time");
                foreach ($functions as $function) {
                    Console::info("Enqueueing :  {$function->getid()}");
                }
            });
        }
    );
});
