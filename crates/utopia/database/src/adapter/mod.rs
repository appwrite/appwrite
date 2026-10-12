//! [`Adapter`]: the storage engine under a [`crate::Database`].
//!
//! An adapter owns one connection (like PHP's PDO handle) and the per-handle
//! settings the database passes down: database (schema) and namespace (table
//! prefix), shared tables and tenant, timeouts, statement transforms
//! ([`Adapter::before`]) and the authorization checked by document-level
//! permissions. It turns the database's calls (collections, attributes,
//! indexes, documents, finds) into statements and back.
//!
//! PHP: `Utopia\Database\Adapter` (abstract class). Its concrete methods are
//! the provided methods of the trait, over the [`State`] every adapter
//! embeds; its abstract methods are the required ones. Capability getters
//! (`getSupportFor*`) keep their names in snake case.
//!
//! Adapters in this crate: [`postgres::Postgres`] (feature `postgres`),
//! `mariadb::MariaDB` (feature `mariadb`; MariaDB and, with feature `mysql`,
//! MySQL), `sqlite::Sqlite` (feature `sqlite`), `memory::Memory` (feature
//! `memory`), `mongo::Mongo` (feature `mongo`), `redis::Redis` (feature
//! `redis`) and `pool::Pool` (feature `pool`), over a pool of adapters.

use std::future::Future;
use std::sync::{Arc, Mutex, MutexGuard};

use async_trait::async_trait;
use php_std::datetime::DateTime;

use crate::change::Change;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::Query;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value};

#[cfg(feature = "memory")]
pub mod memory;
#[cfg(feature = "mongo")]
pub mod mongo;
#[cfg(feature = "pool")]
pub mod pool;
#[cfg(feature = "postgres")]
pub mod postgres;
#[cfg(feature = "redis")]
pub mod redis;
#[cfg(feature = "sqlite")]
pub mod sqlite;

#[cfg(feature = "mariadb")]
pub mod mariadb;

pub mod sqlstate;

/// A statement transform registered with [`Adapter::before`]: receives the
/// SQL about to run and returns the SQL to run instead.
pub type Transform = Arc<dyn Fn(String) -> String + Send + Sync>;

/// `Database::EVENT_ALL`: transforms registered for every statement.
pub const EVENT_ALL: &str = "*";

/// The settings PHP's `Adapter` base class keeps per handle.
pub struct State {
    inner: Mutex<StateInner>,
}

/// The fields of [`State`].
#[derive(Clone)]
pub struct StateInner {
    /// `$database`: the schema (SQL) or database (Mongo) name.
    pub database: String,
    /// `$hostname`.
    pub hostname: String,
    /// `$namespace`: the table name prefix.
    pub namespace: String,
    /// `$sharedTables`.
    pub shared_tables: bool,
    /// `$tenant` (`int|string|null`; [`Value::Null`] when unset).
    pub tenant: Value,
    /// `$tenantPerDocument`.
    pub tenant_per_document: bool,
    /// `$timeout` in milliseconds.
    pub timeout: i64,
    /// `$inTransaction`: the nesting depth.
    pub in_transaction: i64,
    /// `$alterLocks`.
    pub alter_locks: bool,
    /// `$skipDuplicates`.
    pub skip_duplicates: bool,
    /// `$debug`.
    pub debug: Array,
    /// `$transformations`: event => name => transform, in insertion order.
    pub transformations: Vec<(String, Vec<(String, Transform)>)>,
    /// `$metadata`, in insertion order.
    pub metadata: Vec<(String, String)>,
    /// `$authorization`.
    pub authorization: Arc<Authorization>,
}

impl Default for State {
    fn default() -> Self {
        Self {
            inner: Mutex::new(StateInner {
                database: String::new(),
                hostname: String::new(),
                namespace: String::new(),
                shared_tables: false,
                tenant: Value::Null,
                tenant_per_document: false,
                timeout: 0,
                in_transaction: 0,
                alter_locks: false,
                skip_duplicates: false,
                debug: Array::new(),
                transformations: vec![(EVENT_ALL.to_owned(), Vec::new())],
                metadata: Vec::new(),
                authorization: Arc::new(Authorization::new()),
            }),
        }
    }
}

