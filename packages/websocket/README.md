# Utopia WebSocket

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/websocket`](https://github.com/utopia-php/monorepo/tree/main/packages/websocket) — please open issues and pull requests there.

[![Build Status](https://travis-ci.com/utopia-php/system.svg?branch=main)](https://travis-ci.com/utopia-php/websocket)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/websocket.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia WebSocket is a simple and lite abstraction layer around a WebSocket server. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:
```bash
composer require utopia-php/websocket
```

Init in your application:
```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\WebSocket;

$adapter = new WebSocket\Adapter\Swoole();
$adapter->setPackageMaxLength(64000);

$server = new WebSocket\Server($adapter);
$server->onStart(function () {
    echo "Server started!";
});
$server->onWorkerStart(function (int $workerId) {
    echo "Worker {$workerId} started!";
});
$server->onOpen(function (int $connection, $request) {
    echo "Connection {$connection} established!";
});
$server->onMessage(function (int $connection, string $message) {
    echo "Message from {$connection}: {$message}";
});
$server->onClose(function (int $connection) {
    echo "Connection {$workerId} closed!";
});

$server->start();
```

## Slow clients

The Swoole adapter allows sends to wait for a full output buffer to drain, with a five-second timeout for each wait. This prevents pending sends from waiting indefinitely after a client disconnects, including the failure described in [Swoole issue #6196](https://github.com/swoole/swoole-src/issues/6196).

Configure the timeout in the constructor:

```php
$adapter = new WebSocket\Adapter\Swoole(sendTimeout: 2.0); // Seconds; must be finite and greater than zero.
```

When a push fails, including on timeout, the adapter resets the connection and discards queued output. Clients must reconnect and refresh application state to recover missed events. Brief stalls can recover if the buffer drains before the timeout.

The timeout applies to each Swoole wait, not the total lifetime of a send; a retry can start another wait. It does not cap pending bytes or change Swoole's output buffer size. Applications sending large bursts should also limit queued work or reduce update frequency.

## System requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Testing

```sh
composer test       # unit tests, with no servers
composer test:e2e   # starts local Swoole and Workerman fixture servers
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
