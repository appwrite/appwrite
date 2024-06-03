<?php

require_once __DIR__ . '/init2.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Event\Hamster;
use Appwrite\Platform\Appwrite;
use Appwrite\Utopia\Queue\Connections;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Queue\Connection\Redis;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;
use Swoole\Runtime;
use Utopia\CLI\Adapters\Swoole as SwooleCLI;

global $global, $container;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$registry = new Dependency();
$registry
    ->setName('register')
    ->setCallback(fn() => $global);

$connections = new Dependency();
$connections
    ->setName('connections')
    ->setCallback(fn() => new Connections());

$cache = new Dependency();
$cache
    ->setName('cache')
    ->setCallback(function () {
        return new Cache(new None());
    });
$container->set($cache);

$pools = new Dependency();
$pools
    ->setName('pools')
    ->inject('register')
    ->setCallback(function (Registry $register) {
        return $register->get('pools');
    });

$dbForConsole = new Dependency();
$dbForConsole
    ->setName('dbForConsole')
    ->inject('pools')
    ->inject('cache')
    ->inject('auth')
    ->inject('connections')
    ->setCallback(function ($pools, $cache, $auth, Connections $connections) {
        $pool = $pools['pools-console-main']['pool'];
        $dsn = $pools['pools-console-main']['dsn'];
        $connection = $pool->get();
        $connections->add($connection, $pool);

        $adapter = match ($dsn->getScheme()) {
            'mariadb' => new MariaDB($connection),
            'mysql' => new MySQL($connection),
            default => null
        };

        $adapter->setDatabase($dsn->getPath());

        $database = new Database($adapter, $cache);
        $database->setAuthorization($auth);
        $database->setNamespace('_console');

        return $database;
    });

$getProjectDB = new Dependency();
$getProjectDB
    ->setName('getProjectDB')
    ->inject('pools')
    ->inject('dbForConsole')
    ->inject('cache')
    ->inject('auth')
    ->inject('connections')
    ->setCallback(function (array $pools, Database $dbForConsole, Cache $cache, Authorization $auth, Connections $connections) {
        return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases, $auth, $connections): Database {
            if ($project->isEmpty() || $project->getId() === 'console') {
                return $dbForConsole;
            }

            $databaseName = $project->getAttribute('database');

            $pool = $pools['pools-database-' . $databaseName]['pool'];
            $dsn = $pools['pools-database-' . $databaseName]['dsn'];

            $connection = $pool->get();
            $connections->add($connection, $pool);
            $adapter = match ($dsn->getScheme()) {
                'mariadb' => new MariaDB($connection),
                'mysql' => new MySQL($connection),
                default => null
            };
            $adapter->setDatabase($dsn->getPath());

            $database = new Database($adapter, $cache);
            $database->setAuthorization($auth);
            $database->setNamespace('_' . $project->getInternalId());

            return $database;
        };
    });

$queue = new Dependency();
$queue
    ->setName('queue')
    ->inject('pools')
    ->inject('connections')
    ->setCallback(function (array $pools, Connections $connections) {
        $pool = $pools['pools-queue-main']['pool'];
        $dsn = $pools['pools-queue-main']['dsn'];
        $connection = $pool->get();
        $connections->add($connection, $pool);

        return new Redis($dsn->getHost(), $dsn->getPort());
    });

$queueForFunctions = new Dependency();
$queueForFunctions
    ->setName('queueForFunctions')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Func($queue);
    });

$queueForHamster = new Dependency();
$queueForHamster
    ->setName('queueForHamster')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Hamster($queue);
    });

$queueForDeletes = new Dependency();
$queueForDeletes
    ->setName('queueForDeletes')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Delete($queue);
    });

$queueForCertificates = new Dependency();
$queueForCertificates
    ->setName('queueForCertificates')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Certificate($queue);
    });

$queueForCertificates = new Dependency();
$queueForCertificates
    ->setName('queueForCertificates')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Certificate($queue);
    });

$logError = new Dependency();
$logError
    ->setName('logError')
    ->inject('register')
    ->setCallback(function (Registry $register) {
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
    });

$auth = new Dependency();
$auth
    ->setName('auth')
    ->setCallback(fn() => new Authorization());

$container->set($registry);
$container->set($connections);
$container->set($cache);
$container->set($pools);
$container->set($dbForConsole);
$container->set($getProjectDB);
$container->set($queue);
$container->set($queueForFunctions);
$container->set($queueForHamster);
$container->set($queueForDeletes);
$container->set($queueForCertificates);
$container->set($logError);
$container->set($auth);

$platform = new Appwrite();
$platform->init(Service::TYPE_CLI, ['adapter' => new SwooleCLI(1)]);

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

$cli
    ->setContainer($container)
    ->run();
