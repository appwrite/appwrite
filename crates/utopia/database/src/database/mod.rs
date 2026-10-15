//! [`Database`]: collections, attributes, indexes, relationships and
//! documents over an [`Adapter`], with permissions, tenancy, hooks, events
//! and the document and query caches.
//!
//! A [`Database`] is a cheap [`Clone`] handle: clones share one adapter, one
//! cache and one set of settings, as every reference to a PHP object does.
//! The plain settings live behind a [`std::sync::Mutex`] held only for a read
//! or a write, never across an `.await`. The settings PHP scopes per
//! coroutine (`silent()`, `withValidation()`, `skipFilters()`, ...) are
//! [`Scoped`] values: a scope takes the future it runs, and what it sets is
//! seen by that future and what it awaits, and by nothing else.
//!
//! # Areas
//!
//! PHP's class (`Database.php` and its traits) is split by area; each file
//! is one `impl Database` block:
//!
//! | File | PHP |
//! |---|---|
//! | `mod.rs` | the constructor, settings, adapter passthroughs, `profile()` |
//! | `scopes.rs` | `silent()`, the scoped toggles (`withValidation()`, `skipFilters()`, ...), `snapshot()`/`withSnapshot()` |
//! | `hooks.rs` | `addHook()`/`removeHook()`, `listens()`/`dispatch()`, decorators, `rewriteQueries()` |
//! | `filters.rs` | `addFilter()`, the default filters, `encode()`/`decode()`/`casting()`, joins' encode/decode, `castBefore()`/`castAfter()` |
//! | `queries.rs` | `convertQueries()`/`convertQuery()`, `checkQueryTypes()` |
//! | `keys.rs` | cache keys, the query cache field and scope, invalidation tokens |
//! | `retry.rs` | `withRetries()`, `cleanup()`, `updateMetadata()` |
//! | `raw.rs` | `from()`, `schema()`, `query()`, `mutate()` |
//! | `databases.rs` | `Trait\Databases` |
//! | `transactions.rs` | `Trait\Transactions` |
//! | `collections.rs`, `attributes.rs`, `indexes.rs`, `relationships.rs` | `Trait\Collections`, `Trait\Attributes`, `Trait\Indexes`, `Trait\Relationships` |
//! | `documents/` | `Trait\Documents` (`cache.rs`: the document cache; `read.rs`: `getDocument()`; `find.rs`: `find()`, `count()`, `sum()`, `aggregate()`, `cursor()`; `cursors.rs`: `Trait\Cursors`; `create.rs`, `update.rs`, `upsert.rs`, `numeric.rs`, `delete.rs`: the writes) |
//!
//! # Futures and recursion
//!
//! The document operations recurse (a collection is read as a document;
//! relationships read and write related documents through the database), so
//! the methods on that cycle return a [`BoxFuture`]: [`Database::get_document`],
//! [`Database::find`], [`Database::create_document`],
//! [`Database::update_document`], [`Database::delete_document`] and the hooks'
//! methods the writes call.
//!
//! PHP: `Utopia\Database\Database`.

mod consts;

mod attributes;
mod collections;
mod databases;
mod documents;
mod filters;
mod hooks;
mod indexes;
mod keys;
mod queries;
mod raw;
mod relationships;
mod retry;
mod scopes;
mod transactions;

#[cfg(all(test, feature = "memory"))]
mod cache_engine_tests;
#[cfg(test)]
mod core_memory_tests;
#[cfg(test)]
mod core_tests;
#[cfg(all(test, feature = "memory"))]
pub(crate) mod cache_fixtures;
#[cfg(all(test, feature = "memory"))]
pub(crate) mod observed;
#[cfg(test)]
pub(crate) mod fixtures;
#[cfg(test)]
mod schema_tests;
#[cfg(test)]
pub(crate) mod spy;
#[cfg(test)]
pub(crate) mod testing;
#[cfg(all(test, feature = "memory"))]
pub(crate) mod testing_memory;
#[cfg(all(test, feature = "memory"))]
pub(crate) mod testing_intercepted;

