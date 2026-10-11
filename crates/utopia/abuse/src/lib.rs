//! Rate limits that keep clients from abusing an API: how many requests a
//! key (an IP, a user, an endpoint) may make, counted in fixed windows
//! ([`TimeLimit`]), sliding windows ([`SlidingWindow`]) or token buckets
//! ([`TokenBucket`]), stored in Redis, a Redis Cluster, a pool of
//! connections, a document store or Appwrite tables. And [`ReCaptcha`], to
//! tell people from bots.
//!
//! Every limit implements [`Adapter`]: [`Adapter::check`] records a request
//! and says whether it is over the limit, [`Adapter::peek`] says the same
//! without recording one, [`Adapter::reset`] forgets the current window.
//! Each answers a [`Verdict`] with the limit, the requests left and when the
//! window resets, ready for `X-RateLimit-*` headers. Each limit also has
//! `check_at`, `peek_at` and `reset_at`, which take the time instead of
//! reading the clock.
//!
//! ```
//! use utopia_abuse::{Adapter, TimeLimit, time_limit};
//!
//! # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
//! // Two requests per hour for each IP; None keeps no counts.
//! let limit = TimeLimit::new("ip:{ip}", 2, 3600, time_limit::None)?.with_param("{ip}", "203.0.113.7");
//! assert_eq!(limit.key(), "ip:203.0.113.7");
//! assert!(!limit.check().await?.limited);
//! # Ok::<(), utopia_abuse::Error>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\Abuse\Adapter\TimeLimit\None;
//!
//! $limit = new None('ip:{ip}', 2, 3600)->withParam('{ip}', '203.0.113.7');
//! echo $limit->key(), "\n"; // ip:203.0.113.7
//! var_dump($limit->check()->limited); // bool(false)
//! ```
//!
//! PHP: ports `utopia-php/abuse`. Keys and counters are byte for byte the
//! ones PHP writes, so both runtimes count against the same limits.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Abuse\Adapter` | [`Adapter`]; `key()`, `withParams()`, `withParam()` are [`Key`] and the `with_params`/`with_param` of each limit |
//! | `Utopia\Abuse\Result` | [`Verdict`] |
//! | `Adapter\TimeLimit` | [`TimeLimit`] over a [`time_limit::Store`] |
//! | `Adapter\TimeLimit\None`, `Redis`, `RedisCluster`, `RedisPool` | [`time_limit::None`], [`time_limit::Redis`], [`time_limit::RedisCluster`], [`time_limit::RedisPool`] |
//! | `Adapter\TimeLimit\Database` | [`time_limit::Database`] over a [`time_limit::Documents`] store |
//! | `Adapter\TimeLimit\Appwrite\TablesDB` | [`time_limit::TablesDb`] over a [`time_limit::Tables`] client |
//! | `Adapter\SlidingWindow` and its `None`, `Redis`, `RedisCluster`, `RedisPool` | [`SlidingWindow`] over [`sliding_window::None`], [`sliding_window::Redis`], ... |
//! | `Adapter\TokenBucket` and its `None`, `Redis`, `RedisCluster`, `RedisPool` | [`TokenBucket`] over [`token_bucket::None`], [`token_bucket::Redis`], ... |
//! | `Utopia\Pools\Pool<\Redis\|\RedisCluster>` given to a `RedisPool` | [`Pool`], with [`Stack`] |
//! | `Utopia\Abuse\ReCaptcha` | [`ReCaptcha`] over a [`recaptcha::Client`] |
//! | the overridable `now()` | `check_at`, `peek_at`, `reset_at` |
//! | `\InvalidArgumentException`, `\RuntimeException`, ... | [`Error`] |

#![warn(missing_docs)]

mod error;
mod key;
#[cfg(feature = "recaptcha")]
pub mod recaptcha;
#[cfg(feature = "redis")]
mod redis_conn;
pub mod sliding_window;
pub mod time_limit;
pub mod token_bucket;

use std::future::Future;

pub use error::{Error, Result};
pub use key::Key;
#[cfg(feature = "recaptcha")]
pub use recaptcha::ReCaptcha;
#[cfg(feature = "redis")]
pub use redis_conn::{Connection, Pool, Stack};
pub use sliding_window::SlidingWindow;
pub use time_limit::TimeLimit;
pub use token_bucket::TokenBucket;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// What a limit says about a request.
///
/// PHP: `Utopia\Abuse\Result`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub struct Verdict {
    /// Whether the request is over the limit.
    pub limited: bool,
    /// The limit: requests per window, or the bucket's capacity. `0` for a
    /// limit that is off.
    pub limit: i64,
    /// Requests left after this one.
    pub remaining: i64,
    /// When the window resets (or the bucket is full again), as a Unix
    /// timestamp.
    pub reset: i64,
}

/// A rate limit on one key.
///
/// [`TimeLimit`], [`SlidingWindow`] and [`TokenBucket`] implement it over any
/// of their stores.
pub trait Adapter: Send + Sync {
    /// One entry of [`Adapter::logs`].
    type Log;

    /// The key this limit counts under, its placeholders replaced.
    ///
    /// PHP: `key()`.
    fn key(&self) -> &str;

    /// Records a request, unless the limit is reached, and says whether it
    /// is over the limit.
    fn check(&self) -> impl Future<Output = Result<Verdict>> + Send;

    /// Says what [`Adapter::check`] would, without recording a request.
    fn peek(&self) -> impl Future<Output = Result<Verdict>> + Send;

    /// Forgets the requests counted in the current window.
    fn reset(&self) -> impl Future<Output = Result<()>> + Send;

    /// What the store holds, `limit` entries from `offset`.
    ///
    /// PHP: `getLogs()`, whose defaults are no offset and a limit of 25.
    fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> impl Future<Output = Result<Vec<Self::Log>>> + Send;

    /// Deletes what was recorded before `timestamp`, where the store does not
    /// expire it by itself. Returns `true` when done.
    fn cleanup(&self, timestamp: i64) -> impl Future<Output = Result<bool>> + Send;
}

/// The current Unix time in seconds.
pub(crate) fn now_secs() -> i64 {
    php_std::datetime::time()
}

/// The current Unix time in seconds, with microseconds.
pub(crate) fn now_micros() -> f64 {
    php_std::datetime::microtime_float()
}

/// `$a + $b` for a `Result` property: an integer, or the `TypeError` PHP
/// raises when the sum overflowed into a float.
pub(crate) fn add(a: i64, b: i64, property: &str) -> Result<i64> {
    a.checked_add(b).ok_or_else(|| overflow(property))
}

/// The `TypeError` of a float given to an `int` property of `Result`.
pub(crate) fn overflow(property: &str) -> Error {
    let position = match property {
        "limit" => 2,
        "remaining" => 3,
        _ => 4,
    };
    Error::Type(format!(
        "Utopia\\Abuse\\Result::__construct(): Argument #{position} (${property}) must be of type int, float given"
    ))
}

/// `max(0, $limit - $used - 1)`: requests left, never negative. A
/// difference past `i64` is a float in PHP, which `max()` turns back into
/// `0` when negative and which `Result` rejects when positive.
pub(crate) fn remaining(limit: i64, used: i64) -> Result<i64> {
    match limit.checked_sub(used) {
        Some(left) => Ok(left.checked_sub(1).unwrap_or(0).max(0)),
        None if (limit as f64 - used as f64) - 1.0 <= 0.0 => Ok(0),
        None => Err(overflow("remaining")),
    }
}
