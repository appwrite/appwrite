//! [`Adapter`]: the storage engine under a database, and what it offers
//! ([`Limits`], [`Capability`], [`Feature`], [`Profile`]).
//!
//! # The contract
//!
//! An adapter is shared by every task using a database handle, so every
//! method takes `&self`; its per-handle settings (database, namespace,
//! shared tables, tenant, metadata, locks, hooks, transforms, profiler,
//! authorization) live in the [`State`] it embeds and exposes through
//! [`Adapter::state`]. The settings PHP scopes per coroutine (the tenant,
//! `ignoreDuplicates()`) are [`Scoped`](crate::state::Scoped) values there.
//!
//! The required methods are PHP's abstract ones, async where they reach
//! the engine, taking the library's value objects ([`Collection`],
//! [`Attribute`], [`Index`], [`Relationship`]) and moving what they consume
//! ([`Query`] lists, documents) so an adapter hands them to its builder
//! without a copy. The provided methods are PHP's concrete ones.
//!
//! What an adapter may or may not do is declared, not discovered:
//!
//! - **Capabilities** ([`Adapter::capabilities`], a [`Capabilities`] bit
//!   set; [`Adapter::supports`]) are behaviour flags.
//! - **Features** ([`Feature`]) are optional groups of methods, each a
//!   trait ([`Casting`], [`Connection`], [`QueryBuilder`], [`RawQuery`],
//!   [`Relationships`], [`Schemaless`], [`Spatial`], [`Timeouts`],
//!   [`Upserts`]). An adapter implementing one returns itself from the
//!   matching accessor ([`Adapter::casting`], [`Adapter::connection`], ...);
//!   [`Adapter::features`] and [`Adapter::has_feature`] are derived from
//!   the accessors. A proxy such as a pool answers for the adapter it
//!   borrows by forwarding the accessors, which is why the database asks
//!   [`Adapter::has_feature`] and never downcasts.
//! - **Limits** ([`Adapter::limits`]) are one [`Limits`] value.
//!
//! A database reads these once into a [`Profile`], which the validators
//! take.
//!
//! # How a database drives an adapter
//!
//! - Settings: `set_database`, `set_namespace`, `set_shared_tables`,
//!   `set_tenant` (and [`Scoped::with`](crate::state::Scoped::with) on
//!   [`State::tenant`] for a scoped tenant), `set_tenant_per_document`,
//!   `set_metadata`, `set_locks`, `set_authorization`, `set_profiler`,
//!   `add_write_hook`, `add_transform`.
//! - Schema: `create`/`update`/`exists`/`list`/`delete` databases;
//!   `create_collection`, `delete_collection`, `analyze_collection`,
//!   `collection_exists`; attributes and indexes; relationships through
//!   [`Relationships`].
//! - Documents: `create_document(s)`, `get_document`, `update_document(s)`,
//!   `increase_document_attribute`, `delete_document(s)`, `get_sequences`,
//!   upserts through [`Upserts`]; reads through `find` (documents, and rows
//!   of aggregates and `groupBy`), `count` and `sum`.
//! - Transactions: [`with_transaction`] (retries, savepoints, lost
//!   connections) over `start_transaction`, `commit_transaction`,
//!   `rollback_transaction`, [`Adapter::abandon_transaction`].
//!
//! PHP: `Utopia\Database\Adapter`, `Adapter\Limits`, `Adapter\Profile`,
//! `Adapter\CursorBoundary`, `Adapter\Timeout`, `Adapter\Feature\*`.

use std::any::Any;
use std::future::Future;
use std::sync::{Arc, Mutex, MutexGuard};

use async_trait::async_trait;
use futures_util::future::BoxFuture;
use php_std::datetime::DateTime;
use utopia_query::schema::{ColumnType, IndexType};
use utopia_query::{CursorDirection, OrderDirection};

use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::change::Change;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::hook::{Tenancy, Transform, Write};
use crate::index::Index;
use crate::permission::PermissionType;
use crate::profiler::Profiler;
use crate::query::Query;
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::schema;
use crate::state::Scoped;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value};

#[cfg(feature = "memory")]
pub mod memory;

/// The latest datetime an adapter stores (`Adapter::MAX_DATETIME`).
pub const MAX_DATETIME: &str = "9999-12-31 23:59:59";

/// The fixed limits of an adapter: the largest string, varchar and integer
/// sizes, the attribute, index and document size caps (0 when there is
/// none), the attributes and indexes every collection holds, the longest
/// index key and document id, the datetime range, the type of the sequence,
/// and the names no attribute or index may take.
///
/// PHP: `Utopia\Database\Adapter\Limits`.
#[derive(Debug, Clone)]
pub struct Limits {
    /// The largest string (`string`).
    pub string: i64,
    /// The largest varchar (`varchar`).
    pub varchar: i64,
    /// The largest integer (`integer`).
    pub integer: i64,
    /// The largest big integer (`bigInteger`).
    pub big_integer: i64,
    /// The most attributes of a collection, 0 for no cap (`attributes`).
    pub attributes: i64,
    /// The most indexes of a collection, 0 for no cap (`indexes`).
    pub indexes: i64,
    /// The attributes every collection holds (`defaultAttributes`).
    pub default_attributes: i64,
    /// The indexes every collection holds (`defaultIndexes`).
    pub default_indexes: i64,
    /// The longest index key, in bytes (`indexLength`).
    pub index_length: i64,
    /// The longest document id (`uidLength`).
    pub uid_length: i64,
    /// The largest document, in bytes, 0 for no cap (`documentSize`).
    pub document_size: i64,
    /// The earliest datetime (`minDateTime`).
    pub min_date_time: DateTime,
    /// The latest datetime (`maxDateTime`).
    pub max_date_time: DateTime,
    /// The type of the sequence (`idType`).
    pub id_type: ColumnType,
    /// The names no attribute or index may take (`keywords`).
    pub keywords: Vec<String>,
    /// The names of the indexes every collection holds (`internalIndexKeys`).
    pub internal_index_keys: Vec<String>,
}

