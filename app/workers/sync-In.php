<?php

require_once __DIR__ . '/../worker.php';


use Utopia\App;
use Utopia\Cache\Cache;
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

        $payload = $message->getPayload()['value'];
        foreach ($payload['keys'] ?? [] as $key) {
                var_dump('purging -> ' . $key);
                var_dump($cache->purge($key));
        }
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
        echo "In region [" . App::getEnv('_APP_REGION', 'nyc1') . "] cache purging worker Started" . PHP_EOL;
    })
    ->start();
