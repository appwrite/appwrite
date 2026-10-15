//! [`Spy`]: an adapter over another one (usually
//! [`crate::adapter::memory::Memory`]) that records the engine calls and
//! lets a test act before or after any of them: fail it, count it, change
//! state. PHP's tests subclass `Adapter\Memory` for this
//! (`Tests\Unit\Support\CountingMemory`, `InterleavingMemory`,
//! `VerdictMemory`, the anonymous `extends Memory` classes).
//!
//! The spy shares the inner adapter's [`State`], so the database's settings
//! (database, namespace, tenant, authorization) reach the inner adapter.
//! Its capabilities are the inner adapter's, with those the test adds or
//! removes.

#![allow(dead_code)]

use std::any::Any;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_query::schema::{ColumnType, IndexType};

use crate::adapter::{
    Adapter, Casting, Connection, Feature, Features, Find, Limits, QueryBuilder, RawQuery, Relationships, Schemaless,
    Spatial, State, Timeouts, TransactionStrategy, Upserts,
};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::hook::{Tenancy, Transform, Write};
use crate::index::Index;
use crate::permission::PermissionType;
use crate::profiler::Profiler;
use crate::query::Query;
use crate::schema;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value};

/// When a [`Spy`] hook runs.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Stage {
    /// Before the inner adapter is called; an error fails the call.
    Before,
    /// After the inner adapter answered successfully; an error fails the
    /// call (the inner call has happened).
    After,
}

/// An engine call a [`Spy`] intercepts.
#[derive(Clone, Copy)]
pub(crate) struct Seen<'a> {
    /// The PHP name of the method.
    pub(crate) method: &'a str,
    /// Before or after the inner call.
    pub(crate) stage: Stage,
    /// The collection definition the call is given, if any.
    pub(crate) collection: Option<&'a Collection>,
    /// The collection name the call is given, if any (the definition's id
    /// when it is given one).
    pub(crate) name: Option<&'a str>,
    /// The document id the call is given, if any.
    pub(crate) id: Option<&'a str>,
    /// Whether a `getDocument()` locks the row.
    pub(crate) for_update: bool,
    /// The permission a `find()` reads under.
    pub(crate) for_permission: Option<PermissionType>,
}

impl<'a> Seen<'a> {
    fn of(method: &'a str) -> Self {
        Seen {
            method,
            stage: Stage::Before,
            collection: None,
            name: None,
            id: None,
            for_update: false,
            for_permission: None,
        }
    }

    fn on(mut self, collection: &'a Collection) -> Self {
        self.collection = Some(collection);
        self.name = Some(collection.id());
        self
    }

    fn named(mut self, name: &'a str) -> Self {
        self.name = Some(name);
        self
    }

    fn id(mut self, id: &'a str) -> Self {
        self.id = Some(id);
        self
    }
}

