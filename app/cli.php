<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Event\Delete;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Func;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Phone;
use Appwrite\Platform\Appwrite;
use Utopia\CLI\CLI;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Service;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Logger\Log;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;

Authorization::disable();

CLI::setResource('register', fn () => $register);

CLI::setResource('cache', function ($pools) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource();
    }

    return new Cache(new Sharding($adapters));
}, ['pools']);

CLI::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

CLI::setResource('dbForConsole', function ($pools, $cache) {
    $sleep = 3;
    $maxAttempts = 5;
    $attempts = 0;
    $ready = false;

    do {
        $attempts++;
        try {
            // Prepare database connection
            $dbAdapter = $pools
                ->get('console')
                ->pop()
                ->getResource();

            $dbForConsole = new Database($dbAdapter, $cache);
            $dbForConsole->setNamespace('console');

            // Ensure tables exist
            $collections = Config::getParam('collections', []);
            $last = \array_key_last($collections);

            if (!($dbForConsole->exists($dbForConsole->getDefaultDatabase(), $last))) {
                /** TODO cache ready variable using registry */
                throw new Exception('Tables not ready yet.');
            }

            $ready = true;
        } catch (\Exception $err) {
            Console::warning($err->getMessage());
            $pools->get('console')->reclaim();
            sleep($sleep);
        }
    } while ($attempts < $maxAttempts);

    if (!$ready) {
        throw new Exception("Console is not ready yet. Please try again later.");
    }

    return $dbForConsole;
}, ['pools', 'cache']);

CLI::setResource('getProjectDB', function (Group $pools, Database $dbForConsole, $cache) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    $getProjectDB = function (Document $project) use ($pools, $dbForConsole, $cache, &$databases) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }

        $databaseName = $project->getAttribute('database');

        if (isset($databases[$databaseName])) {
            $database = $databases[$databaseName];
            $database->setNamespace('_' . $project->getInternalId());
            return $database;
        }

        $dbAdapter = $pools
            ->get($databaseName)
            ->pop()
            ->getResource();

        $database = new Database($dbAdapter, $cache);

        $databases[$databaseName] = $database;

        $database->setNamespace('_' . $project->getInternalId());

        return $database;
    };

    return $getProjectDB;
}, ['pools', 'dbForConsole', 'cache']);

CLI::setResource('influxdb', function (Registry $register) {
    $client = $register->get('influxdb');
    /** @var InfluxDB\Client $client */
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
        } catch (\Throwable $th) {
            Console::warning("InfluxDB not ready. Retrying connection ({$attempts})...");
            if ($attempts >= $max) {
                throw new \Exception('InfluxDB database not ready yet');
            }
            sleep($sleep);
        }
    } while ($attempts < $max);
    return $database;
}, ['register']);

CLI::setResource('queue', function (Group $pools) {
    return $pools->get('queue')->pop()->getResource();
}, ['pools']);
CLI::setResource('queueForMessaging', function (Connection $queue) {
    return new Phone($queue);
}, ['queue']);
CLI::setResource('queueForMail', function (Connection $queue) {
    return new Mail($queue);
}, ['queue']);
CLI::setResource('queueForBuilds', function (Connection $queue) {
    return new Build($queue);
}, ['queue']);
CLI::setResource('queueForDatabase', function (Connection $queue) {
    return new EventDatabase($queue);
}, ['queue']);
CLI::setResource('queueForDeletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);
CLI::setResource('queueForEvents', function (Connection $queue) {
    return new Event('', '', $queue);
}, ['queue']);
CLI::setResource('queueForAudits', function (Connection $queue) {
    return new Audit($queue);
}, ['queue']);
CLI::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
CLI::setResource('queueForCertificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);
CLI::setResource('logError', function (Registry $register) {
    return function (Throwable $error, string $namespace, string $action) use ($register) {
        $logger = $register->get('logger');

        if ($logger) {
            $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

            $log = new Log();
            $log->setNamespace($namespace);
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
}, ['register']);

$platform = new Appwrite();
$platform->init(Service::TYPE_CLI);

$cli = $platform->getCli();

$cli
    ->error()
    ->inject('error')
    ->action(function (Throwable $error) {
        Console::error($error->getMessage());
    });

$cli->run();
