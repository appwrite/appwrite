# Utopia Pools

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/pools`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/pools) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Fixed-capacity pools of lazily created resources (connections), with bounded waits, recovery, upkeep, groups and telemetry.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-pools = { git = "https://github.com/utopia-rs/pools" }
```

Opening a connection to a database or a cache costs a round trip or more, so a service keeps a few open and reuses them. A pool does the bookkeeping: it creates connections on demand up to a fixed size, lends them one caller at a time, takes them back, and makes callers wait when all are lent. The pool is async, so the examples run it with `utopia_async::run`. This guide covers the common tasks.

## Run work with a pooled connection

`with` borrows a connection, runs the work and returns the connection, also when the work fails. The pool creates connections with the `init` function you give it, the first time it needs each one.

```rust
use utopia_pools::{Pool, Stack};

let pool = Pool::new(Stack::new(), "db", 4, || async { Ok::<_, std::io::Error>("connection".to_owned()) }, 1.0)?;

let length = utopia_async::run(async {
    pool.with(async |connection: &String| Ok::<_, utopia_pools::Error>(connection.len())).await
})?;

assert_eq!(length, 10);
```

## Borrow and return by hand

`pop` lends a connection until you hand it back: `reclaim` it to reuse it, or `destroy` it to discard it and free its place for a new one.

```rust
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
```

## Wait for a connection

With the `Channel` adapter, a caller that finds every connection lent waits up to the pool's timeout for one to come back. When none does, `pop` fails.

```rust
use utopia_pools::{Channel, Pool};

let pool = Pool::new(Channel::new(), "db", 1, || async { Ok::<_, std::io::Error>("connection".to_owned()) }, 0.05)?;

utopia_async::run(async {
    let held = pool.pop().await?;
    let error = pool.pop().await.unwrap_err();
    assert_eq!(error.to_string(), "Pool 'db' could not provide a connection within 0.05s (size 1, active 1, idle 0)");
    pool.reclaim(&held);
    Ok::<_, utopia_pools::Error>(())
})?;
```

## Recover and keep connections alive

What happens to a connection after failed work, and while it sits idle, is up to the resource: implement `Resource`. `recover` decides whether it can be reused after a failure (otherwise it is discarded), and `tick` runs its upkeep when the host calls `maintain`.

```rust
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
```

## Use several pools together

A `Group` keeps pools by name, to run work with a connection from each or to reclaim them all at the end of a request.

```rust
use utopia_pools::{Group, Pool, Stack};

let make = |name: &str, value: &'static str| Pool::new(Stack::new(), name, 1, move || async move { Ok::<_, std::io::Error>(value) }, 0.0);
let mut group = Group::new();
group.add(make("db", "postgres")?).add(make("cache", "redis")?);

let both = utopia_async::run(async {
    group.with(&["db", "cache"], async |resources| Ok::<_, utopia_pools::Error>(resources.iter().map(|r| **r).collect::<Vec<_>>().join("+"))).await
})?;

assert_eq!(both, "postgres+redis");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
