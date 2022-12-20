<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\Event;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Phone;
use Swoole\Runtime;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\DSN\DSN;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

global $register;

Server::setResource('register', fn () => $register);

Server::setResource('dbForConsole', function (Cache $cache, Registry $register) {
    $pools = $register->get('pools');
    $database = $pools
        ->get('console')
        ->pop()
        ->getResource();

    $adapter = new Database($database, $cache);
    $adapter->setNamespace('console');

    return $adapter;
}, ['cache', 'register']);

Server::setResource('dbForProject', function (Cache $cache, Registry $register, Message $message, Database $dbForConsole) {
    $payload = $message->getPayload() ?? [];
    $project = new Document($payload['project'] ?? []);

    if ($project->isEmpty() || $project->getId() === 'console') {
        return $dbForConsole;
    }

    $pools = $register->get('pools');
    $database = $pools
        ->get($project->getAttribute('database'))
        ->pop()
        ->getResource();

    $adapter = new Database($database, $cache);
    $adapter->setNamespace('_' . $project->getInternalId());
    return $adapter;
}, ['cache', 'register', 'message', 'dbForConsole']);

Server::setResource('cache', function (Registry $register) {
    $pools = $register->get('pools');
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource();
    }

    return new Cache(new Sharding($adapters));
}, ['register']);

Server::setResource('database', function (Registry $register) {
    $pools = $register->get('pools');
    return new EventDatabase(
        $pools
            ->get('queue')
            ->pop()
            ->getResource()
    );
}, ['register']);

Server::setResource('queue', function (Group $pools) {
    return $pools->get('queue')->pop()->getResource();
}, ['pools']);
Server::setResource('messaging', function (Connection $queue) {
    return new Phone($queue);
}, ['queue']);
Server::setResource('mails', function (Connection $queue) {
    return new Mail($queue);
}, ['queue']);
Server::setResource('builds', function (Connection $queue) {
    return new Build($queue);
}, ['queue']);
Server::setResource('database', function (Connection $queue) {
    return new EventDatabase($queue);
}, ['queue']);
Server::setResource('deletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);
Server::setResource('events', function (Connection $queue) {
    return new Event('', '', $queue);
}, ['queue']);
Server::setResource('audits', function (Connection $queue) {
    return new Audit($queue);
}, ['queue']);
Server::setResource('events', function (Connection $queue) {
    return new Event('', '', $queue);
}, ['queue']);
Server::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
Server::setResource('certificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);
Server::setResource('events', function (Registry $register) {
    $pools = $register->get('pools');
    return new Event(
        '',
        '',
        $pools
            ->get('queue')
            ->pop()
            ->getResource()
    );
}, ['register']);

Server::setResource('audits', function (Registry $register) {
    $pools = $register->get('pools');
    return new Audit(
        $pools
            ->get('queue')
            ->pop()
            ->getResource()
    );
}, ['register']);

Server::setResource('certificates', function (Registry $register) {
    $pools = $register->get('pools');
    return new Certificate(
        $pools
            ->get('queue')
            ->pop()
            ->getResource()
    );
}, ['register']);

Server::setResource('logger', function ($register) {
    return $register->get('logger');
}, ['register']);

Server::setResource('statsd', function ($register) {
    return $register->get('statsd');
}, ['register']);

Server::setResource('pools', function ($register) {
    return $register->get('pools');
}, ['register']);

$pools = $register->get('pools');
$connection = $pools->get('queue')->pop()->getResource();
$workerNumber = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));

if (empty(App::getEnv('QUEUE'))) {
    throw new Exception('Please configure "QUEUE" environemnt variable.');
}

$adapter = new Swoole($connection, $workerNumber, App::getEnv('QUEUE'));
$server = new Server($adapter);

$server
    ->shutdown()
    ->inject('pools')
    ->action(function (Group $pools) {
        $pools->reclaim();
    });

$server
    ->error()
    ->inject('error')
    ->inject('logger')
    ->action(function (Throwable $error, Logger $logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($error->getCode() >= 500 || $error->getCode() === 0) {
            $log = new Log();

            $log->setNamespace("appwrite-worker");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-queue-' . App::getEnv('QUEUE'));
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', \Utopia\Database\Validator\Authorization::$roles);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $logger->addLog($log);
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());
    });

/**
 * Get Console DB
 *
 * @returns Cache
 */
function getCache(): Cache
{
    global $register;

    $pools = $register->get('pools');
    /** @var \Utopia\Pools\Group $pools */

    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource();
    }

    return new Cache(new Sharding($adapters));
}

/**
 * Get Console DB
 *
 * @returns Database
 */
function getConsoleDB(): Database
{
    global $register;

    /** @var \Utopia\Pools\Group $pools */
    $pools = $register->get('pools');

    $dbAdapter = $pools
        ->get('console')
        ->pop()
        ->getResource();

    $database = new Database($dbAdapter, getCache());

    $database->setNamespace('console');

    return $database;
}

/**
 * Get Project DB
 *
 * @param Document $project
 * @returns Database
 */
function getProjectDB(Document $project): Database
{
    global $register;

    /** @var \Utopia\Pools\Group $pools */
    $pools = $register->get('pools');

    if ($project->isEmpty() || $project->getId() === 'console') {
        return getConsoleDB();
    }

    $dbAdapter = $pools
        ->get($project->getAttribute('database'))
        ->pop()
        ->getResource();

    $database = new Database($dbAdapter, getCache());
    $database->setNamespace('_' . $project->getInternalId());

    return $database;
}


/**
 * Get Functions Storage Device
 * @param string $projectId of the project
 * @return Device
 */
function getFunctionsDevice($projectId): Device
{
    return getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
}

/**
 * Get Files Storage Device
 * @param string $projectId of the project
 * @return Device
 */
function getFilesDevice($projectId): Device
{
    return getDevice(APP_STORAGE_UPLOADS . '/app-' . $projectId);
}

/**
 * Get Builds Storage Device
 * @param string $projectId of the project
 * @return Device
 */
function getBuildsDevice($projectId): Device
{
    return getDevice(APP_STORAGE_BUILDS . '/app-' . $projectId);
}
