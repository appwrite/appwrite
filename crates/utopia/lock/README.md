# Utopia Lock

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/lock`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/lock) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Locks: Redis leases across hosts, in-process mutexes and semaphores, and file locks across processes.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-lock = { git = "https://github.com/utopia-rs/lock" }
```

A lock lets one piece of work use a shared resource while others wait or back off. This library gives every lock the same three moves (acquire, release, and run a section under the lock) and four kinds of lock: `Mutex` and `Semaphore` within one process, `File` across processes on one host, and `Distributed` across hosts through Redis. The locks are async, so the examples run them with `utopia_async::run`. This guide covers the common tasks.

## Run code under a lock

`with_lock` acquires the lock, runs the section, releases the lock and hands back the section's result. `Wait::Forever` waits as long as it takes.

```rust
use utopia_lock::{Lock, Mutex, Wait};

let mutex = Mutex::new();

let total = utopia_async::Runtime::new()?.block_on(async {
    mutex.with_lock(Wait::Forever, async { 2 + 3 }).await
})?;

assert_eq!(total, 5);
```

## Try a lock without waiting

`try_acquire` takes the lock if it is free and returns `false` at once if it is not. Call `release` when you are done. Releasing a lock you do not hold does nothing.

```rust
use utopia_lock::{Lock, Mutex};

let mutex = Mutex::new();

utopia_async::Runtime::new()?.block_on(async {
    assert!(mutex.try_acquire().await?);
    assert!(!mutex.try_acquire().await?);

    mutex.release().await?;
    assert!(mutex.try_acquire().await?);
    mutex.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
```

## Wait a limited time

Pass `Wait::For` to keep trying until a deadline. To take a timeout as float seconds, `Wait::from_secs_f64` converts it: `0.0` tries once and a negative value waits forever.

```rust
use std::time::Duration;
use utopia_lock::{Lock, Mutex, Wait};

assert_eq!(Wait::from_secs_f64(0.05), Wait::For(Duration::from_millis(50)));
assert_eq!(Wait::from_secs_f64(0.0), Wait::None);
assert_eq!(Wait::from_secs_f64(-1.0), Wait::Forever);

let mutex = Mutex::new();

utopia_async::Runtime::new()?.block_on(async {
    assert!(mutex.acquire(Wait::None).await?);
    // Someone holds it, so this gives up after 50 milliseconds.
    assert!(!mutex.acquire(Wait::For(Duration::from_millis(50))).await?);
    Ok::<_, utopia_lock::Error>(())
})?;
```

## Handle a lock you cannot get

When `with_lock` cannot acquire the lock in time, it does not run the section and fails with `Error::Contention`. Every other failure from this library is a different variant, so you can tell "busy" apart from "broken".

```rust
use std::time::Duration;
use utopia_lock::{Error, Lock, Mutex, Wait};

let mutex = Mutex::new();

utopia_async::Runtime::new()?.block_on(async {
    mutex.try_acquire().await?;

    let wait = Wait::For(Duration::from_millis(10));
    let error = mutex.with_lock(wait, async { "never runs" }).await.unwrap_err();
    assert!(matches!(error, Error::Contention(_)));
    assert_eq!(error.to_string(), "Failed to acquire mutex within timeout");
    Ok::<_, Error>(())
})?;
```

## Limit concurrency with a semaphore

A `Semaphore` lets up to a fixed number of holders in at once, which caps work such as outbound requests. Here four tasks share two permits, so at most two run at the same time.

