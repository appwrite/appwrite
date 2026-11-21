use std::sync::Arc;

use utopia_validators::Validator;

use crate::resources::Resource;

/// Builds a param's validator from its injections.
pub type ValidatorFactory = Arc<dyn Fn(&[Resource]) -> Arc<dyn Validator> + Send + Sync>;

/// A param's validator: an instance, or a factory called with its injections.
#[derive(Clone)]
pub enum Check {
    /// A validator instance.
    Validator(Arc<dyn Validator>),
    /// A factory that builds the validator from the param's injections.
    Factory(ValidatorFactory),
}

impl<V: Validator + 'static> From<V> for Check {
    /// Wraps a validator instance.
    fn from(v: V) -> Self {
        Check::Validator(Arc::new(v))
    }
}
