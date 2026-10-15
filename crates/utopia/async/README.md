# Utopia Async

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/async`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/async) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Promises, timers and parallel work on an async engine behind one adapter (Tokio), with the tasks, sockets, files, processes and signals every crate uses through it.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-async = { git = "https://github.com/utopia-rs/async" }
```

Async code waits on many things at once: a database reply, a timer, a socket. This crate gives it three facades, `Promise` to run futures together, `Timer` to run callbacks later or on a schedule, and `Parallel` to spread CPU-bound work over threads, and the engine underneath, which every other crate reaches only through `utopia_async`. The engine is chosen once, by a feature flag. This guide covers the common tasks.

## Run futures together

A Rust future is a promise that has not started: it does nothing until awaited. `Promise::run` runs one to its end, `Promise::spawn` starts one as a task of its own, and `all`, `race`, `any` and `all_settled` combine several.

```rust
use std::time::Duration;

use utopia_async::Promise;

async fn reply(after: u64, text: &'static str) -> &'static str {
    Promise::delay(Duration::from_millis(after)).await;
    text
}

let (both, first) = Promise::run(async {
    let user = Promise::spawn(async { "ada" });
    let both = Promise::all([Promise::resolve::<_, String>(1), Promise::resolve(2)]).await;
    let first = Promise::race([reply(5000, "slow"), reply(0, "fast")]).await;
    assert_eq!(user.await.unwrap(), "ada");
    (both, first)
});

assert_eq!(both, Ok(vec![1, 2]));
assert_eq!(first, Ok("fast"));
```

A future that fails returns a `Result`: `Ok` fulfills, `Err` rejects. `Promise::all` stops at the first error, `Promise::all_settled` keeps every outcome, and `Promise::timeout` gives up on a future that takes too long.

```rust
use std::time::Duration;

use utopia_async::{Error, Promise};

Promise::run(async {
    let failed = Promise::all([Promise::resolve(1), Promise::reject("offline")]).await;
    assert_eq!(failed, Err("offline"));

    let outcomes = Promise::all_settled([Promise::resolve(1), Promise::reject("offline")]).await;
    assert_eq!(outcomes, [Ok(1), Err("offline")]);

    let late = Promise::timeout(Duration::from_millis(5), Promise::delay(Duration::from_secs(5))).await;
    assert_eq!(late, Err(Error::Timeout("Promise timed out after 5ms".into())));
});
```

## Run callbacks later

`Timer::after` runs a callback once after a delay, `Timer::tick` every period until it is cleared. Both run as tasks of the current runtime and return an id to clear them with.

```rust
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::Duration;

use utopia_async::Timer;

utopia_async::run(async {
    let ticks = Arc::new(AtomicUsize::new(0));
    let counter = ticks.clone();
    Timer::tick(Duration::from_millis(2), move |id| {
        if counter.fetch_add(1, Ordering::SeqCst) + 1 == 3 {
            Timer::clear(id);
        }
    });

    utopia_async::sleep(Duration::from_millis(50)).await;
    assert_eq!(ticks.load(Ordering::SeqCst), 3);
});
```

## Spread work over cores

`Parallel` runs closures on the engine's thread pool, off the workers that run tasks: `run` one, `all` of several, `map` over items in chunks, or a `pool` that caps how many run at once.

```rust
use utopia_async::Parallel;

utopia_async::run(async {
    let squares = Parallel::map(1..=8u64, |n, _index| n * n, Some(4)).await;
    assert_eq!(squares, [1, 4, 9, 16, 25, 36, 49, 64]);

    let sum = Parallel::run(|| (1..=1_000_000u64).sum::<u64>()).await;
    assert_eq!(sum, 500_000_500_000);
});
```

## Spawn tasks and share state

Below the facades is the engine's API, which Swoole gives PHP code natively: `spawn` starts a task, `sleep` and `timeout` wait, and `sync` has the channels, mutexes and semaphores tasks coordinate with. Waiting parks the task, not the thread.

```rust
use std::sync::Arc;

use utopia_async::sync::{Mutex, mpsc};
use utopia_async::task::JoinSet;

utopia_async::run(async {
    let total = Arc::new(Mutex::new(0));
    let mut workers = JoinSet::new();
    for n in 1..=10 {
        let total = total.clone();
        workers.spawn(async move { *total.lock().await += n });
    }
    while workers.join_next().await.is_some() {}
    assert_eq!(*total.lock().await, 55);

    let (sender, mut receiver) = mpsc::channel(1);
    utopia_async::spawn(async move { sender.send("ping").await.unwrap() });
    assert_eq!(receiver.recv().await, Some("ping"));
});
```

A server wants every core: build a multi-threaded runtime and drive it with `block_on`.

```rust
use utopia_async::Builder;

let runtime = Builder::multi_thread().threads(4).stack_size(8 * 1024 * 1024).build()?;
let sum = runtime.block_on(async {
    let tasks: Vec<_> = (1..=4).map(|n| utopia_async::spawn(async move { n * n })).collect();
    let mut sum = 0;
    for task in tasks {
        sum += task.await?;
    }
    Ok::<_, utopia_async::JoinError>(sum)
})?;

assert_eq!(sum, 30);
```

## Talk to the network

`net` has TCP connections and listeners, UDP sockets and name resolution. Streams implement the `io` traits, so `read`, `write_all` and the rest come from `AsyncReadExt` and `AsyncWriteExt`.

```rust
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::{TcpListener, TcpStream};

utopia_async::run(async {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let addr = listener.local_addr()?;
    utopia_async::spawn(async move {
        let (mut socket, _) = listener.accept().await?;
        let mut name = [0; 5];
        socket.read_exact(&mut name).await?;
        socket.write_all(&[b"hello ", &name[..]].concat()).await
    });

    let mut client = TcpStream::connect(addr).await?;
    client.write_all(b"world").await?;
    let mut reply = String::new();
    client.read_to_string(&mut reply).await?;
    assert_eq!(reply, "hello world");
    Ok::<(), std::io::Error>(())
})?;
```

## Read files and run processes

`fs` reads, writes and lists files on the blocking pool, and `process` runs a program with its pipes awaited.

```rust
use utopia_async::process::Command;

utopia_async::run(async {
    let path = std::env::temp_dir().join(format!("utopia-async-guide-{}", std::process::id()));
    utopia_async::fs::write(&path, b"delta\nalpha\n").await?;
    let text = utopia_async::fs::read_to_string(&path).await?;
    assert_eq!(text.lines().count(), 2);

    let output = Command::new("sort").arg(&path).output().await?;
    assert_eq!(output.stdout, b"alpha\ndelta\n");
    utopia_async::fs::remove_file(&path).await
})?;
```

## Test async code

`#[utopia_async::test]` runs an async test on a fresh single-threaded runtime; `threads = N` gives it a multi-threaded one.

```rust
#[utopia_async::test]
async fn sleeps() {
    utopia_async::sleep(std::time::Duration::from_millis(1)).await;
}

#[utopia_async::test(threads = 2)]
async fn spawns_in_parallel() {
    let task = utopia_async::spawn(async { 2 });
    assert_eq!(task.await.unwrap(), 2);
}
```

## Switch the engine

The engine is the `Adapter` of the build, `utopia_async::Engine`: `Tokio` with the default `tokio` feature. An engine is added as a module of `utopia_async::adapter` that implements `Adapter` and its resource traits (`TcpStream`, `File`, `Child`, ...), behind a feature of its name; no crate that uses `utopia_async` changes. PHP picks an adapter per facade when the program runs (`Promise::setAdapter()`); Rust picks the engine when it compiles, so every call goes straight to it.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
