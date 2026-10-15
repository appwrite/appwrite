//! [`Memory`]: the in-memory adapter.
//!
//! Collections, documents, permissions and unique-index hash tables live in
//! the process, as the PHP adapter keeps them: PHP arrays with PHP's array
//! semantics (insertion order, numeric string keys as integers), so the
//! order documents are found in, the keys documents are stored under and
//! the checks on them behave as in PHP. Transactions keep a journal of
//! inverse operations per nesting level; rolling back runs them backwards,
//! and committing a nested level hands its journal to the level around it.
//!
//! It supports schemas, full-text indexes (searched word by word, as
//! MariaDB's natural-language mode), attribute resizing, objects and object
//! indexes, operators, random order, nested transactions and caching, and
//! stores relationships ([`Relationships`]); it has no spatial values,
//! vectors or upserts.
//!
//! ```
//! # tokio::runtime::Runtime::new()?.block_on(async {
//! use utopia_database::Adapter;
//! use utopia_database::adapter::memory::Memory;
//!
//! let adapter = Memory::new();
//! adapter.set_database("app");
//! adapter.create("app").await?;
//! assert!(adapter.exists("app").await?);
//! # Ok::<(), utopia_database::Error>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Memory`.

use std::any::Any;
use std::sync::{Arc, Mutex, MutexGuard};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};
use utopia_query::schema::ColumnType;

use super::{Adapter, Find, Limits, MAX_DATETIME, Relationships, State};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::index::Index;
use crate::operator::Operator;
use crate::query::Query;
use crate::relationship::{Relationship, RelationshipSide, Update};
use crate::validator::authorization::Authorization;
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
    /// `$permissionsByDocument`: key => tenant bucket => document => type => permission => true.
    by_document: Array,
    /// `$permissionsByPermission`: key => type => tenant bucket => permission => document => true.
    by_permission: Array,
    /// `$uniqueIndexHashes`: key => index => serialized signature => document key.
    hashes: Array,
    /// `$journals`: one frame of inverses per transaction level.
    journals: Vec<Vec<Inverse>>,
}

/// The adapter settings a call reads (`$this->getDatabase()`, ...).
#[derive(Clone)]
pub(crate) struct Ctx {
    database: String,
    namespace: String,
    shared: bool,
    tenant: Value,
    depth: i64,
    ignoring_duplicates: bool,
    authorization: Arc<Authorization>,
}

impl Default for Memory {
    fn default() -> Self {
        Self::new()
    }
}

impl std::fmt::Debug for Memory {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Memory").finish_non_exhaustive()
    }
}

impl Memory {
    /// An empty in-memory database server (`new Memory()`).
    pub fn new() -> Memory {
        Memory { state: State::default(), store: Mutex::new(Store::default()) }
    }

    fn store(&self) -> MutexGuard<'_, Store> {
        self.store.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn ctx(&self) -> Ctx {
        let (database, namespace, shared, depth, authorization) = {
            let s = self.state.lock();
            (s.database.clone(), s.namespace.clone(), s.shared_tables, s.in_transaction, s.authorization.clone())
        };
        Ctx {
            database,
            namespace,
            shared,
            tenant: self.tenant(),
            depth,
            ignoring_duplicates: self.state.ignoring_duplicates().get(),
            authorization,
        }
    }

    /// The storage key of a collection (`key()`):
    /// `<database>.<namespace>_<collection>`.
    #[doc(hidden)]
    pub fn storage_key(&self, collection: &str) -> String {
        store::key_text(Store::key(&self.ctx(), collection).as_ref())
    }

    /// The stored collections, by storage key: each with its `attributes`,
    /// `indexes`, `documents` (rows by document key) and `sequence`
    /// (`$data`, which PHP's tests read through a subclass).
    #[doc(hidden)]
    pub fn data(&self) -> Array {
        self.store().data.clone()
    }

    /// The unique-index hash tables, by storage key, index and serialized
    /// signature (`$uniqueIndexHashes`).
    #[doc(hidden)]
    pub fn unique_index_hashes(&self) -> Array {
        self.store().hashes.clone()
    }

    /// The flat permission entries, by storage key (`$permissions`).
    #[doc(hidden)]
    pub fn permission_entries(&self) -> Array {
        self.store().permissions.clone()
    }

    /// Every property of the PHP adapter holding data, by PHP name:
    /// `databases`, `data`, `permissions`, `permissionsByDocument`,
    /// `permissionsByPermission`, `uniqueIndexHashes`.
    #[doc(hidden)]
    pub fn properties(&self) -> Vec<(&'static str, Array)> {
        let store = self.store();
        vec![
            ("databases", store.databases.clone()),
            ("data", store.data.clone()),
            ("permissions", store.permissions.clone()),
            ("permissionsByDocument", store.by_document.clone()),
            ("permissionsByPermission", store.by_permission.clone()),
            ("uniqueIndexHashes", store.hashes.clone()),
        ]
    }

    /// `applyOperator($current, $operator)` (protected in PHP; its tests
    /// reach it through a subclass).
    #[doc(hidden)]
    pub fn apply_operator(&self, current: &Value, operator: &Operator) -> Result<Value> {
        operators::apply_operator(current, operator)
    }

