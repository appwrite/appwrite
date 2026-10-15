<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../tests.php';

use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Server;
use Utopia\Validator\Text;

// Dedicated blocking-receive connection; a separate locked connection for commands.
$consumer = new Redis(
    receive: new RedisConnection('127.0.0.1', 16379),
    commands: new Locking(new RedisConnection('127.0.0.1', 16379)),
);
$adapter = new Swoole($consumer, 12);
$server = new Server($adapter);

$server->job('swoole', 5)
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
