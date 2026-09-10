<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../tests.php';

use Utopia\NATS\Connection;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Server;
use Utopia\Validator\Text;

// A Closure factory so each forked worker process resolves its OWN NATS connection
// (a socket cannot survive a fork). job(..., 1) is this suite's choice, not a broker
// limit -- the shared tests assert per-message ordering. Concurrency above one is
// covered by NatsBrokerTest::testConcurrentHandlersDrainTheQueueOnOneConnection().
$consumer = new Nats(
    fn(): Connection => Connection::connect('nats://127.0.0.1:14225'),
    maxDeliver: 3,
);
$adapter = new Swoole($consumer, 12);
$server = new Server($adapter);

$server->job('nats', 1)
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
