Async code waits on many things at once: a database reply, a timer, a socket. This crate gives it three facades, `Promise` to run futures together, `Timer` to run callbacks later or on a schedule, and `Parallel` to spread CPU-bound work over threads, and the engine underneath, which every other crate reaches only through `utopia_async`. The engine is chosen once, by a feature flag. This guide covers the common tasks.

## Run futures together

A Rust future is a promise that has not started: it does nothing until awaited. `Promise::run` runs one to its end, `Promise::spawn` starts one as a task of its own, and `all`, `race`, `any` and `all_settled` combine several.

```
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

```php
use Utopia\Async\Promise;

Swoole\Coroutine\run(function () {
    $user = Promise::async(fn () => 'ada');
    $both = Promise::all([Promise::resolve(1), Promise::resolve(2)])->await();
    $first = Promise::race([
        Promise::async(function () { Promise::delay(5000)->await(); return 'slow'; }),
        Promise::async(fn () => 'fast'),
    ])->await();
    echo $user->await(), ' ', json_encode($both), ' ', $first, "\n"; // ada [1,2] fast
});
```

A future that fails returns a `Result`: `Ok` fulfills, `Err` rejects. `Promise::all` stops at the first error, `Promise::all_settled` keeps every outcome, and `Promise::timeout` gives up on a future that takes too long.

```
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

```
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

```php
use Utopia\Async\Timer;

Swoole\Coroutine\run(function () {
    $ticks = 0;
    Timer::tick(2, function (int $id) use (&$ticks) {
        if (++$ticks === 3) {
            Timer::clear($id);
        }
    });

    Swoole\Coroutine::sleep(0.05);
    echo $ticks, "\n"; // 3
});
```

## Spread work over cores

`Parallel` runs closures on the engine's thread pool, off the workers that run tasks: `run` one, `all` of several, `map` over items in chunks, or a `pool` that caps how many run at once.

```
use utopia_async::Parallel;

utopia_async::run(async {
    let squares = Parallel::map(1..=8u64, |n, _index| n * n, Some(4)).await;
    assert_eq!(squares, [1, 4, 9, 16, 25, 36, 49, 64]);

    let sum = Parallel::run(|| (1..=1_000_000u64).sum::<u64>()).await;
    assert_eq!(sum, 500_000_500_000);
});
```

```php
use Utopia\Async\Parallel;

$squares = Parallel::map(range(1, 8), fn (int $n) => $n * $n, 4);
echo json_encode($squares), "\n"; // [1,4,9,16,25,36,49,64]

echo Parallel::run(fn () => array_sum(range(1, 1000000))), "\n"; // 500000500000
```

## Spawn tasks and share state

Below the facades is the engine's API, which Swoole gives PHP code natively: `spawn` starts a task, `sleep` and `timeout` wait, and `sync` has the channels, mutexes and semaphores tasks coordinate with. Waiting parks the task, not the thread.

```
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

```php
Swoole\Coroutine\run(function () {
    $total = 0;
    $workers = new Swoole\Coroutine\WaitGroup();
    foreach (range(1, 10) as $n) {
        $workers->add();
        go(function () use (&$total, $n, $workers) {
            $total += $n;
            $workers->done();
        });
    }
    $workers->wait();

    $channel = new Swoole\Coroutine\Channel(1);
    go(fn () => $channel->push('ping'));
    echo $total, ' ', $channel->pop(), "\n"; // 55 ping
});
```

A server wants every core: build a multi-threaded runtime and drive it with `block_on`.

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
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;

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

The engine is the `Adapter` of the build, `utopia_async::Engine`: `Tokio` with the default `tokio` feature. An engine is added as a module of `utopia_async::adapter` that implements `Adapter` and its resource traits (`TcpStream`, `File`, `Child`, ...), behind a feature of its name; no crate that uses `utopia_async` changes. PHP picks an adapter per facade when the program runs (`Promise::setAdapter()`); Rust picks the engine when it compiles, so every call goes straight to it.
