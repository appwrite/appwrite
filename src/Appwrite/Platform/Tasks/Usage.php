<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Usage\Calculators\TimeSeries;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database as UtopiaDatabase;
use Throwable;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;

class Usage extends Action
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
            ->inject('register')
            ->inject('getProjectDB')
            ->inject('logError')
            ->callback(fn ($dbForConsole, $influxDB, $register, $getProjectDB, $logError) => $this->action($dbForConsole, $influxDB, $register, $getProjectDB, $logError));
    }

    protected function aggregateTimeseries(UtopiaDatabase $database, InfluxDatabase $influxDB, callable $logError): void
    {
    }

    public function action(UtopiaDatabase $dbForConsole, InfluxDatabase $influxDB, Registry $register, callable $getProjectDB, callable $logError)
    {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $errorLogger = fn(Throwable $error, string $action = 'syncUsageStats') => $logError($error, "usage", $action);

        $interval = (int) App::getEnv('_APP_USAGE_TIMESERIES_INTERVAL', '30'); // 30 seconds (by default)
        $region = App::getEnv('region', 'default');
        $usage = new TimeSeries($region, $dbForConsole, $influxDB, $getProjectDB, $register, $errorLogger);

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
