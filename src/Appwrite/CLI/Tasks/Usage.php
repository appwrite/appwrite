<?php

namespace Appwrite\CLI\Tasks;

use Appwrite\Platform\Task;
use Appwrite\Usage\Calculators\TimeSeries;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database as UtopiaDatabase;
use Throwable;
use Utopia\Registry\Registry;

class Usage extends Task
{
    public static function getName(): string
    {
        return 'usage';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules syncing data from influxdb to Appwrite console db')
            ->inject('dbForConsole')
            ->inject('influxdb')
            ->inject('getProjectDB')
            ->inject('register')
            ->inject('logError')
            ->callback(fn ($dbForConsole, $influxDB, $getProjectDB, $register, $logError) => $this->action($dbForConsole, $influxDB, $getProjectDB, $register, $logError));
    }

    public function action(UtopiaDatabase $dbForConsole, InfluxDatabase $influxDB, callable $getProjectDB, Registry $register, callable $logError)
    {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $errorLogger = fn(Throwable $error, string $action = 'syncUsageStats') => $logError($error, "usage", $action);


        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '30'); // 30 seconds (by default)
        $usage = new TimeSeries($dbForConsole, $influxDB, $getProjectDB, $register, $errorLogger);

        Console::loop(function () use ($interval, $usage) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating Timeseries Usage data every {$interval} seconds");
            $loopStart = microtime(true);

            $usage->collect();

            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregation took {$loopTook} seconds");
        }, $interval);
    }
}
