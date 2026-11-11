Opening a connection to a database or a cache costs a round trip or more, so a service keeps a few open and reuses them. A pool does the bookkeeping: it creates connections on demand up to a fixed size, lends them one caller at a time, takes them back, and makes callers wait when all are lent. The pool is async, so the examples run it with `utopia_async::run`. This guide covers the common tasks.

## Run work with a pooled connection

`with` borrows a connection, runs the work and returns the connection, also when the work fails. The pool creates connections with the `init` function you give it, the first time it needs each one.

```
use utopia_pools::{Pool, Stack};

let pool = Pool::new(Stack::new(), "db", 4, || async { Ok::<_, std::io::Error>("connection".to_owned()) }, 1.0)?;

let length = utopia_async::run(async {
    pool.with(async |connection: &String| Ok::<_, utopia_pools::Error>(connection.len())).await
})?;

assert_eq!(length, 10);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

$pool = new Pool(new Stack(), 'db', 4, fn () => 'connection', timeout: 1.0);

echo $pool->use(fn (string $connection) => strlen($connection)), "\n"; // 10
```

## Borrow and return by hand

`pop` lends a connection until you hand it back: `reclaim` it to reuse it, or `destroy` it to discard it and free its place for a new one.

```
use utopia_pools::{Pool, Stack};

let pool = Pool::new(Stack::new(), "db", 2, || async { Ok::<_, std::io::Error>("connection".to_owned()) }, 0.0)?;

utopia_async::run(async {
    let connection = pool.pop().await?;
    assert_eq!(pool.count(), 1);
    pool.reclaim(&connection);

    let broken = pool.pop().await?;
    pool.destroy(&broken);
    assert!(pool.is_full());
    Ok::<_, utopia_pools::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

$pool = new Pool(new Stack(), 'db', 2, fn () => 'connection', timeout: 0.0);

$connection = $pool->pop();
echo $pool->count(), "\n"; // 1
$pool->reclaim($connection);

$broken = $pool->pop();
$pool->destroy($broken);
var_dump($pool->isFull()); // bool(true)
```

## Wait for a connection

With the `Channel` adapter, a caller that finds every connection lent waits up to the pool's timeout for one to come back. When none does, `pop` fails.

```
use utopia_pools::{Channel, Pool};

let pool = Pool::new(Channel::new(), "db", 1, || async { Ok::<_, std::io::Error>("connection".to_owned()) }, 0.05)?;

utopia_async::run(async {
    let held = pool.pop().await?;
    let error = pool.pop().await.unwrap_err();
    assert_eq!(error.to_string(), "Pool 'db' could not provide a connection within 0.05s (size 1, active 1, idle 0)");
    pool.reclaim(&held);
    Ok::<_, utopia_pools::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Pools\Adapter\Swoole;
use Utopia\Pools\Pool;

use function Swoole\Coroutine\run;

run(function () {
    $pool = new Pool(new Swoole(), 'db', 1, fn () => 'connection', timeout: 0.05);
    $held = $pool->pop();
    try {
        $pool->pop();
    } catch (\Exception $e) {
        echo $e->getMessage(), "\n"; // Pool 'db' could not provide a connection within 0.05s (size 1, active 1, idle 0)
    }
    $pool->reclaim($held);
});
```

## Recover and keep connections alive

What happens to a connection after failed work, and while it sits idle, is up to the resource: implement `Resource`. `recover` decides whether it can be reused after a failure (otherwise it is discarded), and `tick` runs its upkeep when the host calls `maintain`.

```
use std::sync::atomic::{AtomicUsize, Ordering};

use utopia_pools::{BoxError, Pool, Resource, Stack};

struct Connection {
    pings: AtomicUsize,
}

impl Resource for Connection {
    async fn recover(&self) -> bool {
        true
    }

    async fn tick(&self) -> Result<(), BoxError> {
        self.pings.fetch_add(1, Ordering::SeqCst);
        Ok(())
    }
}

let pool = Pool::new(Stack::new(), "nats", 1, || async { Ok::<_, std::io::Error>(Connection { pings: AtomicUsize::new(0) }) }, 0.0)?;

utopia_async::run(async {
    let connection = pool.pop().await?;
    pool.reclaim(&connection);
    pool.maintain().await;
    assert_eq!(connection.resource().pings.load(Ordering::SeqCst), 1);
    Ok::<_, utopia_pools::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

$pool = new Pool(new Stack(), 'nats', 1, fn () => new class () {
    public int $pings = 0;
    public function reconnect(): bool { return true; }
    public function tick(): void { $this->pings++; }
}, timeout: 0.0);

$connection = $pool->pop();
$pool->reclaim($connection);
$pool->maintain();
echo $connection->resource->pings, "\n"; // 1
```

## Use several pools together

A `Group` keeps pools by name, to run work with a connection from each or to reclaim them all at the end of a request.

```
use utopia_pools::{Group, Pool, Stack};

let make = |name: &str, value: &'static str| Pool::new(Stack::new(), name, 1, move || async move { Ok::<_, std::io::Error>(value) }, 0.0);
let mut group = Group::new();
group.add(make("db", "postgres")?).add(make("cache", "redis")?);

let both = utopia_async::run(async {
    group.with(&["db", "cache"], async |resources| Ok::<_, utopia_pools::Error>(resources.iter().map(|r| **r).collect::<Vec<_>>().join("+"))).await
})?;

assert_eq!(both, "postgres+redis");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;

$group = new Group();
$group->add(new Pool(new Stack(), 'db', 1, fn () => 'postgres', timeout: 0.0));
$group->add(new Pool(new Stack(), 'cache', 1, fn () => 'redis', timeout: 0.0));

echo $group->use(['db', 'cache'], fn ($db, $cache) => $db . '+' . $cache), "\n"; // postgres+redis
```
