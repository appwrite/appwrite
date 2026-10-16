use crate::condition::Condition;
use crate::rule::{Rule, RuleInner, ACTION_DENY};
use std::any::Any;

/// Block the request (`Utopia\WAF\Rules\Deny`).
#[derive(Debug, Clone, Default)]
pub struct Deny {
    inner: RuleInner,
}

impl Deny {
    /// Create a deny rule with the given conditions.
    pub fn new(conditions: impl IntoIterator<Item = Condition>) -> Self {
        Self {
            inner: RuleInner::new(conditions),
        }
    }

    /// Fluent identifier setter (PHP `setId`).
    pub fn set_id(mut self, id: impl Into<String>) -> Self {
        self.inner.id = Some(id.into());
        self
    }
}

impl Rule for Deny {
    fn get_action(&self) -> &'static str {
        ACTION_DENY
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
