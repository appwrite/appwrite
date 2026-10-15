//! Test support: [`Spy`], an adapter that hands every call to another one
//! (the Memory adapter, usually) and lets a test count, watch or change some
//! of them, as PHP's tests subclass `Adapter\Memory` (`CountingMemory`,
//! `RelationshipMemory`, `InterleavingMemory` and the anonymous subclasses of
//! the unit tests).
//!
//! The spy shares the inner adapter's [`State`], so every setting the
//! database makes on it reaches the inner adapter.

#![allow(dead_code)]

use std::any::Any;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_query::schema::{ColumnType, IndexType};

use super::METADATA;
use crate::adapter::{
    Adapter, Casting, Connection, Feature, Features, Find, Limits, QueryBuilder, RawQuery, Relationships, Schemaless,
    Spatial, State, Timeouts, TransactionStrategy, Upserts,
};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::index::Index;
use crate::query::Query;
use crate::schema;
use crate::value::Value;

type Watch<A> = Arc<dyn Fn(A) + Send + Sync>;

/// What a [`Spy`] does around the calls it watches.
#[derive(Default)]
pub(crate) struct Watches {
    /// Called with each `find()`'s collection and queries, before it runs.
    pub(crate) find: Option<Watch<(Collection, Vec<Query>)>>,
    /// Called with each `getDocument()`'s collection and id, after it ran.
    pub(crate) get_document: Option<Watch<(Collection, String)>>,
    /// Called with each `updateDocuments()`'s collection and documents,
    /// before it runs.
    pub(crate) update_documents: Option<Watch<(Collection, Vec<Document>)>>,
    /// Given the transaction depth before a commit; the error it returns
    /// fails the commit instead.
    pub(crate) commit: Option<Arc<dyn Fn(i64) -> Option<Error> + Send + Sync>>,
}

/// An adapter handing every call to `inner`. See the [module docs](self).
pub(crate) struct Spy {
    /// The adapter calls go to.
    pub(crate) inner: Arc<dyn Adapter>,
    /// Capabilities the spy does not report.
    without: Mutex<Vec<Capability>>,
    /// Whether a transaction opened inside another is a savepoint; without,
    /// it runs in the outer one (`RelationshipMemory(savepoints: false)`).
    savepoints: std::sync::atomic::AtomicBool,
    /// What the spy does around the calls it watches.
    pub(crate) watches: Mutex<Watches>,
    /// Reads of the metadata collection (`getDocument()` and `find()`).
    pub(crate) metadata_reads: AtomicUsize,
    /// Reads of other documents by id.
    pub(crate) document_reads: AtomicUsize,
    /// Finds on other collections.
    pub(crate) finds: AtomicUsize,
}

impl Spy {
    /// A spy over `inner`.
    pub(crate) fn new(inner: Arc<dyn Adapter>) -> Arc<Spy> {
        Arc::new(Spy {
            inner,
            without: Mutex::new(Vec::new()),
            savepoints: std::sync::atomic::AtomicBool::new(true),
            watches: Mutex::new(Watches::default()),
            metadata_reads: AtomicUsize::new(0),
            document_reads: AtomicUsize::new(0),
            finds: AtomicUsize::new(0),
        })
    }

    /// A spy over a new Memory adapter (`CountingMemory`).
    pub(crate) fn memory() -> Arc<Spy> {
        Spy::new(Arc::new(crate::adapter::memory::Memory::new()))
    }

    /// Without savepoints: no `TransactionNested`, and a transaction opened
    /// inside another runs in it (`RelationshipMemory(savepoints: false)`).
    pub(crate) fn without_savepoints(self: &Arc<Self>) -> Arc<Self> {
        self.savepoints.store(false, Ordering::SeqCst);
        self.without.lock().unwrap().push(Capability::TransactionNested);
        self.clone()
    }

    /// Stops reporting `capability`.
    pub(crate) fn without(&self, capability: Capability) -> &Self {
        self.without.lock().unwrap().push(capability);
        self
    }

    /// Changes what the spy watches.
    pub(crate) fn watch(&self, change: impl FnOnce(&mut Watches)) {
        change(&mut self.watches.lock().unwrap());
    }

    /// Clears the tallies (`reset()`).
    pub(crate) fn reset(&self) {
        self.metadata_reads.store(0, Ordering::SeqCst);
        self.document_reads.store(0, Ordering::SeqCst);
        self.finds.store(0, Ordering::SeqCst);
    }

    fn tally(&self, collection: &Collection, other: &AtomicUsize) {
        if collection.id() == METADATA {
            self.metadata_reads.fetch_add(1, Ordering::SeqCst);
        } else {
            other.fetch_add(1, Ordering::SeqCst);
        }
    }
}

#[async_trait]
impl Adapter for Spy {
    fn state(&self) -> &State {
        self.inner.state()
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        let mut capabilities = self.inner.capabilities();
        for capability in self.without.lock().unwrap().iter() {
            capabilities = capabilities.without(*capability);
        }
        capabilities
    }

    fn supports(&self, capability: Capability) -> bool {
        !self.without.lock().unwrap().contains(&capability) && self.inner.supports(capability)
    }

    fn limits(&self) -> Arc<Limits> {
        self.inner.limits()
    }

    fn features(&self) -> Features {
        self.inner.features()
    }

    fn has_feature(&self, feature: Feature) -> bool {
        self.inner.has_feature(feature)
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

    async fn start_transaction(&self) -> Result<bool> {
        self.inner.start_transaction().await
    }

    /// Without savepoints, a transaction opened inside another runs its
    /// callback in the open one (`RelationshipMemory::withTransaction()`).
    async fn transaction_strategy(&self) -> Result<TransactionStrategy> {
        if !self.savepoints.load(Ordering::SeqCst) && self.inner.in_transaction() {
            return Ok(TransactionStrategy::Direct);
        }
        self.inner.transaction_strategy().await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        let commit = self.watches.lock().unwrap().commit.clone();
        if let Some(commit) = commit
            && let Some(error) = commit(self.state().lock().in_transaction)
        {
            return Err(error);
        }
        self.inner.commit_transaction().await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.inner.rollback_transaction().await
    }

    fn is_retryable(&self, failure: &Error) -> bool {
        self.inner.is_retryable(failure)
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
        self.inner.create_document(collection, document).await
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.inner.create_documents(collection, documents).await
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        self.tally(collection, &self.document_reads);
        let document = self.inner.get_document(collection, id, queries, for_update).await;
        let watch = self.watches.lock().unwrap().get_document.clone();
        if let Some(watch) = watch {
            watch((collection.clone(), id.to_owned()));
        }
        document
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        self.inner.update_document(collection, id, document, skip_permissions).await
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        let watch = self.watches.lock().unwrap().update_documents.clone();
        if let Some(watch) = watch {
            watch((collection.clone(), documents.clone()));
        }
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
        self.inner.increase_document_attribute(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        self.inner.delete_document(collection, id).await
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        self.inner.delete_documents(collection, sequences, permission_ids).await
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        self.tally(collection, &self.finds);
        let watch = self.watches.lock().unwrap().find.clone();
        if let Some(watch) = watch {
            watch((collection.clone(), queries.clone()));
        }
        self.inner.find(collection, queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        self.inner.count(collection, queries, max).await
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
}
