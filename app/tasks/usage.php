<?php

global $cli, $register;

use Appwrite\Usage\Calculators\Aggregator;
use Appwrite\Usage\Calculators\Database;
use Appwrite\Usage\Calculators\TimeSeries;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Registry\Registry;
use Utopia\Validator\WhiteList;

Authorization::disable();
Authorization::setDefaultStatus(false);

$logError = function (Throwable $error, string $action = 'syncUsageStats') use ($register) {
    $logger = $register->get('logger');

    if ($logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("usage");
        $log->setServer(\gethostname());
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Usage stats log pushed with status code: ' . $responseCode);
    }

    Console::warning("Failed: {$error->getMessage()}");
    Console::warning($error->getTraceAsString());
};

function aggregateTimeseries(UtopiaDatabase $database, InfluxDatabase $influxDB, callable $getProjectDB, callable $logError): void
{
    $interval = (int) App::getEnv('_APP_USAGE_TIMESERIES_INTERVAL', '30'); // 30 seconds (by default)
    $usage = new TimeSeries($database, $influxDB, $getProjectDB, $logError);

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

function aggregateDatabase(UtopiaDatabase $database, callable $getProjectDB, Registry $register, callable $logError): void
{
    $interval = (int) App::getEnv('_APP_USAGE_DATABASE_INTERVAL', '900'); // 15 minutes (by default)
    $usage = new Database($database, $getProjectDB, $register, $logError);
    $aggregrator = new Aggregator($database, $getProjectDB, $register, $logError);

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

$cli
    ->task('usage')
    ->param('type', 'timeseries', new WhiteList(['timeseries', 'database']))
    ->desc('Schedules syncing data from influxdb to Appwrite console db')
    ->action(function (string $type) use ($logError, $register) {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $database = getConsoleDB();
        $influxDB = getInfluxDB();
        $getProjectDB = fn (Document $project) => getProjectDB($project);

        switch ($type) {
            case 'timeseries':
                aggregateTimeseries($database, $influxDB, $getProjectDB, $logError);
                break;
            case 'database':
                aggregateDatabase($database, $getProjectDB, $register, $logError);
                break;
            default:
                Console::error("Unsupported usage aggregation type");
        }
        $register->get('pools')->reclaim();
    });