/// An optional group of adapter methods.
///
/// PHP: the `Utopia\Database\Adapter\Feature\*` interfaces, checked with
/// `hasFeature(Feature\X::class)`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, PartialOrd, Ord)]
pub enum Feature {
    /// [`Casting`]: the adapter converts values itself.
    Casting,
    /// [`Connection`]: a connection it can check, re-establish and identify.
    Connection,
    /// [`QueryBuilder`]: builders in the adapter's dialect.
    QueryBuilder,
    /// [`RawQuery`]: raw statements.
    RawQuery,
    /// [`Relationships`]: relationship storage.
    Relationships,
    /// [`Schemaless`]: a schemaless mode.
    Schemaless,
    /// [`Spatial`]: spatial values.
    Spatial,
    /// [`Timeouts`]: statement timeouts.
    Timeouts,
    /// [`Upserts`]: upserts.
    Upserts,
}

impl Feature {
    /// Every feature, in PHP's `Database::FEATURES` order.
    pub const ALL: [Feature; 9] = [
        Feature::Casting,
        Feature::Connection,
        Feature::QueryBuilder,
        Feature::RawQuery,
        Feature::Relationships,
        Feature::Schemaless,
        Feature::Spatial,
        Feature::Timeouts,
        Feature::Upserts,
    ];

    /// The PHP interface (`Feature\X::class`).
    pub fn php_class(self) -> &'static str {
        match self {
            Feature::Casting => "Utopia\\Database\\Adapter\\Feature\\Casting",
            Feature::Connection => "Utopia\\Database\\Adapter\\Feature\\Connection",
            Feature::QueryBuilder => "Utopia\\Database\\Adapter\\Feature\\QueryBuilder",
            Feature::RawQuery => "Utopia\\Database\\Adapter\\Feature\\RawQuery",
            Feature::Relationships => "Utopia\\Database\\Adapter\\Feature\\Relationships",
            Feature::Schemaless => "Utopia\\Database\\Adapter\\Feature\\Schemaless",
            Feature::Spatial => "Utopia\\Database\\Adapter\\Feature\\Spatial",
            Feature::Timeouts => "Utopia\\Database\\Adapter\\Feature\\Timeouts",
            Feature::Upserts => "Utopia\\Database\\Adapter\\Feature\\Upserts",
        }
    }

    const fn bit(self) -> u16 {
        1 << (self as u8)
    }
}

/// A set of [`Feature`]s.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub struct Features(u16);

impl Features {
    /// The empty set.
    pub const EMPTY: Features = Features(0);

    /// The set of `features`.
    pub const fn of(features: &[Feature]) -> Features {
        let mut bits = 0;
        let mut i = 0;
        while i < features.len() {
            bits |= features[i].bit();
            i += 1;
        }
        Features(bits)
    }

    /// Whether `feature` is in the set.
    pub const fn contains(self, feature: Feature) -> bool {
        self.0 & feature.bit() != 0
    }

    /// The set with `feature`.
    #[must_use]
    pub const fn with(self, feature: Feature) -> Features {
        Features(self.0 | feature.bit())
    }

    /// The features in the set.
    pub fn iter(self) -> impl Iterator<Item = Feature> {
        Feature::ALL.into_iter().filter(move |f| self.contains(*f))
    }
}

impl FromIterator<Feature> for Features {
    fn from_iter<I: IntoIterator<Item = Feature>>(iter: I) -> Self {
        iter.into_iter().fold(Features::EMPTY, Features::with)
    }
}

/// What a database's adapter offers and the mode the database runs in,
/// read once so validators are configured from one value.
///
/// [`Capability::DefinedAttributes`] follows the schema mode of the
/// connection a statement runs on: a profile given a `defined_attributes`
/// probe asks it on every check.
///
/// PHP: `Utopia\Database\Adapter\Profile`.
#[derive(Clone)]
pub struct Profile {
    /// The adapter's limits.
    pub limits: Arc<Limits>,
    /// The capabilities it supports.
    pub capabilities: Capabilities,
    /// The features it implements.
    pub features: Features,
    /// Whether tables are shared.
    pub shared_tables: bool,
    /// Whether a migration is running.
    pub migrating: bool,
    defined_attributes: Option<Arc<dyn Fn() -> bool + Send + Sync>>,
}

impl std::fmt::Debug for Profile {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Profile")
            .field("capabilities", &self.capabilities)
            .field("features", &self.features)
            .field("shared_tables", &self.shared_tables)
            .field("migrating", &self.migrating)
            .finish()
    }
}

impl Profile {
    /// A profile (`new Profile(...)`).
    pub fn new(
        limits: Arc<Limits>,
        capabilities: Capabilities,
        features: Features,
        shared_tables: bool,
        migrating: bool,
        defined_attributes: Option<Arc<dyn Fn() -> bool + Send + Sync>>,
    ) -> Self {
        Profile { limits, capabilities, features, shared_tables, migrating, defined_attributes }
    }

    /// The profile of `adapter`, its schema mode asked on every check.
    pub fn of(adapter: Arc<dyn Adapter>, migrating: bool) -> Self {
        let probe = adapter.clone();
        Profile::new(
            adapter.limits(),
            adapter.capabilities(),
            adapter.features(),
            adapter.state().shared_tables(),
            migrating,
            Some(Arc::new(move || probe.supports(Capability::DefinedAttributes))),
        )
    }

