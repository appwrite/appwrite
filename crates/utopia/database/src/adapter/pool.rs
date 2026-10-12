//! [`Pool`]: an adapter over a pool of adapters.
//!
//! Every call takes a connection (an adapter) from a [`utopia_pools::Pool`],
//! puts it in this handle's settings (database, namespace, shared tables,
//! tenant, authorization, timeouts, debug, metadata), runs the call on it and
//! hands it back: reclaimed when the call succeeded, recovered (reconnected)
//! or discarded when it failed. A transaction pins one connection for its
//! whole body, retries included, so its statements share a session.
//!
//! Timeouts are this handle's own: setting one opens no connection, and each
//! connection is reset to them as it is checked out.
//!
//! PHP checks a connection out for every call, getters included. Async calls
//! do the same here; the synchronous getters (capabilities, limits, value
//! conversion) take an idle connection when there is one and otherwise read
//! the last connection this handle used, unsynced: they never wait for one.
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use std::sync::Arc;
//!
//! use utopia_database::Adapter;
//! use utopia_database::adapter::pool::{Pool, Pooled};
//! use utopia_database::adapter::postgres::Postgres;
//! use utopia_pools::Stack;
//!
//! let connections = utopia_pools::Pool::new(
//!     Stack::new(),
//!     "postgres",
//!     10,
//!     || async { Ok::<_, utopia_database::Error>(Pooled::new(Postgres::connect("postgres://root:password@localhost:5432/root").await?)) },
//!     0.0,
//! )
//! .map_err(|e| utopia_database::Error::new(utopia_database::Kind::Exception, e.to_string()))?;
//! let adapter = Pool::new(connections);
//! adapter.set_database("appwrite")?;
//! adapter.create("appwrite").await?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Pool`.

use std::future::Future;
use std::sync::{Arc, Mutex, MutexGuard};

use async_trait::async_trait;
use futures_util::FutureExt;
use php_std::datetime::DateTime;
use utopia_pools::{Connection, Resource, Stack};

use super::{Adapter, EVENT_ALL, FindOptions, State, Transform};
use crate::change::Change;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::Query;
use crate::validator::authorization::Authorization;
use crate::value::{Array, KeyRef, Value};

/// A pooled adapter: the resource of the [`utopia_pools::Pool`] a [`Pool`]
/// draws from.
#[derive(Clone)]
pub struct Pooled(pub Arc<dyn Adapter>);

impl Pooled {
    /// Wraps an adapter.
    pub fn new(adapter: impl Adapter + 'static) -> Self {
        Pooled(Arc::new(adapter))
    }
}

impl Resource for Pooled {
    /// After failed work: reconnects. PHP's pool probes the resource for
    /// `reconnect()`, which every adapter has.
    async fn recover(&self) -> bool {
        self.0.reconnect().await.is_ok()
    }
}

