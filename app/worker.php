<?php

require_once __DIR__ . '/init/worker.php';

use Appwrite\Platform\Appwrite;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\System\System;

$pools = $register->get('pools');
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
    /**
     * Any worker can be configured with the following env vars:
     * - _APP_WORKERS_NUM           The total number of worker processes
     * - _APP_WORKER_PER_CORE       The number of worker processes per core (ignored if _APP_WORKERS_NUM is set)
     * - _APP_QUEUE_NAME            The name of the queue to read for database events
     */
    $platform->init(Service::TYPE_WORKER, [
        'workersNum' => System::getEnv('_APP_WORKERS_NUM', 1),
        'connection' => $pools->get('queue')->pop()->getResource(),
        'workerName' => strtolower($workerName) ?? null,
        'queueName' => $queueName
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() .  ', Line: ' . $e->getLine());
}

$worker = $platform->getWorker();

$worker
    ->shutdown()
    ->inject('pools')
    ->action(function (Group $pools) {
        $pools->reclaim();
    });

$worker
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('log')
    ->inject('pools')
    ->inject('project')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Group $pools, Document $project) use ($queueName) {
        $pools->reclaim();
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($logger) {
            $log->setNamespace("appwrite-worker");
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
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
            $log->addExtra('roles', Authorization::getRoles());

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            try {
                $responseCode = $logger->addLog($log);
                Console::info('Error log pushed with status code: ' . $responseCode);
            } catch (Throwable $th) {
                Console::error('Error pushing log: ' . $th->getMessage());
            }
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
