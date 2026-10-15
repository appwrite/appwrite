//! Databases over the Memory adapter, built as PHP's unit fixtures build
//! them, and what tests over them share.

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};

use utopia_cache::Cache;

use super::Database;
use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::collection::Collection;
use crate::document::Document;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::Value;

/// Builds the future `test` returns and runs it to completion, both on a
/// 64 MiB stack: a debug build's test future can outgrow the test thread's
/// stack before it is first polled.
pub(crate) fn run<F, Fut>(test: F) -> Fut::Output
where
    F: FnOnce() -> Fut + Send,
    Fut: std::future::Future,
    Fut::Output: Send,
{
    std::thread::scope(|scope| {
        std::thread::Builder::new()
            .stack_size(64 * 1024 * 1024)
            .spawn_scoped(scope, || {
                let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("a runtime");
                runtime.block_on(Box::pin(test()))
            })
            .expect("a thread")
            .join()
            .unwrap_or_else(|panic| std::panic::resume_unwind(panic))
    })
}

/// A name no other test uses (PHP's `uniqid()`).
pub(crate) fn unique(prefix: &str) -> String {
    static NEXT: AtomicU64 = AtomicU64::new(0);
    format!("{prefix}{}_{}", std::process::id(), NEXT.fetch_add(1, Ordering::Relaxed))
}

/// A database over `adapter` caching in `cache`, created under a fresh
/// namespace, its authorization holding the `any` role.
pub(crate) async fn database_over(adapter: Arc<dyn Adapter>, cache: Arc<Cache>) -> Database {
    let database = Database::new(adapter, cache);
    setup(&database).await;
    database
}

/// Puts `database` under a fresh namespace, creates it and gives its
/// authorization the `any` role.
pub(crate) async fn setup(database: &Database) {
    database.set_database("utopiaTests").set_namespace(&unique("ns_"));
    database.create(None).await.expect("the database is created");
    database.authorization().add_role(&Role::any().to_string());
}

/// A database over a fresh Memory adapter caching in `cache`.
pub(crate) async fn memory_database(cache: Arc<Cache>) -> Database {
    database_over(Arc::new(Memory::new()), cache).await
}

/// Read, create, update and delete for any (PHP fixtures' `permissions()`).
pub(crate) fn any_permissions() -> Vec<String> {
    let any = Role::any();
    vec![Permission::read(&any), Permission::create(&any), Permission::update(&any), Permission::delete(&any)]
}

/// The same database without a cache (`UncachedTwin::of()`): its writes
/// purge nothing the original cached.
pub(crate) fn uncached_twin(database: &Database) -> Database {
    let none = Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None)));
    let twin = Database::new(database.adapter().clone(), none);
    twin.set_authorization(database.authorization())
        .set_database(&database.database())
        .set_namespace(&database.namespace());
    twin
}

/// A collection `id` without attributes, readable and writable by any.
pub(crate) fn open_collection(id: &str, document_security: bool) -> Collection {
    collection(id, Vec::new(), Vec::new(), Some(any_permissions()), document_security)
}

/// A collection (`Collection::create(...)`).
pub(crate) fn collection(
    id: &str,
    attributes: Vec<crate::Attribute>,
    indexes: Vec<crate::Index>,
    permissions: Option<Vec<String>>,
    document_security: bool,
) -> Collection {
    Collection::create(id, id, attributes, indexes, permissions, document_security, Default::default())
        .expect("a valid collection")
}

/// A string attribute of 255 characters (`Attribute::string(key: ...)`).
pub(crate) fn string(key: &str) -> crate::Attribute {
    crate::Attribute::string(key, 255)
}

/// A document of the given attributes.
pub(crate) fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).expect("a valid document")
}

/// A list of strings as a value (`$permissions`, `['a', 'b']`).
pub(crate) fn strings<S: AsRef<str>>(items: impl IntoIterator<Item = S>) -> Value {
    Value::Array(items.into_iter().map(|s| Value::Str(crate::value::Str::from(s.as_ref()))).collect())
}

/// A failure of the cache (`RuntimeException('Cache unavailable')`).
pub(crate) fn unavailable<T>() -> utopia_cache::Result<T> {
    Err(utopia_cache::Error::Runtime("Cache unavailable".into()))
}

/// A document with just an id.
pub(crate) fn with_id(id: &str) -> Document {
    doc(vec![("$id", Value::from(id))])
}

/// The ids of `documents`.
pub(crate) fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}
