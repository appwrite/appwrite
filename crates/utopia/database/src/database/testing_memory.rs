//! Test support on the real [`Memory`] adapter: databases built as PHP's
//! fixtures build them, and a lifecycle hook recording what it receives.
//!
//! PHP: `Tests\Unit\Event\HookFixture`, `Tests\Unit\Event\RecordingLifecycle`.

#![allow(dead_code)]

use std::any::Any;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_query::schema::ColumnType;

use super::Database;
use crate::adapter::memory::Memory;
use crate::adapter::{Adapter, Casting, Find, Limits, State};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::event::{Domain, Event};
use crate::hook::Lifecycle;
use crate::index::Index;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{Array, Value, string};

/// A unique namespace suffix (PHP's `uniqid()`).
pub(crate) fn unique() -> String {
    crate::id::unique().expect("an id")
}

/// A database over `adapter` without a cache (`new Cache(new None())`), with
/// a fresh authorization holding `any`, on database `name` and a namespace
/// of its own, created.
pub(crate) async fn database_on(adapter: Arc<dyn Adapter>, name: &str) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let database = Database::new(adapter, cache);
    database.set_authorization(Arc::new(Authorization::new(true)));
    database.set_database(name).set_namespace(&format!("{name}_{}", unique()));
    database.create(None).await.expect("the database is created");
    database
}

/// [`database_on`] a new [`Memory`] adapter.
pub(crate) async fn memory(name: &str) -> Database {
    database_on(Arc::new(Memory::new()), name).await
}

/// `create`, `read`, `update` and `delete` for `any`.
pub(crate) fn crud() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any), Permission::delete(&any)]
}

/// Creates a collection of `attributes`.
pub(crate) async fn collection(
    database: &Database,
    id: &str,
    attributes: Vec<Attribute>,
    permissions: Vec<String>,
    document_security: bool,
) {
    let collection =
        Collection::create(id, id, attributes, Vec::new(), Some(permissions), document_security, Array::new())
            .expect("a collection");
    database.create_collection(collection).await.expect("the collection is created");
}

/// A document of `pairs`.
pub(crate) fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).expect("a document")
}

/// The collection of `HookFixture`.
pub(crate) const POSTS: &str = "posts";

/// `HookFixture::memory()`: `posts` with `title` and `views`, every
/// permission for `any`, document security on.
pub(crate) async fn posts() -> Database {
    let database = memory("hooks").await;
    collection(&database, POSTS, vec![Attribute::string("title", 64), Attribute::integer("views")], crud(), true).await;
    database
}

/// `HookFixture::seed()`: a post per id, its views its position from 1.
pub(crate) async fn seed(database: &Database, ids: &[&str]) {
    for (index, id) in ids.iter().enumerate() {
        let document = doc(vec![("$id", string(*id)), ("title", string(*id)), ("views", Value::Int(index as i64 + 1))]);
        database.create_document(POSTS, document).await.expect("seeded");
    }
}

/// A lifecycle hook recording every event it receives
/// (`RecordingLifecycle`).
#[derive(Default)]
pub(crate) struct Recorder {
    events: Mutex<Vec<Domain>>,
}

impl Recorder {
    /// A new recorder, to register with `Hook::lifecycle`.
    pub(crate) fn new() -> Arc<Self> {
        Arc::new(Recorder::default())
    }

    /// The events received, in order (`getEvents()`).
    pub(crate) fn events(&self) -> Vec<Domain> {
        self.events.lock().unwrap().clone()
    }

    /// The events of `kind` received (`received()`).
    pub(crate) fn received(&self, kind: Event) -> Vec<Domain> {
        self.events().into_iter().filter(|e| e.event() == kind).collect()
    }

    /// The kinds of the events received.
    pub(crate) fn kinds(&self) -> Vec<Event> {
        self.events().iter().map(Domain::event).collect()
    }
}

#[async_trait]
impl Lifecycle for Recorder {
    async fn handle(&self, event: &Domain) -> Result<()> {
        self.events.lock().unwrap().push(event.clone());
        Ok(())
    }
}

