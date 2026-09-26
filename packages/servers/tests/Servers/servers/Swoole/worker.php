<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../tests.php';

use Utopia\Queue;
use Utopia\Queue\Message;

$connection = new Queue\Connection\Redis('redis');
$adapter = new Queue\Adapter\Swoole($connection, 12, 'swoole');
$server = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->action(function (Message $message): void {
        handleRequest($message);
    });

$server
    ->error()
    ->inject('error')
    ->action(function ($th): void {
        echo $th->getMessage() . PHP_EOL;
    });

$server
    ->workerStart()
    ->action(function (): void {
        echo 'Worker Started' . PHP_EOL;
    });

$server->start();
