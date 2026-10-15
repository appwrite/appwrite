//! Test support: [`StubAdapter`], an adapter whose every engine call is
//! recorded and answered by a handler the test sets (PHP's tests stub or
//! mock `Adapter` with PHPUnit), and helpers to build a database over it.
//!
//! A call the handler does not answer gets the stub default: `true` for the
//! schema and transaction calls, the document given back for the writes,
//! an empty document for `getDocument()`, nothing for reads, `0` for
//! counts. Transactions keep a real nesting depth, so
//! [`crate::adapter::with_transaction`] behaves as on an engine.

#![allow(dead_code)]

use std::any::Any;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use php_std::datetime::DateTime;
use utopia_query::schema::ColumnType;

use super::Database;
use crate::adapter::{Adapter, Find, Limits, State};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::change::Change;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::{Error, Result};
use crate::index::Index;
use crate::query::Query;
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::schema;
use crate::value::Value;

/// One call the database made on the adapter, with its arguments.
#[derive(Debug, Clone)]
#[allow(missing_docs)]
pub(crate) enum Call {
    StartTransaction,
    CommitTransaction,
    RollbackTransaction,
    Create {
        name: String,
    },
    Update {
        name: String,
        new: String,
    },
    Exists {
        database: String,
    },
    CollectionExists {
        database: String,
        collection: String,
    },
    List,
    Delete {
        name: String,
    },
    CreateCollection {
        collection: String,
        attributes: Vec<Attribute>,
        indexes: Vec<Index>,
    },
    DeleteCollection {
        collection: String,
    },
    AnalyzeCollection {
        collection: String,
    },
    SizeOfCollection {
        collection: String,
    },
    SizeOfCollectionOnDisk {
        collection: String,
    },
    CreateAttribute {
        collection: String,
        attribute: Attribute,
    },
    CreateAttributes {
        collection: String,
        attributes: Vec<Attribute>,
    },
    UpdateAttribute {
        collection: String,
        key: String,
        attribute: Attribute,
    },
    DeleteAttribute {
        collection: String,
        key: String,
    },
    RenameAttribute {
        collection: String,
        old: String,
        new: String,
    },
    SchemaAttributes {
        collection: String,
    },
    SchemaIndexes {
        collection: String,
    },
    CreateIndex {
        collection: String,
        index: Index,
    },
    DeleteIndex {
        collection: String,
        key: String,
    },
    RenameIndex {
        collection: String,
        old: String,
        new: String,
    },
    CreateDocument {
        collection: Collection,
        document: Document,
    },
    CreateDocuments {
        collection: Collection,
        documents: Vec<Document>,
    },
    GetDocument {
        collection: Collection,
        id: String,
        queries: Vec<Query>,
        for_update: bool,
    },
    UpdateDocument {
        collection: Collection,
        id: String,
        document: Document,
        skip_permissions: bool,
    },
    UpdateDocuments {
        collection: Collection,
        updates: Document,
        documents: Vec<Document>,
        skip_permissions: Vec<String>,
    },
    IncreaseDocumentAttribute {
        collection: Collection,
        id: String,
        attribute: String,
        value: Value,
        updated_at: String,
        min: Option<Value>,
        max: Option<Value>,
    },
    DeleteDocument {
        collection: Collection,
        id: String,
    },
    DeleteDocuments {
        collection: Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    },
    Find {
        collection: Collection,
        queries: Vec<Query>,
        options: Find,
    },
    Count {
        collection: Collection,
        queries: Vec<Query>,
        max: Option<i64>,
    },
    Sum {
        collection: Collection,
        attribute: String,
        queries: Vec<Query>,
        max: Option<i64>,
    },
    GetSequences {
        collection: Collection,
        documents: Vec<Document>,
    },
    CreateRelationship {
        collection: String,
        relationship: Relationship,
    },
    UpdateRelationship {
        collection: String,
        relationship: Relationship,
        side: RelationshipSide,
        update: relationship::Update,
    },
    DeleteRelationship {
        collection: String,
        relationship: Relationship,
        side: RelationshipSide,
    },
}

