<?php

require_once __DIR__.'/init.php';
require_once __DIR__.'/../vendor/autoload.php';

use Appwrite\Auth\Auth;
use Appwrite\Database\Document;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Swoole\Request as SwooleRequest;

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

$server->on("workerStart", function ($server, $workerId) use (&$subscriptions, &$connections) {
    Console::success('Worker '.++$workerId.' started succefully');

    $attempts = 0;
    $start = time();
    
    while ($attempts < 300) {
        try {
            if($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted '.(time() - $start).' seconds, worker: '.$workerId.').
                    Attempting restart in 5 seconds (attempt #'.$attempts.')');
                sleep(5); // 1 sec delay between connection attempts
            }

            $redis = new Redis();
            $redis->connect('redis', 6379);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if($redis->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: '.$workerId.')');
            }
            else {
                Console::error('Pub/sub failed (worker: '.$workerId.')');
            }

            $redis->subscribe(['realtime'], function($redis, $channel, $message) use ($server, $workerId, &$connections) {
                $message = 'Message from worker #'.$workerId.'; '.$message;

                // TODO get project and resource ID and itterate over the resource read(?) permissions and send a message to all listeners

                /**
                 * Supported Resources:
                 *  - Collection
                 *  - Document
                 *  - Bucket
                 *  - File
                 *  - User? / Account? (no permissions)
                 *  - Session? (no permissions)
                 *  - Team? (no permissions)
                 *  - Membership? (no permissions)
                 *  - Function
                 *  - Execution
                 */
                        
                foreach($connections as $fd => $connection) {
                    if ($server->exist($fd)
                        && $server->isEstablished($fd)
                        ) {
                            Console::info('Sending message: '.$message.' (user: '.$fd.', worker: '.$workerId.')');
                            $server->push($fd, $message, SWOOLE_WEBSOCKET_OPCODE_TEXT,
                                SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
                    }
                    else { 
                        $server->close($fd);
                    }
                }
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

    $app = new App('');
    $connection = $request->fd;
    $request = new SwooleRequest($request);

    App::setResource('request', function () use ($request) {
        return $request;
    });

    App::setResource('response', function () {
        return null;
    });

    App::setResource('project', function () { // TODO get project from query string
        return new Document();
    });

    App::setResource('user', function () { // TODO get user with JWT token
        return new Document();
    });

    $channels = array_flip($request->getQuery('channels', []));
    $jwt = $request->getQuery('jwt', '');
    $user = $app->getResource('user');
    $project = $app->getResource('project');
    $roles = ['*', 'user:'.$user->getId(), 'role:'.(($user->isEmpty()) ? Auth::USER_ROLE_GUEST : Auth::USER_ROLE_MEMBER)];

    /** @var Appwrite\Database\Document $user */
    /** @var Appwrite\Database\Document $project */

    \array_map(function ($node) use (&$roles) {
        if (isset($node['teamId']) && isset($node['roles'])) {
            $roles[] = 'team:'.$node['teamId'];

            foreach ($node['roles'] as $nodeRole) { // Set all team roles
                $roles[] = 'team:'.$node['teamId'].'/'.$nodeRole;
            }
        }
    }, $user->getAttribute('memberships', []));

    /**
     * Build Subscriptions Tree
     * 
     * [PROJECT_ID] -> 
     *      [ROLE_X] -> 
     *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
     *      [ROLE_Y] -> 
     *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
     */

    if(!isset($subscriptions[$project->getId()])) { // Init Project
        $subscriptions[$project->getId()] = [];
    }

    foreach ($roles as $key => $role) {
        if(!isset($subscriptions[$project->getId()][$role])) { // Add user first connection
            $subscriptions[$project->getId()][$role] = [];
        }
    
        foreach ($channels as $channel => $list) {
            $subscriptions[$project->getId()][$role][$channel][$connection] = true;
        }
    }

    $connections[$connection] = [
        'projectId' => $project->getId(),
        'roles' => $roles,
    ];

    var_dump($project->getId());
    var_dump($project->getAttribute('name'));
    var_dump($user->getId());
    var_dump($user->getAttribute('name'));

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
    $projectId = $connections[$fd]['projectId'] ?? '';
    $roles = $connections[$fd]['roles'] ?? [];

    foreach ($roles as $key => $role) {
        foreach ($subscriptions[$projectId][$role] as $channel => $list) {
            unset($subscriptions[$projectId][$role][$channel][$fd]); // Remove connection

            if(empty($subscriptions[$projectId][$role][$channel])) {
                unset($subscriptions[$projectId][$role][$channel]);  // Remove channel when no connections
            }
        }

        if(empty($subscriptions[$projectId][$role])) {
            unset($subscriptions[$projectId][$role]); // Remove role when no channels
        }
    }

    if(empty($subscriptions[$projectId])) { // Remove project when no roles
        unset($subscriptions[$projectId]);
    }

    unset($connections[$fd]);

    Console::info('Connection close: '.$fd);

    var_dump($subscriptions);
});

$server->start();