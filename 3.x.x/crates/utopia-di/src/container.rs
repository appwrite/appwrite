use std::collections::HashMap;
use std::fmt;
use std::sync::Arc;

use parking_lot::RwLock;

use crate::error::{ContainerError, NotFoundError};
use crate::resource::Resource;

type FactoryFn = Arc<dyn Fn(&Container) -> Result<Resource, ContainerError> + Send + Sync>;

#[derive(Clone)]
struct Entry {
    factory: FactoryFn,
    #[allow(dead_code)]
    deps: Vec<String>,
}

/// Parent/child DI container with lazy factories and per-container caching.
#[derive(Clone, Default)]
pub struct Container {
    inner: Arc<Inner>,
}

impl fmt::Debug for Container {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Container").finish_non_exhaustive()
    }
}

struct Inner {
    parent: Option<Container>,
    entries: RwLock<HashMap<String, Entry>>,
    concrete: RwLock<HashMap<String, Resource>>,
}

impl Default for Inner {
    fn default() -> Self {
        Self {
            parent: None,
            entries: RwLock::new(HashMap::new()),
            concrete: RwLock::new(HashMap::new()),
        }
    }
}

impl Container {
    pub fn new() -> Self {
        Self::default()
    }

    /// Create a child container that falls through to `parent`.
    pub fn child(parent: &Container) -> Self {
        Self {
            inner: Arc::new(Inner {
                parent: Some(parent.clone()),
                entries: RwLock::new(HashMap::new()),
                concrete: RwLock::new(HashMap::new()),
            }),
        }
    }

    /// Register a factory. Dependencies are resolved and passed as a slice of resources
    /// in declaration order when using [`Self::set_with_deps`].
    pub fn set<F>(&self, id: impl Into<String>, factory: F) -> &Self
    where
        F: Fn() -> Result<Resource, ContainerError> + Send + Sync + 'static,
    {
        self.set_with_deps(id, &[], move |_deps| factory())
    }

    /// Register a factory that receives previously resolved dependency resources.
    pub fn set_with_deps<F>(&self, id: impl Into<String>, deps: &[&str], factory: F) -> &Self
    where
        F: Fn(&[Resource]) -> Result<Resource, ContainerError> + Send + Sync + 'static,
    {
        let id = id.into();
        let deps: Vec<String> = deps.iter().map(|d| (*d).to_string()).collect();
        let deps_for_factory = deps.clone();
        let factory: FactoryFn = Arc::new(move |container: &Container| {
            let mut resolved = Vec::with_capacity(deps_for_factory.len());
            for dep in &deps_for_factory {
                resolved.push(container.get(dep)?);
            }
            factory(&resolved)
        });

        self.inner
            .entries
            .write()
            .insert(id.clone(), Entry { factory, deps });
        self.inner.concrete.write().remove(&id);
        self
    }

    /// Convenience: store an already-built resource.
    pub fn set_value(&self, id: impl Into<String>, value: Resource) -> &Self {
        let id = id.into();
        let value = value.clone();
        self.set(id, move || Ok(value.clone()))
    }

    /// Bind a concrete resource without allocating a factory (request-scoped hot path).
    ///
    /// Visible to [`Self::get`] via the concrete cache. Prefer this over [`Self::set_value`]
    /// for per-request bindings (`request`, `response`, `error`) on a [`Self::child`].
    pub fn set_cached(&self, id: impl Into<String>, value: Resource) -> &Self {
        self.inner.concrete.write().insert(id.into(), value);
        self
    }

    pub fn get(&self, id: &str) -> Result<Resource, ContainerError> {
        if let Some(cached) = self.inner.concrete.read().get(id).cloned() {
            return Ok(cached);
        }

        let entry = self.inner.entries.read().get(id).cloned();
        if let Some(entry) = entry {
            let concrete = (entry.factory)(self)?;
            self.inner
                .concrete
                .write()
                .insert(id.to_string(), concrete.clone());
            return Ok(concrete);
        }

        if let Some(parent) = &self.inner.parent {
            return parent.get(id);
        }

        Err(NotFoundError(id.to_string()).into())
    }

    pub fn get_as<T: std::any::Any + Send + Sync + Clone>(
        &self,
        id: &str,
    ) -> Result<T, ContainerError> {
        self.get(id)?.get_as::<T>(id)
    }

    pub fn has(&self, id: &str) -> bool {
        if self.inner.entries.read().contains_key(id) {
            return true;
        }
        self.inner.parent.as_ref().is_some_and(|p| p.has(id))
    }

    /// Clear resolved cache for this container only (not parents).
    pub fn clear_cache(&self) {
        self.inner.concrete.write().clear();
    }
}