impl State {
    /// Locks the fields for reading or writing. Never held across an await.
    pub fn lock(&self) -> MutexGuard<'_, StateInner> {
        self.inner.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// `before($event, $name, $callback)`.
    pub fn before(&self, event: &str, name: &str, transform: Option<Transform>) {
        let mut s = self.lock();
        let slot = match s.transformations.iter().position(|(e, _)| e == event) {
            Some(i) => i,
            None => {
                s.transformations.push((event.to_owned(), Vec::new()));
                s.transformations.len() - 1
            }
        };
        let list = &mut s.transformations[slot].1;
        match transform {
            None => list.retain(|(n, _)| n != name),
            Some(t) => match list.iter_mut().find(|(n, _)| n == name) {
                Some(entry) => entry.1 = t,
                None => list.push((name.to_owned(), t)),
            },
        }
    }

    /// `trigger($event, $query)`: applies the `*` transforms, then the
    /// event's, in registration order.
    pub fn trigger(&self, event: &str, query: String) -> String {
        let transforms: Vec<Transform> = {
            let s = self.lock();
            let all = s.transformations.iter().find(|(e, _)| e == EVENT_ALL).map(|(_, l)| l.as_slice()).unwrap_or(&[]);
            let own = if event == EVENT_ALL {
                &[][..]
            } else {
                s.transformations.iter().find(|(e, _)| e == event).map(|(_, l)| l.as_slice()).unwrap_or(&[])
            };
            all.iter().chain(own.iter()).map(|(_, t)| t.clone()).collect()
        };
        transforms.iter().fold(query, |q, t| t(q))
    }
}

/// `$adapter->withTransaction($callback)`: runs `f` in a transaction,
/// committing on success and rolling back on failure. Failures other than
/// duplicate, restricted, authorization, relationship, conflict, limit and
/// timeout errors are retried twice, after 50 ms and 100 ms.
pub async fn with_transaction<A, T, F, Fut>(adapter: &A, mut f: F) -> Result<T>
where
    A: Adapter + ?Sized,
    F: FnMut() -> Fut,
    Fut: Future<Output = Result<T>>,
{
    // Adapters that cannot (Mongo off a replica set) or need not (already
    // in one) open a transaction run the callback as it is.
    if adapter.bypass_transaction().await? {
        return f().await;
    }
    // An adapter over a pool holds one connection for the whole
    // transaction, retries included (PHP's `Pool::withTransaction`).
    let pinned = adapter.pin_connection().await?;
    let result = run_transaction(adapter, &mut f).await;
    if pinned {
        adapter.unpin_connection(result.is_err()).await;
    }
    result
}

/// The attempts of [`with_transaction`].
async fn run_transaction<A, T, F, Fut>(adapter: &A, f: &mut F) -> Result<T>
where
    A: Adapter + ?Sized,
    F: FnMut() -> Fut,
    Fut: Future<Output = Result<T>>,
{
    let retries = 2;
    for attempt in 0..=retries {
        let result = async {
            adapter.start_transaction().await?;
            let value = f().await?;
            adapter.commit_transaction().await?;
            Ok::<T, Error>(value)
        }
        .await;
        let action = match result {
            Ok(v) => return Ok(v),
            Err(e) => e,
        };
        let mut rollback = None;
        if let Err(e) = adapter.rollback_transaction().await {
            rollback = Some(e);
            adapter.state().lock().in_transaction = 0;
        }
        if [
            Kind::Duplicate,
            Kind::Restricted,
            Kind::Authorization,
            Kind::Relationship,
            Kind::Conflict,
            Kind::Limit,
            Kind::Timeout,
        ]
        .iter()
        .any(|k| action.is_a(*k))
        {
            return Err(action);
        }
        if attempt < retries {
            tokio::time::sleep(std::time::Duration::from_micros(50_000 * (attempt as u64 + 1))).await;
            continue;
        }
        return Err(if adapter.rethrows_rollback_failure() { rollback.unwrap_or(action) } else { action });
    }
    Err(Error::transaction("Failed to execute transaction"))
}

/// The arguments of [`Adapter::find`] besides the collection and queries.
#[derive(Clone, Debug)]
pub struct FindOptions<'a> {
    /// `$limit` (`null` for none).
    pub limit: Option<i64>,
    /// `$offset`.
    pub offset: Option<i64>,
    /// `$orderAttributes`.
    pub order_attributes: &'a [String],
    /// `$orderTypes` (`ASC`, `DESC`, `RANDOM`).
    pub order_types: &'a [String],
    /// `$cursor`: the cursor document's attributes (empty for none).
    pub cursor: &'a Array,
    /// `$cursorDirection` (`after`, `before`).
    pub cursor_direction: &'a str,
    /// `$forPermission` (`read`, `update`, ...).
    pub for_permission: &'a str,
}

