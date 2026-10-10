A lock lets one piece of work use a shared resource while others wait or back off. This library gives every lock the same three moves (acquire, release, and run a section under the lock) and four kinds of lock: `Mutex` and `Semaphore` within one process, `File` across processes on one host, and `Distributed` across hosts through Redis. The locks are async, so the examples run them on a Tokio runtime. This guide covers the common tasks.

## Run code under a lock

`with_lock` acquires the lock, runs the section, releases the lock and hands back the section's result. `Wait::Forever` waits as long as it takes.

```
use utopia_lock::{Lock, Mutex, Wait};

let mutex = Mutex::new();

let total = tokio::runtime::Runtime::new()?.block_on(async {
    mutex.with_lock(Wait::Forever, async { 2 + 3 }).await
})?;

assert_eq!(total, 5);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\Mutex;

use function Swoole\Coroutine\run;

$mutex = new Mutex();

run(function () use ($mutex) {
    $total = $mutex->withLock(fn () => 2 + 3, timeout: -1.0);
    echo $total, "\n"; // 5
});
```

## Try a lock without waiting

`try_acquire` takes the lock if it is free and returns `false` at once if it is not. Call `release` when you are done. Releasing a lock you do not hold does nothing.

```
use utopia_lock::{Lock, Mutex};

let mutex = Mutex::new();

tokio::runtime::Runtime::new()?.block_on(async {
    assert!(mutex.try_acquire().await?);
    assert!(!mutex.try_acquire().await?);

    mutex.release().await?;
    assert!(mutex.try_acquire().await?);
    mutex.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\Mutex;

use function Swoole\Coroutine\run;

$mutex = new Mutex();

run(function () use ($mutex) {
    var_dump($mutex->tryAcquire()); // bool(true)
    var_dump($mutex->tryAcquire()); // bool(false)

    $mutex->release();
    var_dump($mutex->tryAcquire()); // bool(true)
    $mutex->release();
});
```

## Wait a limited time

Pass `Wait::For` to keep trying until a deadline. To take a timeout as float seconds, `Wait::from_secs_f64` converts it: `0.0` tries once and a negative value waits forever.

```
use std::time::Duration;
use utopia_lock::{Lock, Mutex, Wait};

assert_eq!(Wait::from_secs_f64(0.05), Wait::For(Duration::from_millis(50)));
assert_eq!(Wait::from_secs_f64(0.0), Wait::None);
assert_eq!(Wait::from_secs_f64(-1.0), Wait::Forever);

let mutex = Mutex::new();

tokio::runtime::Runtime::new()?.block_on(async {
    assert!(mutex.acquire(Wait::None).await?);
    // Someone holds it, so this gives up after 50 milliseconds.
    assert!(!mutex.acquire(Wait::For(Duration::from_millis(50))).await?);
    Ok::<_, utopia_lock::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\Mutex;

use function Swoole\Coroutine\run;

$mutex = new Mutex();

run(function () use ($mutex) {
    var_dump($mutex->acquire()); // bool(true)
    // Someone holds it, so this gives up after 50 milliseconds.
    var_dump($mutex->acquire(0.05)); // bool(false)
});
```

## Handle a lock you cannot get

When `with_lock` cannot acquire the lock in time, it does not run the section and fails with `Error::Contention`. Every other failure from this library is a different variant, so you can tell "busy" apart from "broken".

```
use std::time::Duration;
use utopia_lock::{Error, Lock, Mutex, Wait};

let mutex = Mutex::new();

tokio::runtime::Runtime::new()?.block_on(async {
    mutex.try_acquire().await?;

    let wait = Wait::For(Duration::from_millis(10));
    let error = mutex.with_lock(wait, async { "never runs" }).await.unwrap_err();
    assert!(matches!(error, Error::Contention(_)));
    assert_eq!(error.to_string(), "Failed to acquire mutex within timeout");
    Ok::<_, Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\Mutex;

use function Swoole\Coroutine\run;

$mutex = new Mutex();

run(function () use ($mutex) {
    $mutex->tryAcquire();

    try {
        $mutex->withLock(fn () => 'never runs', timeout: 0.01);
    } catch (Contention $e) {
        echo $e->getMessage(), "\n"; // Failed to acquire mutex within timeout
    }
});
```

## Limit concurrency with a semaphore

A `Semaphore` lets up to a fixed number of holders in at once, which caps work such as outbound requests. Here four tasks share two permits, so at most two run at the same time.

