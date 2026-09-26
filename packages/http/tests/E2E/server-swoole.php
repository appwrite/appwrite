<?php

declare(strict_types=1);

require_once __DIR__ . '/init.php';

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Http\Adapter\Swoole\Mode;
use Utopia\Http\Adapter\Swoole\Server;
use Utopia\Http\Http;

Http::delete('/swoole-test')
    ->inject('swooleRequest')
    ->inject('swooleResponse')
    ->action(function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
        $method = $swooleRequest->getMethod();
        $swooleResponse->header('Content-Type', 'text/plain');
        $swooleResponse->header('Cache-Control', 'no-cache');
        $swooleResponse->setStatusCode(200);
        $swooleResponse->write($method);
        $swooleResponse->end();
    });

$server = new Server('0.0.0.0', '80', Mode::HYPERLOOP_B);
$http = new Http($server, 'UTC');

$http->start();