use std::collections::HashMap;
use std::sync::{Arc, Mutex, MutexGuard, RwLock};

use async_trait::async_trait;
use php_std::datetime::DateTime;
use utopia_cache::Cache;

pub use futures_util::future::BoxFuture;
pub use futures_util::stream::BoxStream;

pub use consts::*;
pub use filters::Filter;
pub use hooks::Hook;
pub use transactions::Context;

use crate::adapter::{Adapter, Profile};
use crate::cache::{Invalidator, Query as QueryCache};
use crate::capability::Capability;
use crate::error::{Error, Result};
use crate::event::Event;
use crate::filter::Registry;
use crate::hook::relationships::Relationships;
use crate::hook::{Decorator, Lifecycle, Nested, Queries};
use crate::profiler::Profiler;
use crate::state::Scoped;
use crate::validator::authorization::Authorization;
use crate::validator::queries::documents::Documents as DocumentsValidator;
use crate::value::Value;

/// Per-relationship nested selections: relationship key => the select
/// queries to apply on the related collection, in PHP's key order.
///
/// PHP: the `array<string, array<Query>> $selects` of `processQueries()` and
/// `populateDocuments()`.
pub type Selects = Vec<(String, Vec<crate::Query>)>;

/// The staged view of a related document for the `authorize*()` checks:
/// given a collection id and a document id, the document as staged writes
/// leave it (an empty document when they delete it), or `None` to judge the
/// stored one.
///
/// PHP: the `callable(string, string): ?Document $resolve` parameters.
pub type Resolve = Arc<dyn Fn(&str, &str) -> Option<crate::Document> + Send + Sync>;

/// A callback the bulk writes call for each document written (`on_next`)
/// or failure (`on_error`).
///
/// Every `FnMut(A) -> Result<()>` closure is one; implement the trait on a
/// type for a callback that awaits.
///
/// PHP: the `?callable $onNext` / `?callable $onError` parameters.
#[async_trait]
pub trait Callback<A: Send + 'static>: Send {
    /// Calls the callback.
    async fn call(&mut self, args: A) -> Result<()>;
}

#[async_trait]
impl<A, F> Callback<A> for F
where
    A: Send + 'static,
    F: FnMut(A) -> Result<()> + Send,
{
    async fn call(&mut self, args: A) -> Result<()> {
        self(args)
    }
}

/// The database. See the [module docs](self).
///
/// PHP: `Utopia\Database\Database`.
#[derive(Clone)]
pub struct Database {
    pub(crate) inner: Arc<Inner>,
}

impl std::fmt::Debug for Database {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Database").field("database", &self.inner.adapter.database()).finish_non_exhaustive()
    }
}

/// What every clone of a [`Database`] shares.
pub(crate) struct Inner {
    /// `$adapter`.
    pub(crate) adapter: Arc<dyn Adapter>,
    /// The plain properties PHP's methods change.
    pub(crate) settings: Mutex<Settings>,
    /// The properties PHP keeps in `State\Value`s.
    pub(crate) toggles: Toggles,
    /// The invalidation scope the current future runs in (PHP keys its
    /// `$queryCacheMutations`, `$documentCacheMutations`, ... by coroutine
    /// id; a scope is open from the outermost `withInvalidationScope()` to
    /// its end).
    pub(crate) context: Scoped<Option<Arc<Context>>>,
}

