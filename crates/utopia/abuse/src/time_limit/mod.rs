//! Fixed windows: at most `limit` requests per key in each window of
//! `seconds`, the windows aligned on the Unix epoch.

#[cfg(feature = "redis")]
mod redis;

mod database;
mod tables;

pub use database::{COLLECTION, Database, Documents, Entry, Memory};
#[cfg(feature = "redis-cluster")]
pub use redis::RedisCluster;
#[cfg(feature = "redis")]
pub(crate) use redis::with_conn;
#[cfg(feature = "redis")]
pub use redis::{NAMESPACE, Redis, RedisPool};
pub use tables::{
    COLUMNS, Column, DATABASE_NAME, INDEXES, Index, Row, TABLE_ID, TABLE_LOCK, TABLE_NAME, Tables, TablesDb,
};

#[cfg(feature = "redis")]
pub use crate::redis_conn::Counter;

use std::future::Future;

use crate::{Adapter, Error, Key, Result, Verdict};

/// Where a [`TimeLimit`] keeps its counts: one counter per key and window.
///
/// PHP: the abstract methods of `Utopia\Abuse\Adapter\TimeLimit`. Implement
/// it to count somewhere else.
pub trait Store: Send + Sync {
    /// One entry of [`Store::logs`].
    type Log: Send;

    /// Adds one to the counter of `key` in `window` (the window's start)
    /// unless it has reached `rule.limit`, and returns the count before.
    fn hit(&self, key: &str, window: i64, rule: Rule) -> impl Future<Output = Result<i64>> + Send;

    /// The counter of `key` in `window`, `0` when there is none.
    fn count(&self, key: &str, window: i64) -> impl Future<Output = Result<i64>> + Send;

    /// Sets the counter of `key` in `window` to `value`.
    fn set(&self, key: &str, window: i64, value: i64, rule: Rule) -> impl Future<Output = Result<()>> + Send;

    /// What the store holds, `limit` entries from `offset`.
    fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> impl Future<Output = Result<Vec<Self::Log>>> + Send;

    /// Deletes counters of windows before `timestamp`.
    fn cleanup(&self, timestamp: i64) -> impl Future<Output = Result<bool>> + Send;
}

/// The settings of the limit a [`Store`] counts for.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Rule {
    /// Requests allowed per window.
    pub limit: i64,
    /// The window's length in seconds.
    pub seconds: i64,
}

