# Utopia Pools

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/pools`](https://github.com/utopia-php/monorepo/tree/main/packages/pools) — please open issues and pull requests there.

[![Build Status](https://travis-ci.com/utopia-php/pools.svg?branch=main)](https://travis-ci.com/utopia-php/pools)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/pools.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia pools library is simple and lite library for managing long living connection pools. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free, and can be used as standalone with any other PHP project or framework.

## Concepts

* **Pool** - A list of long living connections. You can pop connections out and use them and push them back to the pool for reuse.
* **Connection** - An object that holds a long living database or other external connection in a form of a resource. PDO object or a Redis client are examples of resources that can be used inside a connection.
* **Group** - A group of multiple pools.

## Getting started

Install using Composer:
```bash
composer require utopia-php/pools
```
## Examples

```php
use PDO;
use Utopia\Pools\Adapter\Swoole;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

$pool = new Pool(
    adapter: new Swoole,
    name: 'mysql-pool',
    size: 8,
    init: fn () => new PDO('mysql:host=127.0.0.1;dbname=test;charset=utf8mb4', 'root', ''),
    timeout: 2.0,
);

// Preferred: the pool hands the resource to the callback and takes it back
// afterwards, discarding it if the callback threw.
$rows = $pool->use(fn (PDO $pdo) => $pdo->query('SELECT 1')->fetchAll());

// Manual lifetime, when `use()` does not fit.
$connection = $pool->pop();
$connection->id;         // "mysql-pool-6885a1f2c4e19"
$connection->resource;   // the PDO instance
$connection->reclaim();  // return it for reuse
$connection->destroy();  // or discard it and free the capacity

$pool->count();     // connections a caller could still obtain
$pool->isEmpty();   // none available without one being returned first
$pool->isFull();    // nothing checked out

$group = new Group;
$group->add($pool);
$group->get('mysql-pool');
$group->use(['mysql-pool'], fn (PDO $pdo) => $pdo->query('SELECT 1'));
```

## Acquiring connections

`timeout` bounds how long `pop()` waits for a connection to become available.
The pool creates one when it has spare capacity, otherwise it waits for one to
come back, and it throws once the budget is spent. There is one number for
waiting, and it is the number a caller experiences.

It does not cover time spent inside `init`. The pool cannot interrupt a blocking
connect, so give `init` its own connect timeout if you need the total bounded —
for PDO that is `PDO::ATTR_TIMEOUT`.

If creating a connection fails, that exception propagates untouched, so callers
keep the original type to act on. The pool does not retry and does not fall back
to waiting, because waiting for another caller's connection after your own
create failed is a retry in disguise. Own retries in `init`:

```php
$pool = new Pool(
    adapter: new Swoole,
    name: 'mysql-pool',
    size: 8,
    init: fn () => $breaker->call(fn () => new PDO(/* ... */)),
    timeout: 2.0,
);
```

Compose [`utopia-php/circuit-breaker`](https://github.com/utopia-php/circuit-breaker)
there rather than counting attempts, because a breaker carries state between
calls and an attempt counter cannot.

## Upgrading from 1.x

Configuration is constructor-only, and the retry knobs are gone.

- `timeout` is a required constructor argument, and it is the pool's only
  timeout. It replaces `setRetryAttempts()`, `setRetrySleep()` and
  `setSynchronizationTimeout()`. Pass the total wait you want, in seconds.
- `setReconnectAttempts()` and `setReconnectSleep()` are removed with no
  replacement. Wrap `init` if you want to retry a failed connect.
- `setTelemetry()` is removed. Pass `telemetry:` to the constructor.
- `Group::setReconnectAttempts()`, `Group::setReconnectSleep()` and
  `Group::setTelemetry()` are removed. Pools arrive configured.
- `Pool::getName()` and `Pool::getSize()` are now the `$name` and `$size`
  properties. The `getReconnectAttempts()`, `getReconnectSleep()`,
  `getRetryAttempts()`, `getRetrySleep()` and `getSynchronizationTimeout()`
  getters are removed.
- `Connection` is `final readonly`. `getID()`, `getResource()` and `getPool()`
  become the `$id` and `$resource` properties; `getPool()` has no replacement.
  `setID()`, `setResource()` and `setPool()` are removed.
- `Connection::reclaim()` and `Connection::destroy()` return `void` instead of
  the pool.
- `Pool::release()` no longer takes a `$start` timestamp. The pool measures use
  time itself.
- `Adapter::pop()` takes a `float` timeout. Custom adapters need the wider type.
- `init` is typed `Closure` rather than `callable`. Pass a closure, or wrap a
  string or array callable with `Closure::fromCallable()`.

Two behaviour changes worth checking against your own code:

- A failed connect used to be absorbed by an internal retry and can now reach
  the caller on the first attempt, as whatever type `init` threw rather than a
  pool exception.
- `destroy()` no longer creates the replacement connection itself. Capacity is
  freed immediately and the next `pop()` creates one, so `destroy()` cannot
  block on a connect or fail for reasons unrelated to the connection you asked
  it to discard.

## System requirements

Utopia pools requires PHP 8.4 or later. We recommend using the latest PHP version whenever possible.

## Tests

Run the test suite and static analysis from the monorepo root:

```bash
bin/monorepo test pools
bin/monorepo check pools
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