    /// Whether the adapter supports `capability` (`supports()`).
    pub fn supports(&self, capability: Capability) -> bool {
        if capability == Capability::DefinedAttributes
            && let Some(probe) = &self.defined_attributes
        {
            return probe();
        }
        self.capabilities.contains(capability)
    }

    /// Whether the adapter implements `feature` (`hasFeature()`).
    pub fn has_feature(&self, feature: Feature) -> bool {
        self.features.contains(feature)
    }
}

/// A bound of a cursor over an order: the field, its direction and the
/// cursor's value.
///
/// PHP: `Utopia\Database\Adapter\CursorBoundary` (internal).
#[derive(Debug, Clone, PartialEq)]
pub struct CursorBoundary {
    /// The field ordered by.
    pub field: String,
    /// Its direction.
    pub direction: OrderDirection,
    /// The cursor's value of the field.
    pub reference: Value,
}

/// The options of [`Adapter::find`].
///
/// PHP: `find()`'s parameters after the queries.
#[derive(Debug, Clone)]
pub struct Find {
    /// The most rows to read (`$limit`, 25 by default).
    pub limit: Option<i64>,
    /// The rows to skip (`$offset`).
    pub offset: Option<i64>,
    /// The attributes to order by (`$orderAttributes`).
    pub order_attributes: Vec<String>,
    /// Their directions (`$orderTypes`).
    pub order_types: Vec<OrderDirection>,
    /// The cursor's values by attribute (`$cursor`).
    pub cursor: Array,
    /// Which side of the cursor to read (`$cursorDirection`).
    pub cursor_direction: CursorDirection,
    /// The permission the rows are read under (`$forPermission`).
    pub for_permission: PermissionType,
}

impl Default for Find {
    fn default() -> Self {
        Find {
            limit: Some(25),
            offset: None,
            order_attributes: Vec::new(),
            order_types: Vec::new(),
            cursor: Array::new(),
            cursor_direction: CursorDirection::After,
            for_permission: PermissionType::Read,
        }
    }
}

/// The timeout state of an adapter that implements [`Timeouts`]: a global
/// timeout and one per event.
///
/// PHP: `Utopia\Database\Adapter\Timeout` (a trait).
#[derive(Debug, Default)]
pub struct TimeoutState {
    inner: Mutex<(i64, Vec<(Event, i64)>)>,
}

impl TimeoutState {
    /// The timeout of `event`: its own, else the global one, else 0
    /// (`getTimeout()`).
    pub fn get(&self, event: Event) -> i64 {
        let inner = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        inner
            .1
            .iter()
            .find(|(e, _)| *e == event)
            .or_else(|| inner.1.iter().find(|(e, _)| *e == Event::All))
            .map_or(inner.0, |(_, ms)| *ms)
    }

    /// Sets the timeout of `event` (`setTimeoutState()`).
    pub fn set(&self, milliseconds: i64, event: Event) {
        let mut inner = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        match inner.1.iter_mut().find(|(e, _)| *e == event) {
            Some(entry) => entry.1 = milliseconds,
            None => inner.1.push((event, milliseconds)),
        }
        if event == Event::All {
            inner.0 = milliseconds;
        }
    }

    /// The timeouts set, by event in the order they were first set
    /// (`$timeouts`); [`Event::All`] is the global one.
    pub fn entries(&self) -> Vec<(Event, i64)> {
        self.inner.lock().unwrap_or_else(|e| e.into_inner()).1.clone()
    }

    /// Clears the timeout of `event`, or every timeout for [`Event::All`]
    /// (`clearTimeoutState()`).
    pub fn clear(&self, event: Event) {
        let mut inner = self.inner.lock().unwrap_or_else(|e| e.into_inner());
        if event == Event::All {
            *inner = (0, Vec::new());
        } else {
            inner.1.retain(|(e, _)| *e != event);
        }
    }
}

/// The settings PHP's `Adapter` base class keeps per handle.
pub struct State {
    inner: Mutex<StateInner>,
    tenant: Scoped<Value>,
    ignoring_duplicates: Scoped<bool>,
}

/// The plain fields of [`State`].
#[derive(Clone)]
pub struct StateInner {
    /// `$database`: the schema (SQL) or database (MongoDB) name.
    pub database: String,
    /// `$hostname`.
    pub hostname: String,
    /// `$namespace`: the table name prefix.
    pub namespace: String,
    /// `$sharedTables`.
    pub shared_tables: bool,
    /// `$tenantPerDocument`.
    pub tenant_per_document: bool,
    /// `$inTransaction`: the nesting depth.
    pub in_transaction: i64,
    /// `$transactionCalls`: the `withTransaction()` calls running.
    pub transaction_calls: i64,
    /// `$locks`.
    pub locks: bool,
    /// `$transforms`, by name in registration order.
    pub transforms: Vec<(String, Arc<dyn Transform>)>,
    /// `$metadata`, in insertion order.
    pub metadata: Vec<(String, Value)>,
    /// `$writeHooks`.
    pub write_hooks: Vec<Arc<dyn Write>>,
    /// `$profiler`.
    pub profiler: Option<Arc<Profiler>>,
    /// `$authorization`.
    pub authorization: Arc<Authorization>,
    /// `$limits`, once built (reset when shared tables change).
    pub limits: Option<Arc<Limits>>,
}

impl Default for State {
    fn default() -> Self {
        State {
            inner: Mutex::new(StateInner {
                database: String::new(),
                hostname: String::new(),
                namespace: String::new(),
                shared_tables: false,
                tenant_per_document: false,
                in_transaction: 0,
                transaction_calls: 0,
                locks: false,
                transforms: Vec::new(),
                metadata: Vec::new(),
                write_hooks: Vec::new(),
                profiler: None,
                authorization: Arc::new(Authorization::new(true)),
                limits: None,
            }),
            tenant: Scoped::new(Value::Null),
            ignoring_duplicates: Scoped::new(false),
        }
    }
}

