Async Rust code runs on an engine: something that schedules tasks, keeps timers, waits on sockets and runs blocking work on a pool. This crate is the one place that names it. Every other crate spawns tasks, sleeps, connects and reads files through `utopia_async`, and the engine behind it is chosen once, by a feature flag. This guide covers the common tasks.

## Run async code

`run` starts a single-threaded runtime, runs a future to its end and returns its output. Tasks the future spawns run on the same thread, between its awaits.

```
let answer = utopia_async::run(async {
    let half = utopia_async::spawn(async { 21 });
    half.await.unwrap() * 2
});

assert_eq!(answer, 42);
```

```php
use function Swoole\Coroutine\run;

run(function () {
    $channel = new Swoole\Coroutine\Channel(1);
    go(fn () => $channel->push(21));
    echo $channel->pop() * 2, "\n"; // 42
});
```

A server wants every CPU: build a multi-threaded runtime and drive the server with `block_on`.

```
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

## Wait, time out and tick

`sleep` waits without blocking the thread, `timeout` gives up on a future that takes too long, and `interval` ticks on a schedule.

```
use std::time::Duration;

utopia_async::run(async {
    utopia_async::sleep(Duration::from_millis(1)).await;

    let slow = utopia_async::sleep(Duration::from_secs(10));
    assert!(utopia_async::timeout(Duration::from_millis(5), slow).await.is_err());

    let mut ticks = utopia_async::time::interval(Duration::from_millis(2));
    for _ in 0..3 {
        ticks.tick().await;
    }
});
```

```php
use Swoole\Coroutine;

Coroutine\run(function () {
    Coroutine::sleep(0.001);

    $channel = new Coroutine\Channel();
    var_dump($channel->pop(0.005)); // bool(false): timed out

    $ticks = 0;
    Swoole\Timer::tick(2, function (int $timer) use (&$ticks) {
        if (++$ticks === 3) {
            Swoole\Timer::clear($timer);
        }
    });
});
```

## Share work between tasks

`sync` has the primitives tasks coordinate with: channels, mutexes, semaphores and notifications. Waiting on one parks the task, not the thread. A `JoinSet` waits for a group of tasks in the order they end.

```
use utopia_async::sync::{Mutex, mpsc};
use utopia_async::task::JoinSet;
use std::sync::Arc;

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

## Keep blocking work off the runtime

A task that blocks (hashing a password, compressing a file, calling a blocking library) stalls every task on its worker. `spawn_blocking` moves the work to the blocking pool; `block_in_place` runs it on the current thread after handing the worker's other tasks to another worker.

```
utopia_async::run(async {
    let digest = utopia_async::spawn_blocking(|| (0..1_000_000u64).sum::<u64>()).await.unwrap();
    assert_eq!(digest, 499_999_500_000);

    let inline = utopia_async::block_in_place(|| "computed here");
    assert_eq!(inline, "computed here");
});
```

## Talk to the network

`net` has TCP connections and listeners, UDP sockets and name resolution. Streams implement the `io` traits, so `read`, `write_all` and the rest come from `AsyncReadExt` and `AsyncWriteExt`.

```
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
# Ok::<(), std::io::Error>(())
```

```php
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\Client;

Swoole\Coroutine\run(function () {
    $server = new Server('127.0.0.1', 0);
    go(function () use ($server) {
        $server->handle(function (Connection $connection) {
            $connection->send('hello ' . $connection->recv());
            $connection->close();
        });
        $server->start();
    });

    $client = new Client(SWOOLE_SOCK_TCP);
    $client->connect('127.0.0.1', $server->port);
    $client->send('world');
    echo $client->recv(), "\n"; // hello world
    $server->shutdown();
});
```

## Read files and run processes

`fs` reads, writes and lists files on the blocking pool, and `process` runs a program with its pipes awaited.

```
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
# Ok::<(), std::io::Error>(())
```

## Test async code

`#[utopia_async::test]` runs an async test on a fresh single-threaded runtime; `threads = N` gives it a multi-threaded one.

```
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

The engine is the `Adapter` of the build, `utopia_async::Engine`: `Tokio` with the default `tokio` feature. An engine is added as a module of `utopia_async::adapter` that implements `Adapter` and its resource traits (`TcpStream`, `File`, `Child`, ...), behind a feature of its name; no crate that uses `utopia_async` changes.
