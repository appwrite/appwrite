<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Utopia\WebSocket;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

Worker::$logFile = '/dev/null';

$adapter = new WebSocket\Adapter\Workerman('127.0.0.1', 18082);
$adapter->setWorkerNumber(1); // Important for tests

$server = new WebSocket\Server($adapter);

/** @var array<int,bool> $connections */
$connections = [];

$server
    ->onWorkerStart(function (int $workerId): void {
        echo 'worker started ', $workerId, PHP_EOL;
    })
    ->onWorkerStop(function (int $workerId): void {
        echo 'worker stopped ', $workerId, PHP_EOL;
    })
    ->onOpen(function (int $connection, array $request) use (&$connections): void {
        $connections[$connection] = true;
        echo 'connected ', $connection, PHP_EOL;
    })
    ->onClose(function (int $connection) use (&$connections): void {
        unset($connections[$connection]);
        echo 'disconnected ', $connection, PHP_EOL;
    })
    ->onMessage(function (int $connection, string $message) use ($server, &$connections): void {
        echo $message, PHP_EOL;

        switch ($message) {
            case 'ping':
                $server->send([$connection], 'pong');
                break;
            case 'pong':
                $server->send([$connection], 'ping');
                break;
            case 'broadcast':
                $server->send(array_keys($connections), 'broadcast');
                break;
            case 'disconnect':
                $server->send([$connection], 'disconnect');
                $server->close($connection, 1000);
                break;
        }
    })
    ->onRequest(function (TcpConnection $connection, Request $request) use (&$connections): void {
        $path = $request->path();
        echo 'HTTP request received: ', $path, PHP_EOL;

        if ($path === '/health') {
            $connection->send('HTTP/1.1 200 OK' . "\r\n"
                             . 'Content-Type: application/json' . "\r\n"
                             . 'Connection: close' . "\r\n\r\n"
                             . json_encode(['status' => 'ok', 'message' => 'WebSocket server is running']));
        } elseif ($path === '/info') {
            $connection->send('HTTP/1.1 200 OK' . "\r\n"
                             . 'Content-Type: application/json' . "\r\n"
                             . 'Connection: close' . "\r\n\r\n"
                             . json_encode([
                                 'server' => 'Workerman WebSocket',
                                 'connections' => count($connections),
                                 'timestamp' => time(),
                             ]));
        } else {
            $connection->send('HTTP/1.1 404 Not Found' . "\r\n"
                             . 'Connection: close' . "\r\n\r\n"
                             . 'Not Found');
        }
    })
    ->start();
