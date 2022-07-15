<?php

namespace Appwrite\Task;

use Appwrite\Platform\Action;
use Throwable;
use Exception;
use Appwrite\Stats\Usage as InfluxUsage;
use Appwrite\Stats\UsageDB;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;

class Usage extends Action
{
    public const NAME = 'usage';

    public function __construct()
    {
        $this
            ->desc('Schedules syncing data from influxdb to Appwrite console db')
            ->callback(fn () => $this->action());
    }

    public function action()
    {
        global $register;

        Authorization::disable();
        Authorization::setDefaultStatus(false);

        $logError = fn(Throwable $error, string $action = 'syncUsageStats') => $this->logError($register, $error, $action);

        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '30'); // 30 seconds (by default)

        $database = self::getDatabase($register, '_console');
        $influxDB = self::getInfluxDB($register);

        $usage = new InfluxUsage($database, $influxDB, $logError);
        $usageDB = new UsageDB($database, $logError);

        $iterations = 0;
        Console::loop(function () use ($interval, $usage, $usageDB, &$iterations) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating usage data every {$interval} seconds");

            $loopStart = microtime(true);

            /**
             * Aggregate InfluxDB every 30 seconds
             */
            $usage->collect();

            if ($iterations % 30 != 0) { // return if 30 iterations has not passed
                $iterations++;
                $loopTook = microtime(true) - $loopStart;
                $now = date('d-m-Y H:i:s', time());
                Console::info("[{$now}] Aggregation took {$loopTook} seconds");
                return;
            }

            $iterations = 0; // Reset iterations to prevent overflow when running for long time
            /**
             * Aggregate MariaDB every 15 minutes
             * Some of the queries here might contain full-table scans.
             */
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating database counters.");

            $usageDB->collect();

            $iterations++;
            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());

            Console::info("[{$now}] Aggregation took {$loopTook} seconds");
        }, $interval);
    }
}
