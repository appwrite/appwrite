//! [`Probe`]: a Memory adapter that records what reaches it, the Rust
//! counterpart of PHP's test adapters `PingRecordingMemory`,
//! `SyncRecordingMemory`, `TimeoutRecordingAdapter`, `ProfilerProbeAdapter`,
//! `HostnameSQLite` and the stubbed adapters of the pool tests: it offers a
//! connection (`ping` takes a snapshot of its settings), and optionally
//! timeouts and a schemaless mode; it logs every call it serves.

#![allow(dead_code)]

use std::any::Any;
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_database::adapter::memory::Memory;
use utopia_database::adapter::{Adapter, Connection, Find, Limits, Relationships, Schemaless, State, Timeouts};
use utopia_database::attribute::Attribute;
use utopia_database::capability::{Capabilities, Capability};
use utopia_database::collection::Collection;
use utopia_database::event::Event;
use utopia_database::hook::Transform;
use utopia_database::profiler::Profiler;
use utopia_database::validator::authorization::Authorization;
use utopia_database::{Document, Index, Query, Result, Value};
use utopia_query::schema::ColumnType;

/// What a connection held when a call reached it (`SyncSnapshot`).
#[derive(Clone)]
pub struct Snapshot {
    pub database: String,
    pub namespace: String,
    pub tenant: Value,
    pub metadata: Vec<(String, Value)>,
    pub transforms: Vec<(String, Arc<dyn Transform>)>,
    pub profiler: Option<Arc<Profiler>>,
    pub schemaless: bool,
    pub timeouts: Vec<(Event, i64)>,
    pub task: Option<tokio::task::Id>,
    /// The tenant before and after the ping's pause.
    pub before: Value,
    pub after: Value,
}

type Mutation = Box<dyn FnOnce(&Probe) + Send>;
type Pause = Box<dyn FnOnce() -> futures_util::future::BoxFuture<'static, ()> + Send>;

/// A Memory adapter recording what reaches it. See the [module docs](self).
pub struct Probe {
    inner: Memory,
    pub name: String,
    pub hostname: String,
    pub with_timeouts: bool,
    pub with_schemaless: bool,
    schemaless: AtomicBool,
    /// `$timeouts` and `$timeout` of `TimeoutRecordingMemory`.
    timeouts: Mutex<(Vec<(Event, i64)>, i64)>,
    pub snapshots: Mutex<Vec<Snapshot>>,
    pub calls: Mutex<Vec<&'static str>>,
    /// Checkouts: every one puts the handle's authorization on the connection.
    pub checkouts: AtomicUsize,
    mutation: Mutex<Option<Mutation>>,
    pause: Mutex<Option<Pause>>,
}

impl Probe {
    pub fn new(name: &str) -> Self {
        Probe {
            inner: Memory::new(),
            name: name.to_owned(),
            hostname: String::new(),
            with_timeouts: false,
            with_schemaless: false,
            schemaless: AtomicBool::new(false),
            timeouts: Mutex::new((Vec::new(), 0)),
            snapshots: Mutex::new(Vec::new()),
            calls: Mutex::new(Vec::new()),
            checkouts: AtomicUsize::new(0),
            mutation: Mutex::new(None),
            pause: Mutex::new(None),
        }
    }

    pub fn timeouts(mut self) -> Self {
        self.with_timeouts = true;
        self
    }

    pub fn schemaless(mut self) -> Self {
        self.with_schemaless = true;
        self
    }

    pub fn hostname(mut self, hostname: &str) -> Self {
        self.hostname = hostname.to_owned();
        self
    }

    pub fn memory(&self) -> &Memory {
        &self.inner
    }

    pub fn last(&self) -> Snapshot {
        self.snapshots.lock().unwrap().last().cloned().expect("a call reached the connection")
    }

    pub fn recorded_timeouts(&self) -> Vec<(Event, i64)> {
        self.timeouts.lock().unwrap().0.clone()
    }

    pub fn set_recorded_timeouts(&self, timeouts: Vec<(Event, i64)>) {
        self.timeouts.lock().unwrap().0 = timeouts;
    }

