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

/// A name no other test uses (PHP's `uniqid()`).
pub(crate) fn unique(prefix: &str) -> String {
    static NEXT: AtomicU64 = AtomicU64::new(0);
    format!("{prefix}{}_{}", std::process::id(), NEXT.fetch_add(1, Ordering::Relaxed))
}

/// A database over `adapter` caching in `cache`, created under a fresh
/// namespace, its authorization holding the `any` role.
pub(crate) async fn database_over(adapter: Arc<dyn Adapter>, cache: Arc<Cache>) -> Database {
    let database = Database::new(adapter, cache);
    database.set_database("utopiaTests").set_namespace(&unique("ns_"));
    database.create(None).await.expect("the database is created");
    database.authorization().add_role(&Role::any().to_string());
    database
}

/// A database over a fresh Memory adapter caching in `cache`.
pub(crate) async fn memory_database(cache: Arc<Cache>) -> Database {
    database_over(Arc::new(Memory::new()), cache).await
}

/// A collection `id` without attributes, readable and writable by any.
pub(crate) fn open_collection(id: &str, document_security: bool) -> Collection {
    let any = Role::any();
    Collection::create(
        id,
        id,
        Vec::new(),
        Vec::new(),
        Some(vec![Permission::read(&any), Permission::create(&any), Permission::update(&any), Permission::delete(&any)]),
        document_security,
        Default::default(),
    )
    .expect("a valid collection")
}

/// A document of the given attributes.
pub(crate) fn doc(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_pairs(pairs).expect("a valid document")
}

/// A document with just an id.
pub(crate) fn with_id(id: &str) -> Document {
    doc(vec![("$id", Value::from(id))])
}

/// The ids of `documents`.
pub(crate) fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}
