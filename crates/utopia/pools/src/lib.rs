//! Pools of resources that are costly to create and safe to reuse, such as
//! database or cache connections.
//!
//! A [`Pool`] creates resources on demand up to a fixed size, lends them as
//! [`Connection`]s, and takes them back for reuse or discards them. Callers
//! that find every resource lent wait up to the pool's timeout. Idle
//! connections live in an [`Adapter`]: a [`Stack`] that never waits, or a
//! [`Channel`] whose callers wait for a connection to come back. A
//! [`Group`] keeps pools by name. Pools report wait and use times and their
//! connection counts to a telemetry backend.
//!
//! What a pool does with a resource after failed work, and the upkeep of
//! idle resources, is up to the resource: implement [`Resource`].
//!
//! ```
//! use utopia_pools::{Channel, Pool};
//!
//! # tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
//! let pool = Pool::new(Channel::new(), "db", 4, || async { Ok::<_, std::io::Error>("connection".to_owned()) }, 1.0)?;
//!
//! let length = pool.with(async |connection: &String| Ok::<_, utopia_pools::Error>(connection.len())).await?;
//! assert_eq!(length, 10);
//! assert!(pool.is_full());
//! # Ok::<(), utopia_pools::Error>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\Pools\Adapter\Swoole;
//! use Utopia\Pools\Pool;
//!
//! use function Swoole\Coroutine\run;
//!
//! run(function () {
//!     $pool = new Pool(new Swoole(), 'db', 4, fn () => 'connection', timeout: 1.0);
//!
//!     echo $pool->use(fn (string $connection) => strlen($connection)), "\n"; // 10
//!     var_dump($pool->isFull()); // bool(true)
//! });
//! ```
//!
//! PHP: ports `utopia-php/pools`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Pools\Pool` | [`Pool`] |
//! | `Pool::use()`, `pop()`, `push()`, `release()`, `maintain()` | [`Pool::with`], [`Pool::pop`], [`Pool::push`], [`Pool::release`], [`Pool::maintain`] |
//! | `Pool::reclaim($c)` / `reclaim()`, `destroy($c)` / `destroy()` | [`Pool::reclaim`] / [`Pool::reclaim_all`], [`Pool::destroy`] / [`Pool::destroy_all`] |
//! | `Pool::count()`, `isEmpty()`, `isFull()`, `$name`, `$size`, `$timeout` | [`Pool::count`], [`Pool::is_empty`], [`Pool::is_full`], [`Pool::name`], [`Pool::size`], [`Pool::timeout`] |
//! | `Utopia\Pools\Connection` | [`Connection`] |
//! | `Utopia\Pools\Group` | [`Group`] (`use()` is [`Group::with`]) |
//! | `Utopia\Pools\Adapter` | [`Adapter`] |
//! | `Adapter\Stack` | [`Stack`] |
//! | `Adapter\Swoole` (coroutine channels) | [`Channel`] (tasks on an async runtime) |
//! | a resource's `reset()`, `reconnect()` and `tick()` methods | [`Resource::recover`], [`Resource::tick`] |
//! | `\InvalidArgumentException`, `\Exception` | [`Error::InvalidArgument`], [`Error::Exhausted`], [`Error::NotFound`], [`Error::Empty`]; `init`'s own error is [`Error::Init`] |

#![warn(missing_docs)]

mod adapter;
mod group;
mod pool;

use std::future::Future;

pub use adapter::{Adapter, Channel, Stack};
pub use group::Group;
pub use pool::{Connection, Pool};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Any error, as `init` and [`Resource::tick`] return them.
pub type BoxError = Box<dyn std::error::Error + Send + Sync>;

/// Why a pool operation failed.
///
/// PHP: one variant per PHP exception (see [`Error::php_class`]).
#[derive(Debug, thiserror::Error)]
pub enum Error {
    /// A size below 1 or a negative timeout.
    ///
    /// PHP: `\InvalidArgumentException`.
    #[error("{0}")]
    InvalidArgument(String),
    /// No connection became available within the timeout.
    ///
    /// PHP: `\Exception` (`Pool '...' could not provide a connection ...`).
    #[error("{0}")]
    Exhausted(String),
    /// A group has no pool of that name.
    ///
    /// PHP: `\Exception` (`Pool '...' not found`).
    #[error("{0}")]
    NotFound(String),
    /// A group was asked for no pools.
    ///
    /// PHP: `\Exception` (`Cannot use with empty names`).
    #[error("{0}")]
    Empty(String),
    /// Creating a resource failed: `init`'s error, untouched.
    ///
    /// PHP: whatever `init` threw, propagated as is.
    #[error("{0}")]
    Init(BoxError),
}

