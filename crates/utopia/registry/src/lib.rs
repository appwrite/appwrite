//! Named resources (database connections, clients, caches) created on first
//! use by registered factories, and cached per context.
//!
//! Register a factory under a name with [`Registry::set`], then read the
//! resource with [`Registry::get`]: the first read runs the factory and later
//! reads return the same instance. A factory registered with
//! [`Registry::set_fresh`] runs on every read, and [`Registry::get_fresh`]
//! forces one new instance. Each context ([`Registry::context`]) keeps its own
//! instances, so one process can hold one set of connections per worker,
//! tenant or request.
//!
//! PHP: ports `utopia-php/registry`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `new Registry()` | [`Registry::new`] |
//! | `set($name, $callback, $fresh = false)` | [`Registry::set`] (`$fresh = true`: [`Registry::set_fresh`]) |
//! | `get($name, $fresh = false)` | [`Registry::get`] (`$fresh = true`: [`Registry::get_fresh`]) |
//! | `has($name)` | [`Registry::has`] |
//! | `context($name)` | [`Registry::context`] |
//! | `\Exception` | [`Error::NotFound`] |
//!
//! PHP: resources are values of any type in PHP; here each factory's result
//! is stored as `Box<dyn Any>` and read back with its type
//! ([`Error::Type`] when the caller asks for another type). Mutating the
//! `&mut T` that [`Registry::get`] returns mutates the cached instance, as
//! mutating a returned PHP object does.
//!
//! ```
//! use utopia_registry::Registry;
//!
//! let mut registry = Registry::new();
//! registry.set("db", || String::from("connection"));
//!
//! assert!(registry.has("db"));
//! assert_eq!(registry.get::<String>("db")?, "connection");
//! # Ok::<(), utopia_registry::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Registry\Registry;
//!
//! $registry = new Registry();
//! $registry->set('db', fn () => 'connection');
//!
//! var_dump($registry->has('db'));  // bool(true)
//! echo $registry->get('db'), "\n"; // connection
//! ```

#![warn(missing_docs)]

use std::any::{Any, type_name};
use std::collections::HashMap;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Why a resource could not be read.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// No factory is registered under the name.
    ///
    /// PHP: `\Exception`, with the same message.
    ///
    /// ```
    /// use utopia_registry::{Error, Registry};
    ///
    /// let mut registry = Registry::new();
    /// let error = registry.get::<String>("cache").unwrap_err();
    /// assert!(matches!(error, Error::NotFound(_)));
    /// assert_eq!(error.to_string(), r#"No callback named "cache" found when trying to create connection"#);
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// try {
    ///     (new Registry())->get('cache');
    /// } catch (\Exception $e) {
    ///     echo $e->getMessage(), "\n"; // No callback named "cache" found when trying to create connection
    /// }
    /// ```
    #[error("No callback named \"{0}\" found when trying to create connection")]
    NotFound(String),
    /// The resource exists but is not of the type the caller asked for.
    ///
    /// PHP: a `\TypeError` where the caller declares the type it expects.
    #[error("Resource \"{name}\" is not of type {expected}")]
    Type {
        /// The resource's name.
        name: String,
        /// The type the caller asked for.
        expected: &'static str,
    },
}

impl Error {
    /// The name of the PHP exception class this error matches.
    ///
    /// PHP: the class `utopia-php/registry` (or the engine) throws.
    ///
    /// ```
    /// use utopia_registry::Error;
    ///
    /// assert_eq!(Error::NotFound("db".into()).php_class(), "Exception");
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::NotFound(_) => "Exception",
            Error::Type { .. } => "TypeError",
        }
    }
}

/// The instances of one context, by name.
type Instances = HashMap<String, Box<dyn Any + Send>>;

type Make = Box<dyn Fn() -> Box<dyn Any + Send> + Send + Sync>;

/// A registered factory.
struct Factory {
    make: Make,
    /// Whether every read creates a new instance.
    fresh: bool,
}

/// Named factories, and the instances they created in each context.
///
/// PHP: `Utopia\Registry\Registry`.
///
/// ```
/// use utopia_registry::Registry;
///
/// let mut registry = Registry::new();
/// registry.set("list", || vec![String::from("test")]);
///
/// registry.get::<Vec<String>>("list")?.push("Hello World".into());
/// assert_eq!(registry.get::<Vec<String>>("list")?.len(), 2);
/// # Ok::<(), utopia_registry::Error>(())
/// ```
///
/// ```php
/// use Utopia\Registry\Registry;
///
/// $registry = new Registry();
/// $registry->set('list', fn () => new ArrayObject(['test']));
///
/// $registry->get('list')[] = 'Hello World';
/// echo count($registry->get('list')), "\n"; // 2
/// ```
pub struct Registry {
    factories: HashMap<String, Factory>,
    /// Instances by context, then by name.
    contexts: Vec<(String, Instances)>,
    /// Index of the current context in `contexts`.
    current: usize,
}

