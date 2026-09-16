# Utopia Lock

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/lock`](https://github.com/utopia-php/monorepo/tree/main/packages/lock) — please open issues and pull requests there.

[![Build Status](https://github.com/utopia-php/lock/actions/workflows/tests.yml/badge.svg)](https://github.com/utopia-php/lock/actions/workflows/tests.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/lock.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Lock library is a simple and lite library for coordinating access to shared resources in PHP applications. This library provides a single interface backed by four lock primitives — mutex, semaphore, file and distributed — for serialising work across coroutines, processes, hosts and clusters. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:
```bash
composer require utopia-php/lock
```

## System requirements

Utopia Lock requires PHP 8.3 or later. We recommend using the latest PHP version whenever possible.

The `Mutex` and `Semaphore` primitives require the [Swoole](https://github.com/swoole/swoole-src) extension (>=6.0). The `Distributed` primitive requires the [Redis](https://github.com/phpredis/phpredis) extension.

## Features

### Supported primitives

| Primitive     | Scope                           | Backing                              | Use when                                                |
| ------------- | ------------------------------- | ------------------------------------ | ------------------------------------------------------- |
| `Mutex`       | Single worker, coroutine-scoped | `Swoole\Coroutine\Channel(1)`        | Serialising access to an in-memory resource per worker  |
| `Semaphore`   | Single worker, coroutine-scoped | `Swoole\Coroutine\Channel($permits)` | Capping concurrent access (e.g. outbound request pool)  |
| `File`        | Single host, cross-process      | `flock()`                            | Cron guards, shared-filesystem coordination             |
| `Distributed` | Cross-host, cluster-wide        | Redis `SET NX EX` + Lua release      | Coordinating workers across machines                    |

## Usage

### The interface

All primitives implement the same `Utopia\Lock\Lock` interface:

```php
<?php

namespace Utopia\Lock;

interface Lock
{
    public function acquire(float $timeout = 0.0): bool;
    public function tryAcquire(): bool;
    public function release(): void;

    /** @template T; @param callable(): T $callback; @return T */
    public function withLock(callable $callback, float $timeout = 0.0): mixed;
}
```

`withLock()` throws `Utopia\Lock\Exception\Contention` if the lock cannot be acquired before the timeout expires.

### Mutex

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Coroutine;
use Utopia\Lock\Mutex;
use function Swoole\Coroutine\run;

$mutex = new Mutex();

run(function () use ($mutex): void {
    for ($i = 0; $i < 8; $i++) {
        Coroutine::create(function () use ($mutex, $i): void {
            $mutex->withLock(function () use ($i): void {
                echo "worker {$i} holds the mutex\n";
                Coroutine::usleep(10_000);
            }, timeout: 5.0);
        });
    }
});
```

### Semaphore

```php
<?php

use Utopia\Lock\Semaphore;

$semaphore = new Semaphore(permits: 3);

$semaphore->withLock(function () {
    // at most three coroutines can be here at once
});
```

### File

```php
<?php

use Utopia\Lock\File;

$lock = new File('/var/run/my-daily-job.lock');

if (! $lock->tryAcquire()) {
    exit("another copy is already running\n");
}

try {
    runDailyJob();
} finally {
    $lock->release();
}
```

Pass `LOCK_SH` for shared (reader) mode:

```php
$readers = new File('/tmp/cache.lock', LOCK_SH);
$readers->withLock(fn () => readCache(), timeout: 1.0);
```

### Distributed

```php
<?php

use Redis;
use Utopia\Lock\Distributed;

$redis = new Redis();
$redis->connect('redis.internal', 6379);

$lock = new Distributed($redis, key: 'jobs:rebuild-index', ttl: 120);

$lock->setLogger(fn (string $message) => \error_log($message));

$lock->withLock(function () {
    rebuildSearchIndex();
}, timeout: 30.0);
```

Release is atomic: a Lua script verifies the lock value still matches this instance's token before deleting, so a lock that expires and is re-acquired elsewhere is never released by accident.

The holder can delegate token-guarded commands to another Redis connection. This is useful when a background coroutine refreshes the lease while the action continues using its own connection:

```php
$token = $lock->token();
if ($token === null) {
    throw new RuntimeException('The lock is not held');
}

$refresher = (new Distributed($otherRedis, key: 'jobs:rebuild-index', ttl: 120))
    ->adopt($token);

$refresher->refresh();
```

An adopted token has the same authority as the original instance, including the ability to release the lease. A refresh or release remains safe after the lease expires because the Lua script only acts while the adopted token still matches the value on the key.

### Exception handling

```php
<?php

use Utopia\Lock\Exception;
use Utopia\Lock\Exception\Contention as ContentionException;

try {
    $lock->withLock($work, timeout: 5.0);
} catch (ContentionException) {
    // timed out trying to acquire
} catch (Exception) {
    // base class, catches anything thrown by this package
}
```

## Tests

To run all unit tests, use the following Docker command:

```bash
docker compose exec tests vendor/bin/phpunit --configuration phpunit.xml tests
```

To run static code analysis, use the following PHPStan command:

```bash
docker compose exec tests vendor/bin/phpstan analyse --memory-limit=512M
```

## Security

We take security seriously. If you discover any security-related issues, please email security@appwrite.io instead of using the issue tracker.

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We truly ❤️ pull requests! If you wish to help, you can learn more about how you can contribute to this project in the [contribution guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
