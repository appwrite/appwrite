<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/init2.php';

use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Hamster;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Usage;
use Appwrite\Event\UsageDump;
use Appwrite\Platform\Appwrite;
use Appwrite\Utopia\Queue\Connections;
use Swoole\Runtime;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\Queue\Worker;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Local;
use Utopia\System\System;

global $gloabl, $container;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$register = new Dependency();
$register
    ->setName('register')
    ->setCallback(fn () => $global);
$container->set($register);

$connections = new Dependency();
$connections
    ->setName('connections')
    ->setCallback(function () {
        return new Connections();
    });
$container->set($connections);

$pools = new Dependency();
$pools
    ->setName('pools')
    ->inject('register')
    ->setCallback(function ($register) {
        return $register->get('pools');
    });
$container->set($pools);

$dbForConsole = new Dependency();
$dbForConsole
    ->setName('dbForConsole')
    ->inject('cache')
    ->inject('pools')
    ->inject('auth')
    ->inject('connections')
    ->setCallback(function (Cache $cache, array $pools, Authorization $auth, Connections $connections) {
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
$container->set($dbForConsole);

$dbForProject = new Dependency();
$dbForProject
    ->setName('dbForProject')
    ->inject('cache')
    ->inject('pools')
    ->inject('message')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('auth')
    ->inject('connections')
    ->setCallback(function (Cache $cache, array $pools, Message $message, Document $project, Database $dbForConsole, Authorization $auth, Connections $connections) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }
    
        $pool = $pools['pools-database-'.$project->getAttribute('database')]['pool'];
        $dsn = $pools['pools-database-'.$project->getAttribute('database')]['dsn'];
    
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
    });
$container->set($dbForProject);

$project = new Dependency();
$project
    ->setName('project')
    ->inject('message')
    ->inject('dbForConsole')
    ->setCallback(function (Message $message, Database $dbForConsole) {
        $payload = $message->getPayload() ?? [];
        $project = new Document($payload['project'] ?? []);
    
        if ($project->getId() === 'console') {
            return $project;
        }
    
        return $dbForConsole->getDocument('projects', $project->getId());
    });
$container->set($project);

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

            $pool = $pools['pools-database-'.$databaseName]['pool'];
            $dsn = $pools['pools-database-'.$databaseName]['dsn'];
        
            $connection = $pool->get();
            $connections->add($connection, $pool);
            $adapter = match ($dsn->getScheme()) {
                'mariadb' => new MariaDB($connection),
                'mysql' => new MySQL($connection),
                default => null
            };

            $database = new Database($adapter, $cache);
            $database->setAuthorization($auth);
            $database->setNamespace('_' . $project->getInternalId());

            return $database;
        };
    });
$container->set($getProjectDB);

// Worker::setResource('getProjectDB', function (Group $pools, Database $dbForConsole, $cache, Authorization $auth, Connections $connections) {
//     $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

//     return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases, $auth, $connections): Database {
//         if ($project->isEmpty() || $project->getId() === 'console') {
//             return $dbForConsole;
//         }

//         $databaseName = $project->getAttribute('database');

//         if (isset($databases[$databaseName])) {
//             $database = $databases[$databaseName];
//             $database->setNamespace('_' . $project->getInternalId());
//             return $database;
//         }

//         $connection = $pools->get($databaseName)->pop();
//         $connections->add($connection);
//         $dbAdapter = $connection->getResource();

//         $database = new Database($dbAdapter, $cache);
//         $database->setAuthorization($auth);

//         $databases[$databaseName] = $database;

//         $database->setNamespace('_' . $project->getInternalId());

//         return $database;
//     };
// }, ['pools', 'dbForConsole', 'cache', 'auth', 'connections']);

$abuseRetention = new Dependency();
$abuseRetention
    ->setName('abuseRetention')
    ->setCallback(function () {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', 86400));
    });
$container->set($abuseRetention);

$auditRetention = new Dependency();
$auditRetention
    ->setName('auditRetention')
    ->setCallback(function () {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 1209600));
    });
$container->set($auditRetention);

$executionRetention = new Dependency();
$executionRetention
    ->setName('executionRetention')
    ->setCallback(function () {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', 1209600));
    });
$container->set($executionRetention);

$cache = new Dependency();
$cache
    ->setName('cache')
    ->setCallback(function () {
        return new Cache(new None());
    });
$container->set($cache);

$log = new Dependency();
$log
    ->setName('log')
    ->setCallback(fn () => new Log());
$container->set($log);

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
$container->set($queue);

$queueForMessaging = new Dependency();
$queueForMessaging
    ->setName('queueForMessaging')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Messaging($queue);
    });
$container->set($queueForMessaging);

$queueForMails = new Dependency();
$queueForMails
    ->setName('queueForMails')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Mail($queue);
    });
$container->set($queueForMails);

$queueForBuilds = new Dependency();
$queueForBuilds
    ->setName('queueForBuilds')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Build($queue);
    });
$container->set($queueForBuilds);

$queueForDatabase = new Dependency();
$queueForDatabase
    ->setName('queueForDatabase')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new EventDatabase($queue);
    });
