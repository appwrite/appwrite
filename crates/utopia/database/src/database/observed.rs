//! [`Observed`]: an adapter wrapping another (the Memory adapter, as PHP's
//! tests subclass `Adapter\Memory`), recording the engine calls and running
//! hooks around them.
//!
//! PHP: the test adapters `Tests\Unit\Support\CountingMemory`,
//! `InterleavingMemory` and `Tests\Unit\Cache\ObservedMemory`, which
//! extend `Adapter\Memory` to count reads, pause a commit or interleave a
//! write after a definition read.

use std::any::Any;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use futures_util::future::BoxFuture;
use utopia_query::schema::{ColumnType, IndexType};

use crate::adapter::{
    Adapter, Casting, Connection, Find, Limits, QueryBuilder, RawQuery, Relationships, Schemaless, Spatial, State,
    Timeouts, TransactionBody, TransactionStrategy, Upserts,
};
use crate::attribute::Attribute;
use crate::capability::Capabilities;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::index::Index;
use crate::query::Query;
use crate::schema;
use crate::value::Value;

/// An engine call [`Observed`] saw.
#[derive(Debug, Clone, PartialEq, Eq)]
pub(crate) enum Op {
    /// `startTransaction()` (before it runs).
    Start,
    /// `commitTransaction()` (before it runs).
    Commit,
    /// `rollbackTransaction()` (before it runs).
    Rollback,
    /// `getDocument()` of `collection` (after it ran).
    GetDocument { collection: String, id: String },
    /// `find()` of `collection` (after it ran).
    Find { collection: String },
    /// `count()` of `collection` (after it ran).
    Count { collection: String },
    /// `createDocument(s)()` in `collection` (before it runs).
    Create { collection: String },
    /// `updateDocument(s)()` in `collection` (before it runs).
    Update { collection: String },
    /// `deleteDocument(s)()` in `collection` (before it runs).
    Delete { collection: String },
}

impl Op {
    /// Whether the call reads the metadata collection.
    pub(crate) fn is_metadata_read(&self) -> bool {
        matches!(self, Op::GetDocument { collection, .. } | Op::Find { collection } if collection == super::METADATA)
    }
}

/// A hook: given a call, a future to await around it (its error fails the
/// call), or `None` to stay registered for a later call. A hook that
/// answers is removed.
pub(crate) type ObservedHook = Box<dyn FnMut(&Op) -> Option<BoxFuture<'static, Result<()>>> + Send>;

/// An adapter recording every call to `inner` (see [`Op`]) and running the
/// registered hooks: before transaction and write calls, after reads.
pub(crate) struct Observed {
    inner: Arc<dyn Adapter>,
    ops: Mutex<Vec<Op>>,
    hooks: Mutex<Vec<ObservedHook>>,
}

impl Observed {
    /// `inner`, observed.
    pub(crate) fn new(inner: Arc<dyn Adapter>) -> Arc<Self> {
        Arc::new(Observed { inner, ops: Mutex::new(Vec::new()), hooks: Mutex::new(Vec::new()) })
    }

    /// The Memory adapter, observed.
    pub(crate) fn memory() -> Arc<Self> {
        Self::new(Arc::new(crate::adapter::memory::Memory::new()))
    }

    /// The calls seen so far.
    pub(crate) fn ops(&self) -> Vec<Op> {
        self.ops.lock().unwrap().clone()
    }

    /// How many calls match `filter`.
    pub(crate) fn count(&self, filter: impl Fn(&Op) -> bool) -> usize {
        self.ops.lock().unwrap().iter().filter(|op| filter(op)).count()
    }

    /// Forgets the calls seen (`reset()`).
    pub(crate) fn reset(&self) {
        self.ops.lock().unwrap().clear();
    }

    /// Registers a hook, run once on the first call it answers.
    pub(crate) fn once(&self, hook: impl FnMut(&Op) -> Option<BoxFuture<'static, Result<()>>> + Send + 'static) {
        self.hooks.lock().unwrap().push(Box::new(hook));
    }

