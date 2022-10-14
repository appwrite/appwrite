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

class Usage extends Task
{
    public static function getName(): string
    {
        return 'usage';
    }

    public function __construct()
    {
        $this
            ->param('type', 'timeseries', new WhiteList(['timeseries', 'database']))
            ->desc('Schedules syncing data from influxdb to Appwrite console db')
            ->callback(fn ($type) => $this->action($type));
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

    public function action(string $type)
    {
        global $register;
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $database = $this->getDatabase($register, '_console');
        $influxDB = $this->getInfluxDB($register);

        switch ($type) {
            case 'timeseries':
                $this->aggregateTimeseries($database, $influxDB, $this->logError);
                break;
            case 'database':
                $this->aggregateDatabase($database, $this->logError);
                break;
            default:
                Console::error("Unsupported usage aggregation type");
        }
    }
}
