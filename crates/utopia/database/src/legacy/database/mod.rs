//! [`Database`]: collections, attributes, indexes, documents, finds,
//! permissions, relationships, tenancy and caching over an [`Adapter`].
//!
//! A [`Database`] is a cheap [`Clone`] handle: clones share one adapter,
//! one cache and one set of settings, as every reference to a PHP object
//! does. Settings live behind a [`std::sync::Mutex`] that is only ever held
//! for the duration of a read or a write, never across an `.await` (clippy's
//! `await_holding_lock` guards it).
//!
//! Scoped settings (PHP's `skipRelationships($callback)`, `silent(...)`,
//! `withTenant(...)`, ...) take an async closure, run it, and restore the
//! previous value when the closure's future completes **or is dropped**, as
//! PHP's `finally` does.
//!
//! # Areas and file ownership
//!
//! The PHP class (11k lines) is split by area; each file is one
//! `impl Database` block:
//!
//! | Area | Files | PHP methods |
//! |---|---|---|
//! | schema | `config.rs` | state getters/setters, `on`/`before`/`silent`/`trigger`, `skip*`/`with*` scopes, tenancy, document types, timeouts, metadata, `ping`/`reconnect` |
//! | schema | `filters.rs` | `addFilter` and the registry, `encode`/`decode`/`casting`, `encodeAttribute`/`decodeAttribute`, `removeUnknownAttributes`, `encodeSpatialData`, filter signatures |
//! | schema | `cache.rs` | cache keys, `purgeCached*`, `withCache`, `withDocumentTenant`, `tenantKey` |
//! | schema | `collections.rs` | `create`/`exists`/`list`/`delete`, collections |
//! | schema | `attributes.rs` | attributes, `updateAttributeMeta`/`updateIndexMeta` |
//! | schema | `indexes.rs` | indexes |
//! | schema | `metadata.rs` | `withRetries`, `cleanup*`, `updateMetadata`, `rollbackAttributeMetadata` |
//! | schema | `transaction.rs` | `withTransaction` |
//! | documents | `documents.rs` | `getDocument`, create/update/upsert/increase/decrease/delete documents, `refetchDocuments` |
//! | documents | `find.rs` | `find`, `findOne`, `count`, `sum`, `foreach`, `iterate`, query conversion and validation, limits, schema introspection |
//! | relationships | `relationships.rs` | relationship attributes, population, writes, deletes and relationship queries |
//!
//! Methods that one area calls in another (`encode`, `decode`, `casting`,
//! `populate_documents_relationships`, `create_document_relationships`,
//! `update_document_relationships`, `delete_document_relationships`,
//! `convert_relationship_queries`, `process_relationship_queries`,
//! `update_metadata`, `with_retries`, `purge_cached_*`, ...) keep the
//! signatures they have here; `pub(crate)` helpers only one area calls may
//! be reshaped by that area's owner.
//!
//! # Callbacks
//!
//! - Scopes: `FnOnce() -> Fut`, returning whatever the future returns.
//! - [`Database::with_transaction`]: `FnMut() -> Fut` (PHP retries the
//!   callback).
//! - Bulk methods' `on_next` / `on_error`, and [`Database::foreach`]: a
//!   `&mut dyn` [`Callback`], implemented by every
//!   `FnMut(A) -> Result<()>` closure; implement the trait on a type for an
//!   async callback.
//! - Event listeners ([`Database::on`]): a synchronous [`Listener`]; an
//!   error it returns propagates like a PHP listener's exception.
//! - Attribute filters ([`Database::add_filter`]): a [`Filter`] (async
//!   `encode` / `decode` receiving the document and the database).
//! - [`Database::iterate`] returns a `'static` [`BoxStream`] of documents
//!   (PHP's `Generator`): it pages lazily with `find`, as PHP does.
//!
//! # Status
//!
//! Implemented: the settings, scopes, listeners and `trigger` of
//! `config.rs`; `filters.rs` (registry, default filters with PHP's
//! signatures, `encode` / `decode` / `casting`, `encodeAttribute` /
//! `decodeAttribute`, `encodeSpatialData`, `decodeObject`, filter
//! signatures); `cache.rs` (keys, query cache key and field, purges,
//! `with_cache`); `transaction.rs`; and in `collections.rs` the databases
//! (`create`, `exists`, `list`, `delete`) plus `create_collection` and
//! `get_collection`, which `create()` needs. Also small helpers other areas
//! own, because the foundation calls them: `is_ttl_expired`
//! (`documents.rs`), `check_query_types` (`find.rs`),
//! `Database::metadata_collection` (`consts.rs`, PHP's `COLLECTION`) and
//! `index_support` (`collections.rs`, the adapter's `IndexSupport` for the
//! `Index` validator). Their owners may reshape them.
//!
//! The documents area (`documents.rs`, `find.rs`) is implemented: reads
//! (with the document cache), creates, updates, upserts, increments,
//! deletes, finds, counts, sums and iteration. It calls the relationships
//! area only for collections with relationship attributes (PHP's
//! relationship methods do nothing for the others).
//! [`Database::get_document`], [`Database::create_document`],
//! [`Database::update_document`], [`Database::delete_document`] and
//! [`Database::find`] return a [`futures_util::future::BoxFuture`]: they
//! recurse (a collection is read as a document; relationships read and
//! write related documents), and a named future type breaks the cycle of
//! async types.
//!
//! `get_collection(METADATA)` returns `Database::metadata_collection`
//! itself rather than calling `get_document`: PHP's `getDocument` returns
//! `new Document(COLLECTION)` for that id before anything else, so the
//! behaviour is the same and `create()` works before `get_document` does.
//!
//! Schema area: `collections.rs`, `attributes.rs`, `indexes.rs` and
//! `metadata.rs` are complete.
//!
//! Relationships area (`relationships.rs`) is complete: relationship
//! attributes (with their junction collections and rollbacks), population,
//! writes, on-delete rules and relationship queries. Population keeps the
//! documents it reads in an arena and resolves which document holds which
//! once, deepest first, as PHP's shared objects would show them.
//! `process_relationship_queries` rewrites the `select` queries
//! it is given, as PHP rewrites the query objects (`getDocument` passes the
//! rewritten queries to the adapter; `find` works on `groupByType`'s
//! clones, so its callers' queries are untouched).
//!
//! Cache keys are shared with PHP, so the default filters' signatures are
//! PHP's (`file:line` of their closures in the PHP runtime's image:
//! `/usr/src/code/vendor/utopia-php/database/src/Database/Database.php`).
//! When the PHP library moves those closures, update `default_signature`'s
//! line numbers: the test `default_signatures_match_the_php_source` reads
//! them from `vendor/utopia-php/database/src/Database/Database.php` (or the
//! file `UTOPIA_DATABASE_PHP` names) and fails on a moved closure, and the
//! recorded `get_cache_keys` hashes check them too.
//!
//! # Working on an area
//!
//! Each area owns its files here and its glue in
//! `crates/tools/compat/src/libs/database/core/<area>.rs` (`schema.rs`,
//! `documents.rs`, `relationships.rs`); `core/mod.rs` routes each of the
//! 151 operations of `tests/compat/database/spec.d/core.json` to its area by
//! the area's `OPS` list. Glue an operation in the area's `call(op, json,
//! session) -> R<Json>` with the shared `core/args.rs`:
//!
//! - Results: `R<T> = Result<T, Fail>`; `?` on a `utopia_database::Result`
//!   makes the error the operation's `{"$error": ...}`, `?` on a `Fault`
//!   stops the case, `fault(msg)` builds one. Encode with `encode(&Value)`,
//!   `document(&Document)`, `documents(&[Document])`, `strings(&[S])`,
//!   `datetime(&DateTime)`, `error(&Error)` (an exception passed as a
//!   value), and the markers `database_marker()` (fluent setters),
//!   `adapter_marker()`, `cache_marker()`, `authorization_marker()`.
//! - The database: `args::db(json, session)?` (the `db` handle argument, or
//!   the session's current database); `args::db_handle` also gives its
//!   PostgreSQL adapter.
//! - Arguments: `let a = Args::new(json);` then, by PHP parameter name
//!   (camelCase, as recorded): `string`, `string_or`, `opt_string`, `int`,
//!   `int_or`, `opt_int`, `bool`, `bool_or`, `opt_bool`, `value` (any
//!   value, `null` when absent), `opt_value`, `array`, `strings`,
//!   `opt_strings`, `document`, `opt_document`, `documents`
//!   (`array<Document>`), `queries`, `query`, `datetime`, `cache`,
//!   `authorization`, `database`; `has` (present, even `null`), `given`
//!   (present and not `null`), `raw`. Argument tags (`$now`, `$repeat`,
//!   `$datetime`) are resolved by every getter.
//! - Callbacks: decode the other arguments first, then `let calls =
//!   Calls::new(session);`, `let cb = calls.callback("onNext",
//!   a.raw("onNext"))?;` (`None` for an absent / `null` one), call the
//!   library with `|| cb.invoke(vec![...encoded args])` for closures, or
//!   `Some(&mut cb.clone())` where it takes a `&mut dyn Callback<A>` (`A`:
//!   `Document`, `(Document, Document)`, `(Document, Option<Document>)` or
//!   `Error`, see `CallArgs`), then `calls.finish(result)`, which wraps the
//!   result as `{"result", "calls"}` when there were callbacks. `invoke`
//!   returns the callback's return value (`$return` or a `return: true`
//!   step), and a step's uncaught error as the callback's error.
//! - Fixtures and their log (`db.log`) are already glued: filters
//!   (`database.add_filter`), formats (`db.add_format`), listeners
//!   (`database.on`) and transformations (`database.before`).
//!
//! Run one trait's cases with `bin/compat run database --case <text>`,
//! where `<text>` matches a case file or case name (`--case
//! Scope-DocumentTests`, `--case "DocumentTests::testCreateDocument"`,
//! `--case core-Database`); add `COMPAT_TIMEOUT=300` under load and `-v`
//! for details. The PostgreSQL the cases use is started by
//! `tests/compat/database/services.sh up`.
//!
//! PHP: `Utopia\Database\Database`.

