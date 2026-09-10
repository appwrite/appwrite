# Utopia Queue

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/queue`](https://github.com/utopia-php/monorepo/tree/main/packages/queue) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/queue.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Queue is a powerful Queue library. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/queue
```

Init in your application:

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Queue;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;

$createConsumer = static function (): Consumer {
    return new Queue\Broker\Redis(
        receive: new Queue\Connection\Redis('redis'),
        commands: new Queue\Connection\Redis('redis'),
    );
};

// Adapter is transport only (process count + namespace). Queue and concurrency
// are defined on job().
$adapter = new Queue\Adapter\Swoole($createConsumer, workerNum: 12);
$server = new Queue\Server($adapter);

$server
    ->job('my-queue', 1)
    ->inject('message')
    ->action(function (Message $message) {
        var_dump($message);
    });

$server
    ->error()
    ->inject('error')
    ->action(function ($error) {
        echo $error->getMessage() . PHP_EOL;
    });

$server
    ->workerStart()
    ->action(function () {
        echo "Worker Started" . PHP_EOL;
    });

$server->start();

// Publish with the same broker API
$publisher = new Queue\Broker\Redis(
    receive: new Queue\Connection\Redis('redis'),
    commands: new Queue\Connection\Redis('redis'),
);
$publisher->publish(new Queue\Queue('my-queue'), [
    'type' => 'test_number',
    'value' => 123,
]);
```

## NATS JetStream broker

`Broker\Nats` runs the queue on [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream) instead of Redis, giving durable, server-persisted jobs and native at-least-once redelivery. It implements the same `Publisher\Synchronous` + `Consumer` interfaces as `Broker\Redis`, so it drops into the same `Server` and adapter setup.

```php
use Utopia\NATS\Connection;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Queue;

// Pass a Closure so each forked worker / pooled lease resolves its own connection:
// a socket cannot survive a fork, and a pooled lease needs one of its own.
$broker = new Nats(
    fn (): Connection => Connection::connect('nats://127.0.0.1:4222'),
    ackWait: 30.0,   // redelivery window if a worker dies before commit()
    maxDeliver: 5,   // delivery attempts before a message is dead-lettered
);

$broker->publish(new Queue('my-queue'), ['type' => 'test_number', 'value' => 123]);
```

Each queue is a WorkQueue-retention stream (a message is removed once acknowledged) with a companion dead stream. `commit()` acknowledges a message, `reject()` schedules redelivery until `maxDeliver` and then dead-letters, `retry()` re-drives the dead stream onto the queue, and `getQueueSize()` reports pending (consumer `num_pending`) or failed (dead stream) counts. `reap()` is a no-op — redelivery after `ackWait` reclaims jobs stranded by a dead worker. Requires [`utopia-php/nats`](https://github.com/utopia-php/nats).

### Concurrency

`Broker\Nats` is wired the way `Broker\Redis` is: a connection dedicated to the blocking receive, plus a second, lock-guarded connection carrying the commands. One NATS connection is one socket behind one shared read pump, and driving it from two coroutines does not degrade — Swoole ends the worker on the first overlap:

```
Swoole\Error: Socket#5 has already been bound to another coroutine#2,
reading of the same socket in coroutine#3 at the same time is not allowed
```

| Connection | Carries | Driven by |
|---|---|---|
| receive | fetch, provisioning, the dead-letter advisory, publishing | the consume loop |
| commands | `commit()`, `reject()`, `extend()`, `getQueueSize()` | the handler and telemetry coroutines |

A JetStream acknowledgment is a message published to the delivery's reply subject, so it does not have to leave on the connection that fetched the message. Rebinding it moves the whole per-message acknowledgment path off the receive socket, so an acknowledgment raised while the loop is parked in a fetch is a round trip rather than a wait. Each connection has one lock and the two are never nested, so they cannot deadlock.

So `job('…', N)` above one is safe on NATS, and handlers scale without the socket becoming the serialisation point. Drain rate over 1 → 8 coroutines, measured with `benchmarks/coroutines.php` against a NATS 2.12 cluster and Redis on one host, median of five runs:

| coroutines | `Broker\Redis` | `Broker\Nats` |
|---|---|---|
| 1 | 1,468 | 2,087 |
| 2 | 2,389 | 2,856 |
| 8 | 2,499 | 2,831 |

Rates are host-bound and only meaningful against each other. The shape is the point: both scale, where a single shared socket is flat regardless of the cap, because a fetch-then-ack cost that cannot overlap is fixed per message.

The commands connection is opened lazily on the first acknowledgment, so a publisher-only broker never pays for a socket it will not use — and a broker built from a live connection rather than a Closure factory serves both roles from that one socket, sharing one lock.

Still pass a Closure factory rather than a live connection when the worker forks or reconnects per worker, and do not hand the same connection to anything outside the broker.

`Consumer\Exclusive` stays for consumers built outside this package that drive one socket without serialising it. `Server::start()` refuses a job registered above one coroutine on a consumer carrying that marker, because it would crash exactly as above; scale one of those with replicas rather than coroutines.

The marker is readable by callers too, which matters when concurrency comes from configuration rather than code — there, a refusal at `start()` is a worker that will not boot:

```php
if ($coroutines > 1 && $consumer instanceof Consumer\Exclusive) {
    $coroutines = 1; // and log why
}
```

Clamping on the marker rather than on a transport name or a version also means the cap starts applying by itself once the consumer stops carrying it.

## Background publishing

`Broker\Background` wraps a synchronous publisher with a bounded in-process buffer. `enqueue()` hands work to reader coroutines, applying back pressure when the buffer is full; `publish()` bypasses the buffer and remains synchronous. Call `shutdown()` to drain accepted messages before the process exits.

```php
use Swoole\Coroutine;
use Utopia\Queue\Broker\Background;

