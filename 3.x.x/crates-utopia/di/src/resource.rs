use std::any::Any;
use std::sync::Arc;

use crate::error::ContainerError;

/// Type-erased value stored in the container.
#[derive(Clone)]
pub struct Resource(Arc<dyn Any + Send + Sync>);

impl Resource {
    pub fn new<T: Any + Send + Sync>(value: T) -> Self {
        Self(Arc::new(value))
    }

    pub fn bool(v: bool) -> Self {
        Self::new(v)
    }

    pub fn i64(v: i64) -> Self {
        Self::new(v)
    }

    pub fn f64(v: f64) -> Self {
        Self::new(v)
    }

    pub fn string(v: impl Into<String>) -> Self {
        Self::new(v.into())
    }

    pub fn downcast_ref<T: Any + Send + Sync>(&self) -> Option<&T> {
        self.0.downcast_ref::<T>()
    }

    pub fn get_as<T: Any + Send + Sync + Clone>(&self, id: &str) -> Result<T, ContainerError> {
        self.downcast_ref::<T>()
            .cloned()
            .ok_or_else(|| ContainerError::TypeMismatch {
                id: id.to_string(),
                expected: std::any::type_name::<T>(),
            })
    }
}

impl std::fmt::Debug for Resource {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str("Resource(..)")
    }
}