impl Default for Registry {
    fn default() -> Self {
        Self::new()
    }
}

impl std::fmt::Debug for Registry {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let mut names: Vec<&str> = self.factories.keys().map(String::as_str).collect();
        names.sort_unstable();
        f.debug_struct("Registry").field("factories", &names).field("context", &self.contexts[self.current].0).finish()
    }
}

impl Registry {
    /// An empty registry, in the context `default`.
    ///
    /// PHP: `new Registry()`.
    pub fn new() -> Self {
        Self { factories: HashMap::new(), contexts: vec![("default".to_owned(), HashMap::new())], current: 0 }
    }

    /// Registers the factory of a resource; its instance is created on the
    /// first read in each context, then reused.
    ///
    /// Registering a name again replaces its factory and drops its instance in
    /// the current context. Instances other contexts already created stay.
    ///
    /// PHP: `set($name, $callback)`.
    ///
    /// ```
    /// use utopia_registry::Registry;
    ///
    /// let mut registry = Registry::new();
    /// registry.set("region", || "fra");
    /// registry.set("region", || "nyc");
    /// assert_eq!(*registry.get::<&str>("region")?, "nyc");
    /// # Ok::<(), utopia_registry::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// $registry = new Registry();
    /// $registry->set('region', fn () => 'fra');
    /// $registry->set('region', fn () => 'nyc');
    /// echo $registry->get('region'), "\n"; // nyc
    /// ```
    pub fn set<T, F>(&mut self, name: &str, factory: F) -> &mut Self
    where
        T: Any + Send,
        F: Fn() -> T + Send + Sync + 'static,
    {
        self.register(name, factory, false)
    }

    /// Registers a factory that runs on every read: each read returns a new
    /// instance.
    ///
    /// PHP: `set($name, $callback, true)`.
    ///
    /// ```
    /// use std::sync::atomic::{AtomicU32, Ordering};
    /// use utopia_registry::Registry;
    ///
    /// let mut registry = Registry::new();
    /// let counter = AtomicU32::new(0);
    /// registry.set_fresh("id", move || counter.fetch_add(1, Ordering::Relaxed));
    /// assert_eq!(*registry.get::<u32>("id")?, 0);
    /// assert_eq!(*registry.get::<u32>("id")?, 1);
    /// # Ok::<(), utopia_registry::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// $registry = new Registry();
    /// $counter = 0;
    /// $registry->set('id', function () use (&$counter) { return $counter++; }, true);
    /// echo $registry->get('id'), "\n"; // 0
    /// echo $registry->get('id'), "\n"; // 1
    /// ```
    pub fn set_fresh<T, F>(&mut self, name: &str, factory: F) -> &mut Self
    where
        T: Any + Send,
        F: Fn() -> T + Send + Sync + 'static,
    {
        self.register(name, factory, true)
    }

    fn register<T, F>(&mut self, name: &str, factory: F, fresh: bool) -> &mut Self
    where
        T: Any + Send,
        F: Fn() -> T + Send + Sync + 'static,
    {
        self.contexts[self.current].1.remove(name);
        let make: Make = Box::new(move || Box::new(factory()));
        self.factories.insert(name.to_owned(), Factory { make, fresh });
        self
    }

    /// The resource registered under `name` in the current context, created
    /// by its factory on the first read (or on every read, for a factory
    /// registered with [`Registry::set_fresh`]).
    ///
    /// Fails with [`Error::NotFound`] when no factory has that name, and with
    /// [`Error::Type`] when the resource is not a `T`.
    ///
    /// PHP: `get($name)`.
    ///
    /// ```
    /// use utopia_registry::Registry;
    ///
    /// let mut registry = Registry::new();
    /// registry.set("port", || 6379_u16);
    /// assert_eq!(*registry.get::<u16>("port")?, 6379);
    /// assert!(registry.get::<String>("port").is_err());
    /// # Ok::<(), utopia_registry::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// $registry = new Registry();
    /// $registry->set('port', fn () => 6379);
    /// echo $registry->get('port'), "\n"; // 6379
    /// ```
    pub fn get<T: Any>(&mut self, name: &str) -> Result<&mut T, Error> {
        self.resolve(name, false)
    }