mod attributes;
mod cache;
mod collections;
mod config;
mod consts;
mod documents;
mod filters;
mod find;
mod indexes;
mod metadata;
mod plan;
mod relationships;
mod transaction;

use std::collections::HashSet;
use std::sync::{Arc, Mutex, MutexGuard, OnceLock, Weak};

use async_trait::async_trait;
use php_std::datetime::DateTime;
use utopia_cache::Cache;

pub use futures_util::future::BoxFuture;
pub use futures_util::stream::BoxStream;

pub use collections::CollectionCache;
pub use filters::Filter;

use crate::adapter::Adapter;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;
use crate::validator::authorization::Authorization;
use crate::value::Value;

/// An event listener registered with [`Database::on`]: receives the event
/// name and its payload (a document, a list of documents, a name, ...).
///
/// PHP: the `callable` of `Database::on($event, $name, $callback)`.
pub type Listener = Arc<dyn Fn(&str, &Value) -> Result<()> + Send + Sync>;

/// Per-relationship nested selections: relationship key => the queries to
/// apply on the related collection, in PHP's key order.
///
/// PHP: the `array<string, array<Query>> $selects` of
/// `processRelationshipQueries()` and `populateDocumentsRelationships()`.
pub type Selects = Vec<(String, Vec<Query>)>;