/// A storage engine. See the [module docs](self).
#[async_trait]
pub trait Adapter: Send + Sync {
    /// The settings PHP's base class keeps.
    fn state(&self) -> &State;

    // ----- Settings (PHP's concrete base methods) -----

    /// `setAuthorization()`.
    fn set_authorization(&self, authorization: Arc<Authorization>) {
        self.state().lock().authorization = authorization;
    }

    /// `getAuthorization()`.
    fn authorization(&self) -> Arc<Authorization> {
        self.state().lock().authorization.clone()
    }

    /// `setDebug()`.
    fn set_debug(&self, key: &str, value: Value) {
        self.state().lock().debug.set(key.into(), value);
    }

    /// `getDebug()`.
    fn debug(&self) -> Array {
        self.state().lock().debug.clone()
    }

    /// `resetDebug()`.
    fn reset_debug(&self) {
        self.state().lock().debug = Array::new();
    }

    /// `setNamespace()`: the namespace, filtered to `[A-Za-z0-9_-]`.
    fn set_namespace(&self, namespace: &str) -> Result<()> {
        let filtered = self.filter(namespace)?;
        self.state().lock().namespace = filtered;
        Ok(())
    }

    /// `getNamespace()`.
    fn namespace(&self) -> String {
        self.state().lock().namespace.clone()
    }

    /// `setHostname()`.
    fn set_hostname(&self, hostname: &str) {
        self.state().lock().hostname = hostname.to_owned();
    }

    /// `getHostname()`.
    fn hostname(&self) -> String {
        self.state().lock().hostname.clone()
    }

    /// `setDatabase()`: the name, filtered.
    fn set_database(&self, name: &str) -> Result<bool> {
        let filtered = self.filter(name)?;
        self.state().lock().database = filtered;
        Ok(true)
    }

    /// `getDatabase()`.
    fn database(&self) -> String {
        self.state().lock().database.clone()
    }

    /// `setSharedTables()`.
    fn set_shared_tables(&self, shared: bool) -> bool {
        self.state().lock().shared_tables = shared;
        true
    }

    /// `getSharedTables()`.
    fn shared_tables(&self) -> bool {
        self.state().lock().shared_tables
    }

    /// `setTenant()` (`int|string|null`).
    fn set_tenant(&self, tenant: Value) -> bool {
        self.state().lock().tenant = tenant;
        true
    }

    /// `getTenant()`.
    fn tenant(&self) -> Value {
        self.state().lock().tenant.clone()
    }

    /// `setTenantPerDocument()`.
    fn set_tenant_per_document(&self, enabled: bool) -> bool {
        self.state().lock().tenant_per_document = enabled;
        true
    }

    /// `getTenantPerDocument()`.
    fn tenant_per_document(&self) -> bool {
        self.state().lock().tenant_per_document
    }

    /// `setMetadata()`: also installs the `metadata` transform that prefixes
    /// every statement with `/* key: value */` lines.
    fn set_metadata(&self, key: &str, value: &str) {
        let output = {
            let mut s = self.state().lock();
            match s.metadata.iter_mut().find(|(k, _)| k == key) {
                Some(entry) => entry.1 = value.to_owned(),
                None => s.metadata.push((key.to_owned(), value.to_owned())),
            }
            s.metadata.iter().map(|(k, v)| format!("/* {k}: {v} */\n")).collect::<String>()
        };
        self.before(EVENT_ALL, "metadata", Some(Arc::new(move |q: String| format!("{output}{q}"))));
    }

    /// `getMetadata()`.
    fn metadata(&self) -> Vec<(String, String)> {
        self.state().lock().metadata.clone()
    }

    /// `resetMetadata()` (the `metadata` transform stays, as in PHP).
    fn reset_metadata(&self) {
        self.state().lock().metadata.clear();
    }

    /// `setTimeout()`: the statement timeout for `event` (`*` for all).
    fn set_timeout(&self, milliseconds: i64, event: &str) -> Result<()>;

