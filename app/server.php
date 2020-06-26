<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/../vendor/autoload.php';

use Anews\Ads;
use UtopiaSwoole\Request;
use UtopiaSwoole\Response;
use Utopia\CLI\Console;
use Swoole\WebSocket\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Frame;

$server = new Server('localhost', 9501, SWOOLE_BASE);

$server
    ->set([
        'open_http2_protocol' => true,
        'document_root' => __DIR__ . '/../public',
        'enable_static_handler' => true,
        'timeout' => 4,
    ])
;

$server->on('WorkerStart', function($serv, $workerId) {
    Console::success('Server started succefully');
});

$server->on('BeforeReload', function($serv, $workerId) {
    Console::success('Starting reload...');
});

$server->on('AfterReload', function($serv, $workerId) {
    Console::success('Reload completed...');
});

// http && http2
$server->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    
});

// websocket
$server->on('message', function (Server $server, Frame $frame) {
    $server->push($frame->fd, 'Hello ' . $frame->data);
});

$server->start();