/// The scoped toggles of a database (PHP's `State\Value` properties).
pub(crate) struct Toggles {
    /// `$silenced`: whether every lifecycle hook is silenced.
    pub(crate) silenced: Scoped<bool>,
    /// `$silencedListeners`: the names of the silenced named hooks, in the
    /// order they were silenced.
    pub(crate) silenced_listeners: Scoped<Arc<Vec<String>>>,
    /// `$requestTimestamp`.
    pub(crate) request_timestamp: Scoped<Option<DateTime>>,
    /// `$filtering`.
    pub(crate) filtering: Scoped<bool>,
    /// `$filterExclusions`: the names of the filters turned off (with
    /// whether each is), `None` for PHP's `null`.
    #[allow(clippy::type_complexity)]
    pub(crate) filter_exclusions: Scoped<Option<Arc<Vec<(String, bool)>>>>,
    /// `$validation`.
    pub(crate) validation: Scoped<bool>,
    /// `$datePreservation`.
    pub(crate) date_preservation: Scoped<bool>,
    /// `$sequencePreservation`.
    pub(crate) sequence_preservation: Scoped<bool>,
    /// `$ignoringDuplicates`.
    pub(crate) ignoring_duplicates: Scoped<bool>,
    /// `$grantChecking`.
    pub(crate) grant_checking: Scoped<bool>,
    /// `$rewriting`: whether query hooks rewrite the queries of reads.
    pub(crate) rewriting: Scoped<bool>,
}

impl Default for Toggles {
    fn default() -> Self {
        Toggles {
            silenced: Scoped::new(false),
            silenced_listeners: Scoped::new(Arc::new(Vec::new())),
            request_timestamp: Scoped::new(None),
            filtering: Scoped::new(true),
            filter_exclusions: Scoped::new(Some(Arc::new(Vec::new()))),
            validation: Scoped::new(true),
            date_preservation: Scoped::new(false),
            sequence_preservation: Scoped::new(false),
            ignoring_duplicates: Scoped::new(false),
            grant_checking: Scoped::new(false),
            rewriting: Scoped::new(true),
        }
    }
}

/// A hook registered under the class it was registered as (PHP's
/// `removeHook(Class::class)` removes by class; `instanceof` is the type
/// name here).
pub(crate) struct Registered<T: ?Sized> {
    /// The hook.
    pub(crate) hook: Arc<T>,
    /// Its type name (`std::any::type_name`).
    pub(crate) class: &'static str,
}

impl<T: ?Sized> Clone for Registered<T> {
    fn clone(&self) -> Self {
        Registered { hook: self.hook.clone(), class: self.class }
    }
}

/// The mutable properties of PHP's `Database` (and its traits).
pub(crate) struct Settings {
    /// `$cache`.
    pub(crate) cache: Arc<Cache>,
    /// `$cacheName`.
    pub(crate) cache_name: String,
    /// `$codecs`: the filters of this handle, shared with the handles the
    /// same registry is set on.
    pub(crate) codecs: Option<Arc<RwLock<Registry>>>,
    /// `$lifecycleHooks`.
    pub(crate) lifecycle_hooks: Vec<Registered<dyn Lifecycle>>,
    /// `$decorators`.
    pub(crate) decorators: Vec<Registered<dyn Decorator>>,
    /// `$nestedHooks`.
    pub(crate) nested_hooks: Vec<Registered<dyn Nested>>,
    /// `$queryHooks`.
    pub(crate) query_hooks: Vec<Registered<dyn Queries>>,
    /// `$relationshipHook`.
    pub(crate) relationship_hook: Option<Arc<Relationships>>,
    /// `$dropUnknownAttributes`.
    pub(crate) drop_unknown_attributes: bool,
    /// `$maxQueryValues`.
    pub(crate) max_query_values: i64,
    /// `$migrating`.
    pub(crate) migrating: bool,
    /// `$profile`, once built.
    pub(crate) profile: Option<Profile>,
    /// `$globalCollections`, in insertion order, without duplicates.
    pub(crate) global_collections: Vec<String>,
    /// `$documentTypes`: collection => the class of its documents.
    pub(crate) document_types: Vec<(String, String)>,
    /// `$queryCache`.
    pub(crate) query_cache: Option<Arc<QueryCache>>,
    /// `$invalidator`.
    pub(crate) invalidator: Option<Arc<Invalidator>>,
    /// `$profiler`.
    pub(crate) profiler: Option<Arc<Profiler>>,
    /// `$authorization`.
    pub(crate) authorization: Arc<Authorization>,
    /// `$cacheWriterTimeout` (seconds).
    pub(crate) cache_writer_timeout: i64,
    /// `$documentsValidatorCache`, by `documentsValidatorCacheKey()`.
    pub(crate) documents_validators: HashMap<String, Arc<DocumentsValidator>>,
    /// `$sumValidatorCache`, by `getSumValidator()`'s key.
    pub(crate) sum_validators: HashMap<String, Arc<crate::validator::query::aggregate::Aggregate>>,
    /// `$definitionFillsFail` (`Trait\Transactions`).
    pub(crate) definition_fills_fail: bool,
}