/// An adapter over a pool of adapters. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Pool`.
pub struct Pool<S = Stack<Pooled>> {
    state: State,
    pool: utopia_pools::Pool<Pooled, S>,
    /// The connection a transaction pinned (`$pinnedAdapter`).
    pinned: Mutex<Option<Connection<Pooled>>>,
    /// The timeout of each event, in the order they were set (`$timeouts`).
    timeouts: Mutex<Vec<(String, i64)>>,
    /// The last connection this handle used: synchronous getters read it
    /// when no connection is idle.
    last: Mutex<Option<Arc<dyn Adapter>>>,
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// A pool failure as a database error: PHP lets the pool's exception
/// through (`Exception`, or `InvalidArgumentException`).
fn pool_error(error: utopia_pools::Error) -> Error {
    match error {
        utopia_pools::Error::Init(cause) => match cause.downcast::<Error>() {
            Ok(error) => *error,
            Err(cause) => Error::new(Kind::Exception, cause.to_string()),
        },
        other => Error::new(Kind::Exception, other.to_string()),
    }
}

/// Runs `$body` (an expression using `$a: &dyn Adapter`, awaited) on a
/// connection: the pinned one, or one checked out, prepared and handed back
/// (PHP's `delegate()`).
macro_rules! delegate {
    ($self:ident, |$a:ident| $body:expr) => {{
        let pinned = $self.pinned_adapter();
        match pinned {
            Some(adapter) => {
                let $a: &dyn Adapter = &*adapter;
                $self.skipping($a, async { $body.await }).await
            }
            None => {
                let connection = $self.pool.pop().await.map_err(pool_error)?;
                let adapter = connection.resource().0.clone();
                *lock(&$self.last) = Some(adapter.clone());
                let $a: &dyn Adapter = &*adapter;
                $self.prepare($a);
                let result = $self.skipping($a, async { $body.await }).await;
                $self.pool.release(&connection, result.is_err()).await;
                result
            }
        }
    }};
}

/// Runs `$body` (an expression using `$a: &dyn Adapter`) on a connection
/// without waiting: the pinned one, an idle one (prepared and reclaimed),
/// or the last one used; `$default` when there has been none.
macro_rules! read {
    ($self:ident, |$a:ident| $body:expr, $default:expr) => {{
        match $self.connection_now() {
            Some((adapter, connection)) => {
                let $a: &dyn Adapter = &*adapter;
                let previous = $a.skip_duplicates();
                if $self.skip_duplicates() {
                    $a.set_skip_duplicates(true);
                }
                let result = $body;
                $a.set_skip_duplicates(previous);
                if let Some(connection) = connection {
                    $self.pool.reclaim(&connection);
                }
                result
            }
            None => $default,
        }
    }};
}

impl<S: utopia_pools::Adapter<Pooled>> Pool<S> {
    /// A pooled adapter drawing its connections from `pool`. Building it opens
    /// no connection.
    ///
    /// PHP: `new Pool($pool)`.
    pub fn new(pool: utopia_pools::Pool<Pooled, S>) -> Self {
        Pool {
            state: State::default(),
            pool,
            pinned: Mutex::new(None),
            timeouts: Mutex::new(Vec::new()),
            last: Mutex::new(None),
        }
    }

    /// The pool the connections come from.
    pub fn pool(&self) -> &utopia_pools::Pool<Pooled, S> {
        &self.pool
    }

    /// The adapter of the connection a transaction pinned, if any.
    ///
    /// PHP: `pin()`.
    pub fn pinned_adapter(&self) -> Option<Arc<dyn Adapter>> {
        lock(&self.pinned).as_ref().map(|c| c.resource().0.clone())
    }

    /// Puts a connection in this handle's settings as it is checked out:
    /// database, namespace, shared tables, tenant, authorization, timeouts,
    /// debug and metadata. (PHP leaves the tenant-per-document flag alone.)
    fn prepare(&self, adapter: &dyn Adapter) {
        let s = self.state.lock().clone();
        let _ = adapter.set_database(&s.database);
        let _ = adapter.set_namespace(&s.namespace);
        adapter.set_shared_tables(s.shared_tables);
        adapter.set_tenant(s.tenant.clone());
        adapter.set_authorization(s.authorization.clone());
        self.sync_timeouts(adapter);
        adapter.reset_debug();
        for (key, value) in s.debug.iter() {
            let key = match key {
                KeyRef::Int(i) => i.to_string(),
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            };
            adapter.set_debug(&key, value.clone());
        }
        adapter.reset_metadata();
        for (key, value) in &s.metadata {
            adapter.set_metadata(key, value);
        }
    }

    /// Resets a connection to this handle's timeouts: every per-event one,
    /// then the global one last, so the connection ends on the timeout this
    /// handle reports.
    ///
    /// PHP: `syncTimeouts()`.
    fn sync_timeouts(&self, adapter: &dyn Adapter) {
        adapter.clear_timeouts();
        let timeouts = lock(&self.timeouts).clone();
        for (event, milliseconds) in &timeouts {
            if event != EVENT_ALL {
                let _ = adapter.set_timeout(*milliseconds, event);
            }
        }
        if let Some((_, milliseconds)) = timeouts.iter().find(|(e, _)| e == EVENT_ALL) {
            let _ = adapter.set_timeout(*milliseconds, EVENT_ALL);
        }
    }

