//! A dependency injection container: register how to build each dependency
//! and which dependencies it needs, then resolve it by id. Each dependency is
//! built once per container and shared afterwards; a container falls back to
//! its parent for ids it does not define.
//!
//! [`Container::set`] registers a factory with the ids it depends on; the
//! factory receives them, resolved, as [`Dependencies`]. [`Container::get`]
//! builds the dependency on first use, caches it, and returns it as an
//! [`Arc`]. A child container ([`Container::with_parent`]) overrides some ids
//! and resolves the rest through its parent, which caches what it builds.
//!
//! PHP: ports `utopia-php/di`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `new Container(?ContainerInterface $parent)` | [`Container::new`], [`Container::with_parent`] |
//! | `set($id, $factory, $dependencies)` | [`Container::set`] (the factory takes [`Dependencies`]) |
//! | `get($id)` | [`Container::get`] (typed), [`Container::get_any`] |
//! | `has($id)` | [`Container::has`] |
//! | `scope(...$ids)` | [`Container::scope`] |
//! | `Exceptions\NotFoundException` | [`Error::NotFound`] |
//! | `Exceptions\ContainerException` | [`Error::Container`] |
//!
//! PHP: factories are callables called with the resolved dependencies as
//! positional arguments, and dependencies are values of any type. Here a
//! factory is a closure over [`Dependencies`], which reads each argument with
//! its type, and returns `Result`, so a failing factory reports an [`Error`]
//! instead of throwing. Values are shared as `Arc<dyn Any>`, the way PHP
//! shares one object between every caller of `get()`.
//!
//! ```
//! use utopia_di::Container;
//!
//! let container = Container::new();
//! container.set("config", &[], |_| Ok(String::from("configValue")));
//! container.set("service", &["config"], |deps| Ok(format!("service:{}", deps.get::<String>(0)?)));
//!
//! assert_eq!(*container.get::<String>("service")?, "service:configValue");
//! # Ok::<(), utopia_di::Error>(())
//! ```
//!
//! ```php
//! use Utopia\DI\Container;
//!
//! $container = new Container();
//! $container->set('config', fn () => 'configValue');
//! $container->set('service', fn ($config) => "service:$config", ['config']);
//!
//! echo $container->get('service'), "\n"; // service:configValue
//! ```

#![warn(missing_docs)]

use std::any::{Any, type_name};
use std::collections::HashMap;
use std::sync::{Arc, OnceLock, RwLock};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// A resolved dependency, shared by everything that resolves it.
pub type Instance = Arc<dyn Any + Send + Sync>;

type Factory = Arc<dyn Fn(&Dependencies<'_>) -> Result<Instance, Error> + Send + Sync>;

/// Why a dependency could not be resolved.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// Neither the container nor its parents define the id.
    ///
    /// PHP: `Utopia\DI\Exceptions\NotFoundException` (a PSR-11
    /// `NotFoundExceptionInterface`), with the same message.
    ///
    /// ```
    /// use utopia_di::{Container, Error};
    ///
    /// let error = Container::new().get_any("missing").unwrap_err();
    /// assert!(matches!(error, Error::NotFound(_)));
    /// assert_eq!(error.to_string(), "Dependency missing not found");
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// try {
    ///     (new Container())->get('missing');
    /// } catch (\Psr\Container\NotFoundExceptionInterface $e) {
    ///     echo $e->getMessage(), "\n"; // Dependency missing not found
    /// }
    /// ```
    #[error("Dependency {0} not found")]
    NotFound(String),
    /// [`Container::scope`] named an id no container in the chain has a
    /// factory for.
    ///
    /// PHP: `Utopia\DI\Exceptions\NotFoundException` too, with the message
    /// `scope()` throws.
    #[error("Dependency {0} has no factory on this container or a Container ancestor to scope")]
    Unscoped(String),
    /// A factory failed to build its dependency.
    ///
    /// PHP: `Utopia\DI\Exceptions\ContainerException` (a PSR-11
    /// `ContainerExceptionInterface`), which a factory throws.
    #[error("{0}")]
    Container(String),
    /// A dependency is not of the type its reader asked for.
    ///
    /// PHP: the `\TypeError` of a factory parameter or caller declaring
    /// another type.
    #[error("Dependency {id} is not of type {expected}")]
    Type {
        /// The dependency's id, or its position among a factory's arguments.
        id: String,
        /// The type the reader asked for.
        expected: &'static str,
    },
    /// A factory read more arguments than it declared dependencies.
    ///
    /// PHP: `\ArgumentCountError`, a callable called with too few arguments.
    #[error("Too few arguments: argument {index} requested, {passed} passed")]
    Arguments {
        /// The position requested.
        index: usize,
        /// The number of dependencies declared.
        passed: usize,
    },
}