impl Call {
    /// The PHP name of the method called.
    pub(crate) fn method(&self) -> &'static str {
        match self {
            Call::StartTransaction => "startTransaction",
            Call::CommitTransaction => "commitTransaction",
            Call::RollbackTransaction => "rollbackTransaction",
            Call::Create { .. } => "create",
            Call::Update { .. } => "update",
            Call::Exists { .. } => "exists",
            Call::CollectionExists { .. } => "collectionExists",
            Call::List => "list",
            Call::Delete { .. } => "delete",
            Call::CreateCollection { .. } => "createCollection",
            Call::DeleteCollection { .. } => "deleteCollection",
            Call::AnalyzeCollection { .. } => "analyzeCollection",
            Call::SizeOfCollection { .. } => "getSizeOfCollection",
            Call::SizeOfCollectionOnDisk { .. } => "getSizeOfCollectionOnDisk",
            Call::CreateAttribute { .. } => "createAttribute",
            Call::CreateAttributes { .. } => "createAttributes",
            Call::UpdateAttribute { .. } => "updateAttribute",
            Call::DeleteAttribute { .. } => "deleteAttribute",
            Call::RenameAttribute { .. } => "renameAttribute",
            Call::SchemaAttributes { .. } => "getSchemaAttributes",
            Call::SchemaIndexes { .. } => "getSchemaIndexes",
            Call::CreateIndex { .. } => "createIndex",
            Call::DeleteIndex { .. } => "deleteIndex",
            Call::RenameIndex { .. } => "renameIndex",
            Call::CreateDocument { .. } => "createDocument",
            Call::CreateDocuments { .. } => "createDocuments",
            Call::GetDocument { .. } => "getDocument",
            Call::UpdateDocument { .. } => "updateDocument",
            Call::UpdateDocuments { .. } => "updateDocuments",
            Call::IncreaseDocumentAttribute { .. } => "increaseDocumentAttribute",
            Call::DeleteDocument { .. } => "deleteDocument",
            Call::DeleteDocuments { .. } => "deleteDocuments",
            Call::Find { .. } => "find",
            Call::Count { .. } => "count",
            Call::Sum { .. } => "sum",
            Call::GetSequences { .. } => "getSequences",
            Call::CreateRelationship { .. } => "createRelationship",
            Call::UpdateRelationship { .. } => "updateRelationship",
            Call::DeleteRelationship { .. } => "deleteRelationship",
        }
    }
}

/// What a handler answers a call with.
#[derive(Debug, Clone)]
#[allow(missing_docs)]
pub(crate) enum Reply {
    Bool(bool),
    Int(i64),
    Value(Value),
    Document(Document),
    Documents(Vec<Document>),
    Columns(Vec<schema::Column>),
    Indexes(Vec<schema::Index>),
    Error(Error),
}

type Handler = Arc<dyn Fn(&Call) -> Option<Reply> + Send + Sync>;

/// An adapter whose engine calls a test answers. See the [module docs](self).
pub(crate) struct StubAdapter {
    state: State,
    capabilities: Mutex<Capabilities>,
    limits: Mutex<Arc<Limits>>,
    handler: Mutex<Option<Handler>>,
    calls: Mutex<Vec<Call>>,
    measures: Mutex<Measures>,
    column_type: Mutex<Option<ColumnTypeOf>>,
    relationships: AtomicBool,
}

/// What `getColumnType()` answers, by attribute.
type ColumnTypeOf = Arc<dyn Fn(&Attribute) -> Option<String> + Send + Sync>;

/// The answers of the stub's measures (PHP stubs' `getCountOfAttributes()`,
/// `getCountOfIndexes()`, `getAttributeWidth()`); `None` answers 0.
#[derive(Debug, Clone, Copy, Default)]
pub(crate) struct Measures {
    /// `getCountOfAttributes()`; `Some(-1)` counts the collection's
    /// attributes (the default adapter's count).
    pub(crate) attributes: Option<i64>,
    /// `getCountOfIndexes()`; `Some(-1)` counts the collection's indexes.
    pub(crate) indexes: Option<i64>,
    /// `getAttributeWidth()`.
    pub(crate) width: Option<i64>,
}

/// The limits PHP's stubs give (`new Limits(...)` of the unit tests).
pub(crate) fn limits() -> Limits {
    let utc = php_std::datetime::TzInfo::utc();
    Limits {
        string: 16_777_215,
        varchar: 16_383,
        integer: 2_147_483_647,
        big_integer: 0,
        attributes: 0,
        indexes: 64,
        default_attributes: 0,
        default_indexes: 0,
        index_length: 768,
        uid_length: 36,
        document_size: 0,
        min_date_time: DateTime::new(b"0000-01-01", None, &utc).expect("a date"),
        max_date_time: DateTime::new(b"9999-12-31", None, &utc).expect("a date"),
        id_type: ColumnType::String,
        keywords: Vec::new(),
        internal_index_keys: Vec::new(),
    }
}

