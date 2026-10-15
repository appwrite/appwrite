//! Test support on the real engine: [`Intercepted`], the Memory adapter
//! with its calls recorded and answerable by a handler the test sets (PHP's
//! anonymous `Memory` subclasses that override a method, and
//! `Tests\Unit\Support\CountingMemory`), and [`memory_database`], a
//! database over it built as the PHP fixtures build theirs.
//!
//! A call the handler does not answer (`None`) runs on Memory.

#![allow(dead_code)]

use std::any::Any;
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use utopia_query::schema::ColumnType;

use super::Database;
use super::testing::{Call, Reply, bool_reply, document_reply, documents_reply, int_reply};
use crate::adapter::memory::Memory;
use crate::adapter::{Adapter, Find, Limits, Relationships, State};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::index::Index;
use crate::query::Query;
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::schema;
use crate::value::Value;

type Handler = Arc<dyn Fn(&Call) -> Option<Reply> + Send + Sync>;

/// Memory with every engine call recorded and answerable by a handler.
pub(crate) struct Intercepted {
    inner: Memory,
    handler: Mutex<Option<Handler>>,
    calls: Mutex<Vec<Call>>,
    capabilities: Mutex<Option<Capabilities>>,
    limits: Mutex<Option<Arc<Limits>>>,
}

impl Intercepted {
    /// Memory, intercepted.
    pub(crate) fn new() -> Arc<Self> {
        Arc::new(Intercepted {
            inner: Memory::new(),
            handler: Mutex::new(None),
            calls: Mutex::new(Vec::new()),
            capabilities: Mutex::new(None),
            limits: Mutex::new(None),
        })
    }

    /// Answers calls with `handler`; `None` from it runs the call on
    /// Memory.
    pub(crate) fn on(&self, handler: impl Fn(&Call) -> Option<Reply> + Send + Sync + 'static) -> &Self {
        *self.handler.lock().unwrap() = Some(Arc::new(handler));
        self
    }

    /// Stops answering calls.
    pub(crate) fn off(&self) -> &Self {
        *self.handler.lock().unwrap() = None;
        self
    }

    /// Adds a capability to Memory's.
    pub(crate) fn support(&self, capability: Capability) -> &Self {
        let current = self.capabilities();
        *self.capabilities.lock().unwrap() = Some(current.with(capability));
        self
    }

    /// Removes a capability from Memory's.
    pub(crate) fn unsupport(&self, capability: Capability) -> &Self {
        let kept: Vec<Capability> =
            Capability::CASES.into_iter().filter(|c| *c != capability && self.capabilities().contains(*c)).collect();
        *self.capabilities.lock().unwrap() = Some(Capabilities::of(&kept));
        self
    }

    /// Replaces Memory's limits.
    pub(crate) fn set_limits(&self, limits: Limits) -> &Self {
        *self.limits.lock().unwrap() = Some(Arc::new(limits));
        self
    }

    /// The calls made so far.
    pub(crate) fn calls(&self) -> Vec<Call> {
        self.calls.lock().unwrap().clone()
    }

    /// The calls of `method` (PHP name) made so far.
    pub(crate) fn calls_of(&self, method: &str) -> Vec<Call> {
        self.calls().into_iter().filter(|c| c.method() == method).collect()
    }

    /// How many calls of `method` were made (`CountingMemory`).
    pub(crate) fn count(&self, method: &str) -> usize {
        self.calls_of(method).len()
    }

    /// Forgets the calls made so far.
    pub(crate) fn clear_calls(&self) {
        self.calls.lock().unwrap().clear();
    }

    /// Whether Memory holds the collection's table, without recording a
    /// call (`$adapter->collectionExists()` of a test).
    pub(crate) async fn memory_exists(&self, database: &str, collection: &str) -> bool {
        self.inner.collection_exists(database, collection).await.expect("collectionExists")
    }

    /// The Memory adapter underneath.
    pub(crate) fn memory(&self) -> &Memory {
        &self.inner
    }

    fn call(&self, call: Call) -> Option<Reply> {
        let handler = self.handler.lock().unwrap().clone();
        let reply = handler.and_then(|h| h(&call));
        self.calls.lock().unwrap().push(call);
        reply
    }
}

/// `reply`, or the inner call when there is none.
macro_rules! answer {
    ($self:ident, $call:expr, $convert:ident, $inner:expr) => {
        match $self.call($call) {
            Some(reply) => $convert(Some(reply), Default::default()),
            None => $inner,
        }
    };
}