/// At most `limit` requests per key in each window of `seconds`.
///
/// A window starts at a multiple of `seconds` since the Unix epoch, so every
/// server counts in the same windows. A limit of `0` is off: every request
/// passes and nothing is stored.
///
/// ```
/// use utopia_abuse::{TimeLimit, time_limit};
///
/// # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
/// let limit = TimeLimit::new("login:{email}", 5, 60, time_limit::None)?.with_param("{email}", "ada@example.com");
///
/// // Windows are aligned on minutes: this one resets at the next minute.
/// let verdict = limit.check_at(1_700_000_017).await?;
/// assert_eq!(verdict.reset, 1_700_000_040);
/// assert_eq!(verdict.limit, 5);
/// # Ok::<(), utopia_abuse::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TimeLimit\None;
///
/// $limit = new None('login:{email}', 5, 60)->withParam('{email}', 'ada@example.com');
/// $verdict = $limit->check();
/// echo $verdict->limit, "\n"; // 5
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit`. Its subclasses are the stores:
/// `new Redis($key, $limit, $seconds, $redis)` is
/// `TimeLimit::new(key, limit, seconds, time_limit::Redis::new(conn))`.
#[derive(Debug, Clone)]
pub struct TimeLimit<S> {
    key: Key,
    rule: Rule,
    store: S,
}

impl<S: Store> TimeLimit<S> {
    /// A limit of `limit` requests per `seconds` on the key `pattern`, counted
    /// in `store`. Fails with [`Error::InvalidArgument`] when `seconds` is not
    /// positive.
    pub fn new(pattern: impl Into<String>, limit: i64, seconds: i64, store: S) -> Result<Self> {
        if seconds <= 0 {
            return Err(Error::InvalidArgument("seconds must be greater than 0".into()));
        }
        Ok(TimeLimit { key: Key::new(pattern), rule: Rule { limit, seconds }, store })
    }

    /// A copy whose key has `params` merged in (see [`Key::with_params`]),
    /// counting in the same store.
    ///
    /// PHP: `withParams()`.
    pub fn with_params<K: Into<String>, V: Into<String>>(&self, params: impl IntoIterator<Item = (K, V)>) -> Self
    where
        S: Clone,
    {
        TimeLimit { key: self.key.with_params(params), rule: self.rule, store: self.store.clone() }
    }

    /// A copy whose key has one parameter set.
    ///
    /// PHP: `withParam()`.
    pub fn with_param(&self, name: impl Into<String>, value: impl Into<String>) -> Self
    where
        S: Clone,
    {
        self.with_params([(name.into(), value.into())])
    }

    /// The key, with its pattern and parameters.
    pub fn pattern(&self) -> &Key {
        &self.key
    }

    /// The limit's settings.
    pub fn rule(&self) -> Rule {
        self.rule
    }

    /// The store.
    pub fn store(&self) -> &S {
        &self.store
    }

    /// The start of the window `now` falls in.
    pub fn window(&self, now: i64) -> i64 {
        now - now % self.rule.seconds
    }

    /// [`Adapter::check`] at `now` (Unix seconds).
    pub async fn check_at(&self, now: i64) -> Result<Verdict> {
        let window = self.window(now);
        if self.rule.limit == 0 {
            return self.unlimited(window);
        }
        let used = self.store.hit(self.key.as_str(), window, self.rule).await?;
        self.verdict(used, window)
    }

    /// [`Adapter::peek`] at `now` (Unix seconds).
    pub async fn peek_at(&self, now: i64) -> Result<Verdict> {
        let window = self.window(now);
        if self.rule.limit == 0 {
            return self.unlimited(window);
        }
        let used = self.store.count(self.key.as_str(), window).await?;
        self.verdict(used, window)
    }

    /// [`Adapter::reset`] at `now` (Unix seconds): sets the count of the
    /// window `now` falls in to zero.
    pub async fn reset_at(&self, now: i64) -> Result<()> {
        self.store.set(self.key.as_str(), self.window(now), 0, self.rule).await
    }

    fn verdict(&self, used: i64, window: i64) -> Result<Verdict> {
        Ok(Verdict {
            limited: used >= self.rule.limit,
            limit: self.rule.limit,
            remaining: crate::remaining(self.rule.limit, used)?,
            reset: crate::add(window, self.rule.seconds, "reset")?,
        })
    }

    fn unlimited(&self, window: i64) -> Result<Verdict> {
        Ok(Verdict { limited: false, limit: 0, remaining: 0, reset: crate::add(window, self.rule.seconds, "reset")? })
    }
}

impl<S: Store> Adapter for TimeLimit<S> {
    type Log = S::Log;

    fn key(&self) -> &str {
        self.key.as_str()
    }

    /// Counts the request in the current window unless the window is full.
    async fn check(&self) -> Result<Verdict> {
        self.check_at(crate::now_secs()).await
    }

    /// The current window's count, without counting a request.
    async fn peek(&self) -> Result<Verdict> {
        self.peek_at(crate::now_secs()).await
    }

    /// Sets the current window's count to zero.
    async fn reset(&self) -> Result<()> {
        self.reset_at(crate::now_secs()).await
    }

    /// What the store holds.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<S::Log>> {
        self.store.logs(offset, limit).await
    }

    /// Deletes counts of windows before `timestamp`.
    async fn cleanup(&self, timestamp: i64) -> Result<bool> {
        self.store.cleanup(timestamp).await
    }
}

/// A store that keeps nothing: every request passes.
///
/// PHP: `Utopia\Abuse\Adapter\TimeLimit\None`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct None;

impl Store for None {
    type Log = std::convert::Infallible;

    /// Always `0`.
    async fn hit(&self, _key: &str, _window: i64, _rule: Rule) -> Result<i64> {
        Ok(0)
    }

    /// Always `0`.
    async fn count(&self, _key: &str, _window: i64) -> Result<i64> {
        Ok(0)
    }

    /// Does nothing.
    async fn set(&self, _key: &str, _window: i64, _value: i64, _rule: Rule) -> Result<()> {
        Ok(())
    }

    /// Always empty.
    async fn logs(&self, _offset: Option<i64>, _limit: Option<i64>) -> Result<Vec<Self::Log>> {
        Ok(Vec::new())
    }

    /// Always `true`.
    async fn cleanup(&self, _timestamp: i64) -> Result<bool> {
        Ok(true)
    }
}