/// A callback the bulk methods ([`Database::create_documents`],
/// [`Database::update_documents`], [`Database::upsert_documents`],
/// [`Database::delete_documents`]) call for each document written
/// (`on_next`) or failure (`on_error`), and [`Database::foreach`] calls for
/// each document found.
///
/// Every `FnMut(A) -> Result<()>` closure is one; implement the trait on a
/// type for a callback that awaits. An error returned from `on_next` goes to
/// `on_error` when there is one and is raised otherwise; an error returned
/// from `on_error` is raised.
///
/// PHP: the `?callable $onNext` / `?callable $onError` parameters and the
/// `callable $callback` of `foreach()`.
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

/// Where a database's own calls to methods a PHP subclass overrides go: the
/// database a [`crate::mirror::Mirror`] is built on hands its events to the
/// mirror's source and its relationship writes to the mirror, as PHP's
/// `$this->...` reaches `Mirror`'s overrides.
pub(crate) trait Overrides: Send + Sync {
    /// The database whose listeners and silencing apply (`Mirror::on`,
    /// `silent` and `trigger` go to the source).
    fn events(&self) -> Database;
    /// `$this->createDocument(...)`.
    fn create_document<'a>(&'a self, collection: &'a str, document: Document) -> BoxFuture<'a, Result<Document>>;
    /// `$this->updateDocument(...)`.
    fn update_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>>;
    /// `$this->deleteDocument(...)`.
    fn delete_document<'a>(&'a self, collection: &'a str, id: &'a str) -> BoxFuture<'a, Result<bool>>;
}

