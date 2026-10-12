//! [`Memory`]: the in-memory adapter.
//!
//! Collections, documents, permissions and unique-index hash tables live in
//! the process, as the PHP adapter keeps them: PHP arrays with PHP's array
//! semantics (insertion order, numeric string keys as integers), so the
//! order documents are found in, the keys documents are stored under and
//! the checks on them behave as in PHP. Transactions keep a journal of
//! inverse operations per nesting level; rolling back runs them backwards.
//!
//! ```
//! # tokio::runtime::Runtime::new()?.block_on(async {
//! use utopia_database::Adapter;
//! use utopia_database::adapter::memory::Memory;
//!
//! let adapter = Memory::new();
//! adapter.set_database("app")?;
//! adapter.create("app").await?;
//! assert!(adapter.exists("app", None).await?);
//! # Ok::<(), utopia_database::Error>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Memory`.

use std::sync::{Mutex, MutexGuard};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};

use super::{Adapter, FindOptions, State};
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::Query;
use crate::value::{Array, Value};

mod documents;
mod operators;
mod query;
mod schema;
mod store;

/// The in-memory adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Memory`.
pub struct Memory {
    state: State,
    store: Mutex<Store>,
}

/// An inverse operation of the transaction journal.
type Inverse = Box<dyn FnOnce(&mut Store) + Send>;

/// The PHP adapter's properties.
#[derive(Default)]
pub(crate) struct Store {
    /// `$databases`: database => collection => storage key.
    databases: Array,
    /// `$data`: storage key => `attributes`, `indexes`, `documents`, `sequence`.
    data: Array,
    /// `$permissions`: storage key => list of `document`, `type`, `permission`, `tenant`.
    permissions: Array,
    /// `$permissionsByDocument`: key => document => type => permission => true.
    by_document: Array,
    /// `$permissionsByPermission`: key => type => tenant bucket => permission => document => true.
    by_permission: Array,
    /// `$uniqueIndexHashes`: key => index => serialized signature => document key.
    hashes: Array,
    /// `$journals`: one frame of inverses per transaction level.
    journals: Vec<Vec<Inverse>>,
    /// `$supportForAttributes`.
    support_for_attributes: bool,
}

/// The adapter settings a call reads (`$this->getDatabase()`, ...).
#[derive(Clone)]
pub(crate) struct Ctx {
    database: String,
    namespace: String,
    shared: bool,
    tenant: Value,
    depth: i64,
    skip_duplicates: bool,
    authorization: std::sync::Arc<crate::validator::authorization::Authorization>,
}

impl Default for Memory {
    fn default() -> Self {
        Self::new()
    }
}

impl Memory {
    /// An empty in-memory database server.
    ///
    /// PHP: `new Memory()`.
    pub fn new() -> Memory {
        Memory {
            state: State::default(),
            store: Mutex::new(Store { support_for_attributes: true, ..Store::default() }),
        }
    }

    /// `getDriver()`: `"memory"`.
    ///
    /// PHP: `Utopia\Database\Adapter\Memory::getDriver`.
    pub fn driver(&self) -> &'static str {
        "memory"
    }

    /// The stored collections, by storage key (`<database>.<namespace>_<id>`):
    /// each with its `attributes`, `indexes`, `documents` (rows by document
    /// key) and `sequence`.
    ///
    /// PHP: `Utopia\Database\Adapter\Memory::$data` (protected; the e2e
    /// tests read it by reflection).
    pub fn data(&self) -> Array {
        self.store().data.clone()
    }

    fn store(&self) -> MutexGuard<'_, Store> {
        self.store.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn ctx(&self) -> Ctx {
        let s = self.state.lock();
        Ctx {
            database: s.database.clone(),
            namespace: s.namespace.clone(),
            shared: s.shared_tables,
            tenant: s.tenant.clone(),
            depth: s.in_transaction,
            skip_duplicates: s.skip_duplicates,
            authorization: s.authorization.clone(),
        }
    }
}

