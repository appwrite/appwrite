<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\Func;
use Swoole\Runtime;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Message;
use Utopia\Queue\Server;
use Utopia\Registry\Registry;
use Utopia\Logger\Log;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

global $register;

Server::setResource('register', fn() => $register);

Server::setResource('dbForConsole', function (Cache $cache, Registry $register) {
    $pools = $register->get('pools');
    $database = $pools
        ->get('console')
        ->pop()
        ->getResource()
    ;

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
        ->getResource()
    ;

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
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['register']);

Server::setResource('queueForFunctions', function (Registry $register) {
    $pools = $register->get('pools');
    return new Func(
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

$pools = $register->get('pools');
$connection = $pools->get('queue')->pop()->getResource();
$workerNumber = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));

if (empty(App::getEnv('QUEUE'))) {
    throw new Exception('Please configure "QUEUE" environemnt variable.');
}

$adapter = new Swoole($connection, $workerNumber, App::getEnv('QUEUE'));
$server = new Server($adapter);

$server
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('register')
    ->action(function ($error, $logger, $register) {

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
            $log->setAction('appwrite-worker-functions');
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

        $register->get('pools')->reclaim();
    });
