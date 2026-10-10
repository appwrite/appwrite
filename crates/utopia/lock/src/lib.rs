//! Locks with the semantics of `utopia-php/lock`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Lock\Lock` (interface) | [`Lock`] |
//! | `Lock::acquire(float $timeout)` | [`Lock::acquire`] with a [`Wait`] (`Wait::from_secs_f64` keeps PHP's float convention) |
//! | `Lock::tryAcquire()`, `release()`, `withLock()` | [`Lock::try_acquire`], [`Lock::release`], [`Lock::with_lock`] |
//! | `Utopia\Lock\Distributed` | [`Distributed`] (Redis `SET NX EX` lease with a token) |
//! | `Utopia\Lock\Mutex` | [`Mutex`] |
//! | `Utopia\Lock\Semaphore` | [`Semaphore`] |
//! | `Utopia\Lock\File` | [`File`] (`flock(2)`) |
//! | `Utopia\Lock\Exception`, `Exception\Contention` | [`Error::Lock`], [`Error::Contention`] |
//!
//! PHP's `Mutex` and `Semaphore` fall back to plain counters outside a Swoole
//! coroutine; in Rust they are always the coroutine (async) version: a held
//! lock is waited on for as long as the [`Wait`] allows.

mod distributed;
mod error;
mod file;
mod semaphore;

use std::future::Future;
use std::time::Duration;

pub use distributed::Distributed;
pub use error::Error;
pub use file::{File, Mode};
pub use semaphore::{Mutex, Semaphore};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// How long [`Lock::acquire`] waits for a lock someone else holds.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Wait {
    /// Try once.
    None,
    /// Retry until this much time has passed.
    For(Duration),
    /// Wait until acquired. [`Distributed`] and [`File`] treat this as
    /// [`Wait::None`], as PHP does for a negative timeout.
    Forever,
}

impl Wait {
    /// PHP's convention: `0.0` tries once, a negative value waits forever.
    pub fn from_secs_f64(timeout: f64) -> Self {
        if timeout < 0.0 {
            Wait::Forever
        } else if timeout == 0.0 || timeout.is_nan() {
            Wait::None
        } else {
            Wait::For(Duration::from_secs_f64(timeout.min(1e9)))
        }
    }

    /// The timeout as PHP would have passed it, in seconds.
    pub fn as_secs_f64(self) -> f64 {
        match self {
            Wait::None => 0.0,
            Wait::For(d) => d.as_secs_f64(),
            Wait::Forever => -1.0,
        }
    }
}

/// A lock that can be acquired, released, and held around a section.
pub trait Lock: Sync {
    /// Acquires the lock, waiting as long as `wait` allows. Returns whether it was acquired.
    fn acquire(&self, wait: Wait) -> impl Future<Output = Result<bool, Error>> + Send;

    /// Tries once to acquire the lock.
    fn try_acquire(&self) -> impl Future<Output = Result<bool, Error>> + Send;

    /// Releases the lock. Safe to call when it is not held.
    fn release(&self) -> impl Future<Output = Result<(), Error>> + Send;

    /// The error [`Lock::with_lock`] returns when the lock cannot be acquired.
    fn contention(&self) -> Error;

    /// Acquires the lock, runs `section`, then releases the lock, also when
    /// `section` returns an error. Fails with [`Error::Contention`] when the
    /// lock cannot be acquired in time.
    fn with_lock<T: Send, F: Future<Output = T> + Send>(
        &self,
        wait: Wait,
        section: F,
    ) -> impl Future<Output = Result<T, Error>> + Send {
        async move {
            if !self.acquire(wait).await? {
                return Err(self.contention());
            }
            let result = section.await;
            self.release().await?;
            Ok(result)
        }
    }
}