impl Store {
    /// `journal($inverse)`: kept only inside a transaction.
    fn journal(&mut self, ctx: &Ctx, inverse: impl FnOnce(&mut Store) + Send + 'static) {
        if ctx.depth == 0 {
            return;
        }
        if let Some(frame) = self.journals.last_mut() {
            frame.push(Box::new(inverse));
        }
    }
}

/// `filter($value)`: keeps `[A-Za-z0-9_-]`.
pub(crate) fn filter(value: &str) -> String {
    value.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect()
}

#[async_trait]
impl Adapter for Memory {
    fn state(&self) -> &State {
        &self.state
    }

    fn set_timeout(&self, _milliseconds: i64, _event: &str) -> Result<()> {
        Ok(())
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.store().journals.push(Vec::new());
        self.state.lock().in_transaction += 1;
        Ok(true)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        let depth = self.state.lock().in_transaction;
        if depth == 0 {
            return Ok(false);
        }
        let mut store = self.store();
        let frame = store.journals.pop();
        let depth = {
            let mut s = self.state.lock();
            s.in_transaction -= 1;
            s.in_transaction
        };
        if let Some(frame) = frame
            && !frame.is_empty()
            && depth > 0
        {
            let mut outer = store.journals.pop().unwrap_or_default();
            outer.extend(frame);
            store.journals.push(outer);
        }
        Ok(true)
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        let depth = self.state.lock().in_transaction;
        if depth == 0 {
            return Ok(false);
        }
        let mut store = self.store();
        if let Some(frame) = store.journals.pop() {
            for inverse in frame.into_iter().rev() {
                inverse(&mut store);
            }
        }
        self.state.lock().in_transaction -= 1;
        Ok(true)
    }

    async fn ping(&self) -> Result<bool> {
        Ok(true)
    }

    async fn reconnect(&self) -> Result<()> {
        Ok(())
    }

    async fn connection_id(&self) -> Result<String> {
        Ok("0".to_owned())
    }

