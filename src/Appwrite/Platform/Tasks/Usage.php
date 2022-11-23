<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Database\Database as UtopiaDatabase;
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
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->inject('logError')
            // ->callback(fn ($dbForConsole, $influxDB, $register, $getProjectDB, $logError) => $this->action($dbForConsole, $influxDB, $register, $getProjectDB, $logError))
        ;
    }

    public function action()
    {
        // Console::title('Usage Aggregation V1');
        // Console::success(APP_NAME . ' usage aggregation process v1 has started');

        // $errorLogger = fn(Throwable $error, string $action = 'syncUsageStats') => $logError($error, "usage", $action);

        // $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '30'); // 30 seconds (by default)
        // $region = App::getEnv('region', 'default');
        // $usage = new TimeSeries($region, $dbForConsole, $influxDB, $getProjectDB, $register, $errorLogger);

        // Console::loop(function () use ($interval, $usage) {
        //     $now = date('d-m-Y H:i:s', time());
        //     Console::info("[{$now}] Aggregating Timeseries Usage data every {$interval} seconds");
        //     $loopStart = microtime(true);

        //     $usage->collect();

        //     $loopTook = microtime(true) - $loopStart;
        //     $now = date('d-m-Y H:i:s', time());
        //     Console::info("[{$now}] Aggregation took {$loopTook} seconds");
        // }, $interval);
    }
}