impl Database {
    /// A database over `adapter`, caching in `cache`.
    ///
    /// Gives the database and the adapter a fresh [`Authorization`], maps the
    /// metadata collection's documents to [`crate::Collection`], and
    /// registers the default filters on first use.
    ///
    /// PHP: `new Database(Adapter $adapter, Cache $cache)`.
    pub fn new(adapter: Arc<dyn Adapter>, cache: Arc<Cache>) -> Self {
        let authorization = Arc::new(Authorization::new(true));
        adapter.set_authorization(authorization.clone());
        filters::register_default_filters();
        Database {
            inner: Arc::new(Inner {
                adapter,
                settings: Mutex::new(Settings {
                    cache,
                    cache_name: crate::cache::Scope::NAME.to_owned(),
                    codecs: None,
                    lifecycle_hooks: Vec::new(),
                    decorators: Vec::new(),
                    nested_hooks: Vec::new(),
                    query_hooks: Vec::new(),
                    relationship_hook: None,
                    drop_unknown_attributes: false,
                    max_query_values: 5000,
                    migrating: false,
                    profile: None,
                    global_collections: Vec::new(),
                    document_types: vec![(METADATA.to_owned(), crate::collection::CLASS.to_owned())],
                    query_cache: None,
                    invalidator: None,
                    profiler: None,
                    authorization,
                    cache_writer_timeout: crate::cache::Scope::WRITER_TIMEOUT,
                    documents_validators: HashMap::new(),
                    sum_validators: HashMap::new(),
                    definition_fills_fail: false,
                }),
                toggles: Toggles::default(),
                context: Scoped::new(None),
            }),
        }
    }

    /// A database whose own filters (`codecs`) take precedence over the ones
    /// [`Database::add_filter`] registers.
    ///
    /// Fails ([`crate::Kind::Duplicate`]) when a codec is named after a
    /// built-in filter.
    ///
    /// PHP: `new Database($adapter, $cache, $filters)`.
    pub fn with_filters(
        adapter: Arc<dyn Adapter>,
        cache: Arc<Cache>,
        codecs: Vec<Arc<dyn crate::filter::Codec>>,
    ) -> Result<Self> {
        let database = Database::new(adapter, cache);
        if !codecs.is_empty() {
            let mut registry = Registry::new();
            for codec in codecs {
                registry.register(codec)?;
            }
            database.settings().codecs = Some(Arc::new(RwLock::new(registry)));
        }
        Ok(database)
    }

    /// Locks the settings for one read or write. Never hold the guard
    /// across an `.await`.
    pub(crate) fn settings(&self) -> MutexGuard<'_, Settings> {
        self.inner.settings.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Whether `other` is a handle of this same database.
    pub fn same(&self, other: &Database) -> bool {
        Arc::ptr_eq(&self.inner, &other.inner)
    }

    /// The adapter (`getAdapter()`).
    pub fn adapter(&self) -> &Arc<dyn Adapter> {
        &self.inner.adapter
    }

    /// The relationship hook, when one is registered.
    pub(crate) fn relationship_hook(&self) -> Option<Arc<Relationships>> {
        self.settings().relationship_hook.clone()
    }

    // ---- Adapter passthroughs ------------------------------------------

    /// `setDatabase()`.
    pub fn set_database(&self, name: &str) -> &Self {
        self.inner.adapter.set_database(name);
        self
    }

