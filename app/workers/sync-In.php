<?php

require_once __DIR__ . '/../worker.php';

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Logger\Log;
use Utopia\Queue;
use Utopia\Queue\Message;

global $redisConnection;
global $workerNumber;

$adapter    = new Queue\Adapter\Swoole($redisConnection, $workerNumber, 'syncIn');
$server     = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->inject('cache')
    ->action(function (Message $message, Cache $cache) {
        $time = DateTime::now();
        $payload = $message->getPayload()['value'];
        $cache->setDisableListeners(true);
        foreach ($payload['keys'] ?? [] as $key) {
            Console::info("[{$time}] Purging  {$key}");
            $cache->purge($key);
        }
        $cache->setDisableListeners(false);
    });

$server
    ->error()
    ->inject('error')
    ->inject('logger')
    ->action(function ($error, $logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($error->getCode() >= 500 || $error->getCode() === 0) {
            $log = new Log();

            $log->setNamespace("worker");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('worker-sync-out');
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

$server
    ->workerStart()
    ->action(function () {
        Console::success("In  [" . App::getEnv('_APP_REGION', 'nyc1') . "] edge cache purging worker Started");
    });

$server->start();