$container->set($queueForDatabase);

$queueForDeletes = new Dependency();
$queueForDeletes
    ->setName('queueForDeletes')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Delete($queue);
    });
$container->set($queueForDeletes);

$queueForEvents = new Dependency();
$queueForEvents
    ->setName('queueForEvents')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Event($queue);
    });
$container->set($queueForEvents);

$queueForAudits = new Dependency();
$queueForAudits
    ->setName('queueForAudits')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Audit($queue);
    });
$container->set($queueForAudits);

$queueForFunctions = new Dependency();
$queueForFunctions
    ->setName('queueForFunctions')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Func($queue);
    });
$container->set($queueForFunctions);

$queueForUsage = new Dependency();
$queueForUsage
    ->setName('queueForUsage')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Usage($queue);
    });
$container->set($queueForUsage);

$queueForUsageDump = new Dependency();
$queueForUsageDump
    ->setName('queueForUsageDump')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new UsageDump($queue);
    });

$container->set($queueForUsageDump);

$queueForCertificates = new Dependency();
$queueForCertificates
    ->setName('queueForCertificates')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Certificate($queue);
    });
$container->set($queueForCertificates);

$queueForMigrations = new Dependency();
$queueForMigrations
    ->setName('queueForMigrations')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Migration($queue);
    });
$container->set($queueForMigrations);

$queueForHamster = new Dependency();
$queueForHamster
    ->setName('queueForHamster')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new Hamster($queue);
    });
$container->set($queueForHamster);

$logger = new Dependency();
$logger
    ->setName('logger')
    ->inject('register')
    ->setCallback(function (Registry $register) {
        return $register->get('logger');
    });
$container->set($logger);

$deviceForFunctions = new Dependency();
$deviceForFunctions
    ->setName('deviceForFunctions')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
    });
$container->set($deviceForFunctions);

$deviceForFiles = new Dependency();
$deviceForFiles
    ->setName('deviceForFiles')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
    });
$container->set($deviceForFiles);

$deviceForBuilds = new Dependency();
$deviceForBuilds
    ->setName('deviceForBuilds')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
    });
$container->set($deviceForBuilds);

$deviceForCache = new Dependency();
$deviceForCache
    ->setName('deviceForCache')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return getDevice(APP_STORAGE_CACHE . '/app-' . $project->getId());
    });
$container->set($deviceForCache);

$deviceForLocalFiles = new Dependency();
$deviceForLocalFiles
    ->setName('deviceForLocalFiles')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return new Local(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
    });

$container->set($deviceForLocalFiles);

$auth = new Dependency();
$auth
    ->setName('auth')
    ->setCallback(fn () => new Authorization());
$container->set($auth);

$platform = new Appwrite();
$args = $_SERVER['argv'];

if (!isset($args[1])) {
    Console::error('Missing worker name');
    Console::exit(1);
}

\array_shift($args);
$workerName = $args[0];
$workerIndex = $args[1] ?? '';

if (!empty($workerIndex)) {
    $workerName .= '_' . $workerIndex;
}

if (\str_starts_with($workerName, 'databases')) {
    $queueName = System::getEnv('_APP_QUEUE_NAME', 'database_db_main');
} else {
    $queueName = System::getEnv('_APP_QUEUE_NAME', 'v1-' . strtolower($workerName));
}

try {

    $connection = new Connection\Redis(
        System::getEnv('_APP_REDIS_HOST', 'redis'),
        System::getEnv('_APP_REDIS_PORT', '6379'),
        System::getEnv('_APP_REDIS_USER', ''),
        System::getEnv('_APP_REDIS_PASS', '')
    );

    /**
     * Any worker can be configured with the following env vars:
     * - _APP_WORKERS_NUM           The total number of worker processes
     * - _APP_WORKER_PER_CORE       The number of worker processes per core (ignored if _APP_WORKERS_NUM is set)
     * - _APP_QUEUE_NAME  The name of the queue to read for database events
     */
    $platform->init(Service::TYPE_WORKER, [
        'workersNum' => System::getEnv('_APP_WORKERS_NUM', 1),
        'connection' => $connection,
        'workerName' => strtolower($workerName) ?? null,
        'queueName' => $queueName
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() .  ', Line: ' . $e->getLine());
}

Worker::init()
    ->inject('auth')
    ->action(function (Authorization $auth) {
        $auth->disable();
    });

Worker::shutdown()
    ->inject('connections')
    ->action(function (Connections $connections) {
        $connections->reclaim();
    });

Worker::error()
    ->inject('error')
    ->inject('logger')
    ->inject('log')
    ->inject('connections')
    ->inject('project')
    ->inject('auth')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Connections $connections, Document $project, Authorization $auth) use ($queueName) {
        $connections->reclaim();
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($logger) {
            $log->setNamespace("appwrite-worker");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-queue-' . $queueName);
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addTag('projectId', $project->getId() ?? 'n/a');
            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', $auth->getRoles());

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Usage stats log pushed with status code: ' . $responseCode);
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());
    });

$platform
    ->getWorker()
    ->setContainer($container)
    ->start();