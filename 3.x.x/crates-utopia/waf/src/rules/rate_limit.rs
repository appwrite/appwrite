use crate::condition::Condition;
use crate::error::InvalidArgumentError;
use crate::rule::{Rule, RuleInner, ACTION_RATE_LIMIT};
use std::any::Any;

/// Rate-limit metadata rule (`Utopia\WAF\Rules\RateLimit`).
///
/// Stores `limit` + `interval` for an external throttler. Matching this rule
/// allows the request (`verify()` returns `true`).
#[derive(Debug, Clone)]
pub struct RateLimit {
    inner: RuleInner,
    limit: i64,
    interval: i64,
}

impl RateLimit {
    /// Create a rate-limit rule.
    ///
    /// PHP defaults: `$limit = 100`, `$interval = 3600`.
    ///
    /// # Errors
    ///
    /// Returns [`InvalidArgumentError::InvalidRateLimit`] when `limit` or
    /// `interval` is less than 1.
    pub fn new(
        conditions: impl IntoIterator<Item = Condition>,
        limit: i64,
        interval: i64,
    ) -> Result<Self, InvalidArgumentError> {
        if limit < 1 || interval < 1 {
            return Err(InvalidArgumentError::InvalidRateLimit);
        }
        Ok(Self {
            inner: RuleInner::new(conditions),
            limit,
            interval,
        })
    }

    /// Configured request limit.
    pub fn get_limit(&self) -> i64 {
        self.limit
    }

    /// Window length in seconds.
    pub fn get_interval(&self) -> i64 {
        self.interval
    }

    /// Fluent identifier setter (PHP `setId`).
    pub fn set_id(mut self, id: impl Into<String>) -> Self {
        self.inner.id = Some(id.into());
        self
    }
}

impl Rule for RateLimit {
    fn get_action(&self) -> &'static str {
        ACTION_RATE_LIMIT
    }

    fn get_id(&self) -> Option<&str> {
        self.inner.id.as_deref()
    }

    fn set_id_mut(&mut self, id: String) {
        self.inner.id = Some(id);
    }

    fn get_conditions(&self) -> &[Condition] {
        &self.inner.conditions
    }

    fn add_condition(&mut self, condition: Condition) {
        self.inner.conditions.push(condition);
    }

    fn as_any(&self) -> &dyn Any {
        self
    }
}
