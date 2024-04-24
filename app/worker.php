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
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Queue\Connection\Redis;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Local;
use Utopia\System\System;

global $gloabl;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Server::setResource('register', fn () => $gloabl);

Server::setResource('connections', function () {
    return new Connections();
});

Server::setResource('pools', function () use ($gloabl) {
    return $gloabl->get('pools');
});

Server::setResource('dbForConsole', function (Cache $cache, array $pools, Authorization $auth, Connections $connections) {
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
}, ['cache', 'pools', 'auth', 'connections']);

Server::setResource('project', function (Message $message, Database $dbForConsole) {
    $payload = $message->getPayload() ?? [];
    $project = new Document($payload['project'] ?? []);

    if ($project->getId() === 'console') {
        return $project;
    }

    return $dbForConsole->getDocument('projects', $project->getId());
}, ['message', 'dbForConsole']);

Server::setResource('dbForProject', function (Cache $cache, array $pools, Message $message, Document $project, Database $dbForConsole, Authorization $auth, Connections $connections) {
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

    $database = new Database($adapter, $cache);
    $database->setAuthorization($auth);
    $database->setNamespace('_' . $project->getInternalId());
    return $database;
}, ['cache', 'pools', 'message', 'project', 'dbForConsole', 'auth', 'connections']);

Server::setResource('getProjectDB', function (Group $pools, Database $dbForConsole, $cache, Authorization $auth, Connections $connections) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases, $auth, $connections): Database {
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

        $database->setNamespace('_' . $project->getInternalId());

        return $database;
    };
}, ['pools', 'dbForConsole', 'cache', 'auth', 'connections']);

Server::setResource('abuseRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', 86400));
});

Server::setResource('auditRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 1209600));
});

Server::setResource('executionRetention', function () {
    return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', 1209600));
});

Server::setResource('cache', function () {
    return new Cache(new None());
}, []);

Server::setResource('log', fn () => new Log());

Server::setResource('queueForUsage', function (Connection $queue) {
    return new Usage($queue);
}, ['queue']);

Server::setResource('queueForUsageDump', function (Connection $queue) {
    return new UsageDump($queue);
}, ['queue']);

Server::setResource('queue', function (array $pools, Connections $connections) {
    $pool = $pools['pools-queue-main']['pool'];
    $dsn = $pools['pools-queue-main']['dsn'];
    $connection = $pool->get();
    $connections->add($connection, $pool);

    return new Redis($dsn->getHost(), $dsn->getPort());
}, ['pools', 'connections']);

Server::setResource('queueForDatabase', function (Connection $queue) {
    return new EventDatabase($queue);
}, ['queue']);

Server::setResource('queueForMessaging', function (Connection $queue) {
    return new Messaging($queue);
}, ['queue']);

Server::setResource('queueForMails', function (Connection $queue) {
    return new Mail($queue);
}, ['queue']);

Server::setResource('queueForBuilds', function (Connection $queue) {
    return new Build($queue);
}, ['queue']);

Server::setResource('queueForDeletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);

Server::setResource('queueForEvents', function (Connection $queue) {
    return new Event($queue);
}, ['queue']);

Server::setResource('queueForAudits', function (Connection $queue) {
    return new Audit($queue);
}, ['queue']);

Server::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);

Server::setResource('queueForCertificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);

Server::setResource('queueForMigrations', function (Connection $queue) {
    return new Migration($queue);
}, ['queue']);

Server::setResource('queueForHamster', function (Connection $queue) {
    return new Hamster($queue);
}, ['queue']);

Server::setResource('logger', function (Registry $register) {
    return $register->get('logger');
}, ['register']);

Server::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

Server::setResource('deviceForFunctions', function (Document $project) {
    return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForFiles', function (Document $project) {
    return getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForBuilds', function (Document $project) {
    return getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForCache', function (Document $project) {
    return getDevice(APP_STORAGE_CACHE . '/app-' . $project->getId());
}, ['project']);

Server::setResource('deviceForLocalFiles', function (Document $project) {
    return new Local(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
}, ['project']);

Server::setResource('auth', fn () => new Authorization());

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
    /**
     * Any worker can be configured with the following env vars:
     * - _APP_WORKERS_NUM           The total number of worker processes
     * - _APP_WORKER_PER_CORE       The number of worker processes per core (ignored if _APP_WORKERS_NUM is set)
     * - _APP_QUEUE_NAME  The name of the queue to read for database events
     */
    $platform->init(Service::TYPE_WORKER, [
        'workersNum' => System::getEnv('_APP_WORKERS_NUM', 1),
        'connection' => $global->get('pools')['pools-queue-main']['pool']->get(),
        'workerName' => strtolower($workerName) ?? null,
        'queueName' => $queueName
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() .  ', Line: ' . $e->getLine());
}

$worker = $platform->getWorker();

$worker
    ->init()
    ->inject('auth')
    ->action(function (Authorization $auth) {
        $auth->disable();
    });

$worker
    ->shutdown()
    ->inject('connections')
    ->action(function (Connections $connections) {
        $connections->reclaim();
    });

$worker
    ->error()
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

$worker->workerStart()
    ->action(function () use ($workerName) {
        Console::info("Worker $workerName  started");
    });

$worker->start();
