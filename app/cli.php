<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\CLI\Tasks;
use Utopia\CLI\CLI;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Service;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Logger\Log;
use Utopia\Registry\Registry;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Pools\Group;

Authorization::disable();

CLI::setResource('getProjectDB', function (Group $pools, Database $dbForConsole)
{
    $getProjectDB = function(Document $project) use($pools, $dbForConsole) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }
    
        $dbAdapter = $pools
            ->get($project->getAttribute('database'))
            ->pop()
            ->getResource()
        ;
    
        $database = new Database($dbAdapter, getCache());
        $database->setNamespace('_' . $project->getInternalId());
    
        return $database;
    };

    return $getProjectDB;
}, ['register', 'dbForConsole']);

CLI::setResource('register', fn()=>$register);

CLI::setResource('cache', function($pools) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['pools']);

CLI::setResource('pools', function(Registry $register) {
    return $register->get('pools');
}, ['register']);

CLI::setResource('dbForConsole', function ($pools) {
    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

    $database = new Database($dbAdapter, getCache());

    $database->setNamespace('console');

    return $database;
}, ['pools']);

CLI::setResource('influxdb', function (Registry $register) {
    $client = $register->get('influxdb'); /** @var InfluxDB\Client $client */
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

$cliPlatform = new Tasks();
$cliPlatform->init(Service::TYPE_CLI);

$cli = $cliPlatform->getCli();
$cli
    ->error(function ($error) {
        if (App::getEnv('_APP_ENV', 'development')) {
            Console::error($error);
        } else {
            Console::error($error->getMessage());
        }
    });

$cli->run();
