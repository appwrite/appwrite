<?php

require_once __DIR__.'/init.php';
require_once __DIR__.'/../vendor/autoload.php';

use Appwrite\Swoole\Request as SwooleRequest;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Route;

/**
 * TODO List
 * 
 * - Abuse Control / x mesages per connection
 * - CORS Validation
 * - Limit payload size
 * - Message structure: { status: "ok"|"error", event: EVENT_NAME, data: <any arbitrary data> }
 * - JWT Authentication (in path / or in message)
 * 
 * Protocols Support:
 * - Websocket support: https://www.swoole.co.uk/docs/modules/swoole-websocket-server
 * - MQTT support: https://www.swoole.co.uk/docs/modules/swoole-mqtt-server
 * - SSE support: https://github.com/hhxsv5/php-sse
 * - Socket.io support: https://github.com/shuixn/socket.io-swoole-server
 */

ini_set('default_socket_timeout', -1);
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$server = new Server("0.0.0.0", 80);
$subscriptions = [];
$connections = [];

$server->on("workerStart", function ($server, $workerId) use (&$subscriptions) {
    Console::success('Worker '.++$workerId.' started succefully');

    $attempts = 0;
    $start = time();
    
    while ($attempts < 300) {
        try {
            if($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted '.(time() - $start).' seconds). Attempting restart in 5 seconds (attempt #'.$attempts.')');
                sleep(5); // 1 sec delay between connection attempts
            }

            $redis = new Redis();
            $redis->connect('redis', 6379);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established');
            }
            else {
                Console::error('Pub/sub failed');
            }

            $redis->subscribe(['realtime'], function($redis, $channel, $message) use ($server, $workerId) {
                $message = 'Message from worker #'.$workerId.'; '.$message;
                        
                // foreach($connections as $fd) {
                //     if ($server->exist($fd)
                //         && $server->isEstablished($fd)
                //         ) {
                //             Console::info('Sending message: '.$message.' (user: '.$fd.', worker: '.$workerId.')');

                //             $server->push($fd, $message, SWOOLE_WEBSOCKET_OPCODE_TEXT,
                //                 SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
                //     }
                //     else { 
                //         $server->close($fd);
                //     }
                // }
            });
            
        } catch (\Throwable $th) {
            Console::error('Pub/sub error: '.$th->getMessage());
            $attempts++;
            continue;
        }

        $attempts++;
    }

    Console::error('Failed to restart pub/sub...');
});

$server->on("start", function (Server $server) {
    Console::success('Server started succefully');

    Console::info("Master pid {$server->master_pid}, manager pid {$server->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($server) {
        Console::log('Stop by Ctrl+C');
        $server->shutdown();
    });
});

$server->on('open', function(Server $server, Request $request) use (&$connections, &$subscriptions) {    
    Console::info("Connection open (user: {$request->fd}, worker: {$server->getWorkerId()})");

    $connection = $request->fd;
    $request = new SwooleRequest($request);

    App::setResource('request', function () use ($request) {
        return $request;
    });

    App::setResource('response', function () {
        return null;
    });

    $channels = array_flip($request->getQuery('channels', []));
    $user = App::getResource('user');
    $project = App::getResource('project');

    /** @var Appwrite\Database\Document $user */
    /** @var Appwrite\Database\Document $project */

    var_dump($project->getId());
    var_dump($project->getAttribute('name'));
    var_dump($user->getId());
    var_dump($user->getAttribute('name'));

    if(!isset($subscriptions[$project->getId()])) { // Init Project
        $subscriptions[$project->getId()] = [];
    }

    if(!isset($subscriptions[$project->getId()][$user->getId()])) { // Add user first connection
        $subscriptions[$project->getId()][$user->getId()] = [];
    }

    foreach ($channels as $channel => $list) {
        $subscriptions[$project->getId()][$user->getId()][$channel][$connection] = true;
    }

    $connections[$connection] = [
        'projectId' => $project->getId(),
        'userId' => $user->getId()
    ];

    $server->push($connection, json_encode($subscriptions));
});

$server->on('message', function(Server $server, Frame $frame) {
    if($frame->data === 'reload') {
        $server->reload();
    }

    Console::info('Recieved message: '.$frame->data.' (user: '.$frame->fd.', worker: '.$server->getWorkerId().')');

    $server->push($frame->fd, json_encode(["hello, worker_id:".$server->getWorkerId(), time()]));
});

$server->on('close', function(Server $server, int $fd) use (&$connections, &$subscriptions) {
    Console::error('Connection close: '.$fd);

    $projectId = $connections[$fd]['projectId'] ?? '';
    $userId = $connections[$fd]['userId'] ?? '';

    foreach ($subscriptions[$projectId][$userId] as $channel => $list) {
        unset($subscriptions[$projectId][$userId][$channel][$fd]); // Remove connection

        if(empty($list)) {
            unset($subscriptions[$projectId][$userId][$channel]);  // Remove channel
        }
    }

    if(empty($subscriptions[$projectId][$userId])) {
        unset($subscriptions[$projectId][$userId]); // Remove user
    }

    unset($connections[$fd]);

    var_dump($subscriptions);
});

$server->start();