impl State {
    /// Locks the plain fields. Never held across an await.
    pub fn lock(&self) -> MutexGuard<'_, StateInner> {
        self.inner.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// The tenant, scoped per future (`withTenant()` is
    /// [`Scoped::with`] on it).
    pub fn tenant(&self) -> &Scoped<Value> {
        &self.tenant
    }

    /// Whether the current future runs under `ignoreDuplicates()`.
    pub fn ignoring_duplicates(&self) -> &Scoped<bool> {
        &self.ignoring_duplicates
    }

    /// `hasSharedTables()`.
    pub fn shared_tables(&self) -> bool {
        self.lock().shared_tables
    }

    /// `getDatabase()`.
    pub fn database(&self) -> String {
        self.lock().database.clone()
    }

    /// `getNamespace()`.
    pub fn namespace(&self) -> String {
        self.lock().namespace.clone()
    }

    /// The registered transforms applied to `query`, in registration order
    /// (what an adapter runs before executing a statement).
    pub fn transform(&self, event: Event, query: String) -> Result<String> {
        let transforms: Vec<Arc<dyn Transform>> = self.lock().transforms.iter().map(|(_, t)| t.clone()).collect();
        transforms.iter().try_fold(query, |q, t| t.transform(event, q))
    }
}

/// `Adapter::filter()`: `value` with every character but `A-Z`, `a-z`,
/// `0-9`, `_` and `-` removed.
///
/// ```
/// assert_eq!(utopia_database::adapter::filter("my db!"), "mydb");
/// ```
pub fn filter(value: &str) -> String {
    value.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect()
}

/// `Adapter::escapeWildcards()`: `%`, `_`, `[`, `]`, `^`, `-`, `.`, `*`,
/// `+`, `?`, `(`, `)`, `{`, `}` and `|` escaped with a backslash.
pub fn escape_wildcards(value: &str) -> String {
    let mut out = value.to_owned();
    for wildcard in ['%', '_', '[', ']', '^', '-', '.', '*', '+', '?', '(', ')', '{', '}', '|'] {
        out = out.replace(wildcard, &format!("\\{wildcard}"));
    }
    out
}

