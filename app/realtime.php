<?php

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

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

$server = new Server("0.0.0.0", 80);

$server->on("start", function (Server $server) {
    echo "Swoole WebSocket Server has started at http://127.0.0.1:3000\n";
});

$server->on('open', function(Server $server, Swoole\Http\Request $request) {
    echo "connection open: {$request->fd}\n";
    // $server->tick(1000, function() use ($server, $request) {
    //     $server->push($request->fd, json_encode(["hello", time()]));
    // });

    var_dump($request->header);
    $server->push($request->fd, json_encode(["hello", time()]));
});

$server->on('message', function(Server $server, Frame $frame) {
    echo "received message: {$frame->data}\n";
    $server->push($frame->fd, json_encode(["hello", time()]));
});

$server->on('close', function(Server $server, int $fd) {
    echo "connection close: {$fd}\n";
});

$server->start();