$publisher = new Background(
    $broker,
    capacity: 512,
    coroutines: 1,
    timeout: 0.1,
    maxBatchInterval: 0.01,
    maxBatchSize: 100,
);

Coroutine\run(function () use ($publisher, $queue, $payload): void {
    $publisher->start();
    $publisher->enqueue($queue, $payload);
    $publisher->shutdown();
});
```

When the configured timeout expires, `enqueue()` throws `Publisher\BufferFullException`. Set `maxBatchInterval` and `maxBatchSize` together to opt into batching; a batch flushes when either limit is reached, and messages with different queues or priorities are never mixed. More than one reader coroutine requires a concurrency-safe wrapped publisher, such as `Broker\Pool`; it also gives up FIFO dispatch order.

## Multiple queues in one process

Call `job($queue, $maxCoroutines)` once per queue. The adapter stays the same — only the jobs change. Each job gets its own consume loop and concurrency cap, so `v1-functions` at 8 does not share a pool with `database_db_main` at 1.

```php
use Utopia\Queue;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;

$createConsumer = static function (): Consumer {
    return new Queue\Broker\Redis(
        receive: new Queue\Connection\Redis('redis'),
        commands: new Queue\Connection\Redis('redis'),
    );
};

$adapter = new Queue\Adapter\Swoole($createConsumer, workerNum: 1);
$server = new Queue\Server($adapter);

$server
    ->job('v1-functions', 8)
    ->inject('message')
    ->action(function (Message $message) {
        // Handle a functions job
    });

$server
    ->job('database_db_main', 1)
    ->inject('message')
    ->action(function (Message $message) {
        // Handle a databases job
    });

// Each consume loop calls the factory so blocking receive does not share a connection.

$server->error()->inject('error')->action(function ($error) {
    echo $error->getMessage() . PHP_EOL;
});

$server->start();
```

Publish synchronously to each queue by name (`$publisher->publish(new Queue('v1-functions'), $payload)`, etc.).

With [`utopia-php/platform`](https://github.com/utopia-php/platform), pass `workers` and `jobs` (`queue` / `maxCoroutines` per action) into `Platform::init(Service::TYPE_WORKER, …)`.

## System requirements

Utopia Queue requires PHP 8.5 or later and recommends the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