/// The storage engine under a database.
///
/// PHP: `Utopia\Database\Adapter`. Its abstract methods are the required
/// methods here; its concrete ones are provided, over [`Adapter::state`].
#[async_trait]
pub trait Adapter: Send + Sync + 'static {
    /// The settings of the handle.
    fn state(&self) -> &State;

    /// The adapter as [`Any`], to reach its concrete type (PHP's
    /// `getDriver()` returns the connection; Rust callers downcast the
    /// adapter they built instead).
    fn as_any(&self) -> &dyn Any;

    // ---- What the adapter offers -------------------------------------

    /// The capabilities the adapter supports (`capabilities()`): by default
    /// key, array and unique indexes.
    fn capabilities(&self) -> Capabilities {
        Capabilities::of(&[Capability::IndexKey, Capability::IndexArray, Capability::IndexUnique])
    }

    /// Whether the adapter supports `capability` (`supports()`).
    fn supports(&self, capability: Capability) -> bool {
        self.capabilities().contains(capability)
    }

    /// The adapter's limits (`limits()`), built once per shared-tables mode.
    fn limits(&self) -> Arc<Limits>;

    /// The features the adapter implements, from its accessors.
    fn features(&self) -> Features {
        let mut features = Features::EMPTY;
        let checks: [(Feature, bool); 9] = [
            (Feature::Casting, self.casting().is_some()),
            (Feature::Connection, self.connection().is_some()),
            (Feature::QueryBuilder, self.query_builder().is_some()),
            (Feature::RawQuery, self.raw_query().is_some()),
            (Feature::Relationships, self.relationships().is_some()),
            (Feature::Schemaless, self.schemaless().is_some()),
            (Feature::Spatial, self.spatial().is_some()),
            (Feature::Timeouts, self.timeouts().is_some()),
            (Feature::Upserts, self.upserts().is_some()),
        ];
        for (feature, present) in checks {
            if present {
                features = features.with(feature);
            }
        }
        features
    }

    /// Whether the adapter implements `feature` (`hasFeature()`).
    fn has_feature(&self, feature: Feature) -> bool {
        self.features().contains(feature)
    }

    /// [`Casting`], when implemented.
    fn casting(&self) -> Option<&dyn Casting> {
        None
    }

    /// [`Connection`], when implemented.
    fn connection(&self) -> Option<&dyn Connection> {
        None
    }

    /// [`QueryBuilder`], when implemented.
    fn query_builder(&self) -> Option<&dyn QueryBuilder> {
        None
    }

    /// [`RawQuery`], when implemented.
    fn raw_query(&self) -> Option<&dyn RawQuery> {
        None
    }

    /// [`Relationships`], when implemented.
    fn relationships(&self) -> Option<&dyn Relationships> {
        None
    }

    /// [`Schemaless`], when implemented.
    fn schemaless(&self) -> Option<&dyn Schemaless> {
        None
    }

    /// [`Spatial`], when implemented.
    fn spatial(&self) -> Option<&dyn Spatial> {
        None
    }

    /// [`Timeouts`], when implemented.
    fn timeouts(&self) -> Option<&dyn Timeouts> {
        None
    }

    /// [`Upserts`], when implemented.
    fn upserts(&self) -> Option<&dyn Upserts> {
        None
    }

    // ---- Settings ------------------------------------------------------

    /// `setAuthorization()`.
    fn set_authorization(&self, authorization: Arc<Authorization>) {
        self.state().lock().authorization = authorization;
    }

    /// `getAuthorization()`.
    fn authorization(&self) -> Arc<Authorization> {
        self.state().lock().authorization.clone()
    }

    /// `setProfiler()`.
    fn set_profiler(&self, profiler: Option<Arc<Profiler>>) {
        self.state().lock().profiler = profiler;
    }

    /// `getProfiler()`.
    fn profiler(&self) -> Option<Arc<Profiler>> {
        self.state().lock().profiler.clone()
    }

    /// `setDatabase()`: the name [`filter`]ed.
    fn set_database(&self, name: &str) {
        self.state().lock().database = filter(name);
    }

    /// `getDatabase()`.
    fn database(&self) -> String {
        self.state().database()
    }

    /// `setNamespace()`: the namespace [`filter`]ed.
    fn set_namespace(&self, namespace: &str) {
        self.state().lock().namespace = filter(namespace);
    }

    /// `getNamespace()`.
    fn namespace(&self) -> String {
        self.state().namespace()
    }

    /// `setHostname()`.
    fn set_hostname(&self, hostname: &str) {
        self.state().lock().hostname = hostname.to_owned();
    }

    /// `setSharedTables()`: the limits are built again when it changes.
    fn set_shared_tables(&self, shared_tables: bool) {
        let mut state = self.state().lock();
        if state.shared_tables != shared_tables {
            state.shared_tables = shared_tables;
            state.limits = None;
        }
    }

    /// `hasSharedTables()`.
    fn shared_tables(&self) -> bool {
        self.state().shared_tables()
    }

    /// `setTenant()`: the value the current future sees (see
    /// [`Scoped::set`]).
    fn set_tenant(&self, tenant: Value) {
        self.state().tenant().set(tenant);
    }

    /// `getTenant()`: a string of digits as an integer.
    fn tenant(&self) -> Value {
        match self.state().tenant().get() {
            Value::Str(s) if !s.is_empty() && s.as_bytes().iter().all(u8::is_ascii_digit) => {
                Value::Int(php_std::format::str_to_int(s.as_bytes()))
            }
            other => other,
        }
    }

    /// `setTenantPerDocument()`.
    fn set_tenant_per_document(&self, tenant_per_document: bool) {
        self.state().lock().tenant_per_document = tenant_per_document;
    }

    /// `isTenantPerDocument()`.
    fn tenant_per_document(&self) -> bool {
        self.state().lock().tenant_per_document
    }

    /// `setMetadata()`: the value replaces the key's, in its place.
    fn set_metadata(&self, key: &str, value: Value) {
        let mut state = self.state().lock();
        match state.metadata.iter_mut().find(|(k, _)| k == key) {
            Some(entry) => entry.1 = value,
            None => state.metadata.push((key.to_owned(), value)),
        }
    }

    /// `getMetadata()`.
    fn metadata(&self) -> Vec<(String, Value)> {
        self.state().lock().metadata.clone()
    }

    /// `resetMetadata()`.
    fn reset_metadata(&self) {
        self.state().lock().metadata.clear();
    }

    /// `setLocks()`.
    fn set_locks(&self, locks: bool) {
        self.state().lock().locks = locks;
    }

    /// `addWriteHook()`.
    fn add_write_hook(&self, hook: Arc<dyn Write>) {
        self.state().lock().write_hooks.push(hook);
    }

    /// `removeWriteHook($hook)` for an instance.
    fn remove_write_hook(&self, hook: &Arc<dyn Write>) {
        self.state().lock().write_hooks.retain(|h| !Arc::ptr_eq(h, hook));
    }

    /// `removeWriteHook(Tenancy::class)`: removes the tenancy hooks.
    fn remove_tenancy_hooks(&self) {
        self.state().lock().write_hooks.retain(|h| h.tenancy().is_none());
    }

    /// `getWriteHooks()`.
    fn write_hooks(&self) -> Vec<Arc<dyn Write>> {
        self.state().lock().write_hooks.clone()
    }

    /// `getTenantHook()`: the first [`Tenancy`] write hook.
    fn tenant_hook(&self) -> Option<Tenancy> {
        self.state().lock().write_hooks.iter().find_map(|h| h.tenancy().cloned())
    }

    /// `addTransform()`: replaces a transform of the same name in its place.
    fn add_transform(&self, name: &str, transform: Arc<dyn Transform>) {
        let mut state = self.state().lock();
        match state.transforms.iter_mut().find(|(n, _)| n == name) {
            Some(entry) => entry.1 = transform,
            None => state.transforms.push((name.to_owned(), transform)),
        }
    }

    /// `removeTransform()`.
    fn remove_transform(&self, name: &str) {
        self.state().lock().transforms.retain(|(n, _)| n != name);
    }

    /// `resetTransforms()`.
    fn reset_transforms(&self) {
        self.state().lock().transforms.clear();
    }

    /// The row as every write hook decorates a row written for `document`
    /// (`decorateRow()`): the document's tenant, else the adapter's.
    fn decorate_row(&self, row: Array, document: &Document) -> Array {
        let hooks = self.write_hooks();
        if hooks.is_empty() {
            return row;
        }
        let metadata =
            crate::hook::RowMetadata { tenant: document.tenant().unwrap_or_else(|| self.state().tenant().get()) };
        hooks.iter().fold(row, |row, hook| hook.decorate_row(row, &metadata))
    }

    // ---- Transactions --------------------------------------------------

    /// Starts a transaction, or a savepoint inside one.
    async fn start_transaction(&self) -> Result<bool>;

    /// Commits the innermost transaction.
    async fn commit_transaction(&self) -> Result<bool>;

    /// Rolls the innermost transaction back.
    async fn rollback_transaction(&self) -> Result<bool>;

    /// `inTransaction()`.
    fn in_transaction(&self) -> bool {
        self.state().lock().in_transaction > 0
    }

    /// Ends what the connection may still hold of a failed outermost
    /// attempt (`abandonTransaction()`; nothing by default).
    async fn abandon_transaction(&self) {}

    /// Whether a failure can succeed on another attempt (`isRetryable()`):
    /// a [`Kind::Transaction`] (or [`Kind::Contention`]) anywhere in its
    /// chain, or a lost connection before any other typed library error.
    fn is_retryable(&self, failure: &Error) -> bool {
        let mut cause = Some(failure);
        while let Some(c) = cause {
            if c.is_a(Kind::Transaction) {
                return true;
            }
            if c.is_a(Kind::Database) && c.kind() != Kind::Database {
                return false;
            }
            if self.is_transient(c) {
                return true;
            }
            cause = c.previous();
        }
        false
    }

    /// Whether an error is transient (`isTransient()`): a lost connection.
    fn is_transient(&self, error: &Error) -> bool {
        crate::connection::is_lost(error)
    }

    // ---- Databases -----------------------------------------------------

    /// Creates a database (`create()`).
    async fn create(&self, name: &str) -> Result<bool>;

    /// Renames a database (`update()`).
    async fn update(&self, name: &str, new: &str) -> Result<bool>;

    /// Whether a database exists (`exists()`).
    async fn exists(&self, database: &str) -> Result<bool>;

    /// Whether a collection exists in a database (`collectionExists()`).
    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool>;

    /// The databases (`list()`).
    async fn list(&self) -> Result<Vec<Document>>;

    /// Deletes a database (`delete()`).
    async fn delete(&self, name: &str) -> Result<bool>;

    // ---- Collections ---------------------------------------------------

    /// Creates a collection's storage (`createCollection()`).
    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool>;

    /// Drops a collection's storage (`deleteCollection()`).
    async fn delete_collection(&self, collection: &str) -> Result<bool>;

    /// Refreshes a collection's planner statistics (`analyzeCollection()`).
    async fn analyze_collection(&self, collection: &str) -> Result<bool>;

    /// The size of a collection's data (`getSizeOfCollection()`).
    async fn size_of_collection(&self, collection: &str) -> Result<i64>;

    /// The size of a collection on disk (`getSizeOfCollectionOnDisk()`).
    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64>;

    /// The bytes a row of the collection's attributes takes (`getAttributeWidth()`).
    fn attribute_width(&self, collection: &Collection) -> Result<i64>;

    /// The attributes of a collection, the internal ones included (`getCountOfAttributes()`).
    fn count_of_attributes(&self, collection: &Collection) -> Result<i64> {
        Ok(collection.attributes()?.len() as i64 + self.limits().default_attributes)
    }

    /// The indexes of a collection, the internal ones included (`getCountOfIndexes()`).
    fn count_of_indexes(&self, collection: &Collection) -> Result<i64> {
        Ok(collection.indexes()?.len() as i64 + self.limits().default_indexes)
    }

    // ---- Attributes ----------------------------------------------------

    /// Adds an attribute's column (`createAttribute()`).
    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool>;

    /// Adds several attributes' columns (`createAttributes()`).
    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool>;

    /// Changes an attribute's column; the target key is `attribute.key`
    /// (`updateAttribute()`).
    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool>;

    /// Drops an attribute's `NOT NULL` without a column change
    /// (`relaxAttributeRequired()`; nothing by default).
    async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        let _ = (collection, id);
        Ok(true)
    }

    /// Drops an attribute's column (`deleteAttribute()`).
    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool>;

    /// Renames an attribute's column (`renameAttribute()`).
    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool>;

    /// The columns the engine holds for a collection (`getSchemaAttributes()`).
    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>>;

    /// The indexes the engine holds for a collection (`getSchemaIndexes()`).
    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>>;

    /// The type the engine reports for an index of `kind`
    /// (`getSchemaIndexType()`; `kind` by default).
    fn schema_index_type(&self, kind: IndexType) -> IndexType {
        kind
    }

    /// The column type of an attribute; `None` on engines without columns
    /// (`getColumnType()`).
    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>>;

    // ---- Indexes -------------------------------------------------------

    /// Creates an index (`createIndex()`): `attribute_types` maps each
    /// attribute to its type, `collation` each to its collation.
    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool>;

    /// Drops an index (`deleteIndex()`).
    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool>;

    /// Renames an index (`renameIndex()`).
    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool>;

    // ---- Documents -----------------------------------------------------

    /// Stores a new document (`createDocument()`).
    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document>;

    /// Stores new documents (`createDocuments()`); under `ignoreDuplicates()`
    /// only those inserted are returned.
    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>>;

    /// Reads a document (`getDocument()`); an empty document when missing.
    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document>;

    /// Updates a document stored under `id` (`updateDocument()`).
    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document>;

    /// Applies `updates` to `documents` (`updateDocuments()`);
    /// `skip_permissions` are the ids whose permissions the update keeps.
    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64>;

    /// Adds `value` to an attribute, within `min` and `max`
    /// (`increaseDocumentAttribute()`); numbers may be numeric strings.
    #[allow(clippy::too_many_arguments)]
    async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool>;

    /// Deletes a document (`deleteDocument()`).
    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool>;

    /// Deletes documents by sequence, and the permissions of `permission_ids`
    /// (`deleteDocuments()`).
    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64>;

    /// Reads documents (`find()`): documents, or rows of aggregates and
    /// `groupBy` queries.
    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>>;

    /// Counts documents, up to `max` (`count()`).
    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64>;

    /// Sums an attribute, over at most `max` documents (`sum()`): an integer
    /// or a float.
    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value>;

    /// The documents with their stored `$sequence` (`getSequences()`).
    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>>;

    /// The read concurrency a pool can give relationship population (PHP's
    /// `Pool::getReadConcurrency()`; 1 for an adapter that is not a pool).
    fn read_concurrency(&self) -> usize {
        1
    }

    /// Whether the last read was served by a read replica (PHP's
    /// `ReadWritePool::servedByReplica()`; `false` elsewhere).
    fn served_by_replica(&self) -> bool {
        false
    }

    /// Runs a transaction on another adapter: [`with_transaction`] hands
    /// it the transaction's `body`, which runs the attempts on the adapter
    /// it is given. By default the transaction runs on this adapter, and
    /// `body` is given back.
    ///
    /// PHP: `Pool::withTransaction()` (a pool pins one connection for the
    /// calling coroutine and runs the transaction there, so every statement
    /// of the callback shares its session).
    fn transaction_scope<'a>(
        &'a self,
        body: TransactionBody<'a>,
    ) -> std::result::Result<BoxFuture<'a, Result<()>>, TransactionBody<'a>> {
        Err(body)
    }
}