    /// A new instance of the resource registered under `name`, which replaces
    /// the cached one in the current context.
    ///
    /// PHP: `get($name, true)`.
    ///
    /// ```
    /// use utopia_registry::Registry;
    ///
    /// let mut registry = Registry::new();
    /// registry.set("list", || vec![1]);
    /// registry.get::<Vec<i32>>("list")?.push(2);
    /// assert_eq!(registry.get_fresh::<Vec<i32>>("list")?, &[1]);
    /// # Ok::<(), utopia_registry::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// $registry = new Registry();
    /// $registry->set('list', fn () => new ArrayObject([1]));
    /// $registry->get('list')[] = 2;
    /// echo count($registry->get('list', true)), "\n"; // 1
    /// ```
    pub fn get_fresh<T: Any>(&mut self, name: &str) -> Result<&mut T, Error> {
        self.resolve(name, true)
    }

    fn resolve<T: Any>(&mut self, name: &str, fresh: bool) -> Result<&mut T, Error> {
        let instances = &mut self.contexts[self.current].1;
        let factory = self.factories.get(name);
        let rebuild = fresh || factory.is_some_and(|f| f.fresh) || !instances.contains_key(name);
        if rebuild {
            let factory = factory.ok_or_else(|| Error::NotFound(name.to_owned()))?;
            instances.insert(name.to_owned(), (factory.make)());
        }
        let instance = instances.get_mut(name).ok_or_else(|| Error::NotFound(name.to_owned()))?;
        (**instance)
            .downcast_mut::<T>()
            .ok_or_else(|| Error::Type { name: name.to_owned(), expected: type_name::<T>() })
    }

    /// Whether a factory is registered under `name` (whatever the context).
    ///
    /// PHP: `has($name)`.
    ///
    /// ```
    /// use utopia_registry::Registry;
    ///
    /// let mut registry = Registry::new();
    /// registry.set("item", || [1, 2]);
    /// assert!(registry.has("item"));
    /// assert!(!registry.has("other"));
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// $registry = new Registry();
    /// $registry->set('item', fn () => [1, 2]);
    /// var_dump($registry->has('item'));  // bool(true)
    /// var_dump($registry->has('other')); // bool(false)
    /// ```
    pub fn has(&self, name: &str) -> bool {
        self.factories.contains_key(name)
    }

    /// Switches to the context `name`, created empty on first use. Reads then
    /// create and reuse instances of that context only; factories are shared
    /// by every context.
    ///
    /// PHP: `context($name)`.
    ///
    /// ```
    /// use std::sync::atomic::{AtomicU32, Ordering};
    /// use utopia_registry::Registry;
    ///
    /// let mut registry = Registry::new();
    /// let counter = AtomicU32::new(0);
    /// registry.set("connection", move || counter.fetch_add(1, Ordering::Relaxed));
    ///
    /// assert_eq!(*registry.get::<u32>("connection")?, 0);
    /// registry.context("worker-2");
    /// assert_eq!(*registry.get::<u32>("connection")?, 1);
    /// registry.context("default");
    /// assert_eq!(*registry.get::<u32>("connection")?, 0);
    /// # Ok::<(), utopia_registry::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Registry\Registry;
    ///
    /// $registry = new Registry();
    /// $counter = 0;
    /// $registry->set('connection', function () use (&$counter) { return $counter++; });
    ///
    /// echo $registry->get('connection'), "\n"; // 0
    /// $registry->context('worker-2');
    /// echo $registry->get('connection'), "\n"; // 1
    /// $registry->context('default');
    /// echo $registry->get('connection'), "\n"; // 0
    /// ```
    pub fn context(&mut self, name: &str) -> &mut Self {
        self.current = match self.contexts.iter().position(|(n, _)| n == name) {
            Some(i) => i,
            None => {
                self.contexts.push((name.to_owned(), HashMap::new()));
                self.contexts.len() - 1
            }
        };
        self
    }

    /// The name of the current context (`default` until [`Registry::context`]
    /// switches it).
    ///
    /// PHP: the protected `$context` property.
    pub fn current_context(&self) -> &str {
        &self.contexts[self.current].0
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn set_drops_the_instance_of_the_current_context_only() {
        let mut r = Registry::new();
        r.set("a", || 1_i32);
        assert_eq!(*r.get::<i32>("a").unwrap(), 1);
        r.context("other");
        r.set("a", || 2_i32);
        assert_eq!(*r.get::<i32>("a").unwrap(), 2);
        r.context("default");
        assert_eq!(*r.get::<i32>("a").unwrap(), 1);
        assert_eq!(r.current_context(), "default");
    }
}
