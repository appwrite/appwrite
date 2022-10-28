<?php

namespace Appwrite\CLI\Tasks;

use Appwrite\Platform\Task;
use Appwrite\Usage\Calculators\Aggregator;
use Appwrite\Usage\Calculators\Database;
use Appwrite\Usage\Calculators\TimeSeries;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Validator\WhiteList;
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
            ->param('type', 'timeseries', new WhiteList(['timeseries', 'database']))
            ->inject('dbForConsole')
            ->inject('influxdb')
            ->inject('register')
            ->callback(fn ($type, $dbForConsole, $influxDB, $register) => $this->action($type, $dbForConsole, $influxDB, $register));
    }


    protected function aggregateTimeseries(UtopiaDatabase $database, InfluxDatabase $influxDB, callable $logError): void
    {
        $interval = (int) App::getEnv('_APP_USAGE_TIMESERIES_INTERVAL', '30'); // 30 seconds (by default)
        $usage = new TimeSeries($database, $influxDB, $logError);

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

    protected function aggregateDatabase(UtopiaDatabase $database, callable $logError): void
    {
        $interval = (int) App::getEnv('_APP_USAGE_DATABASE_INTERVAL', '900'); // 15 minutes (by default)
        $usage = new Database($database, $logError);
        $aggregrator = new Aggregator($database, $logError);

        Console::loop(function () use ($interval, $usage, $aggregrator) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Aggregating database usage every {$interval} seconds.");
            $loopStart = microtime(true);
            $usage->collect();
            $aggregrator->collect();
            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());

            Console::info("[{$now}] Aggregation took {$loopTook} seconds");
        }, $interval);
    }

    public function action(string $type, UtopiaDatabase $dbForConsole, InfluxDatabase $influxDB, Registry $register)
    {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $logError = fn(Throwable $error, string $action = 'syncUsageStats') => $this->logError($register, $error, "usage", $action);

        switch ($type) {
            case 'timeseries':
                $this->aggregateTimeseries($dbForConsole, $influxDB, $logError);
                break;
            case 'database':
                $this->aggregateDatabase($dbForConsole, $logError);
                break;
            default:
                Console::error("Unsupported usage aggregation type");
        }
    }
}