/// The body of a transaction an [`Adapter::transaction_scope`] runs: given
/// the adapter the transaction runs on, it runs [`with_transaction`]'s
/// attempts there and says whether they succeeded.
pub type TransactionBody<'a> = Box<dyn FnOnce(Arc<dyn Adapter>) -> BoxFuture<'a, bool> + Send + 'a>;

/// Runs `callback` in a transaction (`withTransaction()`): commits when it
/// succeeds, rolls back when it fails, and runs it again after a failure
/// [`Adapter::is_retryable`] accepts, up to twice, 50 ms and 100 ms apart.
/// Only the outermost call retries; a call nested in another rolls back to
/// its savepoint and returns the failure.
///
/// Fails ([`Kind::Transaction`]) when the transaction was lost before the
/// callback returned or before it could commit.
///
/// An adapter with an [`Adapter::transaction_scope`] (a pool) runs the
/// attempts on the adapter it chooses.
pub async fn with_transaction<A, T, F, Fut>(adapter: &A, callback: F) -> Result<T>
where
    A: Adapter + ?Sized,
    F: FnMut() -> Fut + Send,
    Fut: Future<Output = Result<T>> + Send,
    T: Send,
{
    let mut slot: Option<Result<T>> = None;
    let mut callback = Some(callback);
    let scope = {
        let (slot, callback) = (&mut slot, &mut callback);
        let body: TransactionBody<'_> = Box::new(move |inner: Arc<dyn Adapter>| {
            Box::pin(async move {
                let Some(callback) = callback.take() else { return false };
                let result = attempts(&*inner, callback).await;
                let succeeded = result.is_ok();
                *slot = Some(result);
                succeeded
            })
        });
        match adapter.transaction_scope(body) {
            Ok(scope) => Some(scope.await),
            Err(_) => None,
        }
    };
    match scope {
        Some(scoped) => {
            scoped?;
            slot.unwrap_or_else(|| Err(Error::transaction("Failed to execute transaction")))
        }
        None => match callback.take() {
            Some(callback) => attempts(adapter, callback).await,
            None => Err(Error::transaction("Failed to execute transaction")),
        },
    }
}