/// What a [`Spy`] runs around each engine call.
pub(crate) type Interceptor = Arc<dyn Fn(&Seen<'_>) -> Result<()> + Send + Sync>;

/// An adapter recording and intercepting another's engine calls. See the
/// [module docs](self).
pub(crate) struct Spy {
    inner: Arc<dyn Adapter>,
    interceptor: Mutex<Option<Interceptor>>,
    pending: Mutex<Vec<(&'static str, futures_util::future::BoxFuture<'static, ()>)>>,
    calls: Mutex<Vec<String>>,
    added: Mutex<Vec<Capability>>,
    removed: Mutex<Vec<Capability>>,
}

impl Spy {
    /// A spy over `inner`.
    pub(crate) fn new(inner: Arc<dyn Adapter>) -> Arc<Spy> {
        Arc::new(Spy {
            inner,
            interceptor: Mutex::new(None),
            pending: Mutex::new(Vec::new()),
            calls: Mutex::new(Vec::new()),
            added: Mutex::new(Vec::new()),
            removed: Mutex::new(Vec::new()),
        })
    }

    /// A spy over a new Memory adapter.
    pub(crate) fn memory() -> Arc<Spy> {
        Spy::new(Arc::new(crate::adapter::memory::Memory::new()))
    }

    /// The adapter spied on.
    pub(crate) fn inner(&self) -> &Arc<dyn Adapter> {
        &self.inner
    }

    /// Runs `interceptor` around every engine call from now on.
    pub(crate) fn intercept(&self, interceptor: impl Fn(&Seen<'_>) -> Result<()> + Send + Sync + 'static) {
        *self.interceptor.lock().unwrap() = Some(Arc::new(interceptor));
    }

    /// Runs `work` once, before the next call of `method` reaches the
    /// inner adapter (what PHP's tests do in an overriding method).
    pub(crate) fn before_next(&self, method: &'static str, work: futures_util::future::BoxFuture<'static, ()>) {
        self.pending.lock().unwrap().push((method, work));
    }

    /// Whether work queued with [`Spy::before_next`] is still waiting.
    pub(crate) fn pending(&self) -> bool {
        !self.pending.lock().unwrap().is_empty()
    }

    /// Stops intercepting.
    pub(crate) fn release(&self) {
        *self.interceptor.lock().unwrap() = None;
    }

    /// Adds a capability to the inner adapter's.
    pub(crate) fn support(&self, capability: Capability) -> &Self {
        self.removed.lock().unwrap().retain(|c| *c != capability);
        self.added.lock().unwrap().push(capability);
        self
    }

    /// Removes a capability from the inner adapter's.
    pub(crate) fn withhold(&self, capability: Capability) -> &Self {
        self.added.lock().unwrap().retain(|c| *c != capability);
        self.removed.lock().unwrap().push(capability);
        self
    }

    /// The engine calls made so far, by PHP name, in order.
    pub(crate) fn calls(&self) -> Vec<String> {
        self.calls.lock().unwrap().clone()
    }

    /// How many calls of `method` were made so far.
    pub(crate) fn count(&self, method: &str) -> usize {
        self.calls.lock().unwrap().iter().filter(|c| *c == method).count()
    }

    /// Forgets the calls made so far.
    pub(crate) fn clear(&self) {
        self.calls.lock().unwrap().clear();
    }

    async fn enter(&self, seen: Seen<'_>) -> Result<()> {
        let work = {
            let mut pending = self.pending.lock().unwrap();
            pending.iter().position(|(m, _)| *m == seen.method).map(|i| pending.remove(i).1)
        };
        if let Some(work) = work {
            work.await;
        }
        self.calls.lock().unwrap().push(seen.method.to_owned());
        self.run(seen)
    }

    fn run(&self, seen: Seen<'_>) -> Result<()> {
        let interceptor = self.interceptor.lock().unwrap().clone();
        match interceptor {
            Some(i) => i(&seen),
            None => Ok(()),
        }
    }

    fn leave<T>(&self, mut seen: Seen<'_>, result: Result<T>) -> Result<T> {
        let value = result?;
        seen.stage = Stage::After;
        self.run(seen)?;
        Ok(value)
    }
}

/// An engine call: recorded, intercepted before and after.
macro_rules! call {
    ($self:ident, $seen:expr, $call:expr) => {{
        let seen = $seen;
        $self.enter(seen).await?;
        let result = $call.await;
        $self.leave(seen, result)
    }};
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
        for c in self.added.lock().unwrap().iter() {
            capabilities = capabilities.with(*c);
        }
        for c in self.removed.lock().unwrap().iter() {
            capabilities = capabilities.without(*c);
        }
        capabilities
    }

    fn supports(&self, capability: Capability) -> bool {
        if self.removed.lock().unwrap().contains(&capability) {
            return false;
        }
        self.added.lock().unwrap().contains(&capability) || self.inner.supports(capability)
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

    fn set_authorization(&self, authorization: Arc<Authorization>) {
        self.inner.set_authorization(authorization)
    }

    fn authorization(&self) -> Arc<Authorization> {
        self.inner.authorization()
    }

    fn set_profiler(&self, profiler: Option<Arc<Profiler>>) {
        self.inner.set_profiler(profiler)
    }

    fn profiler(&self) -> Option<Arc<Profiler>> {
        self.inner.profiler()
    }

    fn set_database(&self, name: &str) {
        self.inner.set_database(name)
    }

    fn database(&self) -> String {
        self.inner.database()
    }

    fn set_namespace(&self, namespace: &str) {
        self.inner.set_namespace(namespace)
    }

    fn namespace(&self) -> String {
        self.inner.namespace()
    }

    fn set_hostname(&self, hostname: &str) {
        self.inner.set_hostname(hostname)
    }

    fn set_shared_tables(&self, shared_tables: bool) {
        self.inner.set_shared_tables(shared_tables)
    }

    fn shared_tables(&self) -> bool {
        self.inner.shared_tables()
    }

    fn set_tenant(&self, tenant: Value) {
        self.inner.set_tenant(tenant)
    }

    fn tenant(&self) -> Value {
        self.inner.tenant()
    }

    fn set_tenant_per_document(&self, tenant_per_document: bool) {
        self.inner.set_tenant_per_document(tenant_per_document)
    }

    fn tenant_per_document(&self) -> bool {
        self.inner.tenant_per_document()
    }

    fn set_metadata(&self, key: &str, value: Value) {
        self.inner.set_metadata(key, value)
    }

    fn metadata(&self) -> Vec<(String, Value)> {
        self.inner.metadata()
    }

    fn reset_metadata(&self) {
        self.inner.reset_metadata()
    }

    fn set_locks(&self, locks: bool) {
        self.inner.set_locks(locks)
    }

    fn add_write_hook(&self, hook: Arc<dyn Write>) {
        self.inner.add_write_hook(hook)
    }

    fn remove_write_hook(&self, hook: &Arc<dyn Write>) {
        self.inner.remove_write_hook(hook)
    }

    fn remove_tenancy_hooks(&self) {
        self.inner.remove_tenancy_hooks()
    }

    fn write_hooks(&self) -> Vec<Arc<dyn Write>> {
        self.inner.write_hooks()
    }

    fn tenant_hook(&self) -> Option<Tenancy> {
        self.inner.tenant_hook()
    }

    fn add_transform(&self, name: &str, transform: Arc<dyn Transform>) {
        self.inner.add_transform(name, transform)
    }

    fn remove_transform(&self, name: &str) {
        self.inner.remove_transform(name)
    }

    fn reset_transforms(&self) {
        self.inner.reset_transforms()
    }

    fn decorate_row(&self, row: Array, document: &Document) -> Array {
        self.inner.decorate_row(row, document)
    }

    async fn start_transaction(&self) -> Result<bool> {
        call!(self, Seen::of("startTransaction"), self.inner.start_transaction())
    }

    async fn commit_transaction(&self) -> Result<bool> {
        call!(self, Seen::of("commitTransaction"), self.inner.commit_transaction())
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        call!(self, Seen::of("rollbackTransaction"), self.inner.rollback_transaction())
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

    fn is_retryable(&self, failure: &Error) -> bool {
        self.inner.is_retryable(failure)
    }

    fn is_transient(&self, error: &Error) -> bool {
        self.inner.is_transient(error)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        call!(self, Seen::of("create"), self.inner.create(name))
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        call!(self, Seen::of("update"), self.inner.update(name, new))
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        call!(self, Seen::of("exists"), self.inner.exists(database))
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        call!(self, Seen::of("collectionExists"), self.inner.collection_exists(database, collection))
    }

    async fn list(&self) -> Result<Vec<Document>> {
        call!(self, Seen::of("list"), self.inner.list())
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        call!(self, Seen::of("delete"), self.inner.delete(name))
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        call!(
            self,
            Seen::of("createCollection").named(collection),
            self.inner.create_collection(collection, attributes, indexes)
        )
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        call!(self, Seen::of("deleteCollection").named(collection), self.inner.delete_collection(collection))
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        call!(self, Seen::of("analyzeCollection").named(collection), self.inner.analyze_collection(collection))
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        call!(self, Seen::of("getSizeOfCollection").named(collection), self.inner.size_of_collection(collection))
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        call!(
            self,
            Seen::of("getSizeOfCollectionOnDisk").named(collection),
            self.inner.size_of_collection_on_disk(collection)
        )
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
        call!(self, Seen::of("createAttribute").named(collection), self.inner.create_attribute(collection, attribute))
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        call!(
            self,
            Seen::of("createAttributes").named(collection),
            self.inner.create_attributes(collection, attributes)
        )
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        call!(
            self,
            Seen::of("updateAttribute").named(collection),
            self.inner.update_attribute(collection, key, attribute)
        )
    }

    async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        call!(
            self,
            Seen::of("relaxAttributeRequired").named(collection),
            self.inner.relax_attribute_required(collection, id)
        )
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        call!(self, Seen::of("deleteAttribute").named(collection), self.inner.delete_attribute(collection, key))
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        call!(self, Seen::of("renameAttribute").named(collection), self.inner.rename_attribute(collection, old, new))
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        call!(self, Seen::of("getSchemaAttributes").named(collection), self.inner.schema_attributes(collection))
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        call!(self, Seen::of("getSchemaIndexes").named(collection), self.inner.schema_indexes(collection))
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
        call!(
            self,
            Seen::of("createIndex").named(collection),
            self.inner.create_index(collection, index, attribute_types, collation)
        )
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        call!(self, Seen::of("deleteIndex").named(collection), self.inner.delete_index(collection, key))
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        call!(self, Seen::of("renameIndex").named(collection), self.inner.rename_index(collection, old, new))
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        call!(self, Seen::of("createDocument").on(collection), self.inner.create_document(collection, document))
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        call!(self, Seen::of("createDocuments").on(collection), self.inner.create_documents(collection, documents))
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        call!(
            self,
            Seen { for_update, ..Seen::of("getDocument").on(collection).id(id) },
            self.inner.get_document(collection, id, queries, for_update)
        )
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        call!(
            self,
            Seen::of("updateDocument").on(collection).id(id),
            self.inner.update_document(collection, id, document, skip_permissions)
        )
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        call!(
            self,
            Seen::of("updateDocuments").on(collection),
            self.inner.update_documents(collection, updates, documents, skip_permissions)
        )
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
        call!(
            self,
            Seen::of("increaseDocumentAttribute").on(collection).id(id),
            self.inner.increase_document_attribute(collection, id, attribute, value, updated_at, min, max)
        )
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        call!(self, Seen::of("deleteDocument").on(collection).id(id), self.inner.delete_document(collection, id))
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        call!(
            self,
            Seen::of("deleteDocuments").on(collection),
            self.inner.delete_documents(collection, sequences, permission_ids)
        )
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        call!(
            self,
            Seen { for_permission: Some(options.for_permission), ..Seen::of("find").on(collection) },
            self.inner.find(collection, queries, options)
        )
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        call!(self, Seen::of("count").on(collection), self.inner.count(collection, queries, max))
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        call!(self, Seen::of("sum").on(collection), self.inner.sum(collection, attribute, queries, max))
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        call!(self, Seen::of("getSequences").on(collection), self.inner.get_sequences(collection, documents))
    }

    fn read_concurrency(&self) -> usize {
        self.inner.read_concurrency()
    }

    fn served_by_replica(&self) -> bool {
        self.inner.served_by_replica()
    }
}