impl Error {
    /// The name of the PHP exception class this error matches.
    ///
    /// PHP: the class `utopia-php/di` (or the engine) throws.
    ///
    /// ```
    /// use utopia_di::Error;
    ///
    /// assert_eq!(Error::NotFound("db".into()).php_class(), "Utopia\\DI\\Exceptions\\NotFoundException");
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::NotFound(_) | Error::Unscoped(_) => "Utopia\\DI\\Exceptions\\NotFoundException",
            Error::Container(_) => "Utopia\\DI\\Exceptions\\ContainerException",
            Error::Type { .. } => "TypeError",
            Error::Arguments { .. } => "ArgumentCountError",
        }
    }
}

/// The resolved dependencies a factory receives, in the order it declared
/// them.
///
/// PHP: the positional arguments of the factory callable.
pub struct Dependencies<'a> {
    values: &'a [Instance],
}

impl Dependencies<'_> {
    /// The dependency at `index`, as a `T`.
    ///
    /// Fails with [`Error::Arguments`] past the last declared dependency, and
    /// with [`Error::Type`] when it is not a `T`.
    ///
    /// PHP: the factory's typed parameter at that position.
    ///
    /// ```
    /// use utopia_di::Container;
    ///
    /// let container = Container::new();
    /// container.set("x", &[], |_| Ok(1_i64));
    /// container.set("y", &[], |_| Ok(2_i64));
    /// container.set("sum", &["x", "y"], |deps| Ok(deps.get::<i64>(0)? + deps.get::<i64>(1)?));
    /// assert_eq!(*container.get::<i64>("sum")?, 3);
    /// # Ok::<(), utopia_di::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// $container = new Container();
    /// $container->set('x', fn () => 1);
    /// $container->set('y', fn () => 2);
    /// $container->set('sum', fn (int $x, int $y) => $x + $y, ['x', 'y']);
    /// echo $container->get('sum'), "\n"; // 3
    /// ```
    pub fn get<T: Any>(&self, index: usize) -> Result<&T, Error> {
        self.any(index)?
            .downcast_ref::<T>()
            .ok_or_else(|| Error::Type { id: format!("#{index}"), expected: type_name::<T>() })
    }

    /// The dependency at `index`, as the shared instance.
    pub fn any(&self, index: usize) -> Result<&Instance, Error> {
        self.values.get(index).ok_or(Error::Arguments { index, passed: self.values.len() })
    }

    /// The number of dependencies.
    pub fn len(&self) -> usize {
        self.values.len()
    }

    /// Whether the factory declared no dependencies.
    pub fn is_empty(&self) -> bool {
        self.values.is_empty()
    }

    /// The dependencies in order.
    pub fn iter(&self) -> impl ExactSizeIterator<Item = &Instance> {
        self.values.iter()
    }
}

/// A factory and the ids it depends on, as [`Container::scope`] copies them.
type Definition = (Factory, Arc<[Box<str>]>);

/// A registered factory and the instance it built, once built.
struct Entry {
    factory: Factory,
    dependencies: Arc<[Box<str>]>,
    instance: OnceLock<Instance>,
}

/// Factories by id, built once and cached.
///
/// PHP: `Utopia\DI\Container`.
///
/// ```
/// use std::sync::Arc;
/// use utopia_di::Container;
///
/// let parent = Arc::new(Container::new());
/// parent.set("config", &[], |_| Ok("prodConfig"));
///
/// let child = Container::with_parent(parent.clone());
/// child.set("service", &["config"], |deps| Ok(format!("service:{}", deps.get::<&str>(0)?)));
///
/// assert_eq!(*child.get::<String>("service")?, "service:prodConfig");
/// assert!(!parent.has("service"));
/// # Ok::<(), utopia_di::Error>(())
/// ```
///
/// ```php
/// use Utopia\DI\Container;
///
/// $parent = new Container();
/// $parent->set('config', fn () => 'prodConfig');
///
/// $child = new Container($parent);
/// $child->set('service', fn ($config) => "service:$config", ['config']);
///
/// echo $child->get('service'), "\n";      // service:prodConfig
/// var_dump($parent->has('service'));      // bool(false)
/// ```
#[derive(Default)]
pub struct Container {
    parent: Option<Arc<Container>>,
    entries: RwLock<HashMap<Box<str>, Arc<Entry>, foldhash::fast::RandomState>>,
}