/// The attempts of [`with_transaction`] on `adapter`.
async fn attempts<A, T, F, Fut>(adapter: &A, mut callback: F) -> Result<T>
where
    A: Adapter + ?Sized,
    F: FnMut() -> Fut,
    Fut: Future<Output = Result<T>>,
{
    const RETRIES: u32 = 2;
    let (depth, enclosed) = {
        let mut state = adapter.state().lock();
        let depth = state.in_transaction;
        let enclosed = state.transaction_calls > 0;
        state.transaction_calls += 1;
        (depth, enclosed)
    };
    let outermost = depth == 0 && !enclosed;
    struct Calls<'a>(&'a State);
    impl Drop for Calls<'_> {
        fn drop(&mut self) {
            self.0.lock().transaction_calls -= 1;
        }
    }
    let _calls = Calls(adapter.state());
    let in_transaction = || adapter.state().lock().in_transaction;

    for attempt in 0..=RETRIES {
        let mut started = false;
        let result: Result<T> = async {
            adapter.start_transaction().await?;
            started = true;
            let value = callback().await?;
            if in_transaction() <= depth {
                return Err(Error::transaction(
                    "Failed to commit transaction: the transaction was lost before the callback returned",
                ));
            }
            adapter.commit_transaction().await?;
            Ok(value)
        }
        .await;
        let action = match result {
            Ok(value) => return Ok(value),
            Err(e) => e,
        };
        let mut rollback = None;
        let mut lost = started && in_transaction() <= depth;
        if !lost {
            if let Err(e) = adapter.rollback_transaction().await {
                rollback = Some(e);
                adapter.state().lock().in_transaction = 0;
            }
            lost = in_transaction() < depth;
        }
        if outermost {
            adapter.abandon_transaction().await;
        }
        if lost {
            if !action.is_a(Kind::Contention) {
                return Err(Error::transaction(
                    "Failed to execute transaction: the transaction was lost before it could commit",
                )
                .with_previous(action));
            }
            if depth > 0 {
                return Err(action);
            }
        }
        if enclosed || !adapter.is_retryable(&action) {
            return Err(action);
        }
        if attempt < RETRIES {
            tokio::time::sleep(std::time::Duration::from_millis(50 * u64::from(attempt + 1))).await;
            continue;
        }
        return Err(rollback.unwrap_or(action));
    }
    Err(Error::transaction("Failed to execute transaction"))
}

