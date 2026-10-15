<?php

/**
 * The E2E fixture server (packages/websocket/tests/Fixtures/Swoole/server.php)
 * on a given port, started by Adapter.php's `e2e.run` (and `swoole.config`).
 * crates/tools/compat/src/libs/websocket.rs serves the same handlers on the
 * Rust Network adapter.
 *
 * Usage: php server.php serve <port> <send timeout>
 *        php server.php config '<serialized settings>'
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Swoole\Http\Request;
use Swoole\Http\Response;
use Utopia\WebSocket;

/**
 * Exposes the settings the adapter passes to Swoole's Server::set().
 */
final class Inspect extends WebSocket\Adapter\Swoole
{
    /**
     * @return array<int|string, bool|int|float|string>
     */
    public function config(): array
    {
        return $this->config;
    }
}

/** @var list<string> $args */
$args = $_SERVER['argv'] ?? [];

if (($args[1] ?? '') === 'config') {
    $a = unserialize($args[2] ?? 'a:0:{}', ['allowed_classes' => false]);
    try {
        $adapter = isset($a['send_timeout'])
            ? new Inspect('127.0.0.1', 0, (float) $a['send_timeout'])
            : new Inspect('127.0.0.1', 0);
        if (isset($a['package_max_length'])) {
            $adapter->setPackageMaxLength($a['package_max_length']);
        }
        if (isset($a['compression'])) {
            $adapter->setCompressionEnabled($a['compression']);
        }
        if (isset($a['workers'])) {
            $adapter->setWorkerNumber($a['workers']);
        }
        $config = $adapter->config();
        echo json_encode(['ok' => [
            'max_connection' => $config['max_connection'] ?? null,
            'send_timeout' => $config['send_timeout'] ?? null,
            'package_max_length' => $config['package_max_length'] ?? null,
            'websocket_compression' => $config['websocket_compression'] ?? null,
            'worker_num' => $config['worker_num'] ?? null,
        ]], JSON_PRESERVE_ZERO_FRACTION);
    } catch (\Throwable $e) {
        echo json_encode(['error' => ['class' => $e::class, 'message' => $e->getMessage()]]);
    }
    exit(0);
}

$adapter = new WebSocket\Adapter\Swoole('127.0.0.1', (int) ($args[2] ?? 0), sendTimeout: (float) ($args[3] ?? 1.0));
$adapter->setWorkerNumber(1);
// Fill the buffer quickly without allocating production-sized backlogs.
$adapter->getNative()->ports[0]->set(['socket_buffer_size' => 65536]);

$server = new WebSocket\Server($adapter);

/** @var array<int,bool> $connections */
$connections = [];
/** @var array<int,list<string>> $batches */
$batches = [];
$batchesSent = 0;

$server
    ->onStart(function (): void {
    })
    ->onWorkerStart(function (int $workerId): void {
    })
    ->onWorkerStop(function (int $workerId): void {
    })
    ->onOpen(function (int $connection, Request $request) use (&$connections): void {
        $connections[$connection] = true;
    })
    ->onClose(function (int $connection) use (&$connections, &$batches): void {
        unset($connections[$connection], $batches[$connection]);
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
    ->onRequest(function (Request $request, Response $response) use ($server, &$connections, &$batchesSent): void {
        $response->header('Content-Type', 'application/json');
        switch ($request->server['request_uri']) {
            case '/health':
                $response->end((string) json_encode(['status' => 'ok']));
                break;
            case '/info':
                $response->end((string) json_encode(['connections' => \count($connections), 'batches_sent' => $batchesSent]));
                break;
            case '/shutdown':
                $response->end('{}');
                $server->shutdown();
                break;
            default:
                $response->status(404);
                $response->end('Not Found');
        }
    })
    ->start();
