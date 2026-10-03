# utopia-abuse

Rate limiting and abuse control for Utopia. Rust port of [utopia-php/abuse](https://github.com/utopia-php/abuse).

Provides time-limit, token-bucket, and sliding-window limiters plus Google reCAPTCHA. `check() == true` means **abuse** for limiter adapters (limit `0` is unlimited). reCAPTCHA matches PHP and returns `true` when the response looks human.

The TimeLimit Database adapter talks to [`utopia-database`](../utopia-database) (PHP `utopia-php/database`). [`database::MemoryDatabase`] wraps `utopia_database::Database<Memory>` plus `utopia-cache` for default CI. Redis adapters use the `redis` crate (standalone, cluster, and a [`utopia-pools`](../utopia-pools) pool). Appwrite TablesDB and reCAPTCHA HTTP use [`utopia-client`](../utopia-client).

## Install

```toml
utopia-abuse = { path = "../utopia-abuse" }
```

## Usage

```rust
use utopia_abuse::adapters::time_limit::Memory;
use utopia_abuse::{Abuse, Adapter};

let mut adapter = Memory::new("login-attempt-from-{{ip}}", 10, 60 * 5);
adapter.set_param("{{ip}}", "127.0.0.1");
let mut abuse = Abuse::new(adapter);

if abuse.check().unwrap() {
    panic!("service was abused");
}
```

Token bucket and sliding window:

```rust
use utopia_abuse::adapters::{sliding_window, token_bucket};
use utopia_abuse::Adapter;

let mut bucket = token_bucket::Memory::new("api-{{ip}}", 20, 1.0).unwrap();
bucket.set_param("{{ip}}", "10.0.0.1");
assert!(!bucket.check().unwrap());

let mut window = sliding_window::Memory::new("api-{{ip}}", 100, 60, 120).unwrap();
window.set_param("{{ip}}", "10.0.0.1");
assert!(!window.check().unwrap());
```

## API Reference

### `Abuse<A: Adapter>`

| Method | Signature | Description |
|--------|-----------|-------------|
| `new` | `fn new(adapter: A) -> Self` | PHP `new Abuse($adapter)`. |
| `check` | `fn check(&mut self) -> Result<bool, AbuseError>` | Forwards to the adapter. Limiters: `true` = abuse. |
| `get_logs` | `fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError>` | PHP `getLogs(?int $offset = null, ?int $limit = 25)`. |
| `cleanup` | `fn cleanup(&mut self, timestamp: i64) -> Result<bool, AbuseError>` | Delete logs older than `timestamp`. |
| `reset` | `fn reset(&mut self) -> Result<(), AbuseError>` | Reset the current key. |
| `adapter` / `adapter_mut` / `into_inner` | | Access the inner adapter (PHP keeps object identity). |

### `Adapter`

| Method | Signature | Description |
|--------|-----------|-------------|
| `check` | `fn check(&mut self) -> Result<bool, AbuseError>` | Abuse / human decision. |
| `set_param` | `fn set_param(&mut self, key: &str, value: &str) -> &mut Self` | Bind `{{placeholders}}` in the key pattern. |
| `parse_key` | `fn parse_key(&mut self) -> String` | PHP `parseKey()` - `str_replace` each param into `$this->key` (mutates the key). |
| `get_logs` | `fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError>` | Redis-style map or document list. |
| `cleanup` | `fn cleanup(&mut self, timestamp: i64) -> Result<bool, AbuseError>` | TTL backends return `true` without scanning. |
| `reset` | `fn reset(&mut self) -> Result<(), AbuseError>` | Clear the current key. |

Limiter bases expose `remaining()`, `limit()`, and `time()` with PHP semantics: `remaining = max(0, limit - (count + 1))`.

### Time-limit adapters (`adapters::time_limit`)

Aligned window: `timestamp = now - (now % seconds)`. `check` hits storage when `limit > count`.

| Type | PHP class | Notes |
|------|-----------|--------|
| `None` | `TimeLimit\None` | Always allows; no storage. |
| `Memory` | *(Rust test double)* | Same math as Redis; optional shared `MemoryStore`. |
| `Redis` | `TimeLimit\Redis` | `INCR`+`EXPIRE` MULTI; key `abuse__{key}__{timestamp}`. |
| `RedisCluster` | `TimeLimit\RedisCluster` | Same commands against `redis::cluster::ClusterConnection` / [`ClusterConnectionExt`]. |
| `RedisPool` | `TimeLimit\RedisPool` | [`utopia-pools`](../utopia-pools) [`redis_pool::Pool`] of standalone or cluster connections. |
| `Database<D>` | `TimeLimit\Database` | Uses [`database::Database`] over [`utopia-database`] (`exists`, `find`, `create_document`, increment, `skip_authorization`). |
| `appwrite::TablesDB` | `TimeLimit\Appwrite\TablesDB` | [`utopia-client`](../utopia-client) matching Appwrite SDK TablesDB calls. |

`Database::setup()` creates collection `abuse` with unique `(key, time)`. [`database::MemoryDatabase`] is a cloneable `utopia-database` Memory handle for tests. The Memory adapter does not enforce composite unique indexes, so the wrapper rejects duplicate `(key, time)` itself. Datetime unique compares ignore timezone suffix (`2020-01-01T00:00:00.000+00:00`). Cleanup timestamps must be within the datetime validator range (year ≤ 9999); tests use `2_000_000_000`, not `i64::MAX`.

### Token-bucket adapters (`adapters::token_bucket`)

Capacity `tokens`, refill `refill_rate` tokens/sec. Lua `LIMIT_CHECK_SCRIPT` / `TOKENS_SCRIPT` ported exactly. `refill_rate <= 0` is rejected (except PHP `None`, which ignores the rate).

| Type | PHP class |
|------|-----------|
| `None` | `TokenBucket\None` |
| `Memory` | *(Rust)* same refill/consume math |
| `Redis` / `RedisCluster` / `RedisPool` | Redis-family, hash `abuse__{key}` |

### Sliding-window adapters (`adapters::sliding_window`)

Weighted estimate `current + previous * (1 - elapsed)`. `ttl` must be `>= 2 * window_size`. Bucket keys use hash tags `abuse__{key}__{timestamp}`.

| Type | PHP class |
|------|-----------|
| `None` | `SlidingWindow\None` |
| `Memory` | *(Rust)* same Lua estimate / increment |
| `Redis` / `RedisCluster` / `RedisPool` | Redis-family |

### `ReCaptcha`

PHP `Adapters\ReCaptcha`. POST `application/x-www-form-urlencoded` via [`utopia-client`](../utopia-client) to Google `siteverify` with **double** `urlencode` (PHP `urlencode` then `http_build_query`). `check()` / `check_with_score(0.5)` returns `true` when `success && score >= threshold`. `get_logs` / `cleanup` / `reset` return `Method not supported`. Override the URL with `with_siteverify_url` for tests.

### `database` / `redis_pool`

| Type | Description |
|------|-------------|
| `database::Database` | Trait mirroring PHP methods used by TimeLimit Database; implemented by [`database::MemoryDatabase`] on `utopia-database`. |
| `database::MemoryDatabase` | Cloneable `utopia_database::Database<Memory>` + `utopia-cache` Memory. |
| `database::Document` / `Query` | PHP `Document` / `Query` subset. |
| `redis_pool::Pool` | [`utopia-pools`](../utopia-pools) `Pool`; `use_connection` matches PHP `$pool->use()`. |
| `ClusterConnectionExt` | Cluster seam implemented for `redis::cluster::ClusterConnection`. |

## Tests

```bash
cargo test --manifest-path crates-utopia/abuse/Cargo.toml
```

Ports PHP PHPUnit suites against Memory / None / in-memory Database. Redis tests always hit the compose Redis container (`REDIS_URL`, default `redis://127.0.0.1:6379/`). reCAPTCHA and TablesDB use utopia-test-wiremock (compose/CI WireMock).

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/abuse/Cargo.toml
```

Reports `timelimit_check` ops/s (memory TimeLimit `check` hot path). PHP twin: `benchmarks/abuse/`.

## Code quality

```bash
cargo fmt --manifest-path crates-utopia/abuse/Cargo.toml
cargo clippy --manifest-path crates-utopia/abuse/Cargo.toml --all-targets -- -D warnings
```

## License

MIT - see [LICENSE](LICENSE).