/// A [`Memory`] adapter changed the ways PHP's tests subclass it:
/// capabilities taken away or added, casting itself (`Feature\Casting`, as
/// given), failing commits, or reading ids lower-cased.
pub(crate) struct Tweaked {
    inner: Memory,
    without: Mutex<Vec<Capability>>,
    with: Mutex<Vec<Capability>>,
    casting: bool,
    commit_failures: AtomicI64,
    lower_case_reads: bool,
}

impl Tweaked {
    /// A Memory adapter as it is.
    pub(crate) fn new() -> Self {
        Tweaked {
            inner: Memory::new(),
            without: Mutex::new(Vec::new()),
            with: Mutex::new(Vec::new()),
            casting: false,
            commit_failures: AtomicI64::new(0),
            lower_case_reads: false,
        }
    }

    /// Without `capability`.
    pub(crate) fn without(self, capability: Capability) -> Self {
        self.set_supports(capability, false);
        self
    }

    /// Takes `capability` away, or gives it.
    pub(crate) fn set_supports(&self, capability: Capability, supports: bool) {
        let (mut with, mut without) = (self.with.lock().unwrap(), self.without.lock().unwrap());
        with.retain(|c| *c != capability);
        without.retain(|c| *c != capability);
        if supports {
            with.push(capability);
        } else {
            without.push(capability);
        }
    }

    /// Casting itself: documents go to and come from the engine as given.
    pub(crate) fn casting(mut self) -> Self {
        self.casting = true;
        self
    }

    /// Reading a document by its id lower-cased.
    pub(crate) fn lower_case_reads(mut self) -> Self {
        self.lower_case_reads = true;
        self
    }

    /// Makes the next `failures` commits fail
    /// (`Failed to commit transaction: Commit failed`).
    pub(crate) fn fail_commits(&self, failures: i64) {
        self.commit_failures.store(failures, Ordering::SeqCst);
    }

    /// The commits still to fail.
    pub(crate) fn commit_failures(&self) -> i64 {
        self.commit_failures.load(Ordering::SeqCst)
    }
}

impl Casting for Tweaked {
    fn cast_before(&self, _collection: &Collection, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn cast_after(&self, _collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        Ok(documents)
    }

    fn cast_datetime(&self, value: &str) -> Result<Value> {
        Ok(string(crate::datetime::set_timezone(value)?))
    }
}

#[async_trait]
impl Adapter for Tweaked {
    fn state(&self) -> &State {
        self.inner.state()
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        let without = self.without.lock().unwrap().clone();
        let mut capabilities: Vec<Capability> =
            self.inner.capabilities().iter().filter(|c| !without.contains(c)).collect();
        capabilities.extend(self.with.lock().unwrap().iter().copied());
        Capabilities::of(&capabilities)
    }

    fn limits(&self) -> Arc<Limits> {
        self.inner.limits()
    }

    fn casting(&self) -> Option<&dyn Casting> {
        if self.casting { Some(self) } else { None }
    }

    fn relationships(&self) -> Option<&dyn crate::adapter::Relationships> {
        self.inner.relationships()
    }

    async fn start_transaction(&self) -> Result<bool> {
        self.inner.start_transaction().await
    }

    async fn commit_transaction(&self) -> Result<bool> {
        if self.commit_failures.load(Ordering::SeqCst) > 0 {
            self.commit_failures.fetch_sub(1, Ordering::SeqCst);
            return Err(Error::transaction("Failed to commit transaction: Commit failed"));
        }
        self.inner.commit_transaction().await
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        self.inner.rollback_transaction().await
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

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        self.inner.create_attribute(collection, attribute).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        self.inner.create_attributes(collection, attributes).await
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        self.inner.update_attribute(collection, key, attribute).await
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        self.inner.delete_attribute(collection, key).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.inner.rename_attribute(collection, old, new).await
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<crate::schema::Column>> {
        self.inner.schema_attributes(collection).await
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<crate::schema::Index>> {
        self.inner.schema_indexes(collection).await
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
        let id = if self.lower_case_reads { id.to_lowercase() } else { id.to_owned() };
        self.inner.get_document(collection, &id, queries, for_update).await
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
        self.inner.find(collection, queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        self.inner.count(collection, queries, max).await
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        self.inner.sum(collection, attribute, queries, max).await
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.inner.get_sequences(collection, documents).await
    }
}