/// The database. See the [module docs](self).
///
/// PHP: `Utopia\Database\Database`.
#[derive(Clone)]
pub struct Database {
    inner: Arc<Inner>,
}

/// What every clone of a [`Database`] shares.
struct Inner {
    /// `$adapter`.
    adapter: Arc<dyn Adapter>,
    /// `$instanceFilters`, in the order given to the constructor (set once).
    instance_filters: Vec<(String, Arc<dyn Filter>)>,
    /// Everything PHP's methods change after construction.
    state: Mutex<State>,
    /// Where its own calls to overridden methods go (set once, by a
    /// [`crate::mirror::Mirror`] on the database it is built on).
    overrides: OnceLock<Weak<dyn Overrides>>,
}

/// The mutable properties of PHP's `Database`.
pub(crate) struct State {
    /// `$cache`.
    pub(crate) cache: Arc<Cache>,
    /// `$cacheName`.
    pub(crate) cache_name: String,
    /// `$filterSignatures`: filter name => signature, sorted by name
    /// (`ksort`).
    pub(crate) filter_signatures: Vec<(String, String)>,
    /// `$filterSignaturesEncoded`: `json_encode($filterSignatures)`.
    pub(crate) filter_signatures_encoded: String,
    /// The JSON of the signatures left active by `disabled_filters`, for
    /// the registry version and disabled set it was computed for (not in
    /// PHP, which encodes it on every call).
    pub(crate) disabled_signatures_encoded: Option<(i64, HashSet<String>, String)>,
    /// `$filterSignaturesVersion`: the global registry version the
    /// signatures were computed at (`-1`: never). PHP also keeps
    /// `$filterSignaturesSource` to notice changed instance filters; they
    /// cannot change here after construction.
    pub(crate) filter_signatures_version: i64,
    /// `$listeners`: event => name => listener, in insertion order (`*`
    /// first).
    pub(crate) listeners: Vec<(String, Vec<(String, Listener)>)>,
    /// `$silentListeners`: the listener names silenced; `None` silences all.
    pub(crate) silent_listeners: Option<HashSet<String>>,
    /// `$timestamp`: the request timestamp `withRequestTimestamp()` sets.
    pub(crate) timestamp: Option<DateTime>,
    /// `$resolveRelationships`.
    pub(crate) resolve_relationships: bool,
    /// `$checkRelationshipsExist`.
    pub(crate) check_relationships_exist: bool,
    /// `$relationshipFetchDepth`.
    pub(crate) relationship_fetch_depth: i64,
    /// `$inBatchRelationshipPopulation`.
    pub(crate) in_batch_relationship_population: bool,
    /// `$filter`.
    pub(crate) filter: bool,
    /// `$disabledFilters` (`null` is `None`).
    pub(crate) disabled_filters: Option<HashSet<String>>,
    /// `$validate`.
    pub(crate) validate: bool,
    /// `$dropUnknownAttributes`.
    pub(crate) drop_unknown_attributes: bool,
    /// `$preserveDates`.
    pub(crate) preserve_dates: bool,
    /// `$skipDuplicates`.
    pub(crate) skip_duplicates: bool,
    /// `$preserveSequence`.
    pub(crate) preserve_sequence: bool,
    /// `$maxQueryValues`.
    pub(crate) max_query_values: i64,
    /// `$migrating`.
    pub(crate) migrating: bool,
    /// `$globalCollections`: the keys, in insertion order, without
    /// duplicates.
    pub(crate) global_collections: Vec<String>,
    /// `$relationshipWriteStack`: collection ids.
    pub(crate) relationship_write_stack: Vec<String>,
    // PHP's `$relationshipFetchStack` is never read nor written: not kept.
    /// `$relationshipDeleteStack`.
    pub(crate) relationship_delete_stack: Vec<Document>,
    /// `$documentTypes`: collection => document class name.
    pub(crate) document_types: Vec<(String, String)>,
    /// `$authorization`.
    pub(crate) authorization: Arc<Authorization>,
    /// Where the bulk writes leave the documents they were given, as PHP's
    /// caller sees its objects after the call (`Some` to capture them: a
    /// [`crate::mirror::Mirror`] copies them to its destination).
    pub(crate) capture: Option<Vec<Document>>,
    /// The collections this handle read, by their document cache hash key, when
    /// [`Database::set_collection_memo`] turned it on (`None`: off, as in
    /// PHP). Not in PHP.
    pub(crate) collection_memo: Option<std::collections::HashMap<String, Document>>,
    /// The process's collections ([`Database::set_collection_cache`]). Not
    /// in PHP.
    pub(crate) collection_cache: Option<Arc<CollectionCache>>,
}