    /// `getDatabase()`.
    pub fn database(&self) -> String {
        self.inner.adapter.database()
    }

    /// `setNamespace()`.
    pub fn set_namespace(&self, namespace: &str) -> &Self {
        self.inner.adapter.set_namespace(namespace);
        self
    }

    /// `getNamespace()`.
    pub fn namespace(&self) -> String {
        self.inner.adapter.namespace()
    }

    /// The type of the internal sequence (`getIdAttributeType()`).
    pub fn id_attribute_type(&self) -> utopia_query::schema::ColumnType {
        self.inner.adapter.limits().id_type
    }

    /// The adapter's limits and capabilities and this database's mode, built
    /// once and rebuilt when shared tables, migration or the schemaless mode
    /// change (`profile()`).
    pub fn profile(&self) -> Profile {
        if let Some(profile) = self.settings().profile.clone() {
            return profile;
        }
        let migrating = self.settings().migrating;
        let profile = Profile::of(self.inner.adapter.clone(), migrating);
        self.settings().profile = Some(profile.clone());
        profile
    }

    /// `resetProfile()`: the profile and the validators built from it are
    /// built again.
    pub(crate) fn reset_profile(&self) {
        let mut settings = self.settings();
        settings.profile = None;
        settings.documents_validators.clear();
    }

    /// Turns the adapter's schemaless mode on or off (`setSchemaless()`).
    ///
    /// Fails ([`crate::Kind::Database`]) to turn it on for an adapter
    /// without a schemaless mode (`Adapter does not support schemaless`).
    pub fn set_schemaless(&self, schemaless: bool) -> Result<&Self> {
        match self.inner.adapter.schemaless() {
            Some(s) => s.set_schemaless(schemaless),
            None if schemaless => return Err(Error::database("Adapter does not support schemaless")),
            None => {}
        }
        self.reset_profile();
        Ok(self)
    }

    /// Uses `filters` on this handle instead of the ones it was built with
    /// (`setFilters()`); handles sharing the registry see what is registered
    /// on it later.
    pub fn set_filters(&self, filters: Arc<RwLock<Registry>>) -> &Self {
        self.settings().codecs = Some(filters);
        self
    }

    /// The filters of this handle (`getFilters()`).
    pub fn filters(&self) -> Arc<RwLock<Registry>> {
        self.settings().codecs.get_or_insert_with(|| Arc::new(RwLock::new(Registry::new()))).clone()
    }

    /// Caches `find()` results in `query_cache`, or stops caching them
    /// (`setQueryCache()`); also makes its [`Invalidator`] this database's.
    pub fn set_query_cache(&self, query_cache: Option<Arc<QueryCache>>) -> &Self {
        let mut settings = self.settings();
        settings.invalidator = query_cache.as_ref().map(|q| Arc::new(Invalidator::new(q.clone())));
        settings.query_cache = query_cache;
        self
    }

    /// `getQueryCache()`.
    pub fn query_cache(&self) -> Option<Arc<QueryCache>> {
        self.settings().query_cache.clone()
    }

    /// Records the queries the adapter runs in [`Database::profiler`], or
    /// stops recording them (`setProfiling()`).
    pub fn set_profiling(&self, profiling: bool) -> &Self {
        if !profiling {
            if let Some(p) = &self.settings().profiler {
                p.disable();
            }
            self.inner.adapter.set_profiler(None);
            return self;
        }
        let profiler = self.settings().profiler.get_or_insert_with(|| Arc::new(Profiler::new())).clone();
        profiler.enable();
        self.inner.adapter.set_profiler(Some(profiler));
        self
    }

    /// `isProfiling()`.
    pub fn is_profiling(&self) -> bool {
        self.settings().profiler.as_ref().is_some_and(|p| p.is_enabled())
    }

    /// `getProfiler()`.
    pub fn profiler(&self) -> Option<Arc<Profiler>> {
        self.settings().profiler.clone()
    }

