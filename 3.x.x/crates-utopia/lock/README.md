# utopia-lock

Locks for coordinating access to shared resources. Rust port of [utopia-php/lock](https://github.com/utopia-php/lock).

Coordinates access across threads, processes (`File`), and hosts (`Distributed` Redis lock).

## Install

```toml
utopia-lock = { path = "../utopia-lock" }
```

## Usage

```rust
use utopia_lock::{Lock, Mutex};

let mutex = Mutex::new();
let value = mutex.with_lock(|| "ok", 0.0).unwrap();
assert_eq!(value, "ok");
```

`timeout` is seconds. `0.0` does not wait; a negative value waits forever.

## API Reference

### `Lock`

| Method | Description |
|--------|-------------|
| `acquire` | `fn acquire(&self, timeout: f64) -> bool` |
| `try_acquire` | Non-blocking acquire. |
| `release` | Safe to call when not held. |
| `with_lock` | Acquire, run callback, always release. `Err(Contention)` on timeout. |

### Implementations

| Type | PHP | Notes |
|------|-----|-------|
| `Mutex` | `Mutex` | Condvar mutex (Swoole coroutine equivalent). |
| `Semaphore` | `Semaphore` | Counting semaphore; `new(0)` errors. |
| `FileLock` (`File`) | `File` | `flock` via `fs2`. Default `LOCK_EX`. |
| `Distributed<R>` | `Distributed` | Redis SET NX EX + Lua release/refresh. Feature `redis`. |

### Errors

| Type | PHP message |
|------|-------------|
| `Contention` | `Failed to acquire mutex within timeout` / file / distributed variants |
| `LockError` | `Lock file directory does not exist: {dir}` |

### Intentional deviations

- **Mutex/Semaphore wait** like Swoole coroutines, not PHP's non-preemptive flag, because Rust is multi-threaded. `utopia-queue` `Connection\Locking` depends on this.
- **Distributed** takes a [`RedisCommands`] trait; `redis::Client` implements it behind the `redis` feature.

## Tests

```bash
cargo test -p utopia-lock
```

Ports `LockTest`, `MutexTest`, `SemaphoreTest`, `FileTest`. Redis `Distributed` E2E (`--features redis`) always hits compose Redis (`REDIS_URL`, default `redis://127.0.0.1:6379/`).

## Benchmarks

```bash
cargo bench -p utopia-lock
```

## License

MIT - see [LICENSE](LICENSE).
