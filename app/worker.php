<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/init/worker/message.php';

use Appwrite\Certificates\LetsEncrypt;
use Appwrite\Platform\Appwrite;
use Swoole\Runtime;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Server;
use Utopia\System\System;

Runtime::enableCoroutine();
require_once __DIR__ . '/init/span.php';

global $container;
$container->set('pools', function ($register) {
    return $register->get('pools');
}, ['register']);

$container->set('authorization', function () {
    $authorization = new Authorization();
    $authorization->disable();

    return $authorization;
}, []);

$container->set('project', fn () => new Document([]), []);

$container->set('log', fn () => new Log(), []);

$container->set('consumer', function (Group $pools) {
    return new BrokerPool(consumer: $pools->get('consumer'));
}, ['pools']);

$container->set('consumerDatabases', function (BrokerPool $consumer) {
    return $consumer;
}, ['consumer']);

$container->set('consumerMigrations', function (BrokerPool $consumer) {
    return $consumer;
}, ['consumer']);

$container->set('consumerStatsUsage', function (BrokerPool $consumer) {
    return $consumer;
}, ['consumer']);

$container->set('certificates', function () {
    $email = System::getEnv('_APP_EMAIL_CERTIFICATES', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS'));
    if (empty($email)) {
        throw new Exception('You must set a valid security email address (_APP_EMAIL_CERTIFICATES) to issue a LetsEncrypt SSL certificate.');
    }

    return new LetsEncrypt($email);
}, []);

$platform = new Appwrite();
$args = $platform->getEnv('argv');

if (! isset($args[1])) {
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
    /** @var Group $pools */
    $pools = $container->get('pools');

    $adapter = new Swoole(
        $pools->get('consumer')->pop()->getResource(),
        System::getEnv('_APP_WORKERS_NUM', 1),
        $queueName
    );

    $worker = new Server($adapter, $container);

    $worker->init()->action(function () use ($worker) {
        registerWorkerMessageResources($worker->getContainer());
    });

    $container->set('bus', function ($register) use ($worker) {
        return $register->get('bus')->setResolver(
            fn (string $name) => $worker->getContainer()->get($name)
        );
    }, ['register']);

    $platform->setWorker($worker);
    $platform->init(Service::TYPE_WORKER, [
        'workerName' => strtolower($workerName),
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() . ', Line: ' . $e->getLine());
    Console::exit(1);
}

$worker
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('log')
    ->inject('project')
    ->inject('authorization')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Document $project, Authorization $authorization) use ($queueName) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($logger) {
            $log->setNamespace('appwrite-worker');
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
            $log->addExtra('roles', $authorization->getRoles());

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

$worker->start();