    /// `setCache()`.
    pub fn set_cache(&self, cache: Arc<Cache>) -> &Self {
        self.settings().cache = cache;
        self
    }

    /// `getCache()`.
    pub fn cache(&self) -> Arc<Cache> {
        self.settings().cache.clone()
    }

    /// `setCacheName()`.
    pub fn set_cache_name(&self, name: &str) -> &Self {
        self.settings().cache_name = name.to_owned();
        self
    }

    /// `getCacheName()`.
    pub fn cache_name(&self) -> String {
        self.settings().cache_name.clone()
    }

    /// Seconds after which an invalidation that has not finished is treated
    /// as abandoned (`setCacheWriterTimeout()`; negative is 0).
    pub fn set_cache_writer_timeout(&self, seconds: i64) -> &Self {
        self.settings().cache_writer_timeout = seconds.max(0);
        self
    }

    /// `$cacheWriterTimeout`.
    pub fn cache_writer_timeout(&self) -> i64 {
        self.settings().cache_writer_timeout
    }

    /// `setSharedTables()`.
    pub fn set_shared_tables(&self, shared_tables: bool) -> &Self {
        self.inner.adapter.set_shared_tables(shared_tables);
        self.reset_profile();
        self
    }

    /// `hasSharedTables()`.
    pub fn shared_tables(&self) -> bool {
        self.inner.adapter.shared_tables()
    }

    /// `setTenant()`.
    pub fn set_tenant(&self, tenant: Value) -> &Self {
        self.inner.adapter.set_tenant(tenant);
        self
    }

    /// `getTenant()`.
    pub fn tenant(&self) -> Value {
        self.inner.adapter.tenant()
    }

    /// Runs `future` with `tenant` (`withTenant()`), scoped to it.
    pub async fn with_tenant<F: std::future::Future>(&self, tenant: Value, future: F) -> F::Output {
        self.inner.adapter.state().tenant().with(tenant, future).await
    }

    /// `setTenantPerDocument()`.
    pub fn set_tenant_per_document(&self, enabled: bool) -> &Self {
        self.inner.adapter.set_tenant_per_document(enabled);
        self
    }

    /// `isTenantPerDocument()`.
    pub fn tenant_per_document(&self) -> bool {
        self.inner.adapter.tenant_per_document()
    }

    /// `setAuthorization()`: on the database and its adapter.
    pub fn set_authorization(&self, authorization: Arc<Authorization>) -> &Self {
        self.inner.adapter.set_authorization(authorization.clone());
        self.settings().authorization = authorization;
        self
    }

    /// `getAuthorization()`.
    pub fn authorization(&self) -> Arc<Authorization> {
        self.settings().authorization.clone()
    }

    /// `setTimeout()`.
    ///
    /// Fails ([`crate::Kind::Database`]) for an adapter without timeouts
    /// (`Adapter does not support timeouts`).
    pub fn set_timeout(&self, milliseconds: i64, event: Event) -> Result<&Self> {
        let Some(timeouts) = self.inner.adapter.timeouts() else {
            return Err(Error::database("Adapter does not support timeouts"));
        };
        timeouts.set_timeout(milliseconds, event)?;
        Ok(self)
    }

    /// `clearTimeout()`.
    ///
    /// Fails as [`Database::set_timeout`] does.
    pub fn clear_timeout(&self, event: Event) -> Result<()> {
        let Some(timeouts) = self.inner.adapter.timeouts() else {
            return Err(Error::database("Adapter does not support timeouts"));
        };
        timeouts.clear_timeout(event);
        Ok(())
    }

    /// `isDroppingUnknownAttributes()`.
    pub fn is_dropping_unknown_attributes(&self) -> bool {
        self.settings().drop_unknown_attributes
    }

    /// Drops attributes missing from the collection schema instead of
    /// rejecting the write (`setDropUnknownAttributes()`).
    pub fn set_drop_unknown_attributes(&self, drop: bool) -> &Self {
        self.settings().drop_unknown_attributes = drop;
        self
    }

