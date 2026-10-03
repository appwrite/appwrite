# utopia-queue

Task queue server, brokers, and worker adapters for Utopia. Rust port of [utopia-php/queue](https://github.com/utopia-php/queue) (PHP SHA [`c3ae00025014`](https://github.com/utopia-php/monorepo/commit/c3ae00025014)).

Publish JSON payloads, consume them with Utopia `Hook` params / DI injections / validators, and record OpenTelemetry-style messaging metrics.

Public paths follow PHP `Utopia\Queue\`: `Server`, `Queue`, `Message`, `Adapter`, and `Connection` at the crate root; runtimes under `adapter` (`adapter::Swoole`, `adapter::Workerman`, `adapter::KubernetesJob`); brokers under `broker` (`broker::Redis`, `broker::Nats`, `broker::Pool`); connections under `connection` (`connection::Redis`).

## Install

```toml
utopia-queue = { path = "../utopia-queue" }
# optional: redis = ["utopia-queue/redis"]
# optional: nats = ["utopia-queue/nats"]
```

## Usage

```rust
use serde_json::json;
use utopia_queue::adapter::KubernetesJob;
use utopia_queue::broker::Redis;
use utopia_queue::connection::InMemoryConnection;
use utopia_queue::prelude::*;

let connection = InMemoryConnection::new();
let broker = Redis::new(connection.clone(), connection);
broker
    .enqueue(&Queue::new("emails").unwrap(), json!({"user": "ada"}), false)
    .unwrap();

let adapter = KubernetesJob::new(broker, 1, "emails").unwrap();
let mut server = Server::new(adapter);

server
    .job()
    .inject("message")
    .unwrap()
    .action(|args| {
        let message = args.message()?;
        let _payload = message.get_payload();
        Ok(())
    });

server
    .error()
    .inject("error")
    .unwrap()
    .action(|args| {
        let _err = args.error()?;
        Ok(())
    });

server.start().unwrap();
```

Long-running workers use [`adapter::Swoole`] (PHP `Adapter\Swoole`; Tokio internally) and [`adapter::Workerman`]. Run-to-completion Jobs use [`adapter::KubernetesJob`].

## Features

| Feature | Default | What it enables |
|---------|---------|-----------------|
| *(none)* | yes | In-memory connection, Redis **broker** (any `Connection`), Swoole/Workerman/K8s adapters, Nats type stub |
| `redis` | no | `Connection::Redis` / `RedisCluster` via the workspace `redis` crate |
| `nats` | no | `Broker::Nats` JetStream I/O via `async-nats` |

## Deviations from PHP

| Topic | PHP | Rust |
|-------|-----|------|
| Swoole / Workerman | Process pool + coroutines | [`adapter::Swoole`] multi-thread workers (Tokio internally). [`adapter::Workerman`] wraps Swoole with `max_coroutines = 1`. |
| Job action | `function (Message $m, ...)` positional | [`ActionArgs`] - `args.message()`, `args.param("key")`, `args.inject::<T>("name")`. |
| `utopia-php/lock` | [`utopia-lock`](../utopia-lock) | [`Lock`] / [`MutexLock`] (`utopia_lock::Mutex`). `with_lock` waits on a condvar (Swoole-equivalent); timeout `-1` never contends. |
| `utopia-php/pools` | [`utopia-pools`](../utopia-pools) | [`ResourcePool`] wraps `utopia_pools::Pool` + `Stack`; [`BrokerPool`] checks publishers/consumers out with `use_sync`. |
| NATS | `utopia-php/nats` | Always-exported [`Nats`] type; live I/O behind feature `nats`. Live tests run against the compose NATS container. |
| Observable gauge | PHP Test adapter stores callbacks | [`Server::observe_queue_depth`] runs the same probe (`utopia-telemetry` gauges are stubs). |
| Redis AUTH | Constructor stores user/password but never calls `auth` | Applies credentials in the Redis URL when set. |

## Prelude

```rust
use utopia_queue::prelude::*;
```

## API Reference

### `Queue`

PHP `Utopia\Queue\Queue`. Empty name or `"0"` → `"Cannot create queue with empty name."`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(name) -> Result<Queue, QueueError>` | Namespace `utopia-queue`, `job_ttl = 0`. |
| `with_namespace` | `fn with_namespace(name, namespace) -> Result<Queue, QueueError>` | |
| `with_ttl` | `fn with_ttl(name, namespace, job_ttl) -> Result<Queue, QueueError>` | |
| `key` | `fn key(&self, kind: &str) -> String` | `{namespace}.{kind}.{name}` |

Public fields: `name`, `namespace`, `job_ttl`.

### `Message`

PHP `Utopia\Queue\Message`. `as_array` keys: `pid`, `queue`, `timestamp`, `payload`, `attempts`. Unset payload serializes as JSON `null`.

| Method | Description |
|--------|-------------|
| `new` / `from_value` | Empty envelope, or decode a JSON object. |
| `set_pid` / `get_pid` | Unique id (`uniqid`-shaped on enqueue). |
| `set_queue` / `get_queue` | Queue name. |
| `set_timestamp` / `get_timestamp` | Unix seconds. |
| `set_payload` / `get_payload` | JSON payload. |
| `set_attempts` / `get_attempts` | Requeue count. |
| `as_array` | PHP `asArray()`. |

### `Job`

PHP `Utopia\Queue\Job` extends Hook. Callbacks live on the job (Hook only stores metadata), same pattern as `utopia-http`.

| Method | Description |
|--------|-------------|
| `hook` / `get_hook` | Skip global `*` init/shutdown hooks when `false`. |
| `param` / `param_full` | Payload params + aliases + `utopia-validators`. |
| `inject` | DI name (`"message"`, custom resources). Duplicate → `HookError`. |
| `groups` / `desc` / `label` | Hook metadata. |
| `action` | `Fn(&ActionArgs) -> Result<(), QueueError>`. |

### `Server<A: Adapter>`

PHP `Utopia\Queue\Server`.

| Method | Description |
|--------|-------------|
| `new` | Wraps an adapter; default telemetry is `NoneAdapter`. |
| `job` | Replace and return the job builder. |
| `resources` | App-wide `Container` (adapter resources). |
| `context` | Per-message child container. |
| `set_telemetry` | Histograms + queue-depth probe. |
| `init` / `shutdown` / `error` | Hook builders (`groups(['*'])`). |
| `worker_start` / `worker_stop` | Worker lifecycle hooks. |
| `get_worker_start` / `get_worker_stop` | Registered worker hooks. |
| `start` / `stop` | Run / flip the stop flag. |
| `observe_queue_depth` | Invoke the depth probe (test/collector). |

**Telemetry** (PHP `setTelemetry`):

| Instrument | Name | Unit | Buckets |
|------------|------|------|---------|
| Histogram | `messaging.process.wait.duration` | `s` | 0.005 … 10 |
| Histogram | `messaging.process.duration` | `s` | same |
| ObservableGauge | `messaging.queue.depth` | `{message}` | - |

Wait duration = `max(0, now - message.timestamp)` (clock-skew guard).

**Argument resolution** (PHP `getArguments` / `validate`):

- Missing key → alias → default.
- `""` or JSON `null` → default.
- Non-empty value failing the validator → `"Invalid {key}: {description}"` code 400.
- Empty required param → `"Param {key} is not optional."` code 400.
- `"Validator object is not an instance of the Validator class"` code 500 (`QueueError::invalid_validator`).

### `Consumer` / `Publisher`

PHP interfaces. `retry` accepts Redis extras (`max_attempts`, `newer_than`). `reap` defaults to `0` (NATS no-op).

### `Connection`

Redis-like list ops. Empty pop → `None` (PHP `false`). [`InMemoryConnection`] ports the PHPUnit helper. [`Locking`] serializes every call (`ACQUIRE_TIMEOUT = -1`).

Feature `redis`: [`RedisConnection`], [`RedisCluster`] (5 connect attempts, exponential jitter).

### Brokers

| Type | PHP | Notes |
|------|-----|-------|
| [`RedisBroker`] | `Broker\Redis` | Separate receive vs command connections. Claim keys, stats, `retry` / `reap`, reconnect callbacks. |
| [`Nats`] | `Broker\Nats` | JetStream work + dead streams. Feature `nats`. `reap` = 0. |
| [`BrokerPool`] | `Broker\Pool` | Checkouts [`ResourcePool`] entries. |

Redis keys: `{ns}.queue.{name}`, `{ns}.processing.{name}`, `{ns}.failed.{name}`, `{ns}.dead.{name}`, `{ns}.jobs.{name}.{pid}`, `{ns}.stats.{name}.*`. Priority enqueue is `RPUSH` (BRPOP reads the tail first).

### Adapters

| Type | PHP | Consume |
|------|-----|---------|
| [`Tokio`] / [`Swoole`] | `Adapter\Swoole` | Long-running. Slot **before** receive. `max_coroutines` bounds in-flight work. |
| [`Workerman`] | `Adapter\Workerman` | Tokio with `max_coroutines = 1`. |
| [`KubernetesJob`] | `Adapter\KubernetesJob` | Drain until receive times out, then return. |

Shared: `RECEIVE_TIMEOUT = 2`, `RECEIVE_BACKOFF = 1`. Broker errors in the long-running loop are reported (`error` hook, `message = None`) and retried after backoff. A failing error hook writes `[queue] … failed and its error report failed too: …` to stderr ([`TraceSink`], overridable [`BufferTrace`]).

## Tests

Default `cargo test -p utopia-queue` covers in-memory adapters (including `KubernetesJob` drain behaviour). Live Redis and NATS (`--features redis,nats`) always hit `docker-compose.test.yml` (`REDIS_HOST` / `NATS_URL`).
