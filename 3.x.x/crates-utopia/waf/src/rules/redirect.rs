use crate::condition::Condition;
use crate::rule::{Rule, RuleInner, ACTION_REDIRECT};
use std::any::Any;

/// Redirect the client (`Utopia\WAF\Rules\Redirect`).
#[derive(Debug, Clone)]
pub struct Redirect {
    inner: RuleInner,
    location: String,
    status_code: i64,
}

impl Redirect {
    /// Create a redirect rule.
    ///
    /// PHP defaults: `$location = '/'`, `$statusCode = 302`.
    pub fn new(
        conditions: impl IntoIterator<Item = Condition>,
        location: impl Into<String>,
        status_code: i64,
    ) -> Self {
        Self {
            inner: RuleInner::new(conditions),
            location: location.into(),
            status_code,
        }
    }

    /// Redirect target.
    pub fn get_location(&self) -> &str {
        &self.location
    }

    /// HTTP status code.
    pub fn get_status_code(&self) -> i64 {
        self.status_code
    }

    /// Fluent identifier setter (PHP `setId`).
    pub fn set_id(mut self, id: impl Into<String>) -> Self {
        self.inner.id = Some(id.into());
        self
    }
}

impl Default for Redirect {
    fn default() -> Self {
        Self::new(Vec::<Condition>::new(), "/", 302)
    }
}

impl Rule for Redirect {
    fn get_action(&self) -> &'static str {
        ACTION_REDIRECT
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
