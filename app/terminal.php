<?php

use Utopia\WebSocket\Server;
use Utopia\WebSocket\Adapter;
use Swoole\Table;
use WebSocket\Client;
use Utopia\CLI\Console;
use Ahc\Jwt\JWT;
use Utopia\System\System;

// WebSocket close codes
const CLOSE_NORMAL = 1000;              // Normal closure
const CLOSE_POLICY_VIOLATION = 1008;    // Policy violation or connection lost
const CLOSE_SERVER_ERROR = 1011;        // Server-side error

require_once __DIR__ . '/init.php';

/**
 * Table for managing runtime connections across all workers.
 */
$runtimes = new Table(4096, 1);
$runtimes->column('userId', Table::TYPE_STRING, 64);
$runtimes->column('runtimeHost', Table::TYPE_STRING, 255);
$runtimes->column('runtimePort', Table::TYPE_INT);
$runtimes->create();

// Store WebSocket client connections
$clients = [];

const MAX_PACKAGE_LENGTH = 64000;
const MAX_RUNTIME_CONNECTIONS = 4096;

$adapter = new Adapter\Swoole(port: System::getEnv('PORT', 80));
$adapter
    ->setPackageMaxLength(MAX_PACKAGE_LENGTH)
    ->setWorkerNumber($workerNumber);

$server = new Server($adapter);

$server->onStart(function () use ($workerNumber) {
    Console::success('Terminal WebSocket Proxy started successfully');
    Console::info('Listening on port: ' . System::getEnv('PORT', 80));
    Console::info('Worker processes: ' . $workerNumber);
    Console::info('Max package length: ' . (MAX_PACKAGE_LENGTH / 1000) . 'KB');
    Console::info('Max runtime connections: ' . MAX_RUNTIME_CONNECTIONS);
});

$server->onOpen(function (int $connection, $request) use ($server, $runtimes, &$clients) {
    try {
        Console::info("New connection: {$connection}");

        // Extract JWT from request
        $token = $request->header['authorization'] ?? '';
        if (empty($token)) {
            throw new Exception('Missing authentication token', 401);
        }

        // Verify JWT and extract user information
        $jwt = str_replace('Bearer ', '', $token);
        $key = System::getEnv('_APP_OPENSSL_KEY_V1', '');
        $jwt = new JWT($key, 'HS256', 900, 0);
        
        try {
            $payload = $jwt->decode($token);
            $userId = $payload['userId'] ?? '';
            $sessionId = $payload['sessionId'] ?? '';
            
            if (empty($userId) || empty($sessionId)) {
                throw new Exception('Invalid JWT payload', 401);
            }
        } catch (\Exception $e) {
            throw new Exception('Invalid JWT token', 401);
        }

        // Get runtime details for user (this could come from your database/cache)
        $runtimeHost = "runtime-{$userId}.internal"; // Example hostname
        $runtimePort = 9000;

        // Create WebSocket connection to runtime
        go(function () use ($server, $connection, &$clients, $runtimeHost, $runtimePort, $userId) {
            try {
                // $wsClient = new Client("ws://{$runtimeHost}:{$runtimePort}/", [
                //     'timeout' => 0, // Disable timeout for long-running connections
                //     'filter' => ['text', 'binary', 'close'] // Only process these frame types
                // ]);


                $wsClient = new Client(
                    "ws://appwrite-traefik/v1/realtime",
                    [
                        "headers" => [],
                        "timeout" => 30,
                    ]
                );
                
                // Store client connection
                $clients[$connection] = [
                    'client' => $wsClient,
                    'userId' => $userId
                ];

                // Forward messages from runtime back to client
                while (true) {
                    try {
                        $message = $wsClient->receive();
                        if ($message === null) {
                            // Connection closed normally
                            break;
                        }
                        $server->send([$connection], $message);
                        
                        // Yield to allow other coroutines to run
                        Swoole\Coroutine::yield();
                        
                    } catch (\WebSocket\ConnectionException $e) {
                        Console::error("Runtime connection error for user {$userId}: " . $e->getMessage());
                        break;
                    }
                }

                // Cleanup on disconnect
                $wsClient->close();
                unset($clients[$connection]);
                $server->close($connection, CLOSE_NORMAL);
            } catch (\WebSocket\ConnectionException $e) {
                Console::error("Failed to connect to runtime for user {$userId}: " . $e->getMessage());
                $server->close($connection, CLOSE_SERVER_ERROR);
                return;
            }
        });

        // Send successful connection message
        $server->send([$connection], json_encode([
            'type' => 'connected',
            'data' => [
                'userId' => $userId,
                'timestamp' => time()
            ]
        ]));

    } catch (Throwable $th) {
        Console::error('Connection error: ' . $th->getMessage());
        
        $server->send([$connection], json_encode([
            'type' => 'error',
            'data' => [
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ]
        ]));
        
        $server->close($connection, CLOSE_POLICY_VIOLATION);
    }
});

$server->onMessage(function (int $connection, string $message) use ($server, &$clients) {
    try {
        if (!isset($clients[$connection])) {
            throw new Exception('Client not connected to runtime', 1008);
        }

        $wsClient = $clients[$connection]['client'];
        try {
            // Forward message to runtime
            $wsClient->send($message);
        } catch (\WebSocket\ConnectionException $e) {
            throw new Exception('Runtime connection lost: ' . $e->getMessage(), 1008);
        }

    } catch (Throwable $th) {
        $server->send([$connection], json_encode([
            'type' => 'error',
            'data' => [
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ]
        ]));

        if ($th->getCode() === 1008) {
            $server->close($connection, CLOSE_POLICY_VIOLATION);
        }
    }
});

$server->onClose(function (int $connection) use (&$clients) {
    if (isset($clients[$connection])) {
        $userId = $clients[$connection]['userId'];
        $clients[$connection]['client']->close();
        unset($clients[$connection]);
        Console::info("Closed connection for user {$userId}");
    }
});

$server->start(); 