    /// A timeout changed inside a transaction reaches its pinned connection.
    ///
    /// PHP: `syncPin()`.
    fn sync_pin(&self) {
        if let Some(adapter) = self.pinned_adapter() {
            self.sync_timeouts(&*adapter);
        }
    }

    /// The global timeout this handle holds (`$timeouts[EVENT_ALL] ?? 0`).
    fn global_timeout(&self) -> i64 {
        lock(&self.timeouts).iter().find(|(e, _)| e == EVENT_ALL).map(|(_, m)| *m).unwrap_or(0)
    }

    /// Runs `work` with the connection's duplicate skipping on when this
    /// handle's is (PHP's `$adapter->skipDuplicates(fn () => ...)`).
    async fn skipping<T>(&self, adapter: &dyn Adapter, work: impl Future<Output = Result<T>>) -> Result<T> {
        if !self.skip_duplicates() {
            return work.await;
        }
        let previous = adapter.set_skip_duplicates(true);
        let result = work.await;
        adapter.set_skip_duplicates(previous);
        result
    }

    /// A connection to run a synchronous call on without waiting: the pinned
    /// one, an idle one checked out and prepared (with the connection to
    /// reclaim), or the last one used.
    fn connection_now(&self) -> Option<Lent> {
        if let Some(adapter) = self.pinned_adapter() {
            return Some((adapter, None));
        }
        if self.pool.count() > 0
            && let Some(Ok(connection)) = self.pool.pop().now_or_never()
        {
            let adapter = connection.resource().0.clone();
            *lock(&self.last) = Some(adapter.clone());
            self.prepare(&*adapter);
            return Some((adapter, Some(connection)));
        }
        lock(&self.last).clone().map(|a| (a, None))
    }
}

#[async_trait]
impl<S: utopia_pools::Adapter<Pooled>> Adapter for Pool<S> {
    fn state(&self) -> &State {
        &self.state
    }

    // ----- Settings -----

    /// Holds the timeout for `event` and applies it to every connection as
    /// it is checked out (and to the pinned one at once); zero or less
    /// clears it. Opens no connection.
    fn set_timeout(&self, milliseconds: i64, event: &str) -> Result<()> {
        if milliseconds <= 0 {
            self.clear_timeout(event);
            return Ok(());
        }
        {
            let mut timeouts = lock(&self.timeouts);
            match timeouts.iter_mut().find(|(e, _)| e == event) {
                Some(entry) => entry.1 = milliseconds,
                None => timeouts.push((event.to_owned(), milliseconds)),
            }
        }
        self.state.lock().timeout = self.global_timeout();
        self.sync_pin();
        Ok(())
    }

    /// Drops the timeout of one event, leaving the others.
    fn clear_timeout(&self, event: &str) {
        lock(&self.timeouts).retain(|(e, _)| e != event);
        self.state.lock().timeout = self.global_timeout();
        self.sync_pin();
    }

    /// Drops every timeout.
    fn clear_timeouts(&self) {
        lock(&self.timeouts).clear();
        self.state.lock().timeout = 0;
        self.sync_pin();
    }

    fn hostname(&self) -> String {
        read!(self, |a| a.hostname(), String::new())
    }

    fn before(&self, event: &str, name: &str, transform: Option<Transform>) {
        read!(self, |a| a.before(event, name, transform), ())
    }

    fn trigger(&self, event: &str, query: String) -> String {
        match self.connection_now() {
            Some((adapter, connection)) => {
                let out = adapter.trigger(event, query);
                if let Some(c) = connection {
                    self.pool.reclaim(&c);
                }
                out
            }
            None => query,
        }
    }

    fn set_authorization(&self, authorization: Arc<Authorization>) {
        self.state.lock().authorization = authorization;
    }

    fn column_type(&self, kind: &str, size: i64, signed: bool, array: bool, required: bool) -> Result<String> {
        read!(self, |a| a.column_type(kind, size, signed, array, required), Ok(String::new()))
    }