impl std::fmt::Debug for Container {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let entries = self.entries.read().unwrap_or_else(|e| e.into_inner());
        let mut ids: Vec<&str> = entries.keys().map(|k| &**k).collect();
        ids.sort_unstable();
        f.debug_struct("Container").field("ids", &ids).field("parent", &self.parent).finish()
    }
}

impl Container {
    /// An empty container without a parent.
    ///
    /// PHP: `new Container()`.
    pub fn new() -> Self {
        Self::default()
    }

    /// An empty container that resolves the ids it does not define through
    /// `parent`. The parent builds and caches those itself, resolving their
    /// dependencies in its own scope, so siblings share them.
    ///
    /// PHP: `new Container($parent)`.
    ///
    /// ```
    /// use std::sync::Arc;
    /// use utopia_di::Container;
    ///
    /// let parent = Arc::new(Container::new());
    /// parent.set("shared", &[], |_| Ok(vec![1, 2, 3]));
    ///
    /// let a = Container::with_parent(parent.clone());
    /// let b = Container::with_parent(parent);
    /// assert!(Arc::ptr_eq(&a.get::<Vec<i32>>("shared")?, &b.get::<Vec<i32>>("shared")?));
    /// # Ok::<(), utopia_di::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// $parent = new Container();
    /// $parent->set('shared', fn () => new \stdClass());
    ///
    /// $a = new Container($parent);
    /// $b = new Container($parent);
    /// var_dump($a->get('shared') === $b->get('shared')); // bool(true)
    /// ```
    pub fn with_parent(parent: Arc<Container>) -> Self {
        Self { parent: Some(parent), entries: RwLock::default() }
    }

    /// The parent container, if any.
    pub fn parent(&self) -> Option<&Arc<Container>> {
        self.parent.as_ref()
    }

