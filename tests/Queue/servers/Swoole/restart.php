<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Swoole\Coroutine;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Message;
use Utopia\Queue\Server;

[$script, $namespace, $workers] = $_SERVER['argv'];
$adapter = new Swoole(
    fn(): \Utopia\Queue\Broker\Redis => new Redis(new Connection('127.0.0.1', 16379), new Connection('127.0.0.1', 16379)),
    (int) $workers,
    $namespace,
);
$server = new Server($adapter);
$server->workerStart()->inject('workerId')->action(function (string $workerId) use ($server, $adapter): void {
    ini_set('memory_limit', '64M');
    $server->job('worker-' . $workerId)->inject('message')->action(function (Message $message) use ($workerId, $adapter): void {
        $mode = $message->getPayload()['mode'];
        if ($mode === 'fatal') {
            echo strlen(str_repeat('x', 128 * 1024 * 1024)) . PHP_EOL;
        }
        if ($mode === 'retire') {
            $adapter->stop();

            return;
        }
        if ($mode === 'slow') {
            echo json_encode(['event' => 'started', 'worker' => $workerId, 'pid' => getmypid()]) . PHP_EOL;
            Coroutine::sleep(0.5);
        }
        echo json_encode(['event' => 'processed', 'worker' => $workerId, 'pid' => getmypid()]) . PHP_EOL;
    });
    echo json_encode(['event' => 'ready', 'worker' => $workerId, 'pid' => getmypid()]) . PHP_EOL;
});
$server->workerStop()->inject('workerId')->action(function (string $workerId): void {
    echo json_encode(['event' => 'stopped', 'worker' => $workerId, 'pid' => getmypid()]) . PHP_EOL;
});
$server->error()->inject('error')->action(function (Throwable $error): void {
    echo json_encode(['event' => 'error', 'message' => $error->getMessage()]) . PHP_EOL;
});
$server->start();
echo json_encode(['event' => 'exited', 'pid' => getmypid()]) . PHP_EOL;