impl Default for StubAdapter {
    fn default() -> Self {
        StubAdapter {
            state: State::default(),
            capabilities: Mutex::new(Capabilities::of(&[
                Capability::IndexKey,
                Capability::IndexArray,
                Capability::IndexUnique,
                Capability::DefinedAttributes,
            ])),
            limits: Mutex::new(Arc::new(limits())),
            handler: Mutex::new(None),
            calls: Mutex::new(Vec::new()),
            measures: Mutex::new(Measures::default()),
            column_type: Mutex::new(None),
            relationships: AtomicBool::new(false),
        }
    }
}

impl StubAdapter {
    /// A stub supporting key, array and unique indexes and defined attributes.
    pub(crate) fn new() -> Arc<Self> {
        Arc::new(StubAdapter::default())
    }

    /// Answers calls with `handler` (`method(...)->willReturnCallback(...)`);
    /// `None` from it is the stub default.
    pub(crate) fn on(&self, handler: impl Fn(&Call) -> Option<Reply> + Send + Sync + 'static) -> &Self {
        *self.handler.lock().unwrap() = Some(Arc::new(handler));
        self
    }

    /// Sets the capabilities.
    pub(crate) fn set_capabilities(&self, capabilities: &[Capability]) -> &Self {
        *self.capabilities.lock().unwrap() = Capabilities::of(capabilities);
        self
    }

    /// Adds a capability.
    pub(crate) fn support(&self, capability: Capability) -> &Self {
        let mut c = self.capabilities.lock().unwrap();
        *c = c.with(capability);
        self
    }

    /// Sets the limits.
    pub(crate) fn set_limits(&self, limits: Limits) -> &Self {
        *self.limits.lock().unwrap() = Arc::new(limits);
        self
    }

    /// Sets the measures.
    pub(crate) fn set_measures(&self, measures: Measures) -> &Self {
        *self.measures.lock().unwrap() = measures;
        self
    }

    /// Answers `getColumnType()` with `column_type`.
    pub(crate) fn set_column_type(
        &self,
        column_type: impl Fn(&Attribute) -> Option<String> + Send + Sync + 'static,
    ) -> &Self {
        *self.column_type.lock().unwrap() = Some(Arc::new(column_type));
        self
    }

    /// Implements `Feature\Relationships`: its calls are recorded and
    /// answered like the others (`true` by default).
    pub(crate) fn with_relationships(&self) -> &Self {
        self.relationships.store(true, Ordering::SeqCst);
        self
    }

    /// The calls made so far, in order.
    pub(crate) fn calls(&self) -> Vec<Call> {
        self.calls.lock().unwrap().clone()
    }

    /// The calls of `method` (PHP name) made so far.
    pub(crate) fn calls_of(&self, method: &str) -> Vec<Call> {
        self.calls().into_iter().filter(|c| c.method() == method).collect()
    }

    /// Forgets the calls made so far.
    pub(crate) fn clear_calls(&self) {
        self.calls.lock().unwrap().clear();
    }

    fn call(&self, call: Call) -> Option<Reply> {
        let handler = self.handler.lock().unwrap().clone();
        let reply = handler.and_then(|h| h(&call));
        self.calls.lock().unwrap().push(call);
        reply
    }
}

fn bool_reply(reply: Option<Reply>, default: bool) -> Result<bool> {
    match reply {
        None => Ok(default),
        Some(Reply::Bool(b)) => Ok(b),
        Some(Reply::Error(e)) => Err(e),
        Some(other) => panic!("a boolean reply, not {other:?}"),
    }
}

fn int_reply(reply: Option<Reply>, default: i64) -> Result<i64> {
    match reply {
        None => Ok(default),
        Some(Reply::Int(i)) => Ok(i),
        Some(Reply::Error(e)) => Err(e),
        Some(other) => panic!("an integer reply, not {other:?}"),
    }
}

fn document_reply(reply: Option<Reply>, default: Document) -> Result<Document> {
    match reply {
        None => Ok(default),
        Some(Reply::Document(d)) => Ok(d),
        Some(Reply::Error(e)) => Err(e),
        Some(other) => panic!("a document reply, not {other:?}"),
    }
}

fn documents_reply(reply: Option<Reply>, default: Vec<Document>) -> Result<Vec<Document>> {
    match reply {
        None => Ok(default),
        Some(Reply::Documents(d)) => Ok(d),
        Some(Reply::Error(e)) => Err(e),
        Some(other) => panic!("a documents reply, not {other:?}"),
    }
}

#[async_trait]
impl Adapter for StubAdapter {
    fn state(&self) -> &State {
        &self.state
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        *self.capabilities.lock().unwrap()
    }

