<?php

require_once __DIR__ . '/../worker.php';


use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Queue;
use Utopia\Queue\Message;

global $register;

$connection = new Queue\Connection\Redis(App::getEnv('_APP_REDIS_HOST', 'redis'), App::getEnv('_APP_REDIS_PORT', '6379'));
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
    ->action(function ($error) {
        echo $error->getMessage() . PHP_EOL;
        echo $error->getLine() . PHP_EOL;
    });

$server
    ->workerStart(function () {
        echo "In  [" . App::getEnv('_APP_REGION', 'nyc1') . "] edge cache purging worker Started" . PHP_EOL;
    })
    ->start();
