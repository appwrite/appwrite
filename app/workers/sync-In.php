<?php

require_once __DIR__ . '/../worker.php';

use Appwrite\Extend\Exception;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Queue;
use Utopia\Queue\Message;

if (App::getEnv('_APP_REGION', 'default') === 'default') {
    throw new Exception(Exception::GENERAL_SERVER_ERROR);
}

global $register;
global $dsn;

$connection = new Queue\Connection\Redis($dsn->getHost(), $dsn->getPort());
$adapter    = new Queue\Adapter\Swoole($connection, 2, 'syncIn');
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
    ->inject('logError')
    ->action(function ($error, $logError) {
        Console::error($error->getMessage() . ' ' . $error->getFile() . ' ' . $error->getLine());
        call_user_func($logError, $error, 'sync-in-worker');
    });

$server
    ->workerStart()
    ->action(function () {
        Console::success("In  [" . App::getEnv('_APP_REGION', 'nyc1') . "] edge cache purging worker Started");
    });

$server->start();