impl Database {
    /// A database over `adapter`, caching in `cache`, with `filters` (name
    /// => filter) taking precedence over the global ones of
    /// [`Database::add_filter`]. Registers the default filters (`json`,
    /// `datetime`, `point`, `linestring`, `polygon`, `vector`, `object`) on
    /// first use, and gives the database and the adapter a fresh
    /// [`Authorization`].
    ///
    /// PHP: `Database::__construct(Adapter $adapter, Cache $cache, array $filters = [])`.
    pub fn new(adapter: Arc<dyn Adapter>, cache: Arc<Cache>, filters: Vec<(String, Arc<dyn Filter>)>) -> Self {
        let authorization = Arc::new(Authorization::new());
        adapter.set_authorization(authorization.clone());
        let database = Self {
            inner: Arc::new(Inner {
                adapter,
                instance_filters: filters,
                overrides: OnceLock::new(),
                state: Mutex::new(State {
                    cache,
                    cache_name: "default".to_owned(),
                    filter_signatures: Vec::new(),
                    filter_signatures_encoded: String::new(),
                    filter_signatures_version: -1,
                    disabled_signatures_encoded: None,
                    listeners: vec![(Self::EVENT_ALL.to_owned(), Vec::new())],
                    silent_listeners: Some(HashSet::new()),
                    timestamp: None,
                    resolve_relationships: true,
                    check_relationships_exist: true,
                    relationship_fetch_depth: 0,
                    in_batch_relationship_population: false,
                    filter: true,
                    disabled_filters: Some(HashSet::new()),
                    validate: true,
                    drop_unknown_attributes: false,
                    preserve_dates: false,
                    skip_duplicates: false,
                    preserve_sequence: false,
                    max_query_values: 5000,
                    migrating: false,
                    global_collections: Vec::new(),
                    relationship_write_stack: Vec::new(),
                    relationship_delete_stack: Vec::new(),
                    document_types: Vec::new(),
                    authorization,
                    capture: None,
                    collection_memo: None,
                    collection_cache: None,
                }),
            }),
        };
        Self::register_default_filters();
        database
    }

