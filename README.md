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

Each queue is a WorkQueue-retention stream (a message is removed once acknowledged) with a companion dead stream. `commit()` acknowledges a message, `reject()` schedules redelivery until `maxDeliver` and then dead-letters — unless the handler declared the failure permanent, which dead-letters it at once — `retry()` re-drives the dead stream onto the queue, and `getQueueSize()` reports pending (consumer `num_pending`) or failed (dead stream) counts. `reap()` is a no-op — redelivery after `ackWait` reclaims jobs stranded by a dead worker. Requires [`utopia-php/nats`](https://github.com/utopia-php/nats).

### Shaping a queue

Beyond redelivery, the constructor carries what a queue holds and how much of it is in flight. `maxMsgSize`, `maxMsgs`, `maxBytes` and `discard` bound the work stream — `discard: DiscardPolicy::New` turns a full stream into backpressure, where the publish fails rather than the oldest message being dropped, and it needs one of the two limits to apply to. `maxMsgSize` also applies to the dead stream, so a message the queue accepted can always be dead-lettered. `maxAckPending`, `maxWaiting` and `inactiveThreshold` shape the worker consumers; `maxAckPending` is the one to set, because JetStream's default of 1000 hands out far more than a worker can hold and the surplus spends its `ackWait` window waiting to be picked up. `maxAge` states the message TTL directly instead of deriving it from the queue's `jobTtl`, which each side builds separately.

Size `maxAckPending` above the worker's concurrency, with room to spare. The ceiling is per consumer, so replicas of a worker share one, and it counts messages parked in `backoff` as well as messages being worked — those are asleep waiting for the next attempt, and each holds a slot for the whole of it. A ceiling sized at the handler count is therefore filled by exactly as many failures as there are handlers, after which the consumer has no slot to deliver into and the queue stops moving behind workers that look idle. `Server::start()` refuses a coroutine cap at or above the ceiling for that reason, the way it refuses concurrency on a `Consumer\Exclusive` consumer.

### Permanent failures

Redelivery is the right answer to a timeout, a leader election or a restart. It is the wrong answer to a credential the server rejected or a payload naming a resource that does not exist: every attempt fails the same way, and on JetStream each attempt holds one of the consumer's `maxAckPending` slots for the whole of its backoff. Enough of those and one bad payload takes the queue down with it.

A handler ends a message's life by throwing `PermanentFailure`:

```php
use Utopia\Queue\PermanentFailure;

$server->job('v1-region-manager')->action(function (array $payload) use ($regions) {
    $hostname = $regions[$payload['region']] ?? null;
    if ($hostname === null) {
        // No attempt will find it. Dead-letter now rather than in 21 minutes.
        throw new PermanentFailure("Region hostname not configured: {$payload['region']}");
    }
    // ...
});
```

The broker dead-letters it on the first failure instead of scheduling the next attempt: on NATS the delivery is terminated and the payload copied to the dead stream, on Redis it goes to the dead list rather than the failed list the `retry()` sweep reads. Either way the payload is still there to inspect, and `retry()` re-drives it once the underlying fault is fixed. A handler that cannot reach the throw site — an exception type owned by a library, or a classification made elsewhere — calls `$message->terminal()` instead and throws the exception it already had. The failure is reported to the error hooks either way.

Keep it to failures that are permanent for this payload. A database that is down is what the redelivery budget is for; dead-lettering it converts an outage into lost work.

### Provisioning

By default a broker creates a queue's streams and consumers when it first touches them, and brings an existing queue in line with its own settings. That is what a queue's producer and consumer want, and what any other process should not do: a maintenance task built with different knobs rewrites the fleet's configuration just by using the queue.

```php
use Utopia\Queue\Broker\Provisioning;

$maintenance = new Nats($source, provisioning: Provisioning::Require);
$maintenance->retry(new Queue('my-queue'));   // moves messages, changes no configuration
```

`Provisioning::Require` uses what is already provisioned and refuses when it is absent. The boundary is stream configuration plus the two worker consumers; the consumer `retry()` reads the dead stream through is still created, because it carries none of the settings a running fleet depends on.

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

#### Which axis to scale

`job('…', N)` adds handler coroutines inside one process; replicas add processes. They are not interchangeable, and which one helps is decided by the handler, not by the broker. Measured with `tests/bench/run.sh` (400 messages, median of three, same host):

| handler | 1 process x 1 coroutine | 1 x 4 (coroutines) | 4 x 1 (processes) |
|---|---|---|---|
| waits 25ms (`io`) | 35 msg/s | **143** (4.0x) | 140 (4.0x) |
| hashes (`cpu`) | 72 msg/s | 72 (**1.0x**) | **266** (3.7x) |
| both (`mixed`) | 46 msg/s | 98 (2.1x) | 190 (4.1x) |

A handler that waits is absorbed by coroutines, because the wait yields. A handler that computes is not: PHP runs one coroutine at a time, so raising the cap buys nothing and only processes help. Most jobs are somewhere between, and scale partially on both.

`Broker\Redis` and `Broker\Nats` are within noise of each other in every cell above, which is the point worth remembering: at any realistic handler cost the broker is not the constraint, so pick the axis that matches the work rather than the transport.

`Consumer\Exclusive` stays for consumers built outside this package that drive one socket without serialising it. `Server::start()` refuses a job registered above one coroutine on a consumer carrying that marker, because it would crash exactly as above; scale one of those with replicas rather than coroutines.

The marker is readable by callers too, which matters when concurrency comes from configuration rather than code — there, a refusal at `start()` is a worker that will not boot:

```php
if ($coroutines > 1 && $consumer instanceof Consumer\Exclusive) {
    $coroutines = 1; // and log why
}
```

Clamping on the marker rather than on a transport name or a version also means the cap starts applying by itself once the consumer stops carrying it.

### Batched receive

A `receive()` costs at least one round trip, and on a queue whose handler is cheap that round trip *is* the work. `job('…', $coroutines, batch: N)` lets one receive claim up to N messages:

```php
$server
    ->job('v1-stats-usage', 16, batch: 16)
    ->action(function (array $payload) { /* … */ });
```

Only the first message of a batch waits. On Redis the whole batch is one `BLMPOP`, which blocks for that first message exactly as `BRPOP` did and then takes whatever is already behind it, so a queue holding one message answers at once rather than waiting for company that is not coming. That command is Redis 7.0; a batch of one still uses `BRPOP`, so the floor applies to the queues that asked for a batch and not to every deployment.

The batch is bounded by free handler slots, and `Server::start()` refuses a batch larger than the coroutine cap. That bound is the whole safety argument: a claimed message is out of the broker and invisible to every idle replica, so claiming more than this worker can start would be taking work away from a worker that could have run it. At `job('…', 1)` every batch is one message, which is why raising the coroutine count comes first.

Each message keeps its own acknowledgment — there is no batch commit — so one poison message in a batch of sixteen is rejected on its own and the other fifteen are unaffected.

Consumers expose `receive(Queue $queue, int $timeout, int $n = 1): array`. The result is always a list of up to `$n` claimed messages, or an empty list on timeout. Each returned message needs its own `commit()` or `reject()`. Counts below one use one.

This folds `receiveBatch()` into `receive()` and removes `Consumer\Batched`. To migrate a single-message caller, use `$consumer->receive($queue, $timeout)[0] ?? null`; batch callers use `$consumer->receive($queue, $timeout, $n)`. Custom consumers must implement the same list-returning contract.

`Broker\Redis` shares processing-list and counter writes across the batch. Each message still gets its own payload and heartbeat keys. `Broker\Nats` fetches available messages without waiting for the batch to fill.

The following measurements predate claim heartbeats and are historical comparisons.

Whether that becomes throughput depends entirely on whether those commands were the constraint. Ratio of batch-16 to batch-1, 16 coroutines, 10,000 messages, two passes in opposite order:

| the handler | `Broker\Redis` | `Broker\Nats` |
|---|---|---|
| does nothing | 1.30-1.36x | 2.03-2.31x |
| computes for 0.14ms | 1.18-1.23x | 1.45-1.51x |
| computes for 1ms | 1.04-1.08x | 1.08-1.10x |
| waits 1ms | 1.23-1.25x | 1.62-1.63x |
| waits 2ms | **0.92-0.96x** | **0.80-0.82x** |

Read the axis as "is the receive loop the bottleneck", not "is the handler fast". A handler that computes does not yield, so PHP runs one at a time and throughput is capped by the handler no matter what the broker does — 1ms of computation leaves nothing for a batch to win. A handler that waits yields, so sixteen of them overlap and the loop has to feed all sixteen; there the broker is the constraint and batching pays.

And past the point where it pays, **it costs**: at 2ms of waiting the batch is a 4-20% loss, reproduced in both passes. Sixteen messages claimed together finish together, and their sixteen acknowledgments then queue behind one lock, so a smooth pipeline turns into convoys. The `io`, `cpu` and `mixed` rows in the table above are all in this region, which is why none of them move.

So the default of 1 is not a conservative default, it is the right one for most queues. Raise it for a queue that is demonstrably broker-bound — cheap handlers, deep backlog — and measure the queue you are raising it for, because the same change makes a handler-bound queue slower.

## Message encoding

Both brokers write a message through a `Codec`, and default to `Codec\Json` — the format every release so far has put on the wire.

```php
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Codec\Compat;
use Utopia\Queue\Codec\Igbinary;

$broker = new Broker(
    receive: $receive,
    commands: $commands,
    codec: new Compat(new Igbinary()),
);
```

`Codec\Igbinary` stores envelopes as binary through the `igbinary` extension: smaller on the wire, and several times faster to read. A queue pays the decode once per message per delivery, so a redelivered message pays it again.

Changing the codec of a queue that already holds messages needs `Codec\Compat`. It reads either format and writes the one you give it, because the messages on the list, the jobs in flight, and the dead letters nobody has drained yet were all written by yesterday's release. Deploy it writing JSON first, then give it the `Igbinary` writer, and leave it reading both afterwards — a dead-letter list has no deadline.

On NATS every published message carries a `Content-Type` header naming the format it is in — `application/json` or `application/vnd.php.igbinary` — so a consumer can switch on the header instead of inspecting the payload. `Codec\Compat` still reads by sniffing the bytes, because messages written before this release carry no header. Redis lists have nowhere to put one, so there the sniff is the whole answer.

Bytes that no codec can read are parked rather than dropped or retried: the Redis broker moves them to `<namespace>.poison.<queue>`, and the NATS broker publishes them to the queue's dead subject and terminates the delivery. The pop has already taken them off the queue by the time anything can tell, so the only question is where they go — and a message every worker chokes on must not sit at the head of the queue.

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