    /// Records `op` and runs the first hook that answers it.
    async fn observe(&self, op: Op) -> Result<()> {
        self.ops.lock().unwrap().push(op.clone());
        let future = {
            let mut hooks = self.hooks.lock().unwrap();
            let mut found = None;
            for (i, hook) in hooks.iter_mut().enumerate() {
                if let Some(future) = hook(&op) {
                    found = Some((i, future));
                    break;
                }
            }
            found.map(|(i, future)| {
                drop(hooks.remove(i));
                future
            })
        };
        match future {
            Some(future) => future.await,
            None => Ok(()),
        }
    }
}

#[async_trait]
impl Adapter for Observed {
    fn state(&self) -> &State {
        self.inner.state()
    }
    fn as_any(&self) -> &dyn Any {
        self.inner.as_any()
    }
    fn capabilities(&self) -> Capabilities {
        self.inner.capabilities()
    }
    fn limits(&self) -> Arc<Limits> {
        self.inner.limits()
    }
    fn casting(&self) -> Option<&dyn Casting> {
        self.inner.casting()
    }
    fn connection(&self) -> Option<&dyn Connection> {
        self.inner.connection()
    }
    fn query_builder(&self) -> Option<&dyn QueryBuilder> {
        self.inner.query_builder()
    }
    fn raw_query(&self) -> Option<&dyn RawQuery> {
        self.inner.raw_query()
    }
    fn relationships(&self) -> Option<&dyn Relationships> {
        self.inner.relationships()
    }
    fn schemaless(&self) -> Option<&dyn Schemaless> {
        self.inner.schemaless()
    }
    fn spatial(&self) -> Option<&dyn Spatial> {
        self.inner.spatial()
    }
    fn timeouts(&self) -> Option<&dyn Timeouts> {
        self.inner.timeouts()
    }
    fn upserts(&self) -> Option<&dyn Upserts> {
        self.inner.upserts()
    }
    fn set_authorization(&self, authorization: Arc<crate::validator::authorization::Authorization>) {
        self.inner.set_authorization(authorization)
    }
    fn set_shared_tables(&self, shared_tables: bool) {
        self.inner.set_shared_tables(shared_tables)
    }
    fn set_tenant(&self, tenant: Value) {
        self.inner.set_tenant(tenant)
    }
    fn tenant(&self) -> Value {
        self.inner.tenant()
    }
    fn set_tenant_per_document(&self, enabled: bool) {
        self.inner.set_tenant_per_document(enabled)
    }
    fn tenant_per_document(&self) -> bool {
        self.inner.tenant_per_document()
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.observe(Op::Start).await?;
        self.inner.start_transaction().await
    }
    async fn commit_transaction(&self) -> Result<bool> {
        self.observe(Op::Commit).await?;
        self.inner.commit_transaction().await
    }
    async fn rollback_transaction(&self) -> Result<bool> {
        self.observe(Op::Rollback).await?;
        self.inner.rollback_transaction().await
    }
    async fn transaction_strategy(&self) -> Result<TransactionStrategy> {
        self.inner.transaction_strategy().await
    }
    fn in_transaction(&self) -> bool {
        self.inner.in_transaction()
    }
    async fn abandon_transaction(&self) {
        self.inner.abandon_transaction().await
    }
    fn is_retryable(&self, failure: &crate::error::Error) -> bool {
        self.inner.is_retryable(failure)
    }
    fn is_transient(&self, error: &crate::error::Error) -> bool {
        self.inner.is_transient(error)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        self.inner.create(name).await
    }
    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        self.inner.update(name, new).await
    }
    async fn exists(&self, database: &str) -> Result<bool> {
        self.inner.exists(database).await
    }
    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        self.inner.collection_exists(database, collection).await
    }
    async fn list(&self) -> Result<Vec<Document>> {
        self.inner.list().await
    }
    async fn delete(&self, name: &str) -> Result<bool> {
        self.inner.delete(name).await
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        self.inner.create_collection(collection, attributes, indexes).await
    }
    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        self.inner.delete_collection(collection).await
    }
    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        self.inner.analyze_collection(collection).await
    }
    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.inner.size_of_collection(collection).await
    }
    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.inner.size_of_collection_on_disk(collection).await
    }
    fn attribute_width(&self, collection: &Collection) -> Result<i64> {
        self.inner.attribute_width(collection)
    }
    fn count_of_attributes(&self, collection: &Collection) -> Result<i64> {
        self.inner.count_of_attributes(collection)
    }
    fn count_of_indexes(&self, collection: &Collection) -> Result<i64> {
        self.inner.count_of_indexes(collection)
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        self.inner.create_attribute(collection, attribute).await
    }
    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        self.inner.create_attributes(collection, attributes).await
    }
    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        self.inner.update_attribute(collection, key, attribute).await
    }
    async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        self.inner.relax_attribute_required(collection, id).await
    }
    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        self.inner.delete_attribute(collection, key).await
    }
    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.inner.rename_attribute(collection, old, new).await
    }
    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        self.inner.schema_attributes(collection).await
    }
    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        self.inner.schema_indexes(collection).await
    }
    fn schema_index_type(&self, kind: IndexType) -> IndexType {
        self.inner.schema_index_type(kind)
    }
    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        self.inner.column_type(attribute)
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        self.inner.create_index(collection, index, attribute_types, collation).await
    }
    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        self.inner.delete_index(collection, key).await
    }
    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.inner.rename_index(collection, old, new).await
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.observe(Op::Create { collection: collection.id().to_owned() }).await?;
        self.inner.create_document(collection, document).await
    }
    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.observe(Op::Create { collection: collection.id().to_owned() }).await?;
        self.inner.create_documents(collection, documents).await
    }
    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        let document = self.inner.get_document(collection, id, queries, for_update).await?;
        self.observe(Op::GetDocument { collection: collection.id().to_owned(), id: id.to_owned() }).await?;
        Ok(document)
    }
    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        self.observe(Op::Update { collection: collection.id().to_owned() }).await?;
        self.inner.update_document(collection, id, document, skip_permissions).await
    }
    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        self.observe(Op::Update { collection: collection.id().to_owned() }).await?;
        self.inner.update_documents(collection, updates, documents, skip_permissions).await
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
        self.observe(Op::Update { collection: collection.id().to_owned() }).await?;
        self.inner.increase_document_attribute(collection, id, attribute, value, updated_at, min, max).await
    }
    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        self.observe(Op::Delete { collection: collection.id().to_owned() }).await?;
        self.inner.delete_document(collection, id).await
    }
    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        self.observe(Op::Delete { collection: collection.id().to_owned() }).await?;
        self.inner.delete_documents(collection, sequences, permission_ids).await
    }
    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        let documents = self.inner.find(collection, queries, options).await?;
        self.observe(Op::Find { collection: collection.id().to_owned() }).await?;
        Ok(documents)
    }
    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        let count = self.inner.count(collection, queries, max).await?;
        self.observe(Op::Count { collection: collection.id().to_owned() }).await?;
        Ok(count)
    }
    async fn sum(&self, collection: &Collection, attribute: &str, queries: Vec<Query>, max: Option<i64>) -> Result<Value> {
        self.inner.sum(collection, attribute, queries, max).await
    }
    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.inner.get_sequences(collection, documents).await
    }
    fn read_concurrency(&self) -> usize {
        self.inner.read_concurrency()
    }
    fn served_by_replica(&self) -> bool {
        self.inner.served_by_replica()
    }
    fn transaction_scope<'a>(
        &'a self,
        body: TransactionBody<'a>,
    ) -> std::result::Result<BoxFuture<'a, Result<()>>, TransactionBody<'a>> {
        self.inner.transaction_scope(body)
    }
}