    /// Registers the factory of `id`, called with the resolved `dependencies`
    /// on first use. Registering an id again replaces its factory and drops
    /// the instance the old one built.
    ///
    /// PHP: `set($id, $factory, $dependencies)`.
    ///
    /// ```
    /// use utopia_di::Container;
    ///
    /// let container = Container::new();
    /// container.set("foo", &[], |_| Ok("first"));
    /// assert_eq!(*container.get::<&str>("foo")?, "first");
    /// container.set("foo", &[], |_| Ok("second"));
    /// assert_eq!(*container.get::<&str>("foo")?, "second");
    /// # Ok::<(), utopia_di::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// $container = new Container();
    /// $container->set('foo', fn () => 'first');
    /// echo $container->get('foo'), "\n"; // first
    /// $container->set('foo', fn () => 'second');
    /// echo $container->get('foo'), "\n"; // second
    /// ```
    pub fn set<T, F>(&self, id: &str, dependencies: &[&str], factory: F) -> &Self
    where
        T: Any + Send + Sync,
        F: Fn(&Dependencies<'_>) -> Result<T, Error> + Send + Sync + 'static,
    {
        let factory: Factory = Arc::new(move |deps| factory(deps).map(|v| Arc::new(v) as Instance));
        let entry = Entry {
            factory,
            dependencies: dependencies.iter().map(|d| Box::from(*d)).collect(),
            instance: OnceLock::new(),
        };
        self.entries.write().unwrap_or_else(|e| e.into_inner()).insert(Box::from(id), Arc::new(entry));
        self
    }

    /// Rebuilds `ids`, and everything built from them, in this container's own
    /// scope.
    ///
    /// A child that does not define an id reuses what its parent built, from
    /// the parent's dependencies. Once the child overrides one of those, the
    /// values derived from it must be rebuilt in the child too. Scoping an id
    /// registers this container's own copy of its factory (the nearest
    /// container in the chain that has one), and of every factory in the chain
    /// that depends on it, directly or transitively. Parents keep their values.
    ///
    /// Fails with [`Error::Unscoped`], before scoping anything, when no
    /// container in the chain has a factory for one of `ids`.
    ///
    /// PHP: `scope(string ...$ids)`. PHP's walk stops at the first parent that
    /// is another PSR-11 container; a Rust parent is always a [`Container`].
    ///
    /// ```
    /// use std::sync::Arc;
    /// use utopia_di::Container;
    ///
    /// let parent = Arc::new(Container::new());
    /// parent.set("route", &[], |_| Ok("/v1/graphql"));
    /// parent.set("team", &["route"], |deps| Ok(format!("team for {}", deps.get::<&str>(0)?)));
    /// assert_eq!(*parent.get::<String>("team")?, "team for /v1/graphql");
    ///
    /// let child = Container::with_parent(parent.clone());
    /// child.set("route", &[], |_| Ok("/v1/organization/projects"));
    /// assert_eq!(*child.get::<String>("team")?, "team for /v1/graphql");
    ///
    /// child.scope(&["team"])?;
    /// assert_eq!(*child.get::<String>("team")?, "team for /v1/organization/projects");
    /// assert_eq!(*parent.get::<String>("team")?, "team for /v1/graphql");
    /// # Ok::<(), utopia_di::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// $parent = new Container();
    /// $parent->set('route', fn (): string => '/v1/graphql');
    /// $parent->set('team', fn (string $route): string => "team for $route", ['route']);
    /// echo $parent->get('team'), "\n"; // team for /v1/graphql
    ///
    /// $child = new Container($parent);
    /// $child->set('route', fn (): string => '/v1/organization/projects');
    /// echo $child->get('team'), "\n"; // team for /v1/graphql
    ///
    /// $child->scope('team');
    /// echo $child->get('team'), "\n";  // team for /v1/organization/projects
    /// echo $parent->get('team'), "\n"; // team for /v1/graphql
    /// ```
    pub fn scope(&self, ids: &[&str]) -> Result<&Self, Error> {
        // The nearest definition of every id in the chain.
        let mut definitions: HashMap<Box<str>, Definition, foldhash::fast::RandomState> = HashMap::default();
        let mut container = Some(self);
        while let Some(current) = container {
            for (id, entry) in current.entries.read().unwrap_or_else(|e| e.into_inner()).iter() {
                definitions.entry(id.clone()).or_insert_with(|| (entry.factory.clone(), entry.dependencies.clone()));
            }
            container = current.parent.as_deref();
        }

        if let Some(id) = ids.iter().find(|id| !definitions.contains_key(**id)) {
            return Err(Error::Unscoped((*id).to_owned()));
        }

        let mut scoped: std::collections::HashSet<&str, foldhash::fast::RandomState> = ids.iter().copied().collect();
        loop {
            let added: Vec<&str> = definitions
                .iter()
                .filter(|(id, (_, dependencies))| {
                    !scoped.contains(&***id) && dependencies.iter().any(|d| scoped.contains(&**d))
                })
                .map(|(id, _)| &**id)
                .collect();
            if added.is_empty() {
                break;
            }
            scoped.extend(added);
        }

        let mut entries = self.entries.write().unwrap_or_else(|e| e.into_inner());
        for id in scoped {
            let (factory, dependencies) = &definitions[id];
            let entry =
                Entry { factory: factory.clone(), dependencies: dependencies.clone(), instance: OnceLock::new() };
            entries.insert(Box::from(id), Arc::new(entry));
        }
        Ok(self)
    }

    /// The dependency `id` as a `T`, built on first use and shared after.
    ///
    /// Fails with [`Error::NotFound`] when neither this container nor a parent
    /// defines it (or one of its dependencies), with [`Error::Type`] when it
    /// is not a `T`, and with the error of a failing factory.
    ///
    /// PHP: `get($id)`.
    ///
    /// ```
    /// use utopia_di::Container;
    ///
    /// let container = Container::new();
    /// container.set("a", &[], |_| Ok(String::from("A")));
    /// container.set("b", &["a"], |deps| Ok(format!("B({})", deps.get::<String>(0)?)));
    /// container.set("c", &["b"], |deps| Ok(format!("C({})", deps.get::<String>(0)?)));
    /// assert_eq!(*container.get::<String>("c")?, "C(B(A))");
    /// # Ok::<(), utopia_di::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// $container = new Container();
    /// $container->set('a', fn () => 'A');
    /// $container->set('b', fn ($a) => "B($a)", ['a']);
    /// $container->set('c', fn ($b) => "C($b)", ['b']);
    /// echo $container->get('c'), "\n"; // C(B(A))
    /// ```
    pub fn get<T: Any + Send + Sync>(&self, id: &str) -> Result<Arc<T>, Error> {
        self.get_any(id)?.downcast::<T>().map_err(|_| Error::Type { id: id.to_owned(), expected: type_name::<T>() })
    }

    /// The dependency `id`, built on first use and shared after, whatever its
    /// type.
    ///
    /// PHP: `get($id)`.
    pub fn get_any(&self, id: &str) -> Result<Instance, Error> {
        let entry = self.entries.read().unwrap_or_else(|e| e.into_inner()).get(id).cloned();
        let Some(entry) = entry else {
            return match &self.parent {
                Some(parent) => parent.get_any(id),
                None => Err(Error::NotFound(id.to_owned())),
            };
        };
        if let Some(instance) = entry.instance.get() {
            return Ok(instance.clone());
        }
        let values = entry.dependencies.iter().map(|d| self.get_any(d)).collect::<Result<Vec<_>, _>>()?;
        let built = (entry.factory)(&Dependencies { values: &values })?;
        Ok(entry.instance.get_or_init(|| built).clone())
    }

    /// Whether this container or a parent defines `id`.
    ///
    /// PHP: `has($id)`.
    ///
    /// ```
    /// use std::sync::Arc;
    /// use utopia_di::Container;
    ///
    /// let grandparent = Arc::new(Container::new());
    /// grandparent.set("deep", &[], |_| Ok(()));
    /// let child = Container::with_parent(Arc::new(Container::with_parent(grandparent)));
    /// assert!(child.has("deep"));
    /// assert!(!child.has("missing"));
    /// ```
    ///
    /// ```php
    /// use Utopia\DI\Container;
    ///
    /// $grandparent = new Container();
    /// $grandparent->set('deep', fn () => null);
    /// $child = new Container(new Container($grandparent));
    /// var_dump($child->has('deep'));    // bool(true)
    /// var_dump($child->has('missing')); // bool(false)
    /// ```
    pub fn has(&self, id: &str) -> bool {
        self.entries.read().unwrap_or_else(|e| e.into_inner()).contains_key(id)
            || self.parent.as_ref().is_some_and(|p| p.has(id))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicUsize, Ordering};

    #[test]
    fn builds_once() {
        let calls = Arc::new(AtomicUsize::new(0));
        let c = Container::new();
        let counter = calls.clone();
        c.set("n", &[], move |_| Ok(counter.fetch_add(1, Ordering::SeqCst)));
        let a = c.get::<usize>("n").unwrap();
        let b = c.get::<usize>("n").unwrap();
        assert!(Arc::ptr_eq(&a, &b));
        assert_eq!(calls.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn parent_resolves_in_its_own_scope() {
        let parent = Arc::new(Container::new());
        parent.set("greeter", &["name"], |d| Ok(format!("Hello, {}", d.get::<&str>(0)?)));
        let child = Container::with_parent(parent);
        child.set("name", &[], |_| Ok("Alice"));
        assert_eq!(child.get_any("greeter").unwrap_err(), Error::NotFound("name".into()));
    }

    #[test]
    fn too_few_arguments() {
        let c = Container::new();
        c.set("x", &[], |d| d.get::<i32>(0).copied());
        assert_eq!(c.get_any("x").unwrap_err().php_class(), "ArgumentCountError");
    }
    fn text(d: &Dependencies<'_>, i: usize) -> Result<String, Error> {
        let v = d.any(i)?;
        v.downcast_ref::<String>()
            .cloned()
            .or_else(|| v.downcast_ref::<&str>().map(|s| (*s).to_owned()))
            .ok_or(Error::Type { id: format!("#{i}"), expected: "string" })
    }

    // Ports ContainerTest::testScopeRebuildsAParentDependencyFromTheChildsOverride.
    #[test]
    fn scope_rebuilds_a_parent_dependency_from_the_childs_override() {
        let parent = Arc::new(Container::new());
        parent.set("route", &[], |_| Ok("/v1/graphql"));
        parent.set("team", &["route"], |d| Ok(format!("team for {}", text(d, 0)?)));
        assert_eq!(*parent.get::<String>("team").unwrap(), "team for /v1/graphql");

        let child = Container::with_parent(parent.clone());
        child.set("route", &[], |_| Ok("/v1/organization/projects"));
        assert_eq!(*child.get::<String>("team").unwrap(), "team for /v1/graphql");

        child.scope(&["team"]).unwrap();
        assert_eq!(*child.get::<String>("team").unwrap(), "team for /v1/organization/projects");
        assert_eq!(*parent.get::<String>("team").unwrap(), "team for /v1/graphql");
    }

    // Ports ContainerTest::testScopeFindsTheFactoryOnAnyAncestor.
    #[test]
    fn scope_finds_the_factory_on_any_ancestor() {
        let root = Arc::new(Container::new());
        root.set("route", &[], |_| Ok("root"));
        root.set("team", &["route"], |d| Ok(format!("team for {}", text(d, 0)?)));

        let grandchild = Container::with_parent(Arc::new(Container::with_parent(root)));
        grandchild.set("route", &[], |_| Ok("field"));
        grandchild.scope(&["team"]).unwrap();
        assert_eq!(*grandchild.get::<String>("team").unwrap(), "team for field");
    }

    // Ports ContainerTest::testScopeRebuildsWhatIsBuiltFromAScopedDependency.
    #[test]
    fn scope_rebuilds_what_is_built_from_a_scoped_dependency() {
        let parent = Arc::new(Container::new());
        parent.set("route", &[], |_| Ok("outer"));
        parent.set("team", &["route"], |d| Ok(format!("team for {}", text(d, 0)?)));
        parent.set("key", &["team"], |d| Ok(format!("key for {}", text(d, 0)?)));
        parent.set("plan", &["key"], |d| Ok(format!("plan for {}", text(d, 0)?)));
        parent.set("user", &[], |_| Ok("user"));

        assert_eq!(*parent.get::<String>("plan").unwrap(), "plan for key for team for outer");
        let user = parent.get_any("user").unwrap();

        let child = Container::with_parent(parent.clone());
        child.set("route", &[], |_| Ok("field"));
        child.scope(&["team"]).unwrap();

        assert_eq!(*child.get::<String>("key").unwrap(), "key for team for field");
        assert_eq!(*child.get::<String>("plan").unwrap(), "plan for key for team for field");
        assert!(Arc::ptr_eq(&user, &child.get_any("user").unwrap()));
        assert_eq!(*parent.get::<String>("plan").unwrap(), "plan for key for team for outer");
    }

    // Ports ContainerTest::testScopeRebuildsALocalDependency.
    #[test]
    fn scope_rebuilds_a_local_dependency() {
        let calls = Arc::new(AtomicUsize::new(0));
        let container = Container::new();
        let counter = calls.clone();
        container.set("counter", &[], move |_| Ok(counter.fetch_add(1, Ordering::SeqCst) + 1));
        assert_eq!(*container.get::<usize>("counter").unwrap(), 1);
        container.scope(&["counter"]).unwrap();
        assert_eq!(*container.get::<usize>("counter").unwrap(), 2);
    }

    // Ports ContainerTest::testScopeThrowsForUnknownDependency.
    #[test]
    fn scope_fails_for_an_unknown_dependency() {
        let container = Container::with_parent(Arc::new(Container::new()));
        let error = container.scope(&["missing"]).unwrap_err();
        assert_eq!(error.php_class(), "Utopia\\DI\\Exceptions\\NotFoundException");
        assert_eq!(
            error.to_string(),
            "Dependency missing has no factory on this container or a Container ancestor to scope"
        );
    }

    #[test]
    fn scope_checks_every_id_before_scoping_any() {
        let calls = Arc::new(AtomicUsize::new(0));
        let container = Container::new();
        let counter = calls.clone();
        container.set("counter", &[], move |_| Ok(counter.fetch_add(1, Ordering::SeqCst) + 1));
        assert_eq!(*container.get::<usize>("counter").unwrap(), 1);
        assert!(container.scope(&["counter", "missing"]).is_err());
        assert_eq!(*container.get::<usize>("counter").unwrap(), 1);
    }
}