impl Error {
    /// The name of the exception class this error matches; for
    /// [`Error::Init`], `Exception` (the class of `init`'s error is its own).
    ///
    /// PHP: the class `utopia-php/pools` throws in the same case.
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
            Error::Exhausted(_) | Error::NotFound(_) | Error::Empty(_) | Error::Init(_) => "Exception",
        }
    }
}

/// What a pooled resource does when the pool needs it to: recover after
/// failed work, and keep itself alive while idle. Both default to nothing:
/// a resource that cannot recover is discarded after a failure.
///
/// PHP: the duck-typed `reset()`, `reconnect()` and `tick()` methods the
/// pool probes for; a resource without `reset()` or `reconnect()` cannot
/// recover, except scalars, which are always reusable.
pub trait Resource: Send + Sync + 'static {
    /// Makes the resource reusable after failed work (resets its state,
    /// reconnects); returns whether it is. `false` discards it.
    ///
    /// PHP: `reset()` then `reconnect()`; either returning `false` or
    /// throwing means not recovered.
    fn recover(&self) -> impl Future<Output = bool> + Send {
        async { false }
    }

    /// Runs the resource's upkeep while idle (a ping a server expects); an
    /// error discards it.
    ///
    /// PHP: `tick()`.
    fn tick(&self) -> impl Future<Output = Result<(), BoxError>> + Send {
        async { Ok(()) }
    }
}

macro_rules! plain {
    ($($t:ty),*) => {$(
        /// A plain value: always reusable, no upkeep.
        impl Resource for $t {
            async fn recover(&self) -> bool {
                true
            }
        }
    )*};
}

plain!(String, &'static str, i64, u64, i32, u32, bool, ());

#[cfg(test)]
mod tests {
    use std::sync::Arc;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::time::Duration;

    use super::*;

    fn pool(size: usize, timeout: f64) -> Pool<String, Channel<String>> {
        Pool::new(Channel::new(), "test", size, || async { Ok::<_, std::io::Error>("x".to_owned()) }, timeout).unwrap()
    }

    #[tokio::test]
    async fn pops_until_exhausted_then_reports_counts() {
        let pool = pool(2, 0.0);
        let a = pool.pop().await.unwrap();
        let _b = pool.pop().await.unwrap();
        let error = pool.pop().await.unwrap_err();
        assert_eq!(
            error.to_string(),
            "Pool 'test' could not provide a connection within 0s (size 2, active 2, idle 0)"
        );
        pool.reclaim(&a);
        assert_eq!(pool.count(), 1);
    }

    #[tokio::test(flavor = "multi_thread", worker_threads = 4)]
    async fn waiters_share_the_pool_without_exceeding_it() {
        let created = Arc::new(AtomicUsize::new(0));
        let c = created.clone();
        let pool = Pool::new(
            Channel::new(),
            "stress",
            3,
            move || {
                c.fetch_add(1, Ordering::SeqCst);
                async { Ok::<_, std::io::Error>("x".to_owned()) }
            },
            5.0,
        )
        .unwrap();
        let tasks: Vec<_> = (0..20)
            .map(|_| {
                let pool = pool.clone();
                tokio::spawn(async move {
                    pool.with(async |_: &String| {
                        tokio::time::sleep(Duration::from_millis(5)).await;
                        Ok::<_, Error>(())
                    })
                    .await
                })
            })
            .collect();
        for t in tasks {
            t.await.unwrap().unwrap();
        }
        assert_eq!(created.load(Ordering::SeqCst), 3);
        assert!(pool.is_full());
    }

    #[test]
    fn rejects_invalid_sizes() {
        let error = Pool::new(Stack::<String>::new(), "p", 0, || async { Ok::<_, std::io::Error>(String::new()) }, 0.0)
            .unwrap_err();
        assert_eq!(error.to_string(), "Pool 'p' size must be at least 1, got 0.");
    }
}
