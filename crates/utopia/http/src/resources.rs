//! The container resources are injected from.
//!
//! PHP: `Utopia\DI\Container`.

use std::any::Any;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use crate::error::{Error, Result};

/// An injectable resource: any shared value, read back by type with
/// [`Scope::resource`](crate::Scope::resource).
pub type Resource = Arc<dyn Any + Send + Sync>;

/// A container of resources by name, falling back to a parent container.
///
/// The application's static resources are the parent of every request's
/// context.
///
/// PHP: `Utopia\DI\Container`.
#[derive(Default)]
pub struct Resources {
    values: Mutex<HashMap<String, Resource>>,
    parent: Option<Arc<Resources>>,
}

impl std::fmt::Debug for Resources {
    /// Shows the registered names and the parent.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let names: Vec<String> = self.values.lock().map(|v| v.keys().cloned().collect()).unwrap_or_default();
        f.debug_struct("Resources").field("names", &names).field("parent", &self.parent).finish()
    }
}

impl Resources {
    /// An empty container without a parent.
    pub fn new() -> Self {
        Self::default()
    }

    /// A child container: lookups fall through to `parent`.
    pub fn child(parent: Arc<Resources>) -> Self {
        Self { values: Mutex::new(HashMap::new()), parent: Some(parent) }
    }

    /// Registers `value` under `name`, replacing any value of that name in
    /// this container (the parent is untouched).
    ///
    /// PHP: `set($name, fn () => $value)`.
    pub fn set(&self, name: &str, value: Resource) {
        if let Ok(mut v) = self.values.lock() {
            v.insert(name.to_owned(), value);
        }
    }

    /// The resource named `name`, looked up here and then in the parents;
    /// [`Error::NotFound`] when none has it.
    ///
    /// PHP: `get($name)`.
    pub fn get(&self, name: &str) -> Result<Resource> {
        if let Some(v) = self.values.lock().ok().and_then(|v| v.get(name).cloned()) {
            return Ok(v);
        }
        match &self.parent {
            Some(parent) => parent.get(name),
            None => Err(Error::NotFound(name.to_owned())),
        }
    }

    /// Whether a resource named `name` is registered here or in a parent.
    ///
    /// PHP: `has($name)`.
    pub fn has(&self, name: &str) -> bool {
        self.get(name).is_ok()
    }

    pub(crate) fn resolve(&self, names: &[String]) -> Result<Vec<Resource>> {
        names.iter().map(|n| self.get(n)).collect()
    }
}
