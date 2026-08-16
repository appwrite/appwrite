# utopia-cache

Cache adapters for Utopia. Rust port of [utopia-php/cache](https://github.com/utopia-php/cache) (`packages/cache`, PHP SHA `d7c0806f9bbd`).

Stores, loads, and purges application cache through a small adapter surface. The `Cache` wrapper lowercases keys (unless case-sensitive), records telemetry, and forwards generation leases.

Public paths follow PHP `Utopia\Cache\`: `Cache` and `Adapter` at the crate root, implementations under `adapter` (`adapter::Memory`, `adapter::None`, `adapter::Redis`, `adapter::redis::Client`).

## Install

```toml
utopia-cache = { path = "../utopia-cache" }
```

Default features include `redis` (phpredis-equivalent adapters). `memcached` and `hazelcast` are empty feature flags; those adapters always compile (text memcache protocol).

## Usage

```rust
use utopia_cache::adapter::Memory;
use utopia_cache::{Cache, LoadResult};

let cache = Cache::new(Memory::new());
let key = "data-from-example.com";

let data = match cache.load(key, 60 * 60 * 24 * 30 * 3, "").unwrap() {
    LoadResult::Hit(v) => v,
    LoadResult::Miss => {
        let body = "fetched";
        cache.save(key, body, "").unwrap();
        body.into()
    }
};
```

Filesystem adapter:

```rust
use utopia_cache::adapter::Filesystem;
use utopia_cache::Cache;

let cache = Cache::new(Filesystem::new("/cache-dir"));
```

## Adapters

| Adapter | PHP name | Notes |
|---------|----------|-------|
| [`adapter::Memory`](#memory) | `Adapter\Memory` | In-process map. |
| [`adapter::None`](#none) | `Adapter\None` | Stores nothing. Construct as `adapter::None` so it does not shadow `Option::None`. |
| [`adapter::Filesystem`](#filesystem) | `Adapter\Filesystem` | Files on disk. Keys may contain slashes. |
| [`adapter::Redis`](#redis) | `Adapter\Redis` | redis-rs, reconnect, Lua leases. Feature `redis`. |
| [`adapter::redis::Multiplexing`](#multiplexing) | `Adapter\Redis\Multiplexing` | One TCP connection; mutex instead of Swoole coroutines. |
| [`adapter::RedisCluster`](#rediscluster) | `Adapter\RedisCluster` | redis-rs cluster. Feature `redis`. |
| [`adapter::Memcached`](#memcached) | `Adapter\Memcached` | Text memcache protocol, JSON envelopes. |
| [`adapter::Hazelcast`](#hazelcast) | `Adapter\Hazelcast` | Memcache protocol; `flush` always `false`. |
| [`adapter::Sharding`](#sharding) | `Adapter\Sharding` | `crc32(key) % count` (PHP IEEE CRC-32). |
| [`adapter::Pool`](#pool) | `Adapter\Pool` | Checks an adapter out of [`utopia_pools::Pool`](../utopia-pools). |
| [`adapter::CircuitBreaker`](#circuitbreaker) | `Adapter\CircuitBreaker` | Wraps an adapter with [`utopia_circuit_breaker::CircuitBreaker`](../utopia-circuit-breaker). |

## Deviations from PHP

- **`utopia-php/circuit-breaker` and `utopia-php/pools` are ported.** Cache uses [`utopia_circuit_breaker::CircuitBreaker`](../utopia-circuit-breaker) and [`utopia_pools::Pool`](../utopia-pools). [`adapter::MemoryPool`] is a helper that wraps one adapter in a real pool (PHP tests construct `Utopia\Pools\Pool` of `Adapter`). Circuit-breaker defaults are PHP's (`threshold=3`, `timeout=30`, `successThreshold=2`), not the old stand-in (`5` / `60`).
- **Swoole `Redis\Multiplexing` is N/A.** The Rust type speaks RESP2 over one `TcpStream` serialized with a mutex, not Swoole coroutines.
- **Filesystem streaming** does not return a PHP `resource`; `load` always returns file contents as a string.
- **Memcached** stores JSON envelopes (PHP uses the native Memcached serializer).
- **`LoadResult` / `SaveResult` / `CacheValue`** replace PHP `mixed` / `false`.
- Live Redis / Memcached / Hazelcast / multiplexing tests always hit `docker-compose.test.yml` (`REDIS_HOST` / `MEMCACHED_HOST` / `HAZELCAST_HOST`, defaults `127.0.0.1`).

## API Reference

### `Cache`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(adapter: impl Adapter + 'static) -> Cache` | PHP `__construct(Adapter $adapter)`. Default telemetry is `NoneAdapter`. |
| `set_telemetry` | `fn set_telemetry(&mut self, telemetry: Arc<dyn utopia_telemetry::Adapter>)` | Lazy instruments; forwards to `Feature::Telemetry` adapters. |
| `set_case_sensitivity` | `fn set_case_sensitivity(&mut self, value: bool) -> bool` | PHP `setCaseSensitivity`. Keys lowercased unless `true`. |
| `load` | `fn load(&self, key, ttl, hash) -> Result<LoadResult, CacheError>` | Histogram `cache.operation.duration` (`s`); counter `cache.load.total` with `result=hit\|miss`. |
| `save` | `fn save(&self, key, data, hash) -> Result<SaveResult, CacheError>` | Returns the data on success, `SaveResult::Failed` on failure. |
| `get_generation` | `fn get_generation(&self, key) -> Result<String, CacheError>` | `"0"` when the adapter is not `Leasable`. |
| `save_with_lease` | `fn save_with_lease(&self, key, data, hash, generation) -> Result<SaveResult, CacheError>` | Falls back to `save` when not leasable. |
| `touch` | `fn touch(&self, key, hash) -> Result<bool, CacheError>` | Refresh timestamp without replacing data. |
| `list` | `fn list(&self, key) -> Result<Vec<String>, CacheError>` | Hash fields (Redis); `[]` for Memory/Filesystem. |
| `purge` | `fn purge(&self, key, hash) -> Result<bool, CacheError>` | |
| `flush` | `fn flush(&self) -> Result<bool, CacheError>` | |
| `ping` | `fn ping(&self) -> bool` | |
| `get_size` | `fn get_size(&self) -> Result<i64, CacheError>` | |

Telemetry histogram name `cache.operation.duration`, unit `s`, advisory `ExplicitBucketBoundaries` `0.001,0.005,0.01,0.025,0.05,0.1,0.25,0.5,1`. Counter `cache.load.total` attributes: `adapter`, `result` (`hit` or `miss`). PHP `$result === false` is `LoadResult::Miss`; JSON `null` is a hit.

PHP `empty($key)`: `""` and `"0"` are empty. PHP `empty($data)`: empty string, `"0"`, and `[]` fail `save`.

### `Adapter` trait

| Method | Signature |
|--------|-----------|
| `load` | `fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<LoadResult, CacheError>` |
| `save` | `fn save(&self, key: &str, data: &CacheValue, hash: &str) -> Result<SaveResult, CacheError>` |
| `touch` | `fn touch(&self, key: &str, hash: &str) -> Result<bool, CacheError>` |
| `list` | `fn list(&self, key: &str) -> Result<Vec<String>, CacheError>` |
| `purge` | `fn purge(&self, key: &str, hash: &str) -> Result<bool, CacheError>` |
| `flush` | `fn flush(&self) -> Result<bool, CacheError>` |
| `ping` | `fn ping(&self) -> bool` |
| `get_size` | `fn get_size(&self) -> Result<i64, CacheError>` |
| `get_name` | `fn get_name(&self, key: Option<&str>) -> String` |

Optional: `as_leasable`, `as_telemetry_mut`.

### `CacheValue` / `LoadResult` / `SaveResult`

| Type | Variants |
|------|----------|
| `CacheValue` | `String(String)`, `Array(serde_json::Value)`, `Bytes(Vec<u8>)`, `Null` |
| `LoadResult` | `Miss`, `Hit(CacheValue)` |
| `SaveResult` | `Failed`, `Saved(CacheValue)` |

### Features

| Trait | PHP | Notes |
|-------|-----|-------|
| `Leasable` | `Feature\Leasable` | `get_generation`, `save_with_lease` |
| `Retryable` | `Feature\Retryable` | `MIN_RETRIES=0`, `MAX_RETRIES=10`, default delay 1000ms |
| `Telemetry` | `Feature\Telemetry` | `set_telemetry` |

### `Memory`

In-process `HashMap`. `list` is always `[]`. `get_size` is key count. Rejects empty keys (`""`, `"0"`) and empty data.

### `adapter::None`

PHP `None`. `save`/`touch` fail, `load` misses, `purge`/`flush`/`ping` succeed, `get_size` is 0.

### `Filesystem`

PHP `getPath` = `path + MAIN_SEPARATOR + filename` (keys may contain slashes). `get_size` is directory size in bytes. `flush` deletes the directory tree (PHP `deleteDirectory`). Optional `streaming` flag is accepted; loads still return strings.

### `Json`

PHP `Json::decode`. `serde_json::Value` preserves `{}` vs `[]`. `contains_empty_object` matches `/\{\s*\}/`.

### `Sharding`

PHP `crc32($key) % count` with unsigned IEEE CRC-32. Empty adapter list → `CacheError::NoAdapters`. Implements `Leasable` by forwarding (or `"0"` / unconditional `save`).

### `Pool` / `AdapterPool` / `MemoryPool`

PHP `Adapter\Pool` over [`utopia_pools::Pool`](../utopia-pools) (`AdapterPool` is a type alias). Checkout uses `Pool::use_sync`. Non-leasable inners: `get_generation` → `"0"`, `save_with_lease` → `save`. [`MemoryPool::single`] wraps one adapter in a size-1 pool for tests.

### `CircuitBreaker` / `UtopiaCircuitBreaker`

PHP `Adapter\CircuitBreaker` wrapping [`utopia_circuit_breaker::CircuitBreaker`](../utopia-circuit-breaker). `call(open, close)`: CLOSED runs `close`; on `Err` counts a failure and uses `open`. After `threshold` (PHP default **3**) the breaker is OPEN until `timeout` (PHP default **30s**), then HALF_OPEN. Telemetry: `breaker.calls` and related instruments.

### Redis family

Reserved hash fields: `__utopia_gen__`, `__utopia_tomb__`. Lua scripts are copied verbatim from PHP `Leasable.php`. Envelope: `{"time": <unix>, "data": <value>}`.

| Type | Role |
|------|------|
| `Redis` | Hash fields + leases + reconnect |
| `Multiplexing` | RESP2 TCP client |
| `RedisCluster` | Cluster; no leases (matches PHP) |
| `Envelope` | Encode / decode / touch |
| `Client` | `encode` / `parse` RESP2 |
| `NoScript` | `matches` leading token `NOSCRIPT` |
| `ConnectionException` / `ConnectionError` / `RedisError` / `ConnectionContext` | Protocol types |

### `Memcached` / `Hazelcast`

Text protocol. Hazelcast `flush` returns `false`. Live tests: `MEMCACHED_HOST` / `HAZELCAST_HOST`.

## Tests

```bash
cargo test --manifest-path crates/utopia-cache/Cargo.toml
```

Unit tests (Memory, None, Filesystem, Json, Sharding, Pool, CircuitBreaker, Envelope, NoScript, Client) always run. Redis / Memcached / Hazelcast / Multiplexing E2E always hit compose defaults (`REDIS_HOST` / `MEMCACHED_HOST` / `HAZELCAST_HOST`).

## Benchmarks

```bash
cargo bench --manifest-path crates/utopia-cache/Cargo.toml
```

Reports `cache_memory_save`, `cache_memory_load_hit`, `cache_memory_load_miss` ops/s (PHP twin: `benchmarks/cache/`).

## Code quality

```bash
cargo fmt --manifest-path crates/utopia-cache/Cargo.toml
cargo clippy --manifest-path crates/utopia-cache/Cargo.toml --all-targets -- -D warnings
```

Inherits workspace lint policy (`[lints] workspace = true`).

## License

MIT - see [LICENSE](LICENSE).