    /// `setMigrating()`.
    pub fn set_migrating(&self, migrating: bool) -> &Self {
        self.settings().migrating = migrating;
        self.reset_profile();
        self
    }

    /// `isMigrating()`.
    pub fn is_migrating(&self) -> bool {
        self.settings().migrating
    }

    /// `setMaxQueryValues()`.
    pub fn set_max_query_values(&self, max: i64) -> &Self {
        let mut settings = self.settings();
        if settings.max_query_values != max {
            settings.documents_validators.clear();
        }
        settings.max_query_values = max;
        self
    }

    /// `getMaxQueryValues()`.
    pub fn max_query_values(&self) -> i64 {
        self.settings().max_query_values
    }

    /// `setGlobalCollections()`: adds the collections.
    pub fn set_global_collections<S: AsRef<str>>(&self, collections: &[S]) -> &Self {
        let mut settings = self.settings();
        for c in collections {
            if !settings.global_collections.iter().any(|g| g == c.as_ref()) {
                settings.global_collections.push(c.as_ref().to_owned());
            }
        }
        self
    }

    /// `getGlobalCollections()`.
    pub fn global_collections(&self) -> Vec<String> {
        self.settings().global_collections.clone()
    }

    /// `resetGlobalCollections()`.
    pub fn reset_global_collections(&self) {
        self.settings().global_collections.clear();
    }

    /// Maps the documents of `collection` to the custom class `class`
    /// (`setDocumentType()`): documents read from it carry that class
    /// ([`crate::Document::class`]). PHP checks the class exists and extends
    /// `Document`; Rust has no class to check.
    pub fn set_document_type(&self, collection: &str, class: &str) -> &Self {
        let mut settings = self.settings();
        match settings.document_types.iter_mut().find(|(c, _)| c == collection) {
            Some(entry) => entry.1 = class.to_owned(),
            None => settings.document_types.push((collection.to_owned(), class.to_owned())),
        }
        self
    }

    /// `getDocumentType()`.
    pub fn document_type(&self, collection: &str) -> Option<String> {
        self.settings().document_types.iter().find(|(c, _)| c == collection).map(|(_, t)| t.clone())
    }

    /// `clearDocumentType()`.
    pub fn clear_document_type(&self, collection: &str) {
        self.settings().document_types.retain(|(c, _)| c != collection);
    }

    /// `clearDocumentTypes()`: the metadata collection keeps its own.
    pub fn clear_document_types(&self) {
        self.settings().document_types = vec![(METADATA.to_owned(), crate::collection::CLASS.to_owned())];
    }

    /// Whether `ALTER TABLE` statements take `LOCK=SHARED`, on adapters that
    /// support it (`setLocks()`).
    pub fn set_locks(&self, locks: bool) -> &Self {
        if self.inner.adapter.supports(Capability::AlterLock) {
            self.inner.adapter.set_locks(locks);
        }
        self
    }

    /// `setMetadata()`: a value printed in the query comments.
    pub fn set_metadata(&self, key: &str, value: Value) -> &Self {
        self.inner.adapter.set_metadata(key, value);
        self
    }

    /// `getMetadata()`.
    pub fn metadata(&self) -> Vec<(String, Value)> {
        self.inner.adapter.metadata()
    }

    /// `resetMetadata()`.
    pub fn reset_metadata(&self) {
        self.inner.adapter.reset_metadata();
    }

    /// `removeTransform()`.
    pub fn remove_transform(&self, name: &str) -> &Self {
        self.inner.adapter.remove_transform(name);
        self
    }

    /// The id of the adapter's connection, `None` for an adapter without one
    /// (`getConnectionId()`).
    pub async fn connection_id(&self) -> Result<Option<String>> {
        match self.inner.adapter.connection() {
            Some(c) => c.id().await.map(Some),
            None => Ok(None),
        }
    }

