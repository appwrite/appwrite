<?php

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Utopia\CLI\Console;

/**
 * TODO List
 * 
 * - Abuse Control / x mesages per connection
 * - CORS Validation
 * - Limit payload size
 * - Message structure: { status: "ok"|"error", event: EVENT_NAME, data: <any arbitrary data> }
 * - JWT Authentication (in path / or in message)
 * 
 * 
 * - https://github.com/hhxsv5/php-sse
 * - https://github.com/shuixn/socket.io-swoole-server
 */

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL);

$server = new Server("0.0.0.0", 80);

// $redis = new \Swoole\Coroutine\Redis();
// $redis->on('message', function(\Swoole\Coroutine\Redis $redis, $rs) use ($server) {
//         var_dump($server);
//         echo 'redis got message' . PHP_EOL;
//         var_dump($rs);
//         $server->send(1, $rs);
// });
// $redis->connect('redis', 6379, function(\Swoole\Coroutine\Redis $redis, $result){

//         echo 'connected to redis' . PHP_EOL;
//         $redis->subscribe('chat');
// });

$server->on("workerStart", function ($server, $workerId) {
    Console::success('Worker '.++$workerId.' started succefully');

    $redis = new Redis();
    $redis->connect('redis', 6379);

    $redis->subscribe(['realtime'], function($redis, $channel, $message) use ($server, $workerId) {
        var_dump($redis, $channel, $message);

        $message = 'Message from worker #'.$workerId.'; '.$message;

        foreach($server->connections as $fd) {
            if ($server->exist($fd) && $server->isEstablished($fd)) {
                $server->push($fd, $message, SWOOLE_WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
            }
            else { 
                $server->close($fd); 
            }
        }
    });
});

$server->on('BeforeReload', function($serv, $workerId) {
    Console::success('Starting reload...');
});

$server->on('AfterReload', function($serv, $workerId) {
    Console::success('Reload completed...');
});

// $process = new Process(function($process) use ($server) {
//     while (true) {
//         $msg = $process->read();

//         foreach($server->connections as $fd) {
//             if ($server->exist($fd) && $server->isEstablished($fd)) {
//                 $server->push($fd, json_encode(['hey there']), SWOOLE_WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
//             }
//         }

//         sleep(10);
//     }
// });

// $server->addProcess($process);

$server->on("start", function (Server $server) {
    Console::success('Server started succefully');
});

$server->on('open', function(Server $server, Swoole\Http\Request $request) {
    echo "connection open: {$request->fd}\n";

    foreach($server->connections as $fd) {
        if ($server->exist($fd) && $server->isEstablished($fd)) {
            $server->push($fd, json_encode(['hey there', count($server->ports[0]->connections), ]), SWOOLE_WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
        }
    }

    $server->push($request->fd, json_encode(["hello", time()]));
});

$server->on('message', function(Server $server, Frame $frame) {
    echo "received message: {$frame->data}\n";
    $server->push($frame->fd, json_encode(["hello, worker_id:".$server->getWorkerId(), time()]));
});

$server->on('close', function(Server $server, int $fd) {
    echo "connection close: {$fd}\n";
});

$server->start();