    fn limits(&self) -> Arc<Limits> {
        self.limits.lock().unwrap().clone()
    }

    async fn start_transaction(&self) -> Result<bool> {
        let ok = bool_reply(self.call(Call::StartTransaction), true)?;
        if ok {
            self.state.lock().in_transaction += 1;
        }
        Ok(ok)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        let ok = bool_reply(self.call(Call::CommitTransaction), true)?;
        let mut state = self.state.lock();
        state.in_transaction = (state.in_transaction - 1).max(0);
        Ok(ok)
    }

    async fn rollback_transaction(&self) -> Result<bool> {
        let ok = bool_reply(self.call(Call::RollbackTransaction), true)?;
        let mut state = self.state.lock();
        state.in_transaction = (state.in_transaction - 1).max(0);
        Ok(ok)
    }

    async fn create(&self, name: &str) -> Result<bool> {
        bool_reply(self.call(Call::Create { name: name.into() }), true)
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        bool_reply(self.call(Call::Update { name: name.into(), new: new.into() }), true)
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        bool_reply(self.call(Call::Exists { database: database.into() }), false)
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        bool_reply(
            self.call(Call::CollectionExists { database: database.into(), collection: collection.into() }),
            false,
        )
    }

    async fn list(&self) -> Result<Vec<Document>> {
        documents_reply(self.call(Call::List), Vec::new())
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        bool_reply(self.call(Call::Delete { name: name.into() }), true)
    }

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        bool_reply(
            self.call(Call::CreateCollection {
                collection: collection.into(),
                attributes: attributes.to_vec(),
                indexes: indexes.to_vec(),
            }),
            true,
        )
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        bool_reply(self.call(Call::DeleteCollection { collection: collection.into() }), true)
    }

    async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        bool_reply(self.call(Call::AnalyzeCollection { collection: collection.into() }), false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        int_reply(self.call(Call::SizeOfCollection { collection: collection.into() }), 0)
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        int_reply(self.call(Call::SizeOfCollectionOnDisk { collection: collection.into() }), 0)
    }

    fn attribute_width(&self, _collection: &Collection) -> Result<i64> {
        Ok(self.measures.lock().unwrap().width.unwrap_or(0))
    }

    fn count_of_attributes(&self, collection: &Collection) -> Result<i64> {
        let measure = self.measures.lock().unwrap().attributes;
        match measure {
            Some(-1) => Ok(collection.attributes()?.len() as i64 + self.limits().default_attributes),
            other => Ok(other.unwrap_or(0)),
        }
    }

    fn count_of_indexes(&self, collection: &Collection) -> Result<i64> {
        let measure = self.measures.lock().unwrap().indexes;
        match measure {
            Some(-1) => Ok(collection.indexes()?.len() as i64 + self.limits().default_indexes),
            other => Ok(other.unwrap_or(0)),
        }
    }

    fn relationships(&self) -> Option<&dyn crate::adapter::Relationships> {
        if self.relationships.load(Ordering::SeqCst) { Some(self) } else { None }
    }

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        bool_reply(
            self.call(Call::CreateAttribute { collection: collection.into(), attribute: attribute.clone() }),
            true,
        )
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        bool_reply(
            self.call(Call::CreateAttributes { collection: collection.into(), attributes: attributes.to_vec() }),
            true,
        )
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        bool_reply(
            self.call(Call::UpdateAttribute {
                collection: collection.into(),
                key: key.into(),
                attribute: attribute.clone(),
            }),
            true,
        )
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        bool_reply(self.call(Call::DeleteAttribute { collection: collection.into(), key: key.into() }), true)
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        bool_reply(
            self.call(Call::RenameAttribute { collection: collection.into(), old: old.into(), new: new.into() }),
            true,
        )
    }

    async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        match self.call(Call::SchemaAttributes { collection: collection.into() }) {
            None => Ok(Vec::new()),
            Some(Reply::Columns(c)) => Ok(c),
            Some(Reply::Error(e)) => Err(e),
            Some(other) => panic!("a columns reply, not {other:?}"),
        }
    }

    async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        match self.call(Call::SchemaIndexes { collection: collection.into() }) {
            None => Ok(Vec::new()),
            Some(Reply::Indexes(i)) => Ok(i),
            Some(Reply::Error(e)) => Err(e),
            Some(other) => panic!("an indexes reply, not {other:?}"),
        }
    }

    fn column_type(&self, attribute: &Attribute) -> Result<Option<String>> {
        let column_type = self.column_type.lock().unwrap().clone();
        Ok(column_type.and_then(|f| f(attribute)))
    }

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        _attribute_types: &[(String, ColumnType)],
        _collation: &[(String, String)],
    ) -> Result<bool> {
        bool_reply(self.call(Call::CreateIndex { collection: collection.into(), index: index.clone() }), true)
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        bool_reply(self.call(Call::DeleteIndex { collection: collection.into(), key: key.into() }), true)
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        bool_reply(
            self.call(Call::RenameIndex { collection: collection.into(), old: old.into(), new: new.into() }),
            true,
        )
    }

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        let reply = self.call(Call::CreateDocument { collection: collection.clone(), document: document.clone() });
        document_reply(reply, document)
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        let reply = self.call(Call::CreateDocuments { collection: collection.clone(), documents: documents.clone() });
        documents_reply(reply, documents)
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        let reply = self.call(Call::GetDocument { collection: collection.clone(), id: id.into(), queries, for_update });
        document_reply(reply, Document::empty())
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        let reply = self.call(Call::UpdateDocument {
            collection: collection.clone(),
            id: id.into(),
            document: document.clone(),
            skip_permissions,
        });
        document_reply(reply, document)
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        skip_permissions: &[String],
    ) -> Result<i64> {
        let count = documents.len() as i64;
        let reply = self.call(Call::UpdateDocuments {
            collection: collection.clone(),
            updates: updates.clone(),
            documents,
            skip_permissions: skip_permissions.to_vec(),
        });
        int_reply(reply, count)
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
        let reply = self.call(Call::IncreaseDocumentAttribute {
            collection: collection.clone(),
            id: id.into(),
            attribute: attribute.into(),
            value,
            updated_at: updated_at.into(),
            min,
            max,
        });
        bool_reply(reply, true)
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        bool_reply(self.call(Call::DeleteDocument { collection: collection.clone(), id: id.into() }), true)
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        let count = sequences.len() as i64;
        let reply = self.call(Call::DeleteDocuments { collection: collection.clone(), sequences, permission_ids });
        int_reply(reply, count)
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        documents_reply(self.call(Call::Find { collection: collection.clone(), queries, options }), Vec::new())
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        int_reply(self.call(Call::Count { collection: collection.clone(), queries, max }), 0)
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        match self.call(Call::Sum { collection: collection.clone(), attribute: attribute.into(), queries, max }) {
            None => Ok(Value::Int(0)),
            Some(Reply::Value(v)) => Ok(v),
            Some(Reply::Int(i)) => Ok(Value::Int(i)),
            Some(Reply::Error(e)) => Err(e),
            Some(other) => panic!("a sum reply, not {other:?}"),
        }
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        let reply = self.call(Call::GetSequences { collection: collection.clone(), documents: documents.clone() });
        documents_reply(reply, documents)
    }
}

