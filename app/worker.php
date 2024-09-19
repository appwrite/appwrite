<?php

require_once __DIR__ . '/init.php';

use Appwrite\Event\UsageDump;
use Appwrite\Platform\Appwrite;
use Appwrite\Utopia\Queue\Connections;
use Swoole\Runtime;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Queue\Connection;
use Utopia\Queue\Message;
use Utopia\Queue\Worker;
use Utopia\Storage\Device\Local;
use Utopia\System\System;

global $registry, $container;

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$project = new Dependency();
$register = new Dependency();
$dbForProject = new Dependency();
$abuseRetention = new Dependency();
$deviceForCache = new Dependency();
$auditRetention = new Dependency();
$queueForUsageDump = new Dependency();
$executionRetention = new Dependency();
$deviceForLocalFiles = new Dependency();

$register
    ->setName('register')
    ->setCallback(fn () => $registry);

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

$abuseRetention
    ->setName('abuseRetention')
    ->setCallback(function () {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', 86400));
    });

$auditRetention
    ->setName('auditRetention')
    ->setCallback(function () {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 1209600));
    });

$executionRetention
    ->setName('executionRetention')
    ->setCallback(function () {
        return DateTime::addSeconds(new \DateTime(), -1 * System::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', 1209600));
    });

$queueForUsageDump
    ->setName('queueForUsageDump')
    ->inject('queue')
    ->setCallback(function (Connection $queue) {
        return new UsageDump($queue);
    });

$deviceForCache
    ->setName('deviceForCache')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return getDevice(APP_STORAGE_CACHE . '/app-' . $project->getId());
    });

$deviceForLocalFiles
    ->setName('deviceForLocalFiles')
    ->inject('project')
    ->setCallback(function (Document $project) {
        return new Local(APP_STORAGE_UPLOADS . '/app-' . $project->getId());
    });

$container->set($project);
$container->set($register);
$container->set($dbForProject);
$container->set($abuseRetention);
$container->set($auditRetention);
$container->set($deviceForCache);
$container->set($queueForUsageDump);
$container->set($executionRetention);
$container->set($deviceForLocalFiles);

$platform = new Appwrite();
$args = $platform->getEnv('argv');

if (!isset($args[1])) {
    Console::error('Missing worker name');
    Console::exit(1);
}

\array_shift($args);
$workerName = $args[0];

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
     * - _APP_QUEUE_NAME            The name of the queue to read for database events
     */
    $platform->init(Service::TYPE_WORKER, [
        'workersNum' => System::getEnv('_APP_WORKERS_NUM', 1),
        'connection' => $connection,
        'workerName' => strtolower($workerName) ?? null,
        'queueName' => $queueName
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() . ', Line: ' . $e->getLine());
}

Worker::init()
    ->inject('authorization')
    ->action(function (Authorization $authorization) {
        $authorization->disable();
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
    ->inject('authorization')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Connections $connections, Document $project, Authorization $authorization) use ($queueName) {
        $connections->reclaim();
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

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
            $log->addExtra('roles', $authorization->getRoles());

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