    // ----- Transactions -----

    async fn pin_connection(&self) -> Result<bool> {
        if lock(&self.pinned).is_some() {
            return Ok(false);
        }
        let connection = self.pool.pop().await.map_err(pool_error)?;
        let adapter = connection.resource().0.clone();
        *lock(&self.last) = Some(adapter.clone());
        self.prepare(&*adapter);
        *lock(&self.pinned) = Some(connection);
        Ok(true)
    }

    async fn unpin_connection(&self, failed: bool) {
        let connection = lock(&self.pinned).take();
        if let Some(connection) = connection {
            self.pool.release(&connection, failed).await;
        }
    }

    async fn start_transaction(&self) -> Result<bool> {
        delegate!(self, |a| a.start_transaction())
    }

    async fn commit_transaction(&self) -> Result<bool> {
        delegate!(self, |a| a.commit_transaction())
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        delegate!(self, |a| a.rollback_transaction())
    }

    // ----- Connection -----

    async fn ping(&self) -> Result<bool> {
        delegate!(self, |a| a.ping())
    }

    async fn reconnect(&self) -> Result<()> {
        delegate!(self, |a| a.reconnect())
    }

    async fn connection_id(&self) -> Result<String> {
        delegate!(self, |a| a.connection_id())
    }

    // ----- Databases -----

    async fn create(&self, name: &str) -> Result<bool> {
        delegate!(self, |a| a.create(name))
    }

    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        delegate!(self, |a| a.exists(database, collection))
    }

    async fn list(&self) -> Result<Vec<Document>> {
        delegate!(self, |a| a.list())
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        delegate!(self, |a| a.delete(name))
    }

    // ----- Collections -----

    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool> {
        delegate!(self, |a| a.create_collection(name, attributes, indexes))
    }

    async fn delete_collection(&self, id: &str) -> Result<bool> {
        delegate!(self, |a| a.delete_collection(id))
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        delegate!(self, |a| a.analyze_collection(collection))
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        delegate!(self, |a| a.size_of_collection(collection))
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        delegate!(self, |a| a.size_of_collection_on_disk(collection))
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<Document>> {
        delegate!(self, |a| a.schema_attributes(collection))
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<Document>> {
        delegate!(self, |a| a.schema_indexes(collection))
    }

    // ----- Attributes -----

    async fn create_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        delegate!(self, |a| a.create_attribute(collection, id, kind, size, signed, array, required))
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Array]) -> Result<bool> {
        delegate!(self, |a| a.create_attributes(collection, attributes))
    }

