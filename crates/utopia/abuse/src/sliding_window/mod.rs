//! Sliding windows: the current window's count plus the previous window's,
//! weighted by how much of it the sliding window still covers.

#[cfg(feature = "redis")]
mod redis;

#[cfg(feature = "redis-cluster")]
pub use redis::RedisCluster;
#[cfg(feature = "redis")]
pub use redis::{Redis, RedisPool};

#[cfg(feature = "redis")]
pub use crate::redis_conn::Counter;

use std::future::Future;

use crate::{Adapter, Error, Key, Result, Verdict};

/// Where a [`SlidingWindow`] keeps its counts: one bucket per key and
/// window.
///
/// PHP: the abstract methods of `Utopia\Abuse\Adapter\SlidingWindow`.
/// Implement it to count somewhere else.
pub trait Store: Send + Sync {
    /// One entry of [`Store::logs`].
    type Log: Send;

    /// Checks that the store can count for `rule`, when the limit is built.
    fn validate(&self, rule: Rule) -> Result<()> {
        let _ = rule;
        Ok(())
    }

    /// Estimates the requests in the sliding window (the previous bucket
    /// weighted by `1 - elapsed`, plus the current one) and counts this one
    /// when the estimate is under the limit. Returns the estimate, rounded
    /// down.
    fn hit(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> impl Future<Output = Result<i64>> + Send;

    /// The estimate, without counting a request.
    fn count(&self, key: &str, window: i64, elapsed: f64, rule: Rule) -> impl Future<Output = Result<i64>> + Send;

    /// Clears the buckets of `window` and the one before.
    fn clear(&self, key: &str, window: i64, rule: Rule) -> impl Future<Output = Result<()>> + Send;

    /// What the store holds, `limit` entries from `offset`.
    fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> impl Future<Output = Result<Vec<Self::Log>>> + Send;

    /// Deletes buckets older than `timestamp`.
    fn cleanup(&self, timestamp: i64) -> impl Future<Output = Result<bool>> + Send;
}

/// The settings of the limit a [`Store`] counts for.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Rule {
    /// Requests allowed per window.
    pub limit: i64,
    /// The window's length in seconds.
    pub window: i64,
}

/// At most `limit` requests per key in any `window_size` seconds, estimated
/// from fixed buckets: a request at 25% into a window counts 75% of the
/// previous window's requests and all of the current one's.
///
/// ```
/// use utopia_abuse::{SlidingWindow, sliding_window};
///
/// # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
/// let limit = SlidingWindow::new("ip:{ip}", 100, 60, sliding_window::None)?;
/// let verdict = limit.check_at(1_700_000_000).await?;
/// assert_eq!((verdict.limit, verdict.reset), (100, 1_700_000_040));
/// # Ok::<(), utopia_abuse::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\SlidingWindow\None;
///
/// $limit = new None('ip:{ip}', 100, 60);
/// echo $limit->check()->limit, "\n"; // 100
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\SlidingWindow`.
#[derive(Debug, Clone)]
pub struct SlidingWindow<S> {
    key: Key,
    rule: Rule,
    store: S,
}

impl<S: Store> SlidingWindow<S> {
    /// A limit of `limit` requests per `window_size` seconds on the key
    /// `pattern`, counted in `store`. Fails with [`Error::InvalidArgument`]
    /// when `window_size` is not positive.
    pub fn new(pattern: impl Into<String>, limit: i64, window_size: i64, store: S) -> Result<Self> {
        if window_size <= 0 {
            return Err(Error::InvalidArgument("windowSize must be greater than 0".into()));
        }
        let rule = Rule { limit, window: window_size };
        store.validate(rule)?;
        Ok(SlidingWindow { key: Key::new(pattern), rule, store })
    }

    /// A copy whose key has `params` merged in (see [`Key::with_params`]).
    ///
    /// PHP: `withParams()`.
    pub fn with_params<K: Into<String>, V: Into<String>>(&self, params: impl IntoIterator<Item = (K, V)>) -> Self
    where
        S: Clone,
    {
        SlidingWindow { key: self.key.with_params(params), rule: self.rule, store: self.store.clone() }
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

    /// The start of the window `now` falls in, and how far into it `now` is
    /// (from 0 to 1).
    pub fn window(&self, now: i64) -> (i64, f64) {
        let start = now - now % self.rule.window;
        (start, (now - start) as f64 / self.rule.window as f64)
    }

    /// [`Adapter::check`] at `now` (Unix seconds).
    pub async fn check_at(&self, now: i64) -> Result<Verdict> {
        let (window, elapsed) = self.window(now);
        if self.rule.limit == 0 {
            return self.unlimited(window);
        }
        let used = self.store.hit(self.key.as_str(), window, elapsed, self.rule).await?;
        self.verdict(used, window)
    }

    /// [`Adapter::peek`] at `now` (Unix seconds).
    pub async fn peek_at(&self, now: i64) -> Result<Verdict> {
        let (window, elapsed) = self.window(now);
        if self.rule.limit == 0 {
            return self.unlimited(window);
        }
        let used = self.store.count(self.key.as_str(), window, elapsed, self.rule).await?;
        self.verdict(used, window)
    }

    /// [`Adapter::reset`] at `now` (Unix seconds): clears the buckets of the
    /// window `now` falls in and the one before.
    pub async fn reset_at(&self, now: i64) -> Result<()> {
        let (window, _) = self.window(now);
        self.store.clear(self.key.as_str(), window, self.rule).await
    }

    fn verdict(&self, used: i64, window: i64) -> Result<Verdict> {
        Ok(Verdict {
            limited: used >= self.rule.limit,
            limit: self.rule.limit,
            remaining: crate::remaining(self.rule.limit, used)?,
            reset: crate::add(window, self.rule.window, "reset")?,
        })
    }

    fn unlimited(&self, window: i64) -> Result<Verdict> {
        Ok(Verdict { limited: false, limit: 0, remaining: 0, reset: crate::add(window, self.rule.window, "reset")? })
    }
}

impl<S: Store> Adapter for SlidingWindow<S> {
    type Log = S::Log;

    fn key(&self) -> &str {
        self.key.as_str()
    }

    /// Counts the request unless the sliding window is full.
    async fn check(&self) -> Result<Verdict> {
        self.check_at(crate::now_secs()).await
    }

    /// The sliding window's estimate, without counting a request.
    async fn peek(&self) -> Result<Verdict> {
        self.peek_at(crate::now_secs()).await
    }

    /// Clears the current and previous buckets.
    async fn reset(&self) -> Result<()> {
        self.reset_at(crate::now_secs()).await
    }

    /// What the store holds.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<S::Log>> {
        self.store.logs(offset, limit).await
    }

    /// Deletes buckets older than `timestamp`.
    async fn cleanup(&self, timestamp: i64) -> Result<bool> {
        self.store.cleanup(timestamp).await
    }
}

/// A store that keeps nothing: every request passes.
///
/// PHP: `Utopia\Abuse\Adapter\SlidingWindow\None`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct None;

impl Store for None {
    type Log = std::convert::Infallible;

    /// Always `0`.
    async fn hit(&self, _key: &str, _window: i64, _elapsed: f64, _rule: Rule) -> Result<i64> {
        Ok(0)
    }

    /// Always `0`.
    async fn count(&self, _key: &str, _window: i64, _elapsed: f64, _rule: Rule) -> Result<i64> {
        Ok(0)
    }

    /// Does nothing.
    async fn clear(&self, _key: &str, _window: i64, _rule: Rule) -> Result<()> {
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