    /// Locks the settings for one read or write. Never hold the guard across
    /// an `.await`.
    pub(crate) fn state(&self) -> MutexGuard<'_, State> {
        self.inner.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// The adapter.
    ///
    /// PHP: `Database::getAdapter()`.
    pub fn adapter(&self) -> &Arc<dyn Adapter> {
        &self.inner.adapter
    }

    /// Where this database's own calls to overridden methods go, if
    /// anywhere.
    pub(crate) fn overrides(&self) -> Option<Arc<dyn Overrides>> {
        self.inner.overrides.get()?.upgrade()
    }

    /// Hands this database's own calls to overridden methods to `overrides`
    /// from now on (once).
    pub(crate) fn set_overrides(&self, overrides: Weak<dyn Overrides>) {
        let _ = self.inner.overrides.set(overrides);
    }

    /// `$this->createDocument(...)` from within: the override's when there
    /// is one.
    pub(crate) fn dispatch_create_document<'a>(
        &'a self,
        collection: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        match self.overrides() {
            Some(o) => Box::pin(async move { o.create_document(collection, document).await }),
            None => self.create_document(collection, document),
        }
    }

    /// `$this->updateDocument(...)` from within.
    pub(crate) fn dispatch_update_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        match self.overrides() {
            Some(o) => Box::pin(async move { o.update_document(collection, id, document).await }),
            None => self.update_document(collection, id, document),
        }
    }

    /// `$this->deleteDocument(...)` from within.
    pub(crate) fn dispatch_delete_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
    ) -> BoxFuture<'a, Result<bool>> {
        match self.overrides() {
            Some(o) => Box::pin(async move { o.delete_document(collection, id).await }),
            None => self.delete_document(collection, id),
        }
    }
}

/// Runs a closure when dropped: the `finally` of PHP's scoped setters, which
/// also runs when the scoped future is cancelled.
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

#[cfg(test)]
mod tests {
    use super::*;

    fn assert_send_sync<T: Send + Sync + Clone + 'static>() {}

    #[test]
    fn handle_is_send_sync_and_clone() {
        assert_send_sync::<Database>();
    }

    /// The futures of the main entry points must stay `Send` (tokio spawns
    /// them): this fails to compile when an implementation holds a
    /// non-`Send` value (such as the settings guard) across an await.
    #[allow(dead_code, unreachable_code, clippy::diverging_sub_expression)]
    fn futures_are_send(db: &Database) {
        fn send<T: Send>(_: T) {}
        send(db.get_document("c", "d", Vec::new(), false));
        send(db.create_document("c", Document::empty()));
        send(db.update_document("c", "d", Document::empty()));
        send(db.delete_document("c", "d"));
        send(db.find("c", Vec::new(), Database::PERMISSION_READ));
        send(db.count("c", Vec::new(), None));
        send(db.create_collection("c", Vec::new(), Vec::new(), None, true));
        send(db.create_attribute(
            "c",
            "a",
            Database::VAR_STRING,
            10,
            false,
            Value::Null,
            true,
            false,
            None,
            Default::default(),
            Vec::new(),
        ));
        send(db.create_relationship("c", "r", Database::RELATION_ONE_TO_ONE, false, None, None, "restrict"));
        send(db.iterate("c", Vec::new(), Database::PERMISSION_READ));
    }
}