    pub fn calls(&self) -> Vec<&'static str> {
        self.calls.lock().unwrap().clone()
    }

    pub fn clear_calls(&self) {
        self.calls.lock().unwrap().clear();
    }

    pub fn mutate_on_next_ping(&self, mutation: impl FnOnce(&Probe) + Send + 'static) {
        *self.mutation.lock().unwrap() = Some(Box::new(mutation));
    }

    pub fn pause_next_ping(
        &self,
        pause: impl FnOnce() -> futures_util::future::BoxFuture<'static, ()> + Send + 'static,
    ) {
        *self.pause.lock().unwrap() = Some(Box::new(pause));
    }

    pub fn set_own_schemaless(&self, schemaless: bool) {
        self.schemaless.store(schemaless, Ordering::SeqCst);
    }

    fn log(&self, call: &'static str) {
        self.calls.lock().unwrap().push(call);
    }

    fn snapshot(&self, before: Value) -> Snapshot {
        let transforms = self.state().lock().transforms.clone();
        Snapshot {
            database: self.database(),
            namespace: self.namespace(),
            tenant: self.tenant(),
            metadata: self.metadata(),
            transforms,
            profiler: self.profiler(),
            schemaless: self.schemaless.load(Ordering::SeqCst),
            timeouts: self.recorded_timeouts(),
            task: tokio::task::try_id(),
            before,
            after: self.tenant(),
        }
    }
}

#[async_trait]
impl Adapter for Probe {
    fn state(&self) -> &State {
        self.inner.state()
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        self.log("capabilities");
        self.inner.capabilities()
    }

    fn supports(&self, capability: Capability) -> bool {
        self.log("supports");
        if capability == Capability::DefinedAttributes && self.with_schemaless {
            return !self.schemaless.load(Ordering::SeqCst);
        }
        self.inner.supports(capability)
    }

    fn limits(&self) -> Arc<Limits> {
        self.log("limits");
        self.inner.limits()
    }

    fn set_shared_tables(&self, shared_tables: bool) {
        self.inner.set_shared_tables(shared_tables);
    }

    fn set_authorization(&self, authorization: Arc<Authorization>) {
        self.checkouts.fetch_add(1, Ordering::SeqCst);
        self.inner.set_authorization(authorization);
    }

    fn connection(&self) -> Option<&dyn Connection> {
        Some(self)
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        self.inner.relationships()
    }

    fn schemaless(&self) -> Option<&dyn Schemaless> {
        self.with_schemaless.then_some(self as &dyn Schemaless)
    }

    fn timeouts(&self) -> Option<&dyn Timeouts> {
        self.with_timeouts.then_some(self as &dyn Timeouts)
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.log("startTransaction");
        self.inner.start_transaction().await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        self.log("commitTransaction");
        self.inner.commit_transaction().await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.log("rollbackTransaction");
        self.inner.rollback_transaction().await
    }

    fn in_transaction(&self) -> bool {
        self.inner.in_transaction()
    }

    async fn create(&self, name: &str) -> Result<bool> {
        self.log("create");
        self.inner.create(name).await
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        self.log("update");
        self.inner.update(name, new).await
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        self.log("exists");
        self.inner.exists(database).await
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        self.log("collectionExists");
        self.inner.collection_exists(database, collection).await
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.log("list");
        self.inner.list().await
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.log("delete");
        self.inner.delete(name).await
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        self.log("createCollection");
        self.inner.create_collection(collection, attributes, indexes).await
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        self.log("deleteCollection");
        self.inner.delete_collection(collection).await
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        self.log("analyzeCollection");
        self.inner.analyze_collection(collection).await
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.log("getSizeOfCollection");
        self.inner.size_of_collection(collection).await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.log("getSizeOfCollectionOnDisk");
        self.inner.size_of_collection_on_disk(collection).await
    }

    fn attribute_width(&self, collection: &Collection) -> Result<i64> {
        self.log("getAttributeWidth");
        self.inner.attribute_width(collection)
    }

    fn count_of_attributes(&self, collection: &Collection) -> Result<i64> {
        self.log("getCountOfAttributes");
        self.inner.count_of_attributes(collection)
    }