```rust
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;
use utopia_lock::{Lock, Semaphore, Wait};

let semaphore = Semaphore::new(2)?;
let (live, peak) = (AtomicUsize::new(0), AtomicUsize::new(0));

let task = || {
    let (semaphore, live, peak) = (&semaphore, &live, &peak);
    semaphore.with_lock(Wait::Forever, async move {
        let now = live.fetch_add(1, Ordering::SeqCst) + 1;
        peak.fetch_max(now, Ordering::SeqCst);
        utopia_async::sleep(Duration::from_millis(10)).await;
        live.fetch_sub(1, Ordering::SeqCst);
    })
};

utopia_async::Runtime::new()?.block_on(async {
    let (a, b, c, d) = utopia_async::join!(task(), task(), task(), task());
    a.and(b).and(c).and(d)
})?;

assert_eq!(peak.load(Ordering::SeqCst), 2);
assert!(Semaphore::new(0).is_err());
```

## Lock across processes with a file

A `File` lock holds `flock(2)` on a path, so it works between processes on one host, for example to stop two copies of a cron job. Each handle competes, even inside one process. The directory must exist.

```rust
use utopia_lock::{File, Lock, Mode};

let path = std::env::temp_dir().join("utopia-lock-guide-nightly.lock");
let path = path.to_string_lossy();

let first = File::new(path.as_ref(), Mode::Exclusive);
let second = File::new(path.as_ref(), Mode::Exclusive);

utopia_async::Runtime::new()?.block_on(async {
    assert!(first.try_acquire().await?);
    assert!(!second.try_acquire().await?);

    first.release().await?;
    assert!(second.try_acquire().await?);
    second.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
```

## Share a file lock between readers

`Mode::Shared` lets many readers hold the lock together. A writer that asks for `Mode::Exclusive` waits until every reader has released it.

```rust
use utopia_lock::{File, Lock, Mode};

let path = std::env::temp_dir().join("utopia-lock-guide-cache.lock");
let path = path.to_string_lossy();

let reader = File::new(path.as_ref(), Mode::Shared);
let another = File::new(path.as_ref(), Mode::Shared);
let writer = File::new(path.as_ref(), Mode::Exclusive);

utopia_async::Runtime::new()?.block_on(async {
    assert!(reader.try_acquire().await?);
    assert!(another.try_acquire().await?);
    assert!(!writer.try_acquire().await?);

    reader.release().await?;
    another.release().await?;
    assert!(writer.try_acquire().await?);
    writer.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
```

## Lock across hosts with Redis

A `Distributed` lock writes a random token to a Redis key with a time to live, so a crashed holder cannot block others forever. Only the instance holding the token can refresh or release the lease. Give the TTL in seconds.

```rust
use utopia_lock::{Distributed, Lock};

utopia_async::Runtime::new()?.block_on(async {
    let client = redis::Client::open("redis://localhost:6379")?;
    let conn = client.get_connection_manager().await?;

    let lock = Distributed::new(conn.clone(), "guide:rebuild-index", 120);
    let other = Distributed::new(conn, "guide:rebuild-index", 120);

    assert!(lock.try_acquire().await?);
    assert!(!other.try_acquire().await?);
    assert!(lock.is_held().await?);
    assert!(lock.refresh().await?);

    lock.release().await?;
    assert!(other.try_acquire().await?);
    other.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
```

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/lock`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/lock). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-lock
cargo clippy -p utopia-lock --all-targets -- -D warnings
cargo fmt --all --check
```

### Compare with PHP

Replay the results PHP recorded for every case against Rust, without PHP:

```bash
COMPAT_SERVICES=1 COMPAT_LIB=lock cargo test -p compat
```

Without `COMPAT_SERVICES=1` the cases that need a service are skipped.

Run every case on both runtimes, fuzz both with generated inputs, then run the whole conversion check. These need the dev stack (`docker compose up -d`):

```bash
bin/compat run lock
bin/compat fuzz lock
bin/compat check lock
```

### Benchmark

```bash
bin/compat bench lock
```

This times every benchmark on PHP and on a release build of Rust, both in containers on the dev stack. It fails if Rust is slower. Add `--write` to record the run in [`tests/compat/lock/bench.json`](https://github.com/appwrite/appwrite/blob/main/tests/compat/lock/bench.json). Run it on a quiet machine, one library at a time.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
