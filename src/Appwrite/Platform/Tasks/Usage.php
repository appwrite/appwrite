<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Usage\Calculators\TimeSeries;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Validator\WhiteList;
use Throwable;
use Utopia\Platform\Action;

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
            ->param('type', 'timeseries', new WhiteList(['timeseries', 'database']))
            ->inject('dbForConsole')
            ->inject('influxdb')
            ->inject('logError')
            ->callback(fn ($type, $dbForConsole, $influxDB, $logError) => $this->action($type, $dbForConsole, $influxDB, $logError));
    }

    protected function aggregateTimeseries(UtopiaDatabase $database, InfluxDatabase $influxDB, callable $logError): void
    {
        $interval = (int) App::getEnv('_APP_USAGE_TIMESERIES_INTERVAL', '30'); // 30 seconds (by default)
        $region = App::getEnv('region', 'default');
        $usage = new TimeSeries($region, $database, $influxDB, $logError);

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

    public function action(string $type, UtopiaDatabase $dbForConsole, InfluxDatabase $influxDB, callable $logError)
    {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $errorLogger = fn(Throwable $error, string $action = 'syncUsageStats') => $logError($error, "usage", $action);

        switch ($type) {
            case 'timeseries':
                $this->aggregateTimeseries($dbForConsole, $influxDB, $errorLogger);
                break;
            default:
                Console::error("Unsupported usage aggregation type");
        }
    }
}
