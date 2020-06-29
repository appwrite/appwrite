<?php

require_once __DIR__.'/../vendor/autoload.php';

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Process;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Registry\Registry;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$http = new Server("localhost", 9501);
echo 'test';
$http
    ->set([
        'open_http2_protocol' => true,
        'document_root' => __DIR__ . '/../public',
        'enable_static_handler' => true,
        'timeout' => 4,
    ])
;

$http->on('WorkerStart', function($serv, $workerId) {
    Console::success('Worker '.$workerId.' started succefully');
});

$http->on('BeforeReload', function($serv, $workerId) {
    Console::success('Starting reload...');
});

$http->on('AfterReload', function($serv, $workerId) {
    Console::success('Reload completed...');
});

$http->on('start', function (Server $http) {
    Console::success('Server started succefully');
    printf("x master pid %d, manager pid %d\n", $http->master_pid, $http->manager_pid);

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        echo "Stop by Ctrl+C\n";
        $http->shutdown();
    });
});

// $register = new Registry();
// $utopia = new App('Asia/Tel_Aviv');
// /**
//  * @var $request Request
//  */
// $request &= null;
// $response &= null;

// include 'init.php';
// include 'app.php';

$counter = 0;

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use (&$counter) {
    // global $request, $response, $utopia;
    // $request = new Request($swooleRequest);
    // $response = new Response($swooleResponse);
    $swooleResponse->end('test: '.$counter++);
    try {
        //$utopia->run($request, $response);
    } catch (\Throwable $th) {
        var_dump($th->getMessage());
        var_dump($th->getFile());
        var_dump($th->getLine());
        $swooleResponse->end('error: '.$th->getMessage());
    }
});

$http->start();