    fn count_of_indexes(&self, collection: &Collection) -> Result<i64> {
        self.log("getCountOfIndexes");
        self.inner.count_of_indexes(collection)
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        self.log("createAttribute");
        self.inner.create_attribute(collection, attribute).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        self.log("createAttributes");
        self.inner.create_attributes(collection, attributes).await
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        self.log("updateAttribute");
        self.inner.update_attribute(collection, key, attribute).await
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        self.log("deleteAttribute");
        self.inner.delete_attribute(collection, key).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.log("renameAttribute");
        self.inner.rename_attribute(collection, old, new).await
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<utopia_database::schema::Column>> {
        self.log("getSchemaAttributes");
        self.inner.schema_attributes(collection).await
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<utopia_database::schema::Index>> {
        self.log("getSchemaIndexes");
        self.inner.schema_indexes(collection).await
    }

    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        self.log("getColumnType");
        self.inner.column_type(attribute)
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        self.log("createIndex");
        self.inner.create_index(collection, index, attribute_types, collation).await
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        self.log("deleteIndex");
        self.inner.delete_index(collection, key).await
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.log("renameIndex");
        self.inner.rename_index(collection, old, new).await
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.log("createDocument");
        self.inner.create_document(collection, document).await
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.log("createDocuments");
        self.inner.create_documents(collection, documents).await
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        self.log("getDocument");
        self.inner.get_document(collection, id, queries, for_update).await
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip: bool,
    ) -> Result<Document> {
        self.log("updateDocument");
        self.inner.update_document(collection, id, document, skip).await
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        self.log("updateDocuments");
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
        self.log("increaseDocumentAttribute");
        self.inner.increase_document_attribute(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        self.log("deleteDocument");
        self.inner.delete_document(collection, id).await
    }

    async fn delete_documents(&self, collection: &Collection, sequences: Vec<Value>, ids: Vec<String>) -> Result<i64> {
        self.log("deleteDocuments");
        self.inner.delete_documents(collection, sequences, ids).await
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        self.log("find");
        self.inner.find(collection, queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        self.log("count");
        self.inner.count(collection, queries, max).await
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        self.log("sum");
        self.inner.sum(collection, attribute, queries, max).await
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.log("getSequences");
        self.inner.get_sequences(collection, documents).await
    }
}

#[async_trait]
impl Connection for Probe {
    async fn ping(&self) -> Result<bool> {
        self.log("ping");
        let before = self.tenant();
        let pause = self.pause.lock().unwrap().take();
        if let Some(pause) = pause {
            pause().await;
        }
        let snapshot = self.snapshot(before);
        self.snapshots.lock().unwrap().push(snapshot);
        let mutation = self.mutation.lock().unwrap().take();
        if let Some(mutation) = mutation {
            mutation(self);
        }
        Ok(true)
    }

    async fn reconnect(&self) -> Result<()> {
        Ok(())
    }

    async fn id(&self) -> Result<String> {
        self.log("id");
        Ok(self.name.clone())
    }

    async fn hostname(&self) -> Result<String> {
        self.log("hostname");
        Ok(self.hostname.clone())
    }
}

impl Schemaless for Probe {
    fn set_schemaless(&self, schemaless: bool) {
        self.schemaless.store(schemaless, Ordering::SeqCst);
    }

    fn is_schemaless(&self) -> bool {
        self.schemaless.load(Ordering::SeqCst)
    }
}

impl Timeouts for Probe {
    /// `TimeoutRecordingMemory::setTimeout()`: the event's entry, and the
    /// scalar.
    fn set_timeout(&self, milliseconds: i64, event: Event) -> Result<()> {
        let mut timeouts = self.timeouts.lock().unwrap();
        match timeouts.0.iter_mut().find(|(e, _)| *e == event) {
            Some(entry) => entry.1 = milliseconds,
            None => timeouts.0.push((event, milliseconds)),
        }
        timeouts.1 = milliseconds;
        Ok(())
    }

    fn clear_timeout(&self, event: Event) {
        let mut timeouts = self.timeouts.lock().unwrap();
        if event == Event::All {
            *timeouts = (Vec::new(), 0);
        } else {
            timeouts.0.retain(|(e, _)| *e != event);
        }
    }

    fn timeout(&self, event: Event) -> i64 {
        let timeouts = self.timeouts.lock().unwrap();
        timeouts
            .0
            .iter()
            .find(|(e, _)| *e == event)
            .or_else(|| timeouts.0.iter().find(|(e, _)| *e == Event::All))
            .map_or(timeouts.1, |(_, ms)| *ms)
    }
}