    /// `getTimeout()`.
    fn timeout(&self) -> i64 {
        self.state().lock().timeout
    }

    /// `clearTimeout()`.
    fn clear_timeout(&self, event: &str) {
        self.before(event, "timeout", None);
        self.state().lock().timeout = 0;
    }

    /// `clearTimeouts()`.
    fn clear_timeouts(&self) {
        let events: Vec<String> = self.state().lock().transformations.iter().map(|(e, _)| e.clone()).collect();
        for e in events {
            self.clear_timeout(&e);
        }
        self.state().lock().timeout = 0;
    }

    /// `inTransaction()`.
    fn in_transaction(&self) -> bool {
        self.state().lock().in_transaction > 0
    }

    /// The flag `skipDuplicates($callback)` sets around its callback.
    fn set_skip_duplicates(&self, skip: bool) -> bool {
        std::mem::replace(&mut self.state().lock().skip_duplicates, skip)
    }

    /// Whether duplicates are skipped (`$skipDuplicates`).
    fn skip_duplicates(&self) -> bool {
        self.state().lock().skip_duplicates
    }

    /// `before($event, $name, $callback)`; `None` removes the transform.
    fn before(&self, event: &str, name: &str, transform: Option<Transform>) {
        self.state().before(event, name, transform);
    }

    /// `trigger($event, $query)`.
    fn trigger(&self, event: &str, query: String) -> String {
        self.state().trigger(event, query)
    }

    /// `filter()`: keeps `[A-Za-z0-9_-]`.
    fn filter(&self, value: &str) -> Result<String> {
        Ok(value.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect())
    }

    /// `enableAlterLocks()`.
    fn enable_alter_locks(&self, enable: bool) {
        self.state().lock().alter_locks = enable;
    }

    /// `getColumnType()` (adapters without SQL types return `""`).
    fn column_type(&self, _kind: &str, _size: i64, _signed: bool, _array: bool, _required: bool) -> Result<String> {
        Ok(String::new())
    }

    // ----- Transactions -----

    /// Before [`with_transaction`] runs its attempts: an adapter over a pool
    /// of connections pins one for the whole transaction and returns `true`
    /// (`false` when one is pinned already). Other adapters own their
    /// connection and pin nothing.
    ///
    /// PHP: the pinning in `Pool::withTransaction()`.
    async fn pin_connection(&self) -> Result<bool> {
        Ok(false)
    }

    /// After a transaction [`Adapter::pin_connection`] pinned a connection
    /// for: hands it back (`failed` when the transaction failed).
    async fn unpin_connection(&self, _failed: bool) {}

    /// Whether `withTransaction()` runs its callback without a transaction:
    /// adapters that override it to skip one when they cannot (Mongo off a
    /// replica set) or need not (already in one). `false`.
    async fn bypass_transaction(&self) -> Result<bool> {
        Ok(false)
    }

    /// Whether `withTransaction()`, once out of retries, reports a failed
    /// rollback in place of the failure that caused it (`true`; Mongo
    /// reports the failure).
    fn rethrows_rollback_failure(&self) -> bool {
        true
    }

    /// `startTransaction()`.
    async fn start_transaction(&self) -> Result<bool>;
    /// `commitTransaction()`.
    async fn commit_transaction(&self) -> Result<bool>;
    /// `rollbackTransaction()`.
    async fn rollback_transaction(&self) -> Result<bool>;

    // ----- Connection -----

    /// `ping()`.
    async fn ping(&self) -> Result<bool>;
    /// `reconnect()`.
    async fn reconnect(&self) -> Result<()>;
    /// `getConnectionId()`.
    async fn connection_id(&self) -> Result<String>;

    // ----- Databases -----

    /// `create($name)`.
    async fn create(&self, name: &str) -> Result<bool>;
    /// `exists($database, $collection)`.
    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool>;
    /// `list()`.
    async fn list(&self) -> Result<Vec<Document>>;
    /// `delete($name)`.
    async fn delete(&self, name: &str) -> Result<bool>;

    // ----- Collections -----