    async fn create(&self, name: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create(&ctx, name);
        Ok(true)
    }

    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        Ok(self.store().exists(database, collection))
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.store().list()
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete(&ctx, name);
        Ok(true)
    }

    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_collection(&ctx, name, attributes, indexes)
    }

    async fn delete_collection(&self, id: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_collection(&ctx, id);
        Ok(true)
    }

    async fn analyze_collection(&self, _collection: &str) -> Result<bool> {
        Ok(false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        let ctx = self.ctx();
        Ok(self.store().size_of_collection(&ctx, collection))
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        let ctx = self.ctx();
        Ok(self.store().size_of_collection(&ctx, collection))
    }

    async fn schema_attributes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn schema_indexes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

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
        let ctx = self.ctx();
        self.store().create_attribute(&ctx, collection, id, kind, size, signed, array, required)
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Array]) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_attributes(&ctx, collection, attributes)
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
        let ctx = self.ctx();
        self.store().update_attribute(&ctx, collection, id, kind, size, signed, array, new_key, required)
    }

    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        let ctx = self.ctx();
        Ok(self.store().delete_attribute(&ctx, collection, id))
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().rename_attribute(&ctx, collection, old, new)
    }

    async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_relationship(&ctx, collection, related_collection, kind, two_way, id, two_way_key)
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
        let ctx = self.ctx();
        self.store().update_relationship(
            &ctx,
            collection,
            related_collection,
            kind,
            two_way,
            key,
            two_way_key,
            side,
            new_key,
            new_two_way_key,
        )
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
        let ctx = self.ctx();
        self.store().delete_relationship(&ctx, collection, related_collection, kind, two_way, key, two_way_key, side)
    }

    async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
        _attribute_types: &[(String, String)],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_index(&ctx, collection, id, kind, attributes, lengths, orders)
    }

    async fn create_index_arrays(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &Array,
        orders: &Array,
        _attribute_types: &[(String, String)],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_index_with(&ctx, collection, id, kind, attributes, lengths.clone(), orders.clone())
    }

    async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_index(&ctx, collection, id);
        Ok(true)
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().rename_index(&ctx, collection, old, new)
    }

    async fn get_document(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        _for_update: bool,
    ) -> Result<Document> {
        let ctx = self.ctx();
        self.store().get_document(&ctx, collection, id, queries)
    }

    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document> {
        let ctx = self.ctx();
        self.store().create_document(&ctx, collection, document)
    }

    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>> {
        let ctx = self.ctx();
        let mut store = self.store();
        let mut created = Vec::with_capacity(documents.len());
        for document in documents {
            created.push(store.create_document(&ctx, collection, document)?);
        }
        Ok(created)
    }

    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let ctx = self.ctx();
        self.store().update_document(&ctx, collection, id, document, skip_permissions)
    }

    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64> {
        let ctx = self.ctx();
        self.store().update_documents(&ctx, collection, updates, documents)
    }

    async fn upsert_documents(
        &self,
        _collection: &Document,
        _attribute: &str,
        _changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        Err(Error::database("Upsert is not implemented in the Memory adapter"))
    }

    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>> {
        let ctx = self.ctx();
        Ok(self.store().get_sequences(&ctx, collection, documents))
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_document(&ctx, collection, id)
    }

    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64> {
        let ctx = self.ctx();
        self.store().delete_documents(&ctx, collection, sequences, permission_ids)
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
        let ctx = self.ctx();
        self.store().increase(&ctx, collection, id, attribute, value, updated_at, min, max)
    }

    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>> {
        let ctx = self.ctx();
        self.store().find(&ctx, collection, queries, &options)
    }

    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value> {
        let ctx = self.ctx();
        self.store().sum(&ctx, collection, attribute, queries, max)
    }

    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let ctx = self.ctx();
        self.store().count(&ctx, collection, queries, max)
    }

    fn casting_before(&self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn casting_after(&self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn set_utc_datetime(&self, value: &str) -> Result<Value> {
        Ok(Value::Str(value.into()))
    }

    fn decode_point(&self, _wkb: &[u8]) -> Result<Value> {
        Err(Error::database("Spatial types are not implemented in the Memory adapter"))
    }

    fn decode_linestring(&self, _wkb: &[u8]) -> Result<Value> {
        Err(Error::database("Spatial types are not implemented in the Memory adapter"))
    }

    fn decode_polygon(&self, _wkb: &[u8]) -> Result<Value> {
        Err(Error::database("Spatial types are not implemented in the Memory adapter"))
    }

    fn tenant_query(&self, _collection: &str, _alias: &str) -> String {
        String::new()
    }

    fn limit_for_string(&self) -> i64 {
        4294967295
    }

    fn limit_for_int(&self) -> i64 {
        4294967295
    }

    fn limit_for_big_int(&self) -> i64 {
        Database::MAX_BIG_INT
    }

    fn limit_for_attributes(&self) -> i64 {
        1017
    }

    fn limit_for_indexes(&self) -> i64 {
        64
    }

    fn max_index_length(&self) -> i64 {
        1024
    }

    fn max_varchar_length(&self) -> i64 {
        16381
    }

    fn max_uid_length(&self) -> i64 {
        255
    }

    fn min_date_time(&self) -> DateTime {
        DateTime::new(b"0001-01-01 00:00:00", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("a valid date"))
    }

    fn id_attribute_type(&self) -> &'static str {
        Database::VAR_INTEGER
    }

    fn count_of_attributes(&self, collection: &Document) -> i64 {
        count_of(collection, "attributes") + self.count_of_default_attributes()
    }

    fn count_of_indexes(&self, collection: &Document) -> i64 {
        count_of(collection, "indexes") + self.count_of_default_indexes()
    }

    fn count_of_default_attributes(&self) -> i64 {
        Database::internal_attributes_const().len() as i64
    }

    fn count_of_default_indexes(&self) -> i64 {
        Database::INTERNAL_INDEXES.len() as i64
    }

    fn document_size_limit(&self) -> i64 {
        0
    }

    fn attribute_width(&self, _collection: &Document) -> Result<i64> {
        Ok(0)
    }

    fn keywords(&self) -> &'static [&'static str] {
        &[]
    }

    fn internal_indexes_keys(&self) -> &'static [&'static str] {
        &[]
    }

    fn support_for_schemas(&self) -> bool {
        true
    }
    fn support_for_attributes(&self) -> bool {
        self.store().support_for_attributes
    }
    fn set_support_for_attributes(&self, support: bool) -> bool {
        self.store().support_for_attributes = support;
        support
    }
    fn support_for_schema_attributes(&self) -> bool {
        false
    }
    fn support_for_schema_indexes(&self) -> bool {
        false
    }
    fn support_for_index(&self) -> bool {
        true
    }
    fn support_for_index_array(&self) -> bool {
        false
    }
    fn support_for_cast_index_array(&self) -> bool {
        false
    }
    fn support_for_unique_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_wildcard_index(&self) -> bool {
        false
    }
    fn support_for_casting(&self) -> bool {
        true
    }
    fn support_for_query_contains(&self) -> bool {
        true
    }
    fn support_for_timeouts(&self) -> bool {
        false
    }
    fn support_for_relationships(&self) -> bool {
        true
    }
    fn support_for_update_lock(&self) -> bool {
        false
    }
    fn support_for_batch_operations(&self) -> bool {
        true
    }
    fn support_for_attribute_resizing(&self) -> bool {
        true
    }
    fn support_for_get_connection_id(&self) -> bool {
        false
    }
    fn support_for_upserts(&self) -> bool {
        false
    }
    fn support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn support_for_vectors(&self) -> bool {
        false
    }
    fn support_for_cache_skip_on_failure(&self) -> bool {
        false
    }
    fn support_for_caching(&self) -> bool {
        true
    }
    fn support_for_reconnection(&self) -> bool {
        false
    }
    fn support_for_hostname(&self) -> bool {
        false
    }
    fn support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn support_for_spatial_attributes(&self) -> bool {
        false
    }
    fn support_for_object(&self) -> bool {
        true
    }
    fn support_for_object_indexes(&self) -> bool {
        true
    }
    fn support_for_spatial_index_null(&self) -> bool {
        false
    }
    fn support_for_operators(&self) -> bool {
        true
    }
    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        false
    }
    fn support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn support_for_boundary_inclusive_contains(&self) -> bool {
        false
    }
    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        false
    }
    fn support_for_multiple_fulltext_indexes(&self) -> bool {
        false
    }
    fn support_for_identical_indexes(&self) -> bool {
        false
    }
    fn support_for_order_random(&self) -> bool {
        true
    }
    fn support_for_internal_casting(&self) -> bool {
        false
    }
    fn support_for_utc_casting(&self) -> bool {
        false
    }
    fn support_for_integer_booleans(&self) -> bool {
        false
    }
    fn support_for_alter_locks(&self) -> bool {
        false
    }
    fn support_non_utf_characters(&self) -> bool {
        false
    }
    fn support_for_trigram_index(&self) -> bool {
        false
    }
    fn support_for_pcre_regex(&self) -> bool {
        true
    }
    fn support_for_posix_regex(&self) -> bool {
        false
    }
    fn support_for_transaction_retries(&self) -> bool {
        false
    }
    fn support_for_nested_transactions(&self) -> bool {
        true
    }
}

/// `\count($collection->getAttribute($key, []))`.
fn count_of(collection: &Document, key: &str) -> i64 {
    match collection.get(key) {
        Some(Value::Array(a)) => a.len() as i64,
        Some(Value::Ext(crate::value::Ext::Document(d))) => d.len() as i64,
        _ => 0,
    }
}