```
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
        tokio::time::sleep(Duration::from_millis(10)).await;
        live.fetch_sub(1, Ordering::SeqCst);
    })
};

tokio::runtime::Runtime::new()?.block_on(async {
    let (a, b, c, d) = tokio::join!(task(), task(), task(), task());
    a.and(b).and(c).and(d)
})?;

assert_eq!(peak.load(Ordering::SeqCst), 2);
assert!(Semaphore::new(0).is_err());
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Swoole\Coroutine;
use Utopia\Lock\Semaphore;

use function Swoole\Coroutine\run;

$semaphore = new Semaphore(2);
$live = 0;
$peak = 0;

run(function () use ($semaphore, &$live, &$peak) {
    for ($i = 0; $i < 4; $i++) {
        Coroutine::create(function () use ($semaphore, &$live, &$peak) {
            $semaphore->withLock(function () use (&$live, &$peak) {
                $live++;
                $peak = max($peak, $live);
                Coroutine::sleep(0.01);
                $live--;
            }, timeout: -1.0);
        });
    }
});

echo $peak, "\n"; // 2
```

## Lock across processes with a file

A `File` lock holds `flock(2)` on a path, so it works between processes on one host, for example to stop two copies of a cron job. Each handle competes, even inside one process. The directory must exist.

```
use utopia_lock::{File, Lock, Mode};

let path = std::env::temp_dir().join("utopia-lock-guide-nightly.lock");
let path = path.to_string_lossy();

let first = File::new(path.as_ref(), Mode::Exclusive);
let second = File::new(path.as_ref(), Mode::Exclusive);

tokio::runtime::Runtime::new()?.block_on(async {
    assert!(first.try_acquire().await?);
    assert!(!second.try_acquire().await?);

    first.release().await?;
    assert!(second.try_acquire().await?);
    second.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\File;

$path = sys_get_temp_dir() . '/utopia-lock-guide-nightly.lock';

$first = new File($path, LOCK_EX);
$second = new File($path, LOCK_EX);

var_dump($first->tryAcquire());  // bool(true)
var_dump($second->tryAcquire()); // bool(false)

$first->release();
var_dump($second->tryAcquire()); // bool(true)
$second->release();
```

## Share a file lock between readers

`Mode::Shared` lets many readers hold the lock together. A writer that asks for `Mode::Exclusive` waits until every reader has released it.

```
use utopia_lock::{File, Lock, Mode};

let path = std::env::temp_dir().join("utopia-lock-guide-cache.lock");
let path = path.to_string_lossy();

let reader = File::new(path.as_ref(), Mode::Shared);
let another = File::new(path.as_ref(), Mode::Shared);
let writer = File::new(path.as_ref(), Mode::Exclusive);

tokio::runtime::Runtime::new()?.block_on(async {
    assert!(reader.try_acquire().await?);
    assert!(another.try_acquire().await?);
    assert!(!writer.try_acquire().await?);

    reader.release().await?;
    another.release().await?;
    assert!(writer.try_acquire().await?);
    writer.release().await?;
    Ok::<_, utopia_lock::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\File;

$path = sys_get_temp_dir() . '/utopia-lock-guide-cache.lock';

$reader = new File($path, LOCK_SH);
$another = new File($path, LOCK_SH);
$writer = new File($path, LOCK_EX);

var_dump($reader->tryAcquire());  // bool(true)
var_dump($another->tryAcquire()); // bool(true)
var_dump($writer->tryAcquire());  // bool(false)

$reader->release();
$another->release();
var_dump($writer->tryAcquire()); // bool(true)
$writer->release();
```

## Lock across hosts with Redis

A `Distributed` lock writes a random token to a Redis key with a time to live, so a crashed holder cannot block others forever. Only the instance holding the token can refresh or release the lease. Give the TTL in seconds.

```no_run
use utopia_lock::{Distributed, Lock};

tokio::runtime::Runtime::new()?.block_on(async {
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Lock\Distributed;

$redis = new Redis();
$redis->connect('redis', 6379);
$redis->del('guide:rebuild-index');

$lock = new Distributed($redis, 'guide:rebuild-index', 120);
$other = new Distributed($redis, 'guide:rebuild-index', 120);

var_dump($lock->tryAcquire());  // bool(true)
var_dump($other->tryAcquire()); // bool(false)
var_dump($lock->isHeld());      // bool(true)
var_dump($lock->refresh());     // bool(true)

$lock->release();
var_dump($other->tryAcquire()); // bool(true)
$other->release();
```
