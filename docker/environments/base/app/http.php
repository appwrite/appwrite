<?php

require_once __DIR__.'/../vendor/autoload.php';

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Validator\Integer;
use Utopia\Validator\Mock;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Swoole\Process;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

ini_set('memory_limit','512M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

App::post('/')
    ->desc('Progressive app manifest file')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('command', null, new Mock(), 'Command to execute', false)
    ->param('timeout', 900, new Integer(), 'Timeout in seconds', false)
    ->action(function ($command, $timeout, $response) {
        /** @var Utopia\Swoole\Response $response */

        Console::log('executing command: ' . $command);

        $stdout = '';
        $stderr = '';
        $exitCode = Console::execute($command, '', $stdout, $stderr, $timeout);

        $response->json([
            'command' => $command,
            'timeout' => $timeout,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);
    }, ['response']);

App::error(function ($error, $utopia, $request, $response) {
    /** @var Exception $error */
    /** @var Utopia\App $utopia */
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\View $layout */
    /** @var Appwrite\Database\Document $project */

    $route = $utopia->match($request);

    if (php_sapi_name() === 'cli') {
        Console::error('[Error] Method: '.$route->getMethod());
        Console::error('[Error] URL: '.$route->getURL());
        Console::error('[Error] Type: '.get_class($error));
        Console::error('[Error] Message: '.$error->getMessage());
        Console::error('[Error] File: '.$error->getFile());
        Console::error('[Error] Line: '.$error->getLine());
    }

    $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

    switch ($error->getCode()) {
        case 400: // Error allowed publicly
        case 401: // Error allowed publicly
        case 402: // Error allowed publicly
        case 403: // Error allowed publicly
        case 404: // Error allowed publicly
        case 409: // Error allowed publicly
        case 412: // Error allowed publicly
        case 429: // Error allowed publicly
            $code = $error->getCode();
            $message = $error->getMessage();
            break;
        default:
            $code = 500; // All other errors get the generic 500 server error status code
            $message = 'Server Error';
    }

    $output = ((App::isDevelopment())) ? [
        'message' => $error->getMessage(),
        'code' => $error->getCode(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
        'trace' => $error->getTrace(),
        'version' => $version,
    ] : [
        'message' => $message,
        'code' => $code,
        'version' => $version,
    ];

    $response
        ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '0')
        ->addHeader('Pragma', 'no-cache')
        ->setStatusCode($code)
    ;

    $response->json($output);
}, ['error', 'utopia', 'request', 'response']);


$http = new Server("0.0.0.0", 80);

$payloadSize = 4000000; /* 4mb */

$http
    ->set([
        'open_http2_protocol' => true,
        // 'document_root' => __DIR__.'/../public',
        // 'enable_static_handler' => true,
        'http_compression' => true,
        'http_compression_level' => 6,
        'package_max_length' => $payloadSize,
    ])
;

$http->on('WorkerStart', function($serv, $workerId) {
    Console::success('Worker '.++$workerId.' started succefully');
});

$http->on('BeforeReload', function($serv, $workerId) {
    Console::success('Starting reload...');
});

$http->on('AfterReload', function($serv, $workerId) {
    Console::success('Reload completed...');
});

$http->on('start', function (Server $http) use ($payloadSize) {
    Console::success('Server started succefully (max payload is '.number_format($payloadSize).' bytes)');

    Console::info("Master pid {$http->master_pid}, manager pid {$http->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);

    $app = new App('America/New_York');
    
    try {
        $app->run($request, $response);
    } catch (\Throwable $th) {
        Console::error('[Error] Type: '.get_class($th));
        Console::error('[Error] Message: '.$th->getMessage());
        Console::error('[Error] File: '.$th->getFile());
        Console::error('[Error] Line: '.$th->getLine());

        if(App::isDevelopment()) {
            $swooleResponse->end('error: '.$th->getMessage());
        }
        
        $swooleResponse->end('500: Server Error');
    }
});

$http->start();