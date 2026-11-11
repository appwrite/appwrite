use std::sync::Arc;

use serde_json::Value;

use crate::resources::Resource;

/// Builds a param's default from its injections.
pub type DefaultFactory = Arc<dyn Fn(&[Resource]) -> Value + Send + Sync>;

/// A param's default: a value, or a factory called with its injections.
///
/// Re-exported from the crate root as `ParamDefault`.
#[derive(Clone)]
pub enum Default {
    /// A fixed default value.
    Value(Value),
    /// A factory that builds the default from the param's injections.
    Factory(DefaultFactory),
}

impl Default {
    pub(crate) fn is_null(&self) -> bool {
        matches!(self, Default::Value(Value::Null))
    }
}

impl From<Value> for Default {
    /// A fixed default value.
    fn from(v: Value) -> Self {
        Default::Value(v)
    }
}