/// An adapter whose engine stores and returns native types, so it converts
/// documents itself instead of having the library cast them.
///
/// PHP: `Utopia\Database\Adapter\Feature\Casting`.
pub trait Casting: Send + Sync {
    /// A document's values converted to the engine's types before it is
    /// written (`castBefore()`).
    fn cast_before(&self, collection: &Collection, document: Document) -> Result<Document>;

    /// Documents read from the engine converted back, a page at a time
    /// (`castAfter()`).
    fn cast_after(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>>;

    /// A datetime string as the value the engine compares stored datetimes
    /// with (`castDatetime()`).
    fn cast_datetime(&self, value: &str) -> Result<Value>;
}

/// An adapter that talks to a server over a connection it can check,
/// re-establish and identify.
///
/// PHP: `Utopia\Database\Adapter\Feature\Connection`.
#[async_trait]
pub trait Connection: Send + Sync {
    /// Whether the connection answers (`ping()`).
    async fn ping(&self) -> Result<bool>;

    /// Re-establishes the connection (`reconnect()`).
    async fn reconnect(&self) -> Result<()>;

    /// The connection's id (`id()`).
    async fn id(&self) -> Result<String>;

    /// The server's hostname (`hostname()`).
    async fn hostname(&self) -> Result<String>;
}

/// An adapter with builders in its dialect.
///
/// PHP: `Utopia\Database\Adapter\Feature\QueryBuilder`.
pub trait QueryBuilder: Send + Sync {
    /// A query builder scoped with the adapter's scope, whose `from()` takes
    /// a collection id (`builder()`).
    fn builder(&self) -> Result<crate::hook::SqlBuilder>;

    /// A schema builder in the adapter's dialect (`schema()`).
    fn schema(&self) -> Result<SqlSchema>;
}

/// A schema builder of `utopia-query` in an adapter's dialect.
///
/// PHP: `MySQLSchema|PostgresSchema` (SQLite's extends MySQL's).
#[derive(Clone)]
#[allow(missing_debug_implementations)]
pub enum SqlSchema {
    /// MySQL and MariaDB.
    MySql(utopia_query::schema::MySql),
    /// PostgreSQL.
    PostgreSql(utopia_query::schema::PostgreSql),
    /// SQLite.
    Sqlite(utopia_query::schema::Sqlite),
}

/// The values of the `select` queries among `queries`, in order
/// (`getAttributeSelections()`).
pub fn attribute_selections(queries: &[Query]) -> Vec<utopia_query::Value> {
    queries
        .iter()
        .filter(|q| q.method() == utopia_query::Method::Select)
        .flat_map(|q| q.values().values().cloned().collect::<Vec<_>>())
        .collect()
}

/// An adapter that runs raw statements.
///
/// PHP: `Utopia\Database\Adapter\Feature\RawQuery`.
#[async_trait]
pub trait RawQuery: Send + Sync {
    /// The rows a raw query reads, as documents (`rawQuery()`).
    async fn raw_query(&self, query: &str, bindings: utopia_query::Array) -> Result<Vec<Document>>;

    /// The rows a raw statement changes (`rawMutation()`).
    async fn raw_mutation(&self, query: &str, bindings: utopia_query::Array) -> Result<i64>;
}

/// An adapter that stores relationships. Every relationship is given from
/// the side of `collection`, with both keys resolved.
///
/// PHP: `Utopia\Database\Adapter\Feature\Relationships`.
#[async_trait]
pub trait Relationships: Send + Sync {
    /// Creates the storage of a relationship from its parent side
    /// (`createRelationship()`).
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool>;

    /// Renames the stored keys of a relationship as `update` says
    /// (`updateRelationship()`).
    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool>;

    /// Drops the storage of a relationship (`deleteRelationship()`).
    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool>;
}

/// An adapter that can store documents without a declared schema.
///
/// PHP: `Utopia\Database\Adapter\Feature\Schemaless`.
pub trait Schemaless: Send + Sync {
    /// Switches schemaless mode (`setSchemaless()`).
    fn set_schemaless(&self, schemaless: bool);

    /// Whether schemaless mode is on (`isSchemaless()`).
    fn is_schemaless(&self) -> bool;
}

/// An adapter that converts spatial values between coordinate arrays and
/// the form the engine stores.
///
/// PHP: `Utopia\Database\Adapter\Feature\Spatial`.
pub trait Spatial: Send + Sync {
    /// A point, linestring or polygon encoded for storage (`encode()`).
    ///
    /// Fails ([`Kind::Structure`]) for an invalid geometry, and
    /// ([`Kind::Database`]) for a type that is not spatial.
    fn encode(&self, value: &Value, kind: ColumnType) -> Result<String>;

    /// A stored geometry decoded into coordinates (`decode()`).
    fn decode(&self, value: &[u8], kind: ColumnType) -> Result<Value>;
}

/// An adapter that bounds how long its statements may run.
///
/// PHP: `Utopia\Database\Adapter\Feature\Timeouts`.
pub trait Timeouts: Send + Sync {
    /// Sets the timeout of `event` (`setTimeout()`).
    fn set_timeout(&self, milliseconds: i64, event: Event) -> Result<()>;

    /// Clears the timeout of `event`, or every one (`clearTimeout()`).
    fn clear_timeout(&self, event: Event);

    /// The timeout statements of `event` run under (`getTimeout()`).
    fn timeout(&self, event: Event) -> i64;
}

/// An adapter that upserts.
///
/// PHP: `Utopia\Database\Adapter\Feature\Upserts`.
#[async_trait]
pub trait Upserts: Send + Sync {
    /// Inserts the change's new document or updates the stored one
    /// (`upsertDocument()`).
    async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document>;

    /// Upserts each change; with `increase`, an update adds the new
    /// document's value of that attribute to the stored one
    /// (`upsertDocuments()`).
    async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>>;
}
