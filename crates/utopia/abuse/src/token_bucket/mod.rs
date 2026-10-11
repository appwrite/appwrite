//! Token buckets: a key holds up to `tokens` tokens, refilled at
//! `refill_rate` a second; each request takes one.

#[cfg(feature = "redis")]
mod redis;

#[cfg(feature = "redis-cluster")]
pub use redis::RedisCluster;
#[cfg(feature = "redis")]
pub use redis::{Bucket, Redis, RedisPool};

use std::future::Future;

use crate::{Adapter, Error, Key, Result, Verdict};

/// Where a [`TokenBucket`] keeps its buckets.
///
/// PHP: the abstract methods of `Utopia\Abuse\Adapter\TokenBucket`.
/// Implement it to keep buckets somewhere else.
pub trait Store: Send + Sync {
    /// One entry of [`Store::logs`].
    type Log: Send;

    /// Refills the bucket of `key` up to `now`, takes a token when one is
    /// available, and returns the balance before taking it.
    fn hit(&self, key: &str, now: f64, rule: Rule) -> impl Future<Output = Result<f64>> + Send;

    /// The refilled balance, without taking a token.
    fn count(&self, key: &str, now: f64, rule: Rule) -> impl Future<Output = Result<f64>> + Send;

    /// Empties the record of `key`, so its bucket is full again.
    fn clear(&self, key: &str) -> impl Future<Output = Result<()>> + Send;

    /// What the store holds, `limit` entries from `offset`.
    fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> impl Future<Output = Result<Vec<Self::Log>>> + Send;

    /// Deletes buckets idle since before `timestamp`.
    fn cleanup(&self, timestamp: i64) -> impl Future<Output = Result<bool>> + Send;
}

/// The settings of the bucket a [`Store`] keeps.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Rule {
    /// The bucket's capacity.
    pub tokens: i64,
    /// Tokens refilled per second.
    pub refill_rate: f64,
}

/// Bursts of up to `tokens` requests per key, then `refill_rate` requests a
/// second. A capacity of `0` is off: every request passes.
///
/// ```
/// use utopia_abuse::{TokenBucket, token_bucket};
///
/// # tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
/// // A full bucket of 10, refilled at 1 token every 2 seconds.
/// let limit = TokenBucket::new("ip:{ip}", 10, 0.5, token_bucket::None)?;
/// let verdict = limit.check_at(1_700_000_000.25).await?;
/// assert_eq!((verdict.limited, verdict.remaining), (false, 9));
/// // Full again two seconds after the token was taken.
/// assert_eq!(verdict.reset, 1_700_000_003);
/// # Ok::<(), utopia_abuse::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Abuse\Adapter\TokenBucket\None;
///
/// $limit = new None('ip:{ip}', 10, 0.5);
/// echo $limit->check()->remaining, "\n"; // 9
/// ```
///
/// PHP: `Utopia\Abuse\Adapter\TokenBucket`.
#[derive(Debug, Clone)]
pub struct TokenBucket<S> {
    key: Key,
    rule: Rule,
    store: S,
}

impl<S: Store> TokenBucket<S> {
    /// A bucket of `tokens` refilled at `refill_rate` tokens a second, on the
    /// key `pattern`, kept in `store`. Fails with [`Error::InvalidArgument`]
    /// when a bucket with tokens does not refill.
    pub fn new(pattern: impl Into<String>, tokens: i64, refill_rate: f64, store: S) -> Result<Self> {
        if tokens > 0 && refill_rate <= 0.0 {
            return Err(Error::InvalidArgument("refillRate must be greater than 0".into()));
        }
        Ok(TokenBucket { key: Key::new(pattern), rule: Rule { tokens, refill_rate }, store })
    }

    /// A copy whose key has `params` merged in (see [`Key::with_params`]).
    ///
    /// PHP: `withParams()`.
    pub fn with_params<K: Into<String>, V: Into<String>>(&self, params: impl IntoIterator<Item = (K, V)>) -> Self
    where
        S: Clone,
    {
        TokenBucket { key: self.key.with_params(params), rule: self.rule, store: self.store.clone() }
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

    /// The bucket's settings.
    pub fn rule(&self) -> Rule {
        self.rule
    }

    /// The store.
    pub fn store(&self) -> &S {
        &self.store
    }

    /// [`Adapter::check`] at `now` (Unix seconds, with a fraction).
    pub async fn check_at(&self, now: f64) -> Result<Verdict> {
        if self.rule.tokens == 0 {
            return Ok(self.unlimited(now));
        }
        let available = self.store.hit(self.key.as_str(), now, self.rule).await?;
        self.verdict(available, now, true)
    }

    /// [`Adapter::peek`] at `now` (Unix seconds, with a fraction).
    pub async fn peek_at(&self, now: f64) -> Result<Verdict> {
        if self.rule.tokens == 0 {
            return Ok(self.unlimited(now));
        }
        let available = self.store.count(self.key.as_str(), now, self.rule).await?;
        self.verdict(available, now, false)
    }

    /// [`Adapter::reset`]: the bucket is full again.
    pub async fn reset_at(&self, _now: f64) -> Result<()> {
        self.store.clear(self.key.as_str()).await
    }

    fn unlimited(&self, now: f64) -> Verdict {
        Verdict { limited: false, limit: 0, remaining: 0, reset: php_std::format::dval_to_lval(now) }
    }

    fn verdict(&self, available: f64, now: f64, consumed: bool) -> Result<Verdict> {
        let limited = available < 1.0;
        let balance = if consumed && !limited { available - 1.0 } else { available };
        if self.rule.refill_rate == 0.0 {
            return Err(Error::DivisionByZero("Division by zero".into()));
        }
        let floor = php_std::format::dval_to_lval(available.floor());
        let full = now + (self.rule.tokens as f64 - balance) / self.rule.refill_rate;
        Ok(Verdict {
            limited,
            limit: self.rule.tokens,
            remaining: floor.checked_sub(1).unwrap_or(0).max(0),
            reset: php_std::format::dval_to_lval(full.ceil()),
        })
    }
}

impl<S: Store> Adapter for TokenBucket<S> {
    type Log = S::Log;

    fn key(&self) -> &str {
        self.key.as_str()
    }

    /// Takes a token when one is available.
    async fn check(&self) -> Result<Verdict> {
        self.check_at(crate::now_micros()).await
    }

    /// The balance, without taking a token.
    async fn peek(&self) -> Result<Verdict> {
        self.peek_at(crate::now_micros()).await
    }

    /// Fills the bucket.
    async fn reset(&self) -> Result<()> {
        self.reset_at(crate::now_micros()).await
    }

    /// What the store holds.
    async fn logs(&self, offset: Option<i64>, limit: Option<i64>) -> Result<Vec<S::Log>> {
        self.store.logs(offset, limit).await
    }

    /// Deletes buckets idle since before `timestamp`.
    async fn cleanup(&self, timestamp: i64) -> Result<bool> {
        self.store.cleanup(timestamp).await
    }
}

/// A store whose buckets are always full: every request passes.
///
/// PHP: `Utopia\Abuse\Adapter\TokenBucket\None`.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct None;

impl Store for None {
    type Log = std::convert::Infallible;

    /// Always the capacity.
    async fn hit(&self, _key: &str, _now: f64, rule: Rule) -> Result<f64> {
        Ok(rule.tokens as f64)
    }

    /// Always the capacity.
    async fn count(&self, _key: &str, _now: f64, rule: Rule) -> Result<f64> {
        Ok(rule.tokens as f64)
    }

    /// Does nothing.
    async fn clear(&self, _key: &str) -> Result<()> {
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
