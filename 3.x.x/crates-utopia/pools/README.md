# utopia-pools

Generic resource pools for Utopia. Rust port of [utopia-php/pools](https://github.com/utopia-php/pools).

A bounded, lazily filled pool of long-lived resources (HTTP clients, SMTP sessions, Redis handles, …). Internals are Tokio + a mutex; the public surface stays PHP-shaped: `Pool`, `Connection`, `Group`, `use()`, `pop` / `reclaim` / `destroy`.

This crate does **not** wrap `sqlx::Pool` or `mongodb::Client`. Those already pool. Put native pools on database adapters; use this crate for resources that are one connection each.

## Install

```toml
utopia-pools = { path = "../utopia-pools" }
```

## Usage

```rust
use utopia_pools::prelude::*;

# async fn demo() -> Result<(), utopia_pools::PoolError> {
let pool = Pool::new(Stack::new(), "http", 8, || "client".to_string(), 2.0)?;

let body = pool
    .use_resource(|client| Ok(client.clone()))
    .await?;

let mut group = Group::new();
group.add(pool);
let pool = group.get("http")?;
# let _ = (body, pool);
# Ok(())
# }
```

Preferred entry point is `use_resource` (PHP `Pool::use()`): borrow, run the callback, reclaim; discard the resource if the callback returns `Err` and recovery fails.

## API Reference

### `Pool<T>`

PHP `Utopia\Pools\Pool`. `T` must be [`Recover`] + `Send + 'static`. Clone shares the pool (Arc).

| Method | PHP | Description |
|--------|-----|-------------|
| `new` | `new Pool($adapter, $name, $size, $init, $timeout)` | Infallible `init`. `timeout` is seconds. Fails if `size < 1` or `timeout < 0`. |
| `with_telemetry` | `telemetry:` constructor arg | Optional `Arc<dyn utopia_telemetry::Adapter>`. Default is `NoneAdapter`. |
| `try_new` | same, when `init` throws | `init` returns `Result<T, BoxError>`. |
| `name` / `size` / `timeout` | `$pool->name` / `size` / `timeout` | Constructor values. |
| `use_resource` | `use($callback)` | Async acquire; sync callback. Reclaim or destroy afterwards. |
| `pop` | `pop()` | Checkout. Creates lazily until `size`. Waits up to `timeout` when full. |
| `push` | `push($connection)` | Return a connection to the idle set. |
| `reclaim` | `reclaim(?$connection)` | `None` reclaims every active connection (pool still holds them). |
| `destroy` | `destroy(?$connection)` | Discard and free capacity. Replacement is created by the next `pop()`. |
| `release` | `release($connection, $failed)` | Reclaim, or recover/destroy when `$failed`. |
| `count` | `count()` | Idle plus not-yet-created. |
| `is_empty` / `is_full` | `isEmpty()` / `isFull()` | `count() == 0` / `count() == size`. |

`timeout` is only how long `pop` waits for a free slot. It does not cover time inside `init`. Put connect timeouts in the factory. A failed `init` is not retried.

### `Connection<T>`

PHP `Utopia\Pools\Connection`.

| Item | PHP | Description |
|------|-----|-------------|
| `id` | `$connection->id` | `"{pool-name}-{uniqid}"`. |
| `resource()` | `$connection->resource` | Mutex guard over `T`. |
| `reclaim()` | `reclaim()` | Return to the pool. No-op if the pool was dropped. |
| `destroy()` | `destroy()` | Free capacity. No-op if the pool was dropped. |

The pool holds a weak reference (PHP `WeakReference`) so idle connections do not pin the pool, and a checked-out connection can outlive it.

### `Group<T>`

PHP `Utopia\Pools\Group`. Homogeneous in `T` - PHP is untyped. Mixed MySQL/Redis/HTTP pools are separate fields in Rust.

| Method | PHP | Description |
|--------|-----|-------------|
| `add` | `add($pool)` | Keyed by `pool.name()`. |
| `get` | `get($name)` | `Pool '{name}' not found`. |
| `remove` | `remove($name)` | |
| `reclaim` | `reclaim()` | Reclaim every pool. |
| `use_resources` | `use($names, $callback)` | Checkout in order, callback gets `&mut [&mut T]`, release in reverse. Empty names → `Cannot use with empty names`. |

### Adapters

PHP `Utopia\Pools\Adapter`.

| Type | PHP | Behaviour |
|------|-----|-----------|
| `Stack` | `Adapter\Stack` | Vec idle list. `timeout` ignored (nothing can return a connection while you wait). |
| `Swoole` | `Adapter\Swoole` | Tokio `Notify` wait (not `ext-swoole`). Construction does not need a runtime; `pop` does. `timeout == 0` polls 1ms like PHP Swoole. |

Custom adapters implement [`Adapter`].

### `Recover`

PHP `Pool::recover()`: `reset()` / `reconnect()` via `method_exists`.

| `RecoverCall` | PHP |
|---------------|-----|
| `Missing` | method does not exist |
| `Succeeded` | ran and did not return `false` |
| `Failed` | returned `false` (or the hook panicked) |

Default `recover()` is an object with no hooks → destroy on callback error. `String` / integers reclaim (PHP scalars). Implement `reconnect()` to recycle SMTP/HTTP clients after a failed `use_resource`.

### `PoolError`

Messages match PHP `Exception` / `InvalidArgumentException` text.

| Variant | Message |
|---------|---------|
| `InvalidArgument` | `Pool '{name}' size must be at least 1, got {size}.` / `timeout cannot be negative` |
| `Timeout` | `Pool '{name}' could not provide a connection within {timeout}s (size {size}, active {active}, idle {idle})` |
| `NotFound` | `Pool '{name}' not found` |
| `EmptyNames` | `Cannot use with empty names` |
| `Init` | original `init` error, unwrapped |
| `Callback` | callback error |
| `Adapter` | `synchronized()` failed |

`TypeError` is provided so tests can downcast a failed `init` like PHP `\TypeError`.

### Telemetry

When a telemetry adapter is passed:

- histograms `pool.connection.wait_time` and `pool.connection.use_time` (seconds)
- observable gauges `active` / `idle` / `open` / `capacity` counts (registered; `utopia-telemetry`'s `ObservableGauge` is currently a no-op stub)
- attributes `pool` = name, `size` = size

## Intentional deviations

- **`use` is a keyword** - PHP `use()` is `use_resource` / `use_resources`.
- **`Swoole` adapter waits with Tokio**, not `ext-swoole`. Same contract: timeout wait, concurrent checkout.
- **`pop` / `use_resource` are async** so the Swoole adapter can yield. Stack still returns immediately.
- **`Group<T>` is single-type.** PHP `Group` holds mixed pools; Rust apps keep typed pools as fields.
- **`resource()` is a mutex guard**, not a public field (the pool also holds the connection for `reclaim()` with no argument).
- **Database drivers** - do not wrap `sqlx::Pool` or `mongodb::Client` in this pool.

## Tests

```bash
cargo test -p utopia-pools
```

Ports PHPUnit `StackTest` / `SwooleTest` shared scopes (`Connection`, `Group`, `Pool`) plus Tokio concurrency tests for the Swoole adapter.

## Benchmarks

```bash
cargo bench -p utopia-pools
```

Prints `pool_use` and `pool_pop_push` ops/s. PHP twin: [`benchmarks/pools/`](../../benchmarks/pools/).

## Code quality

- **rustfmt** - `cargo fmt --manifest-path crates-utopia/pools/Cargo.toml`
- **Clippy** - `cargo clippy --manifest-path crates-utopia/pools/Cargo.toml --all-targets -- -D warnings`

## License

MIT - see [LICENSE](LICENSE).
