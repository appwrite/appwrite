<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Network\Validator\Origin;
use Appwrite\Realtime\Realtime;
use Appwrite\Utopia\Response;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Swoole\Request as SwooleRequest;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;

/**
 * TODO List
 * 
 * - JWT Authentication (in path / or in message)
 * 
 * Protocols Support:
 * - Websocket support: https://www.swoole.co.uk/docs/modules/swoole-websocket-server
 */

ini_set('default_socket_timeout', -1);
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$server = new Server('0.0.0.0', 80);

$server->set([
    'package_max_length' => 64000 // Default maximum Package Size (64kb)
]);

$subscriptions = [];
$connections = [];

$server->on('workerStart', function ($server, $workerId) use (&$subscriptions, &$connections, &$register) {
    Console::success('Worker ' . $workerId . ' started succefully');
    
    $attempts = 0;
    $start = time();

    while ($attempts < 300) {
        try {
            if ($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted ' . (time() - $start) . ' seconds, worker: ' . $workerId . ').
                    Attempting restart in 5 seconds (attempt #' . $attempts . ')');
                sleep(5); // 5 sec delay between connection attempts
            }

            $redis = $register->get('cache');

            if ($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $redis->subscribe(['realtime'], function ($redis, $channel, $payload) use ($server, &$connections, &$subscriptions) {
                /**
                 * Supported Resources:
                 *  - Collection
                 *  - Document
                 *  - File
                 *  - Account
                 *  - Session
                 *  - Team? (not implemented yet)
                 *  - Membership? (not implemented yet)
                 *  - Function? (not available yet)
                 *  - Execution? (not available yet)
                 */
                $event = json_decode($payload, true);

                $receivers = Realtime::identifyReceivers($event, $subscriptions);

                foreach ($receivers as $receiver) {
                    if ($server->exist($receiver) && $server->isEstablished($receiver)) {
                        $server->push(
                            $receiver,
                            json_encode($event['data']),
                            SWOOLE_WEBSOCKET_OPCODE_TEXT,
                            SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
                        );
                    } else {
                        $server->close($receiver);
                    }
                }
            });
        } catch (\Throwable $th) {
            Console::error('Pub/sub error: ' . $th->getMessage());
            $attempts++;
            continue;
        }

        $attempts++;
    }

    Console::error('Failed to restart pub/sub...');
});

$server->on('start', function (Server $server) {
    Console::success('Server started succefully');

    Console::info("Master pid {$server->master_pid}, manager pid {$server->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($server) {
        Console::log('Stop by Ctrl+C');
        $server->shutdown();
    });
});

$server->on('open', function (Server $server, Request $request) use (&$connections, &$subscriptions, &$register) {
    Console::info("Connection open (user: {$request->fd}, connections: {}, worker: {$server->getWorkerId()})");

    $app = new App('');
    $connection = $request->fd;
    $request = new SwooleRequest($request);

    App::setResource('request', function () use ($request) {
        return $request;
    });

    App::setResource('response', function () {
        return new Response(new SwooleResponse());
    });

    /** @var Appwrite\Database\Document $user */
    $user = $app->getResource('user');

    /** @var Appwrite\Database\Document $project */
    $project = $app->getResource('project');

    /** @var Appwrite\Database\Document $console */
    $console = $app->getResource('console');

    /*
     *  Project Check
     */
    if (empty($project->getId())) {
        $server->push($connection, 'Missing or unknown project ID');
        $server->close($connection);
        return;
    }

    /*
     * Abuse Check
     */
    $timeLimit = new TimeLimit('url:{url},ip:{ip}', 60, 60, function () use ($register) {
        return $register->get('db');
    });
    $timeLimit
        ->setNamespace('app_' . $project->getId())
        ->setParam('{ip}', $request->getIP())
        ->setParam('{url}', $request->getURI());

    $abuse = new Abuse($timeLimit);

    if ($abuse->check() && App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
        $server->push($connection, 'Too many requests');
        $server->close($connection);
        return;
    }

    /*
     * Validate Client Domain - Check to avoid CSRF attack.
     * Adding Appwrite API domains to allow XDOMAIN communication.
     * Skip this check for non-web platforms which are not required to send an origin header.
     */
    $origin = $request->getOrigin();
    $originValidator = new Origin(\array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

    if (!$originValidator->isValid($origin)) {
        $server->push($connection, $originValidator->getDescription());
        $server->close($connection);
        return;
    }

    Realtime::setUser($user);

    $roles = Realtime::getRoles();
    $channels = Realtime::parseChannels($request->getQuery('channels', []));

    /**
     * Channels Check
     */
    if (empty($channels)) {
        $server->push($connection, 'Missing channels');
        $server->close($connection);
        return;
    }

    Realtime::subscribe($project->getId(), $connection, $roles, $subscriptions, $connections, $channels);

    $server->push($connection, json_encode($channels));
});

$server->on('message', function (Server $server, Frame $frame) {
    $server->push($frame->fd, 'Sending messages is not allowed.');
    $server->close($frame->fd);
});

$server->on('close', function (Server $server, int $fd) use (&$connections, &$subscriptions) {
    Realtime::unsubscribe($fd, $subscriptions, $connections);
    Console::info('Connection close: ' . $fd);
});

$server->start();