    async fn update_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        delegate!(self, |a| a.update_attribute(collection, id, kind, size, signed, array, new_key, required))
    }

    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        delegate!(self, |a| a.delete_attribute(collection, id))
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        delegate!(self, |a| a.rename_attribute(collection, old, new))
    }

    // ----- Relationships -----

    async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        delegate!(self, |a| a.create_relationship(collection, related_collection, kind, two_way, id, two_way_key))
    }

    async fn update_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        delegate!(self, |a| a.update_relationship(
            collection,
            related_collection,
            kind,
            two_way,
            key,
            two_way_key,
            side,
            new_key,
            new_two_way_key
        ))
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        delegate!(self, |a| a.delete_relationship(
            collection,
            related_collection,
            kind,
            two_way,
            key,
            two_way_key,
            side
        ))
    }

    // ----- Indexes -----

    async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
        attribute_types: &[(String, String)],
        collation: &[String],
        ttl: i64,
    ) -> Result<bool> {
        delegate!(self, |a| a.create_index(
            collection,
            id,
            kind,
            attributes,
            lengths,
            orders,
            attribute_types,
            collation,
            ttl
        ))
    }

    async fn create_index_arrays(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &Array,
        orders: &Array,
        attribute_types: &[(String, String)],
        collation: &[String],
        ttl: i64,
    ) -> Result<bool> {
        delegate!(self, |a| a.create_index_arrays(
            collection,
            id,
            kind,
            attributes,
            lengths,
            orders,
            attribute_types,
            collation,
            ttl
        ))
    }

    async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        delegate!(self, |a| a.delete_index(collection, id))
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        delegate!(self, |a| a.rename_index(collection, old, new))
    }

    // ----- Documents -----

    async fn get_document(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> Result<Document> {
        delegate!(self, |a| a.get_document(collection, id, queries, for_update))
    }

    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document> {
        delegate!(self, |a| a.create_document(collection, document))
    }

    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>> {
        delegate!(self, |a| a.create_documents(collection, documents))
    }

    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        delegate!(self, |a| a.update_document(collection, id, document, skip_permissions))
    }

    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64> {
        delegate!(self, |a| a.update_documents(collection, updates, documents))
    }

    async fn upsert_documents(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        delegate!(self, |a| a.upsert_documents(collection, attribute, changes))
    }

    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>> {
        delegate!(self, |a| a.get_sequences(collection, documents))
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        delegate!(self, |a| a.delete_document(collection, id))
    }

    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64> {
        delegate!(self, |a| a.delete_documents(collection, sequences, permission_ids))
    }

    async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        delegate!(self, |a| a.increase_document_attribute(collection, id, attribute, value, updated_at, min, max))
    }

    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>> {
        delegate!(self, |a| a.find(collection, queries, options))
    }

    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value> {
        delegate!(self, |a| a.sum(collection, attribute, queries, max))
    }

    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        delegate!(self, |a| a.count(collection, queries, max))
    }

    // ----- Value conversion -----

    fn casting_before(&self, collection: &Document, document: Document) -> Result<Document> {
        let fallback = document.clone();
        read!(self, |a| a.casting_before(collection, document), Ok(fallback))
    }

    fn casting_after(&self, collection: &Document, document: Document) -> Result<Document> {
        let fallback = document.clone();
        read!(self, |a| a.casting_after(collection, document), Ok(fallback))
    }

    fn set_utc_datetime(&self, value: &str) -> Result<Value> {
        read!(self, |a| a.set_utc_datetime(value), Ok(Value::Str(value.into())))
    }

    fn decode_point(&self, wkb: &[u8]) -> Result<Value> {
        read!(self, |a| a.decode_point(wkb), Err(no_connection()))
    }

    fn decode_linestring(&self, wkb: &[u8]) -> Result<Value> {
        read!(self, |a| a.decode_linestring(wkb), Err(no_connection()))
    }

    fn decode_polygon(&self, wkb: &[u8]) -> Result<Value> {
        read!(self, |a| a.decode_polygon(wkb), Err(no_connection()))
    }

    fn tenant_query(&self, collection: &str, alias: &str) -> String {
        read!(self, |a| a.tenant_query(collection, alias), String::new())
    }

    // ----- Limits -----

    fn limit_for_string(&self) -> i64 {
        read!(self, |a| a.limit_for_string(), 0)
    }

    fn limit_for_int(&self) -> i64 {
        read!(self, |a| a.limit_for_int(), 0)
    }

    fn limit_for_big_int(&self) -> i64 {
        read!(self, |a| a.limit_for_big_int(), 0)
    }

    fn limit_for_attributes(&self) -> i64 {
        read!(self, |a| a.limit_for_attributes(), 0)
    }

    fn limit_for_indexes(&self) -> i64 {
        read!(self, |a| a.limit_for_indexes(), 0)
    }

    fn max_index_length(&self) -> i64 {
        read!(self, |a| a.max_index_length(), 0)
    }

    fn max_varchar_length(&self) -> i64 {
        read!(self, |a| a.max_varchar_length(), 0)
    }

    fn max_uid_length(&self) -> i64 {
        read!(self, |a| a.max_uid_length(), 0)
    }

    fn min_date_time(&self) -> DateTime {
        read!(self, |a| a.min_date_time(), self.max_date_time())
    }

    fn id_attribute_type(&self) -> &'static str {
        read!(self, |a| a.id_attribute_type(), "")
    }

    fn count_of_attributes(&self, collection: &Document) -> i64 {
        read!(self, |a| a.count_of_attributes(collection), 0)
    }

    fn count_of_indexes(&self, collection: &Document) -> i64 {
        read!(self, |a| a.count_of_indexes(collection), 0)
    }

    fn count_of_default_attributes(&self) -> i64 {
        read!(self, |a| a.count_of_default_attributes(), 0)
    }

    fn count_of_default_indexes(&self) -> i64 {
        read!(self, |a| a.count_of_default_indexes(), 0)
    }

    fn document_size_limit(&self) -> i64 {
        read!(self, |a| a.document_size_limit(), 0)
    }

    fn attribute_width(&self, collection: &Document) -> Result<i64> {
        read!(self, |a| a.attribute_width(collection), Ok(0))
    }

    fn keywords(&self) -> &'static [&'static str] {
        read!(self, |a| a.keywords(), &[])
    }

    fn internal_indexes_keys(&self) -> &'static [&'static str] {
        read!(self, |a| a.internal_indexes_keys(), &[])
    }

    // ----- Capabilities -----

    fn support_for_schemas(&self) -> bool {
        read!(self, |a| a.support_for_schemas(), false)
    }

    fn support_for_attributes(&self) -> bool {
        read!(self, |a| a.support_for_attributes(), false)
    }

    fn set_support_for_attributes(&self, support: bool) -> bool {
        read!(self, |a| a.set_support_for_attributes(support), false)
    }

    fn support_for_schema_attributes(&self) -> bool {
        read!(self, |a| a.support_for_schema_attributes(), false)
    }

    fn support_for_schema_indexes(&self) -> bool {
        read!(self, |a| a.support_for_schema_indexes(), false)
    }

    fn support_for_index(&self) -> bool {
        read!(self, |a| a.support_for_index(), false)
    }

    fn support_for_index_array(&self) -> bool {
        read!(self, |a| a.support_for_index_array(), false)
    }

    fn support_for_cast_index_array(&self) -> bool {
        read!(self, |a| a.support_for_cast_index_array(), false)
    }

    fn support_for_unique_index(&self) -> bool {
        read!(self, |a| a.support_for_unique_index(), false)
    }

    fn support_for_fulltext_index(&self) -> bool {
        read!(self, |a| a.support_for_fulltext_index(), false)
    }

    fn support_for_fulltext_wildcard_index(&self) -> bool {
        read!(self, |a| a.support_for_fulltext_wildcard_index(), false)
    }

    fn support_for_casting(&self) -> bool {
        read!(self, |a| a.support_for_casting(), false)
    }

    fn support_for_query_contains(&self) -> bool {
        read!(self, |a| a.support_for_query_contains(), false)
    }

    fn support_for_timeouts(&self) -> bool {
        read!(self, |a| a.support_for_timeouts(), false)
    }

    fn support_for_relationships(&self) -> bool {
        read!(self, |a| a.support_for_relationships(), false)
    }

    fn support_for_update_lock(&self) -> bool {
        read!(self, |a| a.support_for_update_lock(), false)
    }

    fn support_for_batch_operations(&self) -> bool {
        read!(self, |a| a.support_for_batch_operations(), false)
    }

    fn support_for_attribute_resizing(&self) -> bool {
        read!(self, |a| a.support_for_attribute_resizing(), false)
    }

    fn support_for_get_connection_id(&self) -> bool {
        read!(self, |a| a.support_for_get_connection_id(), false)
    }

    fn support_for_upserts(&self) -> bool {
        read!(self, |a| a.support_for_upserts(), false)
    }

    fn support_for_upsert_on_unique_index(&self) -> bool {
        read!(self, |a| a.support_for_upsert_on_unique_index(), false)
    }

    fn support_for_vectors(&self) -> bool {
        read!(self, |a| a.support_for_vectors(), false)
    }

    fn support_for_cache_skip_on_failure(&self) -> bool {
        read!(self, |a| a.support_for_cache_skip_on_failure(), false)
    }

    fn support_for_caching(&self) -> bool {
        read!(self, |a| a.support_for_caching(), false)
    }

    fn support_for_reconnection(&self) -> bool {
        read!(self, |a| a.support_for_reconnection(), false)
    }

    fn support_for_hostname(&self) -> bool {
        read!(self, |a| a.support_for_hostname(), false)
    }

    fn support_for_batch_create_attributes(&self) -> bool {
        read!(self, |a| a.support_for_batch_create_attributes(), false)
    }

    fn support_for_spatial_attributes(&self) -> bool {
        read!(self, |a| a.support_for_spatial_attributes(), false)
    }

    fn support_for_object(&self) -> bool {
        read!(self, |a| a.support_for_object(), false)
    }

    fn support_for_object_indexes(&self) -> bool {
        read!(self, |a| a.support_for_object_indexes(), false)
    }

    fn support_for_spatial_index_null(&self) -> bool {
        read!(self, |a| a.support_for_spatial_index_null(), false)
    }

    fn support_for_operators(&self) -> bool {
        read!(self, |a| a.support_for_operators(), false)
    }

    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        read!(self, |a| a.support_for_optional_spatial_attribute_with_existing_rows(), false)
    }

    fn support_for_spatial_index_order(&self) -> bool {
        read!(self, |a| a.support_for_spatial_index_order(), false)
    }

    fn support_for_spatial_axis_order(&self) -> bool {
        read!(self, |a| a.support_for_spatial_axis_order(), false)
    }

    fn support_for_boundary_inclusive_contains(&self) -> bool {
        read!(self, |a| a.support_for_boundary_inclusive_contains(), false)
    }

    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        read!(self, |a| a.support_for_distance_between_multi_dimension_geometry_in_meters(), false)
    }

    fn support_for_multiple_fulltext_indexes(&self) -> bool {
        read!(self, |a| a.support_for_multiple_fulltext_indexes(), false)
    }

    fn support_for_identical_indexes(&self) -> bool {
        read!(self, |a| a.support_for_identical_indexes(), false)
    }

    fn support_for_order_random(&self) -> bool {
        read!(self, |a| a.support_for_order_random(), false)
    }

    fn support_for_unsigned_big_int(&self) -> bool {
        read!(self, |a| a.support_for_unsigned_big_int(), false)
    }

    fn support_for_internal_casting(&self) -> bool {
        read!(self, |a| a.support_for_internal_casting(), false)
    }

    fn support_for_utc_casting(&self) -> bool {
        read!(self, |a| a.support_for_utc_casting(), false)
    }

    fn support_for_integer_booleans(&self) -> bool {
        read!(self, |a| a.support_for_integer_booleans(), false)
    }

    fn support_for_alter_locks(&self) -> bool {
        read!(self, |a| a.support_for_alter_locks(), false)
    }

    fn support_non_utf_characters(&self) -> bool {
        read!(self, |a| a.support_non_utf_characters(), false)
    }

    fn support_for_trigram_index(&self) -> bool {
        read!(self, |a| a.support_for_trigram_index(), false)
    }

    fn support_for_pcre_regex(&self) -> bool {
        read!(self, |a| a.support_for_pcre_regex(), false)
    }

    fn support_for_posix_regex(&self) -> bool {
        read!(self, |a| a.support_for_posix_regex(), false)
    }

    fn support_for_ttl_indexes(&self) -> bool {
        read!(self, |a| a.support_for_ttl_indexes(), false)
    }

    fn support_for_transaction_retries(&self) -> bool {
        read!(self, |a| a.support_for_transaction_retries(), false)
    }

    fn support_for_nested_transactions(&self) -> bool {
        read!(self, |a| a.support_for_nested_transactions(), false)
    }
}

/// A connection a synchronous call runs on: its adapter, and the checkout to
/// hand back (`None` for the pinned or the last connection).
type Lent = (Arc<dyn Adapter>, Option<Connection<Pooled>>);

/// What a synchronous call that needs a connection reports when this handle
/// has none yet.
fn no_connection() -> Error {
    Error::new(Kind::Exception, "Pool: no connection to run the call on yet")
}