    /// `createCollection($name, $attributes, $indexes)`.
    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool>;
    /// `deleteCollection($id)`.
    async fn delete_collection(&self, id: &str) -> Result<bool>;
    /// `analyzeCollection($collection)`.
    async fn analyze_collection(&self, collection: &str) -> Result<bool>;
    /// `getSizeOfCollection($collection)`.
    async fn size_of_collection(&self, collection: &str) -> Result<i64>;
    /// `getSizeOfCollectionOnDisk($collection)`.
    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64>;
    /// `getSchemaAttributes($collection)`.
    async fn schema_attributes(&self, collection: &str) -> Result<Vec<Document>>;
    /// `getSchemaIndexes($collection)`.
    async fn schema_indexes(&self, collection: &str) -> Result<Vec<Document>>;

    // ----- Attributes -----

    /// `createAttribute($collection, $id, $type, $size, $signed, $array, $required)`.
    #[allow(clippy::too_many_arguments)]
    async fn create_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool>;
    /// `createAttributes($collection, $attributes)`: each an array with
    /// `$id`, `type`, `size`, `signed`, `array`, `required`.
    async fn create_attributes(&self, collection: &str, attributes: &[Array]) -> Result<bool>;
    /// `updateAttribute($collection, $id, $type, $size, $signed, $array, $newKey, $required)`.
    #[allow(clippy::too_many_arguments)]
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
    ) -> Result<bool>;
    /// `deleteAttribute($collection, $id)`.
    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool>;
    /// `renameAttribute($collection, $old, $new)`.
    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool>;

    // ----- Relationships -----

    /// `createRelationship($collection, $relatedCollection, $type, $twoWay, $id, $twoWayKey)`.
    async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool>;
    /// `updateRelationship(...)`.
    #[allow(clippy::too_many_arguments)]
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
    ) -> Result<bool>;
    /// `deleteRelationship(...)`.
    #[allow(clippy::too_many_arguments)]
    async fn delete_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool>;

    // ----- Indexes -----

    /// `createIndex($collection, $id, $type, $attributes, $lengths, $orders, $indexAttributeTypes, $collation, $ttl)`.
    #[allow(clippy::too_many_arguments)]
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
    ) -> Result<bool>;
    /// `createIndex(...)` with `$lengths` and `$orders` as the arrays the
    /// database built (their keys kept, sparse ones included). The default
    /// hands [`Adapter::create_index`] one entry per attribute (`null` for
    /// a missing one); an adapter that stores the arrays overrides it.
    #[allow(clippy::too_many_arguments)]
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
        let positional = |a: &Array| -> Vec<Value> {
            (0..attributes.len())
                .map(|i| a.get(&crate::value::ArrayKey::Int(i as i64)).cloned().unwrap_or(Value::Null))
                .collect()
        };
        self.create_index(
            collection,
            id,
            kind,
            attributes,
            &positional(lengths),
            &positional(orders),
            attribute_types,
            collation,
            ttl,
        )
        .await
    }
    /// `deleteIndex($collection, $id)`.
    async fn delete_index(&self, collection: &str, id: &str) -> Result<bool>;
    /// `renameIndex($collection, $old, $new)`.
    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool>;

    // ----- Documents -----

    /// `getDocument($collection, $id, $queries, $forUpdate)`.
    async fn get_document(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> Result<Document>;
    /// `createDocument($collection, $document)`.
    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document>;
    /// `createDocuments($collection, $documents)`.
    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>>;
    /// `updateDocument($collection, $id, $document, $skipPermissions)`.
    /// Returns `$document` as the call leaves it (PHP's caller holds that
    /// object, which an adapter may change in place), not what PHP returns.
    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document>;
    /// `updateDocuments($collection, $updates, $documents)`.
    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64>;
    /// `upsertDocuments($collection, $attribute, $changes)`.
    async fn upsert_documents(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>>;
    /// `getSequences($collection, $documents)`.
    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>>;
    /// `deleteDocument($collection, $id)`.
    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool>;
    /// `deleteDocuments($collection, $sequences, $permissionIds)`.
    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64>;
    /// `increaseDocumentAttribute($collection, $id, $attribute, $value, $updatedAt, $min, $max)`.
    #[allow(clippy::too_many_arguments)]
    async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool>;
    /// `find($collection, $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursor, $cursorDirection, $forPermission)`.
    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>>;
    /// `sum($collection, $attribute, $queries, $max)`: an int or a float.
    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value>;
    /// `count($collection, $queries, $max)`.
    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64>;

    // ----- Value conversion -----

    /// `castingBefore($collection, $document)`.
    fn casting_before(&self, collection: &Document, document: Document) -> Result<Document>;
    /// `castingAfter($collection, $document)`.
    fn casting_after(&self, collection: &Document, document: Document) -> Result<Document>;
    /// `setUTCDatetime($value)`.
    fn set_utc_datetime(&self, value: &str) -> Result<Value>;
    /// `decodePoint($wkb)`.
    fn decode_point(&self, wkb: &[u8]) -> Result<Value>;
    /// `decodeLinestring($wkb)`.
    fn decode_linestring(&self, wkb: &[u8]) -> Result<Value>;
    /// `decodePolygon($wkb)`.
    fn decode_polygon(&self, wkb: &[u8]) -> Result<Value>;
    /// `getTenantQuery($collection, $alias)`.
    fn tenant_query(&self, collection: &str, alias: &str) -> String;

    // ----- Limits -----

    /// `getLimitForString()`.
    fn limit_for_string(&self) -> i64;
    /// `getLimitForInt()`.
    fn limit_for_int(&self) -> i64;
    /// `getLimitForBigInt()`.
    fn limit_for_big_int(&self) -> i64;
    /// `getLimitForAttributes()`.
    fn limit_for_attributes(&self) -> i64;
    /// `getLimitForIndexes()`.
    fn limit_for_indexes(&self) -> i64;
    /// `getMaxIndexLength()`.
    fn max_index_length(&self) -> i64;
    /// `getMaxVarcharLength()`.
    fn max_varchar_length(&self) -> i64;
    /// `getMaxUIDLength()`.
    fn max_uid_length(&self) -> i64;
    /// `getMinDateTime()`.
    fn min_date_time(&self) -> DateTime;
    /// `getMaxDateTime()`: `9999-12-31 23:59:59`.
    fn max_date_time(&self) -> DateTime {
        DateTime::new(b"9999-12-31 23:59:59", None, &php_std::datetime::TzInfo::utc())
            .unwrap_or_else(|_| unreachable!("a valid date"))
    }
    /// `getIdAttributeType()`.
    fn id_attribute_type(&self) -> &'static str;
    /// `getCountOfAttributes($collection)`.
    fn count_of_attributes(&self, collection: &Document) -> i64;
    /// `getCountOfIndexes($collection)`.
    fn count_of_indexes(&self, collection: &Document) -> i64;
    /// `getCountOfDefaultAttributes()`.
    fn count_of_default_attributes(&self) -> i64;
    /// `getCountOfDefaultIndexes()`.
    fn count_of_default_indexes(&self) -> i64;
    /// `getDocumentSizeLimit()`.
    fn document_size_limit(&self) -> i64;
    /// `getAttributeWidth($collection)`.
    fn attribute_width(&self, collection: &Document) -> Result<i64>;
    /// `getKeywords()`.
    fn keywords(&self) -> &'static [&'static str];
    /// `getInternalIndexesKeys()`.
    fn internal_indexes_keys(&self) -> &'static [&'static str];

    // ----- Capabilities -----

    /// `getSupportForSchemas()`.
    fn support_for_schemas(&self) -> bool;
    /// `getSupportForAttributes()`.
    fn support_for_attributes(&self) -> bool;
    /// `setSupportForAttributes()`.
    fn set_support_for_attributes(&self, support: bool) -> bool;
    /// `getSupportForSchemaAttributes()`.
    fn support_for_schema_attributes(&self) -> bool;
    /// `getSupportForSchemaIndexes()`.
    fn support_for_schema_indexes(&self) -> bool;
    /// `getSupportForIndex()`.
    fn support_for_index(&self) -> bool;
    /// `getSupportForIndexArray()`.
    fn support_for_index_array(&self) -> bool;
    /// `getSupportForCastIndexArray()`.
    fn support_for_cast_index_array(&self) -> bool;
    /// `getSupportForUniqueIndex()`.
    fn support_for_unique_index(&self) -> bool;
    /// `getSupportForFulltextIndex()`.
    fn support_for_fulltext_index(&self) -> bool;
    /// `getSupportForFulltextWildcardIndex()`.
    fn support_for_fulltext_wildcard_index(&self) -> bool;
    /// `getSupportForCasting()`.
    fn support_for_casting(&self) -> bool;
    /// `getSupportForQueryContains()`.
    fn support_for_query_contains(&self) -> bool;
    /// `getSupportForTimeouts()`.
    fn support_for_timeouts(&self) -> bool;
    /// `getSupportForRelationships()`.
    fn support_for_relationships(&self) -> bool;
    /// `getSupportForUpdateLock()`.
    fn support_for_update_lock(&self) -> bool;
    /// `getSupportForBatchOperations()`.
    fn support_for_batch_operations(&self) -> bool;
    /// `getSupportForAttributeResizing()`.
    fn support_for_attribute_resizing(&self) -> bool;
    /// `getSupportForGetConnectionId()`.
    fn support_for_get_connection_id(&self) -> bool;
    /// `getSupportForUpserts()`.
    fn support_for_upserts(&self) -> bool;
    /// `getSupportForUpsertOnUniqueIndex()`.
    fn support_for_upsert_on_unique_index(&self) -> bool;
    /// `getSupportForVectors()`.
    fn support_for_vectors(&self) -> bool;
    /// `getSupportForCacheSkipOnFailure()`.
    fn support_for_cache_skip_on_failure(&self) -> bool;
    /// `getSupportForCaching()`.
    fn support_for_caching(&self) -> bool;
    /// `getSupportForReconnection()`.
    fn support_for_reconnection(&self) -> bool;
    /// `getSupportForHostname()`.
    fn support_for_hostname(&self) -> bool;
    /// `getSupportForBatchCreateAttributes()`.
    fn support_for_batch_create_attributes(&self) -> bool;
    /// `getSupportForSpatialAttributes()`.
    fn support_for_spatial_attributes(&self) -> bool;
    /// `getSupportForObject()`.
    fn support_for_object(&self) -> bool;
    /// `getSupportForObjectIndexes()`.
    fn support_for_object_indexes(&self) -> bool;
    /// `getSupportForSpatialIndexNull()`.
    fn support_for_spatial_index_null(&self) -> bool;
    /// `getSupportForOperators()`.
    fn support_for_operators(&self) -> bool;
    /// `getSupportForOptionalSpatialAttributeWithExistingRows()`.
    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool;
    /// `getSupportForSpatialIndexOrder()`.
    fn support_for_spatial_index_order(&self) -> bool;
    /// `getSupportForSpatialAxisOrder()`.
    fn support_for_spatial_axis_order(&self) -> bool;
    /// `getSupportForBoundaryInclusiveContains()`.
    fn support_for_boundary_inclusive_contains(&self) -> bool;
    /// `getSupportForDistanceBetweenMultiDimensionGeometryInMeters()`.
    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool;
    /// `getSupportForMultipleFulltextIndexes()`.
    fn support_for_multiple_fulltext_indexes(&self) -> bool;
    /// `getSupportForIdenticalIndexes()`.
    fn support_for_identical_indexes(&self) -> bool;
    /// `getSupportForOrderRandom()`.
    fn support_for_order_random(&self) -> bool;
    /// `getSupportForUnsignedBigInt()`.
    fn support_for_unsigned_big_int(&self) -> bool {
        false
    }
    /// `getSupportForInternalCasting()`.
    fn support_for_internal_casting(&self) -> bool;
    /// `getSupportForUTCCasting()`.
    fn support_for_utc_casting(&self) -> bool;
    /// `getSupportForIntegerBooleans()`.
    fn support_for_integer_booleans(&self) -> bool;
    /// `getSupportForAlterLocks()`.
    fn support_for_alter_locks(&self) -> bool;
    /// `getSupportNonUtfCharacters()`.
    fn support_non_utf_characters(&self) -> bool;
    /// `getSupportForTrigramIndex()`.
    fn support_for_trigram_index(&self) -> bool;
    /// `getSupportForPCRERegex()`.
    fn support_for_pcre_regex(&self) -> bool;
    /// `getSupportForPOSIXRegex()`.
    fn support_for_posix_regex(&self) -> bool;
    /// `getSupportForRegex()`.
    fn support_for_regex(&self) -> bool {
        self.support_for_pcre_regex() || self.support_for_posix_regex()
    }
    /// `getSupportForTTLIndexes()`.
    fn support_for_ttl_indexes(&self) -> bool {
        false
    }
    /// `getSupportForTransactionRetries()`.
    fn support_for_transaction_retries(&self) -> bool;
    /// `getSupportForNestedTransactions()`.
    fn support_for_nested_transactions(&self) -> bool;
}