    fn build_limits() -> Limits {
        let utc = TzInfo::utc();
        let date = |text: &str| DateTime::new(text.as_bytes(), None, &utc).expect("a valid datetime literal");
        Limits {
            string: 4_294_967_295,
            varchar: 16_381,
            integer: 4_294_967_295,
            big_integer: crate::database::MAX_BIG_INT,
            attributes: 1_017,
            indexes: 64,
            default_attributes: crate::database::internal_attributes(true).len() as i64,
            default_indexes: crate::database::INTERNAL_INDEXES.len() as i64,
            index_length: 1_024,
            uid_length: 255,
            document_size: 0,
            min_date_time: date("0001-01-01 00:00:00"),
            max_date_time: date(MAX_DATETIME),
            id_type: ColumnType::Integer,
            keywords: Vec::new(),
            internal_index_keys: Vec::new(),
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
    super::filter(value)
}

#[async_trait]
impl Adapter for Memory {
    fn state(&self) -> &State {
        &self.state
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        Capabilities::of(&[
            Capability::IndexKey,
            Capability::IndexArray,
            Capability::IndexUnique,
            Capability::Schemas,
            Capability::IndexFulltext,
            Capability::AttributeResizing,
            Capability::Objects,
            Capability::IndexObject,
            Capability::Operators,
            Capability::OrderRandom,
            Capability::DefinedAttributes,
            Capability::TransactionNested,
            Capability::Caching,
        ])
    }

    fn limits(&self) -> Arc<Limits> {
        let mut state = self.state.lock();
        state.limits.get_or_insert_with(|| Arc::new(Memory::build_limits())).clone()
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        Some(self)
    }

    async fn start_transaction(&self) -> Result<bool> {
        let mut store = self.store();
        store.journals.push(Vec::new());
        self.state.lock().in_transaction += 1;
        Ok(true)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        let mut store = self.store();
        let depth = {
            let mut s = self.state.lock();
            if s.in_transaction == 0 {
                return Ok(false);
            }
            s.in_transaction -= 1;
            s.in_transaction
        };
        let frame = store.journals.pop();
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
        let mut store = self.store();
        if self.state.lock().in_transaction == 0 {
            return Ok(false);
        }
        if let Some(frame) = store.journals.pop() {
            for inverse in frame.into_iter().rev() {
                inverse(&mut store);
            }
        }
        self.state.lock().in_transaction -= 1;
        Ok(true)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create(&ctx, name);
        Ok(true)
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().update(&ctx, name, new)
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        Ok(self.store().exists(database))
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        Ok(self.store().collection_exists(database, collection))
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.store().list()
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete(&ctx, name);
        Ok(true)
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_collection(&ctx, collection, attributes, indexes)
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_collection(&ctx, collection);
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
        self.size_of_collection(collection).await
    }

    fn attribute_width(&self, _collection: &Collection) -> Result<i64> {
        Ok(0)
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_attribute(&ctx, collection, attribute)
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        let ctx = self.ctx();
        let mut store = self.store();
        for attribute in attributes {
            store.create_attribute(&ctx, collection, attribute)?;
        }
        Ok(true)
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        let ctx = self.ctx();
        self.store().update_attribute(&ctx, collection, key, attribute)
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        let ctx = self.ctx();
        Ok(self.store().delete_attribute(&ctx, collection, key))
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().rename_attribute(&ctx, collection, old, new)
    }

    async fn schema_attributes(&self, _collection: &str) -> Result<Vec<crate::schema::Column>> {
        Ok(Vec::new())
    }

    async fn schema_indexes(&self, _collection: &str) -> Result<Vec<crate::schema::Index>> {
        Ok(Vec::new())
    }

    fn column_type(&self, _attribute: &Attribute) -> Result<Option<String>> {
        Ok(None)
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        _attribute_types: &[(String, ColumnType)],
        _collation: &[(String, String)],
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_index(&ctx, collection, index)
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_index(&ctx, collection, key);
        Ok(true)
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().rename_index(&ctx, collection, old, new)
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        let ctx = self.ctx();
        self.store().create_document(&ctx, collection, document)
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        let ctx = self.ctx();
        self.store().create_documents(&ctx, collection, documents)
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        _for_update: bool,
    ) -> Result<Document> {
        let ctx = self.ctx();
        self.store().get_document(&ctx, collection, id, &queries)
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let ctx = self.ctx();
        self.store().update_document(&ctx, collection, id, document, skip_permissions)
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        _skip_permissions: &[String],
    ) -> Result<i64> {
        let ctx = self.ctx();
        self.store().update_documents(&ctx, collection, updates, &documents)
    }

    async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().increase(&ctx, collection, id, attribute, &value, updated_at, min.as_ref(), max.as_ref())
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_document(&ctx, collection, id)
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        let ctx = self.ctx();
        self.store().delete_documents(&ctx, collection, &sequences, &permission_ids)
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        let ctx = self.ctx();
        self.store().find(&ctx, collection, &queries, &options)
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        let ctx = self.ctx();
        self.store().count(&ctx, collection, &queries, max)
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        let ctx = self.ctx();
        self.store().sum(&ctx, collection, attribute, &queries, max)
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        let ctx = self.ctx();
        Ok(self.store().get_sequences(&ctx, collection, documents))
    }
}

#[async_trait]
impl Relationships for Memory {
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        let ctx = self.ctx();
        self.store().create_relationship(&ctx, collection, relationship);
        Ok(true)
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &Update,
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().update_relationship(&ctx, collection, relationship, side, update);
        Ok(true)
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        let ctx = self.ctx();
        self.store().delete_relationship(&ctx, collection, relationship, side);
        Ok(true)
    }
}
