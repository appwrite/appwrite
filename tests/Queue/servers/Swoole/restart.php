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
// A third argument above 1 registers extra queues per worker, so the adapter
// takes its multi-queue loop instead of the single-queue one.
$queues = (int) ($_SERVER['argv'][3] ?? 1);
$adapter = new Swoole(
    fn(): \Utopia\Queue\Broker\Redis => new Redis(new Connection('127.0.0.1', 16379), new Connection('127.0.0.1', 16379)),
    (int) $workers,
    $namespace,
);
$server = new Server($adapter);
$server->workerStart()->inject('workerId')->action(function (string $workerId) use ($server, $adapter, $queues): void {
    ini_set('memory_limit', '64M');
    $handler = function (Message $message) use ($workerId, $adapter): void {
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
    };
    $server->job('worker-' . $workerId)->inject('message')->action($handler);
    for ($extra = 1; $extra < $queues; $extra++) {
        $server->job('worker-' . $workerId . '-' . $extra)->inject('message')->action($handler);
    }
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
