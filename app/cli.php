<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Event\Hamster;
use Appwrite\Platform\Appwrite;
use Appwrite\Utopia\Queue\Connections;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Http;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;

global $register;

CLI::setResource('register', fn () => $register);

CLI::setResource('connections', function () {
    return new Connections();
});

CLI::setResource('cache', function ($pools, Connections $connections) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $connection = $pools->get($value)->pop();
        $connections->add($connection);
        $adapters[] = $connection->getResource();
    }

    return new Cache(new Sharding($adapters));
}, ['pools', 'connections']);

CLI::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

CLI::setResource('dbForConsole', function ($pools, $cache, $auth, Connections $connections) {
    $sleep = 3;
    $maxAttempts = 5;
    $attempts = 0;
    $ready = false;

    $connection = null;

    do {
        $attempts++;
        try {
            // Prepare database connection
            $connection = $pools->get('console')->pop();
            $dbAdapter = $connection->getResource();

            $dbForConsole = new Database($dbAdapter, $cache);
            $dbForConsole->setAuthorization($auth);

            $dbForConsole
                ->setNamespace('_console')
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', 'console');

            // Ensure tables exist
            $collections = Config::getParam('collections', [])['console'];
            $last = \array_key_last($collections);

            if (!($dbForConsole->exists($dbForConsole->getDatabase(), $last))) { /** TODO cache ready variable using registry */
                throw new Exception('Tables not ready yet.');
            }

            $ready = true;
        } catch (\Throwable $err) {
            if($connection !== null) {
                $connection->reclaim();
                $connection = null;
            }

            Console::warning($err->getMessage());
            sleep($sleep);
        }
    } while ($attempts < $maxAttempts && !$ready);

    if($connection !== null) {
        $connections->add($connection);
    }

    if (!$ready) {
        throw new Exception("Console is not ready yet. Please try again later.");
    }

    return $dbForConsole;
}, ['pools', 'cache', 'auth', 'connections']);

CLI::setResource('getProjectDB', function (Group $pools, Database $dbForConsole, $cache, $auth, Connections $connections) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases, $auth, $connections) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }

        $databaseName = $project->getAttribute('database');

        if (isset($databases[$databaseName])) {
            $database = $databases[$databaseName];
            $database->setNamespace('_' . $project->getInternalId());
            return $database;
        }

        $connection = $pools->get($databaseName)->pop();
        $connections->add($connection);
        $dbAdapter = $connection->getResource();

        $database = new Database($dbAdapter, $cache);
        $database->setAuthorization($auth);

        $databases[$databaseName] = $database;

        $database
            ->setNamespace('_' . $project->getInternalId())
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());

        return $database;
    };
}, ['pools', 'dbForConsole', 'cache', 'auth', 'connections']);

CLI::setResource('queue', function (Group $pools, Connections $connections) {
    $connection = $pools->get('queue')->pop();
    $connections->add($connection);
    return $connection->getResource();
}, ['pools', 'connections']);
CLI::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
CLI::setResource('queueForHamster', function (Connection $queue) {
    return new Hamster($queue);
}, ['queue']);
CLI::setResource('queueForDeletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);
CLI::setResource('queueForCertificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);
CLI::setResource('logError', function (Registry $register) {
    return function (Throwable $error, string $namespace, string $action) use ($register) {
        $logger = $register->get('logger');

        if ($logger) {
            $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

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

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
       
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Usage stats log pushed with status code: ' . $responseCode);
        }

        Console::warning("Failed: {$error->getMessage()}");
        Console::warning($error->getTraceAsString());
    };
}, ['register']);

CLI::setResource('auth', fn () => new Authorization());

$platform = new Appwrite();
$platform->init(Service::TYPE_CLI);

$cli = $platform->getCli();

$cli
    ->init()
    ->inject('auth')
    ->action(function (Authorization $auth) {
        $auth->disable();
    });

$cli
    ->error()
    ->inject('error')
    ->action(function (Throwable $error) {
        Console::error($error->getMessage());
    });

$cli->run();
