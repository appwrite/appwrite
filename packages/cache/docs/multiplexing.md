# Redis Multiplexing Adapter

A Redis cache adapter for Swoole that serves many concurrent coroutines from a
single Redis TCP connection.

## When to use it vs. a connection pool

Most Swoole apps reach for a pool (`Adapter\Pool` wrapping `Adapter\Redis`):
each request checks out one connection, runs its commands, returns it. Pool
size = max concurrent commands.

`Redis\Multiplexing` is the alternative for *cache* traffic specifically:

- One TCP socket serving thousands of concurrent commands.
- No pool sizing to tune, no checkout backpressure on bursts.
- Lower memory: ~1 connection per worker, not N.

You'd still want a pool for transactions, pub/sub, blocking commands, or any
workload where commands are not independent. Many Swoole apps end up using
both — multiplex the cache, pool the rest.

## Quick start

```php
use Swoole\Coroutine;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis\Multiplexing;

Coroutine\run(function () {
    $adapter = new Multiplexing(host: 'redis');
    $cache   = new Cache($adapter);

    $cache->save('user:42', ['name' => 'Ada'], 'profile');
    $cache->load('user:42', ttl: 60, hash: 'profile');

    $adapter->disconnect();
});
```

Construct once at worker start, share across requests, `disconnect()` on
`WorkerStop`. The adapter must be constructed inside a Swoole coroutine.

## Constructor

```php
new Multiplexing(
    host:            'redis',
    port:            6379,
    timeout:         1.0,    // connect timeout (s)
    readTimeout:     0.25,   // per-call response deadline (s)
    auth:            null,   // string password, [user, password], or null
    dbIndex:         0,
    livenessTimeout: 5.0,    // reader silence before the connection is dead (s)
    idleGrace:       1.0,    // how long the reader outlives the last reply (s)
);
```

The two timeouts answer different questions, and the difference matters on a
shared connection.

`readTimeout` defaults to **250 ms** and is a deadline for *one call*: how long
this caller waits for its own reply before giving up with a `TimeoutException`.
Caches should fail fast and let callers fall through to the source of truth. It
expires that call only — the connection is left alone, because a caller running
out of patience is not evidence that the socket is broken.

The abandoned call keeps its slot on the pending queue. That queue's order is
what pairs replies with callers, so dropping the slot would hand this call's
reply to whoever asked next, and every reply after it to the wrong caller.
Leaving it means the reader dequeues it in order and pushes the late reply into
a channel nobody is reading. No resync is needed, and no reply is misrouted.

`livenessTimeout` defaults to **5 s** and is a verdict on the *connection*: if
the reader has made no progress at all while callers are still waiting, the
socket is treated as dead. It is torn down, every pending caller fails with
`IdleConnectionException`, and the next call rebuilds it. This is what catches a
connection whose packets are dropped rather than refused, where no close ever
arrives — the reader blocks in `recv()` with no deadline of its own, so without
this check its callers would wait forever. Keep it well above `readTimeout`: a server that is merely busy looks
exactly like one that is gone until enough time has passed.

`idleGrace` defaults to **1 s** and bounds the reader coroutine's life on an
idle connection. The first caller to find no reader spawns one; it keeps
serving replies while any are outstanding and, once the queue is empty, parks
in `recv()` for the grace before retiring. Steady traffic therefore runs on one
long-lived reader rather than one coroutine per command, and a worker exit
waits at most the grace for it: a coroutine parked in `recv()` is a reactor
event, and Swoole cannot stop a worker while one remains.

## Errors

- `\RedisException` — Redis-side error (`WRONGTYPE`, `NOAUTH`, …). Connection
  is fine; the command was wrong. Not retried.
- `Utopia\Cache\Adapter\Redis\ConnectionException` — transport failure
  (socket closed, send failed, frame failed to parse). Connection has been
  discarded and the call is retried once on a rebuilt connection.
- `Utopia\Cache\Adapter\Redis\TimeoutException` — this call's `readTimeout`
  expired. The connection is still in use by everyone else. Not retried:
  resending would put a second copy of the command on a server that is already
  answering too slowly.
- `Utopia\Cache\Adapter\Redis\IdleConnectionException` — the reader went
  quiet for `livenessTimeout` with replies outstanding, so the connection was
  declared dead and discarded. Not retried: a fresh socket to a server that has
  answered nothing for seconds will not answer this attempt either.

Both of the latter extend `ConnectionException`, so existing `catch` sites keep
working. Match them ahead of the parent to tell "slow" apart from "broken".

## Telemetry

Implements `Cache\Feature\Telemetry`. Emits a
`cache.redis_multiplexing.pending.depth` gauge after each enqueue — non-zero
steady-state means callers are queueing faster than Redis is replying. Wire
up via `Cache::setTelemetry()`.

## Limitations

- Swoole coroutine context required.
- No pub/sub, transactions, blocking commands, or pipelining APIs.
- No Redis Cluster (use `Adapter\RedisCluster`).
- One Redis host per adapter instance; one connection per worker process.
