<?php

require_once __DIR__ . '/init.php';
$registerWorkerMessageResources = require __DIR__ . '/init/worker/message.php';

use Appwrite\Certificates\LetsEncrypt;
use Appwrite\Platform\Appwrite;
use Appwrite\Workers\Jobs;
use Swoole\Runtime;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Service;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Server;
use Utopia\Span\Span;
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

$container->set('certificates', function () {
    $email = System::getEnv('_APP_EMAIL_CERTIFICATES', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS'));
    if (empty($email)) {
        throw new Exception('You must set a valid security email address (_APP_EMAIL_CERTIFICATES) to issue a LetsEncrypt SSL certificate.');
    }

    return new LetsEncrypt($email);
}, []);

$platform = new Appwrite();
$args = $_SERVER['argv'] ?? [];

\array_shift($args);
$requested = [];
foreach ($args as $arg) {
    if (!\is_string($arg) || $arg === '' || \str_starts_with($arg, '-')) {
        continue;
    }
    foreach (\preg_split('/[,\s]+/', strtolower($arg), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
        $requested[] = $name;
    }
}

/** @var array<string, array{queue: string, queueEnv?: string, maxCoroutines?: int}> $workersConfig */
$workersConfig = Config::getParam('workers', []);
$known = \array_keys($workersConfig);

if ($requested === [] || \in_array('all', $requested, true)) {
    $workers = $known;
    $workerName = 'all';
} else {
    $unknown = \array_values(\array_diff($requested, $known));
    if ($unknown !== []) {
        Console::error('Unknown worker: ' . \implode(', ', $unknown) . '. Valid: ' . \implode(', ', $known));
        Console::exit(1);
    }
    $workers = $requested;
    $workerName = $workers[0];
}

// Same as a single worker: resolve queue + concurrency from config/env.
// For one worker, `_APP_WORKER_MAX_COROUTINES` still overrides (except databases).
// For many, each queue keeps its own cap so databases stays at 1.
$jobs = Jobs::resolve($workers, $workersConfig, System::getEnv(...));

// Receive and commands borrow from the existing publisher pool so concurrent
// workers do not serialize on one Locking Redis connection. Combined Compose
// sets `_APP_WORKER_MAX_COROUTINES` to size that pool.
$createConsumer = static function () use ($container): BrokerPool {
    $publisher = $container->get('pools')->get('publisher');

    return new BrokerPool(
        publisher: $publisher,
        consumer: $publisher,
    );
};

// Adapter is transport only — queue names and concurrency come from job().
$adapter = new Swoole(
    $createConsumer,
    System::getEnv('_APP_WORKERS_NUM', 1),
    resources: $container,
);

$worker = new Server($adapter);

try {
    $worker->init()->action(function () use ($worker, $registerWorkerMessageResources) {
        $registerWorkerMessageResources($worker->context());
        $message = $worker->context()->get('message');
        $spanQueue = $message instanceof \Utopia\Queue\Message ? $message->getQueue() : 'worker';
        Span::init("worker.{$spanQueue}");
    });

    $worker->shutdown()->action(function () {
        Span::current()?->finish();
    });

    $container->set('bus', function ($register) use ($worker) {
        return $register->get('bus')->setResolver(
            fn (string $name) => $worker->context()->get($name)
        );
    }, ['register']);

    $platform->setWorker($worker);
    $platform->init(Service::TYPE_WORKER, [
        'workerName' => $workerName,
        'workers' => $workerName === 'all' ? ['all'] : $workers,
        'jobs' => $jobs,
    ]);
} catch (\Throwable $e) {
    Console::error($e->getMessage() . ', File: ' . $e->getFile() . ', Line: ' . $e->getLine());
    Console::exit(1);
}

$combined = $workerName === 'all';
Console::title($combined ? 'Worker V1 (combined)' : 'Worker V1 (' . $workerName . ')');
Console::success(APP_NAME . ' worker v1 has started');
Console::info('Mode: ' . ($combined ? 'combined — all queues in one process' : 'dedicated — single queue'));
Console::info('Workers: ' . \count($jobs) . '  |  processes: ' . System::getEnv('_APP_WORKERS_NUM', 1));
Console::info(str_pad('queue', 16) . str_pad('redis key', 28) . 'coroutines');
Console::info(str_repeat('-', 56));
foreach ($jobs as $name => $job) {
    Console::info(
        str_pad($name, 16)
        . str_pad($job['queue'], 28)
        . (string) $job['maxCoroutines']
    );
}
Console::info(str_repeat('-', 56));
Console::success('Listening for jobs…');

$worker
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('log')
    ->inject('project')
    ->inject('authorization')
    ->action(function (Throwable $error, ?Logger $logger, Log $log, Document $project, Authorization $authorization) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        Span::current()?->setError($error);

        if ($logger) {
            $log->setNamespace('appwrite-worker');
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-queue-worker');
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addTag('projectId', $project->getId());
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