#[async_trait]
impl Adapter for Intercepted {
    fn state(&self) -> &State {
        self.inner.state()
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        self.capabilities.lock().unwrap().unwrap_or_else(|| self.inner.capabilities())
    }

    fn limits(&self) -> Arc<Limits> {
        self.limits.lock().unwrap().clone().unwrap_or_else(|| self.inner.limits())
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        Some(self)
    }

    async fn start_transaction(&self) -> Result<bool> {
        answer!(self, Call::StartTransaction, bool_reply, self.inner.start_transaction().await)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        answer!(self, Call::CommitTransaction, bool_reply, self.inner.commit_transaction().await)
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        answer!(self, Call::RollbackTransaction, bool_reply, self.inner.rollback_transaction().await)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        answer!(self, Call::Create { name: name.into() }, bool_reply, self.inner.create(name).await)
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        answer!(self, Call::Update { name: name.into(), new: new.into() }, bool_reply, self.inner.update(name, new).await)
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        answer!(self, Call::Exists { database: database.into() }, bool_reply, self.inner.exists(database).await)
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        answer!(
            self,
            Call::CollectionExists { database: database.into(), collection: collection.into() },
            bool_reply,
            self.inner.collection_exists(database, collection).await
        )
    }

    async fn list(&self) -> Result<Vec<Document>> {
        answer!(self, Call::List, documents_reply, self.inner.list().await)
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        answer!(self, Call::Delete { name: name.into() }, bool_reply, self.inner.delete(name).await)
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        answer!(
            self,
            Call::CreateCollection {
                collection: collection.into(),
                attributes: attributes.to_vec(),
                indexes: indexes.to_vec()
            },
            bool_reply,
            self.inner.create_collection(collection, attributes, indexes).await
        )
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        answer!(
            self,
            Call::DeleteCollection { collection: collection.into() },
            bool_reply,
            self.inner.delete_collection(collection).await
        )
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        answer!(
            self,
            Call::AnalyzeCollection { collection: collection.into() },
            bool_reply,
            self.inner.analyze_collection(collection).await
        )
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        answer!(
            self,
            Call::SizeOfCollection { collection: collection.into() },
            int_reply,
            self.inner.size_of_collection(collection).await
        )
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        answer!(
            self,
            Call::SizeOfCollectionOnDisk { collection: collection.into() },
            int_reply,
            self.inner.size_of_collection_on_disk(collection).await
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
        answer!(
            self,
            Call::CreateAttribute { collection: collection.into(), attribute: attribute.clone() },
            bool_reply,
            self.inner.create_attribute(collection, attribute).await
        )
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        answer!(
            self,
            Call::CreateAttributes { collection: collection.into(), attributes: attributes.to_vec() },
            bool_reply,
            self.inner.create_attributes(collection, attributes).await
        )
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        answer!(
            self,
            Call::UpdateAttribute { collection: collection.into(), key: key.into(), attribute: attribute.clone() },
            bool_reply,
            self.inner.update_attribute(collection, key, attribute).await
        )
    }

    async fn relax_attribute_required(&self, collection: &str, id: &str) -> Result<bool> {
        answer!(
            self,
            Call::RelaxAttributeRequired { collection: collection.into(), key: id.into() },
            bool_reply,
            self.inner.relax_attribute_required(collection, id).await
        )
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        answer!(
            self,
            Call::DeleteAttribute { collection: collection.into(), key: key.into() },
            bool_reply,
            self.inner.delete_attribute(collection, key).await
        )
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        answer!(
            self,
            Call::RenameAttribute { collection: collection.into(), old: old.into(), new: new.into() },
            bool_reply,
            self.inner.rename_attribute(collection, old, new).await
        )
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        match self.call(Call::SchemaAttributes { collection: collection.into() }) {
            Some(Reply::Columns(c)) => Ok(c),
            Some(Reply::Error(e)) => Err(e),
            Some(other) => panic!("a columns reply, not {other:?}"),
            None => self.inner.schema_attributes(collection).await,
        }
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        match self.call(Call::SchemaIndexes { collection: collection.into() }) {
            Some(Reply::Indexes(i)) => Ok(i),
            Some(Reply::Error(e)) => Err(e),
            Some(other) => panic!("an indexes reply, not {other:?}"),
            None => self.inner.schema_indexes(collection).await,
        }
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
        answer!(
            self,
            Call::CreateIndex { collection: collection.into(), index: index.clone() },
            bool_reply,
            self.inner.create_index(collection, index, attribute_types, collation).await
        )
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        answer!(
            self,
            Call::DeleteIndex { collection: collection.into(), key: key.into() },
            bool_reply,
            self.inner.delete_index(collection, key).await
        )
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        answer!(
            self,
            Call::RenameIndex { collection: collection.into(), old: old.into(), new: new.into() },
            bool_reply,
            self.inner.rename_index(collection, old, new).await
        )
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        answer!(
            self,
            Call::CreateDocument { collection: collection.clone(), document: document.clone() },
            document_reply,
            self.inner.create_document(collection, document).await
        )
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        answer!(
            self,
            Call::CreateDocuments { collection: collection.clone(), documents: documents.clone() },
            documents_reply,
            self.inner.create_documents(collection, documents).await
        )
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        answer!(
            self,
            Call::GetDocument { collection: collection.clone(), id: id.into(), queries: queries.clone(), for_update },
            document_reply,
            self.inner.get_document(collection, id, queries, for_update).await
        )
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        answer!(
            self,
            Call::UpdateDocument {
                collection: collection.clone(),
                id: id.into(),
                document: document.clone(),
                skip_permissions
            },
            document_reply,
            self.inner.update_document(collection, id, document, skip_permissions).await
        )
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        answer!(
            self,
            Call::UpdateDocuments {
                collection: collection.clone(),
                updates: updates.clone(),
                documents: documents.clone(),
                skip_permissions: skip_permissions.to_vec()
            },
            int_reply,
            self.inner.update_documents(collection, updates, documents, skip_permissions).await
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
        answer!(
            self,
            Call::IncreaseDocumentAttribute {
                collection: collection.clone(),
                id: id.into(),
                attribute: attribute.into(),
                value: value.clone(),
                updated_at: updated_at.into(),
                min: min.clone(),
                max: max.clone()
            },
            bool_reply,
            self.inner.increase_document_attribute(collection, id, attribute, value, updated_at, min, max).await
        )
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        answer!(
            self,
            Call::DeleteDocument { collection: collection.clone(), id: id.into() },
            bool_reply,
            self.inner.delete_document(collection, id).await
        )
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        answer!(
            self,
            Call::DeleteDocuments {
                collection: collection.clone(),
                sequences: sequences.clone(),
                permission_ids: permission_ids.clone()
            },
            int_reply,
            self.inner.delete_documents(collection, sequences, permission_ids).await
        )
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        answer!(
            self,
            Call::Find { collection: collection.clone(), queries: queries.clone(), options: options.clone() },
            documents_reply,
            self.inner.find(collection, queries, options).await
        )
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        answer!(
            self,
            Call::Count { collection: collection.clone(), queries: queries.clone(), max },
            int_reply,
            self.inner.count(collection, queries, max).await
        )
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        match self.call(Call::Sum { collection: collection.clone(), attribute: attribute.into(), queries: queries.clone(), max })
        {
            Some(Reply::Value(v)) => Ok(v),
            Some(Reply::Int(i)) => Ok(Value::Int(i)),
            Some(Reply::Error(e)) => Err(e),
            Some(other) => panic!("a sum reply, not {other:?}"),
            None => self.inner.sum(collection, attribute, queries, max).await,
        }
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        answer!(
            self,
            Call::GetSequences { collection: collection.clone(), documents: documents.clone() },
            documents_reply,
            self.inner.get_sequences(collection, documents).await
        )
    }
}

#[async_trait]
impl Relationships for Intercepted {
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        answer!(
            self,
            Call::CreateRelationship { collection: collection.into(), relationship: relationship.clone() },
            bool_reply,
            self.inner.create_relationship(collection, relationship).await
        )
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        answer!(
            self,
            Call::UpdateRelationship {
                collection: collection.into(),
                relationship: relationship.clone(),
                side,
                update: update.clone()
            },
            bool_reply,
            self.inner.update_relationship(collection, relationship, side, update).await
        )
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        answer!(
            self,
            Call::DeleteRelationship { collection: collection.into(), relationship: relationship.clone(), side },
            bool_reply,
            self.inner.delete_relationship(collection, relationship, side).await
        )
    }
}

/// A database over `adapter` without a cache, its database and namespace
/// set and created, as the PHP fixtures build theirs (`new Database($adapter,
/// new Cache(new None()))`, `setDatabase()`, `setNamespace()`, `create()`),
/// its authorization holding the `any` role.
pub(crate) async fn memory_database(adapter: Arc<Intercepted>, namespace: &str) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let database = Database::new(adapter.clone(), cache);
    database.authorization().add_role("any");
    database.set_database("utopiaTests").set_namespace(namespace);
    database.create(None).await.expect("the database is created");
    adapter.clear_calls();
    database
}
