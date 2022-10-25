<?php

require_once __DIR__ . '/../init.php';


use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Queue;
use Utopia\Queue\Message;
use Utopia\Queue\Server;

global $register;

$pools = $register->get('pools');
$queue = $pools
    ->get('queue')
    ->pop()
    ->getResource()
;

$connection = new Queue\Connection\Redis(fn() => $queue);
$adapter    = new Queue\Adapter\Swoole($connection, 1, 'syncIn');
$server     = new Queue\Server($adapter);

Server::setResource('cache', function () use ($register) {

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
});

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
