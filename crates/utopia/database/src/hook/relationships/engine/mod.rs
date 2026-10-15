//! The PHP tests of the relationships hook that run on `Adapter\Memory`,
//! on the Memory adapter.
//!
//! Each module is one PHP test file (`tests/unit/Relationships/*`); the
//! cases of PHP's data providers that need an SQL engine stay with the SQL
//! adapters' ports.

mod hook;

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};

use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::{BATCH_SIZE, Database, Hook};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::hook::Permissions;
use crate::hook::relationships::Relationships;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::relationship::Relationship;
use crate::role::Role;
use crate::validator::authorization::Authorization;
use crate::value::{self, Array, Value};

pub(crate) use crate::database::testing::block_on;

static NAMESPACES: AtomicU64 = AtomicU64::new(0);

/// A namespace no other test uses (`uniqid()`).
pub(crate) fn unique(prefix: &str) -> String {
    format!("{prefix}_{}", NAMESPACES.fetch_add(1, Ordering::Relaxed))
}

/// A database over `adapter` as PHP's fixtures build it: no cache, an
/// authorization holding `any`, a fresh namespace, created.
pub(crate) async fn database_on(adapter: Arc<dyn Adapter>, name: &str) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let database = Database::new(adapter, cache);
    let authorization = Arc::new(Authorization::new(true));
    authorization.add_role(&Role::any().to_string());
    database.set_authorization(authorization);
    database.set_database(name).set_namespace(&unique(name));
    database.create(None).await.unwrap();
    database
}

/// A database on a fresh Memory adapter with `hook` and the permissions
/// hook added.
pub(crate) async fn memory(hook: Arc<Relationships>) -> Database {
    let database = database_on(Arc::new(Memory::new()), "relationship_hook").await;
    database.add_hook(Hook::Relationships(hook)).unwrap();
    database.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    database
}

/// A document of `pairs` (`new Document([...])`).
pub(crate) fn doc(pairs: &[(&str, Value)]) -> Document {
    Document::from_pairs(pairs.iter().map(|(k, v)| (*k, v.clone()))).unwrap()
}

/// A string value.
pub(crate) fn s(text: &str) -> Value {
    value::string(text)
}

/// A list of strings.
pub(crate) fn strs(items: &[&str]) -> Value {
    value::list(items.iter().map(|i| value::string(*i)))
}

/// A list of documents.
pub(crate) fn docs(items: Vec<Document>) -> Value {
    value::list(items.into_iter().map(Value::from))
}

/// A document value.
pub(crate) fn d(pairs: &[(&str, Value)]) -> Value {
    Value::from(doc(pairs))
}

/// A list of permission strings.
pub(crate) fn perms(items: &[String]) -> Value {
    value::list(items.iter().map(|p| value::string(p.as_str())))
}

/// Create, read, update and delete for any role.
pub(crate) fn crud() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any), Permission::update(&any), Permission::delete(&any)]
}

/// Create and read for any role.
pub(crate) fn create_read() -> Vec<String> {
    let any = Role::any();
    vec![Permission::create(&any), Permission::read(&any)]
}

/// `Attribute::string($key, $size)`.
pub(crate) fn string(key: &str, size: i64) -> Attribute {
    Attribute::string(key, size)
}

/// Creates the collection `id` (`Collection::create(id:, attributes:,
/// permissions:, documentSecurity:)`).
pub(crate) async fn collection(
    database: &Database,
    id: &str,
    attributes: Vec<Attribute>,
    permissions: Vec<String>,
    document_security: bool,
) {
    let collection =
        Collection::create(id, "", attributes, Vec::new(), Some(permissions), document_security, Array::new())
            .unwrap();
    database.create_collection(collection).await.unwrap();
}

/// Creates a relationship from `collection`.
pub(crate) async fn relate(database: &Database, collection: &str, relationship: Relationship) {
    database.create_relationship(collection, relationship).await.unwrap();
}

/// Creates a document, failing the test on an error.
pub(crate) async fn create(database: &Database, collection: &str, document: Document) -> Document {
    database.create_document(collection, document).await.unwrap()
}

/// Reads a document.
pub(crate) async fn get(database: &Database, collection: &str, id: &str) -> Document {
    database.get_document(collection, id, Vec::new(), false).await.unwrap()
}

/// Reads a document as stored: permissions and relationships skipped.
pub(crate) async fn stored(database: &Database, collection: &str, id: &str) -> Document {
    database
        .authorization()
        .skip(database.skip_relationships(database.get_document(collection, id, Vec::new(), false)))
        .await
        .unwrap()
}

/// Updates a document, failing the test on an error.
pub(crate) async fn update(database: &Database, collection: &str, id: &str, document: Document) -> Document {
    database.update_document(collection, id, document).await.unwrap()
}

/// The sorted ids of every document of `collection`, permissions and
/// relationships skipped.
pub(crate) async fn ids(database: &Database, collection: &str) -> Vec<String> {
    let found = database
        .authorization()
        .skip(database.skip_relationships(database.find(collection, Vec::new(), PermissionType::Read)))
        .await
        .unwrap();
    let mut ids: Vec<String> = found.iter().map(|d| d.id().to_owned()).collect();
    ids.sort();
    ids
}

/// The sorted ids of the related documents `key` of `document` holds.
pub(crate) fn related_ids(document: &Document, key: &str) -> Vec<String> {
    let mut ids: Vec<String> = document.documents(key).unwrap().iter().map(|d| d.id().to_owned()).collect();
    ids.sort();
    ids
}

/// The id of the related document `key` of `document` holds.
pub(crate) fn related_id(document: &Document, key: &str) -> String {
    document.document(key).unwrap().id().to_owned()
}

/// The ids of documents, in order.
pub(crate) fn ids_of(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

/// The sorted ids of documents.
pub(crate) fn sorted_ids(documents: &[Document]) -> Vec<String> {
    let mut ids = ids_of(documents);
    ids.sort();
    ids
}

/// Strings, for comparisons.
pub(crate) fn names(items: &[&str]) -> Vec<String> {
    items.iter().map(|s| (*s).to_owned()).collect()
}

/// The error of `result`, which must be of `kind`.
#[track_caller]
pub(crate) fn failure<T: std::fmt::Debug>(result: Result<T>, kind: Kind) -> Error {
    let error = result.expect_err("the operation must fail");
    assert!(error.is_a(kind), "expected {kind:?}, got {:?}: {}", error.kind(), error.message());
    error
}

/// `updateDocuments()` with the default batch.
pub(crate) async fn update_all(
    database: &Database,
    collection: &str,
    updates: Document,
    queries: Vec<Query>,
) -> Result<i64> {
    database.update_documents(collection, updates, queries, BATCH_SIZE, None).await
}

/// `deleteDocuments()` with the default batch.
pub(crate) async fn delete_all(database: &Database, collection: &str, queries: Vec<Query>) -> Result<i64> {
    database.delete_documents(collection, queries, BATCH_SIZE, None).await
}