#[async_trait]
impl crate::adapter::Relationships for StubAdapter {
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        bool_reply(
            self.call(Call::CreateRelationship { collection: collection.into(), relationship: relationship.clone() }),
            true,
        )
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        bool_reply(
            self.call(Call::UpdateRelationship {
                collection: collection.into(),
                relationship: relationship.clone(),
                side,
                update: update.clone(),
            }),
            true,
        )
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        bool_reply(
            self.call(Call::DeleteRelationship {
                collection: collection.into(),
                relationship: relationship.clone(),
                side,
            }),
            true,
        )
    }
}

/// A database over `adapter`, with an in-memory cache, its authorization
/// holding the `any` role (what PHP's tests set up).
pub(crate) fn database(adapter: Arc<StubAdapter>) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())));
    let database = Database::new(adapter, cache);
    database.authorization().add_role("any");
    database
}

/// A database over `adapter` with a cache that stores nothing (PHP's
/// `new Cache(new None())`).
pub(crate) fn uncached(adapter: Arc<StubAdapter>) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let database = Database::new(adapter, cache);
    database.authorization().add_role("any");
    database
}

/// Runs a future to completion on a current-thread runtime.
pub(crate) fn block_on<F: std::future::Future>(future: F) -> F::Output {
    tokio::runtime::Builder::new_current_thread().enable_all().build().expect("a runtime").block_on(future)
}

/// An `Adapter` upsert feature for stubs that need one.
pub(crate) struct StubUpserts;

#[async_trait]
impl crate::adapter::Upserts for StubUpserts {
    async fn upsert_document(&self, _collection: &Collection, change: Change) -> Result<Document> {
        Ok(change.new)
    }

    async fn upsert_documents(
        &self,
        _collection: &Collection,
        changes: Vec<Change>,
        _increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        Ok(changes.into_iter().map(|c| c.new).collect())
    }
}
