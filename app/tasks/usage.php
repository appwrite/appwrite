<?php

global $cli, $register;

use Appwrite\Stats\Usage;
use Appwrite\Stats\UsageDB;
use InfluxDB\Database as InfluxDatabase;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Registry\Registry;
use Utopia\Logger\Log;

Authorization::disable();
Authorization::setDefaultStatus(false);

function getDatabase(Registry &$register, string $namespace): Database
{
    $attempts = 0;

    do {
        try {
            $attempts++;

            $db = $register->get('db');
            $redis = $register->get('cache');

            $cache = new Cache(new RedisCache($redis));
            $database = new Database(new MariaDB($db), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace($namespace);

            if (!$database->exists($database->getDefaultDatabase(), 'projects')) {
                throw new Exception('Projects collection not ready');
            }
            break; // leave loop if successful
        } catch (\Exception$e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep(DATABASE_RECONNECT_SLEEP);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return $database;
}

function getInfluxDB(Registry &$register): InfluxDatabase
{
    /** @var InfluxDB\Client $client */
    $client = $register->get('influxdb');
    $attempts = 0;
    $max = 10;
    $sleep = 1;

    do { // check if telegraf database is ready
        try {
            $attempts++;
            $database = $client->selectDB('telegraf');
            if (in_array('telegraf', $client->listDatabases())) {
                break; // leave the do-while if successful
            }
        } catch (\Throwable$th) {
            Console::warning("InfluxDB not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('InfluxDB database not ready yet');
            }
            sleep($sleep);
        }
    } while ($attempts < $max);
    return $database;
}

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

$cli
    ->task('usage')
    ->desc('Schedules syncing data from influxdb to Appwrite console db')
    ->action(function () use ($register, $logError) {
        Console::title('Usage Aggregation V1');
        Console::success(APP_NAME . ' usage aggregation process v1 has started');

        $interval = (int) App::getEnv('_APP_USAGE_AGGREGATION_INTERVAL', '30'); // 30 seconds (by default)

        $database = getDatabase($register, '_console');
        $influxDB = getInfluxDB($register);

        $usage = new Usage($database, $influxDB, $logError);
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
    });
