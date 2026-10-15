//! The schema area's PHP unit tests that run on `Adapter\Memory`, on the
//! Rust Memory adapter (through [`Intercepted`] where PHP subclasses
//! Memory to override a method).

mod attributes;
mod collections;
mod indexes;
mod relationships;

use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};

use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::Database;
use crate::database::testing_memory::{Intercepted, memory_database};
use crate::index::Index;
use crate::value::Array;

/// A fresh Memory database under a namespace of its own.
pub(super) async fn memory() -> (Arc<Intercepted>, Database) {
    static NEXT: AtomicUsize = AtomicUsize::new(0);
    let adapter = Intercepted::new();
    let namespace = format!("schema_{}", NEXT.fetch_add(1, Ordering::SeqCst));
    let database = memory_database(adapter.clone(), &namespace).await;
    (adapter, database)
}

/// `Collection::create($id, attributes: ..., indexes: ...)`.
pub(super) fn collection(id: &str, attributes: Vec<Attribute>, indexes: Vec<Index>) -> Collection {
    Collection::create(id, "", attributes, indexes, None, true, Array::new()).expect("a collection")
}

/// The keys of the collection's attributes.
pub(super) async fn attribute_keys(database: &Database, collection: &str) -> Vec<String> {
    database.get_collection(collection).await.unwrap().attributes().unwrap().iter().map(|a| a.key.clone()).collect()
}

/// The keys of the collection's indexes.
pub(super) async fn index_keys(database: &Database, collection: &str) -> Vec<String> {
    database.get_collection(collection).await.unwrap().indexes().unwrap().iter().map(|i| i.key.clone()).collect()
}
