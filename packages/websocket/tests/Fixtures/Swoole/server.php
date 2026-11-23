<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Swoole\Http\Request;
use Swoole\Http\Response;
use Utopia\WebSocket;

$adapter = new WebSocket\Adapter\Swoole('127.0.0.1', 18081, sendTimeout: 1.0);
$adapter->setWorkerNumber(1); // Important for tests
// Fill the buffer quickly without allocating production-sized backlogs.
$adapter->getNative()->ports[0]->set(['socket_buffer_size' => 65536]);

$server = new WebSocket\Server($adapter);

/** @var array<int,bool> $connections */
$connections = [];
/** @var array<int,list<string>> $batches */
$batches = [];
$batchesSent = 0;

$server
    ->onWorkerStart(function (int $workerId): void {
        echo 'worker started ', $workerId, PHP_EOL;
    })
    ->onWorkerStop(function (int $workerId): void {
        echo 'worker stopped ', $workerId, PHP_EOL;
    })
    ->onOpen(function (int $connection, Request $request) use (&$connections): void {
        $connections[$connection] = true;
        echo 'connected ', $connection, PHP_EOL;
    })
    ->onClose(function (int $connection) use (&$connections, &$batches): void {
        unset($connections[$connection], $batches[$connection]);
        echo 'disconnected ', $connection, PHP_EOL;
    })
    ->onMessage(function (int $connection, string $message) use ($server, &$connections, &$batches, &$batchesSent): void {
        [$command, $payload] = explode(':', $message, 2) + [1 => ''];

        switch ($command) {
            case 'buffer':
                $batches[$connection][] = $payload;
                break;
            case 'flush':
                $batch = $batches[$connection] ?? [];
                unset($batches[$connection]);
                foreach ($batch as $payload) {
                    $server->send([$connection], $payload);
                }
                $batchesSent++;
                break;
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
    ->onRequest(function (Request $request, Response $response) use (&$connections, &$batchesSent): void {
        echo 'HTTP request received: ', $request->server['request_uri'], PHP_EOL;

        if ($request->server['request_uri'] === '/health') {
            $response->header('Content-Type', 'application/json');
            $response->status(200);
            $response->end(json_encode(['status' => 'ok', 'message' => 'WebSocket server is running']));
        } elseif ($request->server['request_uri'] === '/info') {
            $response->header('Content-Type', 'application/json');
            $response->status(200);
            $response->end(json_encode([
                'server' => 'Swoole WebSocket',
                'connections' => count($connections),
                'memory_used' => memory_get_usage(),
                'batches_sent' => $batchesSent,
                'timestamp' => time(),
            ]));
        } else {
            $response->status(404);
            $response->end('Not Found');
        }
    })
    ->start();
