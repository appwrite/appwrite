//! Tests of the schema area (collections, attributes, indexes,
//! relationships and the permissions write hook) on [`StubAdapter`].
//!
//! Tests marked `#[ignore]` write metadata through `updateDocument()` /
//! `createDocument()` / `deleteDocument()` / `find()`, which the writes and
//! reads areas port: they run once those land.

mod attributes;
mod collections;
mod indexes;
mod memory;
mod permissions;
mod relationships;

use std::sync::{Arc, Mutex};

use super::testing::{Call, Reply, StubAdapter, uncached};
use super::{Database, METADATA};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::document::{self, Document};
use crate::error::Error;
use crate::index::Index;
use crate::permission::Permission;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Str, Value};

type Extra = Arc<dyn Fn(&Call) -> Option<Reply> + Send + Sync>;

/// A database over a stub that stores collection definitions: it answers
/// `getDocument()` of the metadata collection from them and records (and
/// applies) the metadata writes (PHP's `setupCollection()` /
/// `metadataWrites`).
pub(super) struct Fixture {
    pub(super) adapter: Arc<StubAdapter>,
    pub(super) db: Database,
    pub(super) store: Arc<Mutex<Vec<Document>>>,
    pub(super) writes: Arc<Mutex<Vec<Document>>>,
    extra: Arc<Mutex<Option<Extra>>>,
}

impl Fixture {
    /// The fixture with `collections` stored.
    pub(super) fn new(collections: Vec<Document>) -> Self {
        let adapter = StubAdapter::new();
        let store = Arc::new(Mutex::new(collections));
        let writes = Arc::new(Mutex::new(Vec::new()));
        let extra: Arc<Mutex<Option<Extra>>> = Arc::new(Mutex::new(None));
        {
            let store = store.clone();
            let writes = writes.clone();
            let extra = extra.clone();
            adapter.on(move |call| {
                let handler = extra.lock().unwrap().clone();
                if let Some(handler) = handler
                    && let Some(reply) = handler(call)
                {
                    return Some(reply);
                }
                match call {
                    Call::GetDocument { collection, id, .. } if collection.id() == METADATA => {
                        let found = store.lock().unwrap().iter().find(|d| d.id() == id).cloned();
                        Some(Reply::Document(found.unwrap_or_else(Document::empty)))
                    }
                    Call::UpdateDocument { collection, id, document, .. } if collection.id() == METADATA => {
                        writes.lock().unwrap().push(document.clone());
                        let mut stored = store.lock().unwrap();
                        stored.retain(|d| d.id() != id);
                        stored.push(document.clone());
                        Some(Reply::Document(document.clone()))
                    }
                    Call::CreateDocument { collection, document } if collection.id() == METADATA => {
                        writes.lock().unwrap().push(document.clone());
                        store.lock().unwrap().push(document.clone());
                        Some(Reply::Document(document.clone()))
                    }
                    Call::DeleteDocument { collection, id } if collection.id() == METADATA => {
                        store.lock().unwrap().retain(|d| d.id() != id);
                        Some(Reply::Bool(true))
                    }
                    _ => None,
                }
            });
        }
        let db = uncached(adapter.clone());
        Fixture { adapter, db, store, writes, extra }
    }

    /// Answers calls with `handler` first (`None` falls through to the
    /// fixture's own answers).
    pub(super) fn on(&self, handler: impl Fn(&Call) -> Option<Reply> + Send + Sync + 'static) -> &Self {
        *self.extra.lock().unwrap() = Some(Arc::new(handler));
        self
    }

    /// The last metadata write, as a collection (`lastMetadataWrite()`).
    pub(super) fn last_write(&self) -> Collection {
        let write = self.writes.lock().unwrap().last().cloned().expect("no metadata was written");
        Collection::from_document(&write).expect("a collection")
    }

    /// The metadata writes so far.
    pub(super) fn write_count(&self) -> usize {
        self.writes.lock().unwrap().len()
    }

    /// The stored definition of `id`.
    pub(super) fn stored(&self, id: &str) -> Collection {
        let found = self.store.lock().unwrap().iter().find(|d| d.id() == id).cloned().expect("a stored collection");
        Collection::from_document(&found).expect("a collection")
    }

    /// The names of the adapter's calls so far.
    pub(super) fn methods(&self) -> Vec<&'static str> {
        self.adapter.calls().iter().map(Call::method).collect()
    }
}

/// The stored definition of a collection, as the metadata collection holds
/// it (`setupCollection()`'s document).
pub(super) fn definition(id: &str, attributes: Vec<Attribute>, indexes: Vec<Index>) -> Document {
    let key = |k: &'static str| ArrayKey::Str(Str::from_static(k));
    let mut metadata = Array::new();
    metadata.set(key(document::COLLECTION), Value::Str(Str::from(METADATA)));
    metadata.set(key(document::CREATED_AT), Value::Str(Str::from("2024-01-01T00:00:00.000+00:00")));
    metadata.set(key(document::UPDATED_AT), Value::Str(Str::from("2024-01-01T00:00:00.000+00:00")));
    let any = Role::any();
    let permissions =
        vec![Permission::read(&any), Permission::create(&any), Permission::update(&any), Permission::delete(&any)];
    Collection::create(id, id, attributes, indexes, Some(permissions), true, metadata).expect("a definition").into()
}

/// [`definition`] with a `$sequence` (junction collections are named after
/// it).
pub(super) fn sequenced(id: &str, sequence: &str, attributes: Vec<Attribute>, indexes: Vec<Index>) -> Document {
    let mut document = definition(id, attributes, indexes);
    document.set(document::SEQUENCE, Value::Str(Str::from(sequence))).expect("a sequence");
    document
}

/// The keys of attributes.
pub(super) fn keys(attributes: &[Attribute]) -> Vec<String> {
    attributes.iter().map(|a| a.key.clone()).collect()
}

/// The keys of indexes.
pub(super) fn index_keys(indexes: &[Index]) -> Vec<String> {
    indexes.iter().map(|i| i.key.clone()).collect()
}

/// The error's kind and message.
pub(super) fn describe(error: &Error) -> (crate::Kind, String) {
    (error.kind(), error.message().to_owned())
}
