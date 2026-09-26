<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../tests.php';

use Utopia\Queue;
use Utopia\Queue\Adapter\Workerman;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Validator\Text;

$consumer = new Redis(
    receive: new RedisConnection('127.0.0.1', 16379),
    commands: new RedisConnection('127.0.0.1', 16379),
);
$adapter = new Workerman($consumer, 12);
$server = new Queue\Server($adapter);

$server->job('wokerman')
    ->inject('message')
    ->param(
        key: 'aliasValue',
        default: '',
        validator: new Text(length: 255, min: 0),
        description: 'alias resolution test value',
        optional: true,
        aliases: ['alias_value', 'aliased'],
    )
    ->action(handleRequest(...));

$server
    ->error()
    ->inject('error')
    ->action(function ($th): void {
        echo $th->getMessage() . PHP_EOL;
    });

$server->workerStart()->action(function (): void {
    echo 'Worker Started' . PHP_EOL;
});

$server->workerStop()->action(function (): void {
    echo 'Worker Stopped' . PHP_EOL;
});

$server->start();