    /// The host the adapter is connected to, `None` for an adapter without
    /// a connection (`getHostname()`).
    pub async fn hostname(&self) -> Result<Option<String>> {
        match self.inner.adapter.connection() {
            Some(c) => c.hostname().await.map(Some),
            None => Ok(None),
        }
    }

    /// Whether the adapter's connection answers; an adapter without one is
    /// always reachable (`ping()`).
    pub async fn ping(&self) -> Result<bool> {
        match self.inner.adapter.connection() {
            Some(c) => c.ping().await,
            None => Ok(true),
        }
    }

    /// Re-establishes the adapter's connection (`reconnect()`).
    pub async fn reconnect(&self) -> Result<()> {
        match self.inner.adapter.connection() {
            Some(c) => c.reconnect().await,
            None => Ok(()),
        }
    }

    /// The attributes a collection may declare besides the internal ones; 0
    /// when there is no limit (`getLimitForAttributes()`).
    pub fn limit_for_attributes(&self) -> i64 {
        let limits = self.inner.adapter.limits();
        if limits.attributes == 0 { 0 } else { limits.attributes - limits.default_attributes }
    }

    /// The indexes a collection may declare besides the internal ones
    /// (`getLimitForIndexes()`).
    pub fn limit_for_indexes(&self) -> i64 {
        let limits = self.inner.adapter.limits();
        limits.indexes - limits.default_indexes
    }

    /// `getMaxUidLength()`.
    pub fn max_uid_length(&self) -> i64 {
        self.inner.adapter.limits().uid_length
    }

    /// The attributes every document carries; `$tenant` only under shared
    /// tables (`internalAttributes()`).
    pub fn internal_attributes(&self) -> &'static [crate::Attribute] {
        internal_attributes(self.inner.adapter.shared_tables())
    }

    /// The columns the engine holds for a collection, empty where the adapter
    /// does not support [`Capability::SchemaIntrospection`]
    /// (`getSchemaAttributes()`).
    pub async fn schema_attributes(&self, collection: &str) -> Result<Vec<crate::schema::Column>> {
        if !self.inner.adapter.supports(Capability::SchemaIntrospection) {
            return Ok(Vec::new());
        }
        self.inner.adapter.schema_attributes(collection).await
    }

    /// The indexes the engine holds for a collection (`getSchemaIndexes()`).
    pub async fn schema_indexes(&self, collection: &str) -> Result<Vec<crate::schema::Index>> {
        if !self.inner.adapter.supports(Capability::SchemaIntrospection) {
            return Ok(Vec::new());
        }
        self.inner.adapter.schema_indexes(collection).await
    }

    /// Whether the last read was served by a read replica, whose result must
    /// not be cached for other readers (`isReadFromReplica()`).
    pub(crate) fn is_read_from_replica(&self) -> bool {
        self.inner.adapter.served_by_replica()
    }
}

/// Runs a closure when dropped: the `finally` of a PHP scope, which also
/// runs when the scoped future is cancelled.
pub(crate) struct Finally<F: FnOnce()>(Option<F>);

impl<F: FnOnce()> Finally<F> {
    /// Runs `f` on drop.
    pub(crate) fn new(f: F) -> Self {
        Self(Some(f))
    }
}

impl<F: FnOnce()> Drop for Finally<F> {
    fn drop(&mut self) {
        if let Some(f) = self.0.take() {
            f();
        }
    }
}

/// A console line (PHP's `Console::warning()`), as a `tracing` warning.
pub(crate) fn warning(message: impl AsRef<str>) {
    tracing::warn!("{}", message.as_ref());
}

/// A console error line (PHP's `Console::error()`).
pub(crate) fn console_error(message: impl AsRef<str>) {
    tracing::error!("{}", message.as_ref());
}

#[cfg(test)]
mod tests {
    use super::*;

    fn assert_send_sync<T: Send + Sync + Clone + 'static>() {}

    #[test]
    fn handle_is_send_sync_and_clone() {
        assert_send_sync::<Database>();
    }
}
