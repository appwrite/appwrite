//! PHP: `Collections\DefinitionModelCacheTest`, on Memory (counted as
//! `CountingMemory` counts) with an in-memory cache.

use std::sync::Arc;

use crate::attribute::{Attribute, Update};
use crate::collection::Update as CollectionUpdate;
use crate::database::testing::{Call, block_on};
use crate::database::testing_memory::Intercepted;
use crate::database::{Database, METADATA};
use crate::index::Index;
use crate::permission::{Permission, PermissionType};
use crate::role::Role;

/// What a cached read of `books` shows.
#[derive(Debug, PartialEq)]
struct Read {
    attributes: Vec<String>,
    sizes: Vec<(String, Option<i64>)>,
    indexes: Vec<String>,
    name: String,
}

fn cache() -> Arc<utopia_cache::Cache> {
    Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::Memory::new())))
}

async fn database(adapter: Arc<Intercepted>, cache: Arc<utopia_cache::Cache>, namespace: &str) -> Database {
    let db = Database::new(adapter, cache);
    db.authorization().add_role("any");
    db.set_database("definitions").set_namespace(namespace);
    db.create(None).await.unwrap();
    let any = Role::any();
    let books = crate::collection::Collection::create(
        "books",
        "",
        vec![Attribute::string("title", 64)],
        Vec::new(),
        Some(vec![Permission::create(&any), Permission::read(&any)]),
        true,
        crate::value::Array::new(),
    )
    .unwrap();
    db.create_collection(books).await.unwrap();
    db
}

/// The `getDocument()` calls on the metadata collection (`metadataReads`).
fn metadata_reads(adapter: &Intercepted) -> usize {
    adapter
        .calls_of("getDocument")
        .iter()
        .filter(|c| matches!(c, Call::GetDocument { collection, .. } if collection.id() == METADATA))
        .count()
}

/// Reads the definition twice and checks the second read was served by
/// the cache (`cachedRead()`).
async fn cached_read(db: &Database, adapter: &Intercepted) -> Read {
    db.get_collection("books").await.unwrap();
    adapter.clear_calls();
    let collection = db.get_collection("books").await.unwrap();
    assert_eq!(metadata_reads(adapter), 0, "the definition was not served by the cache");
    let attributes = collection.attributes().unwrap();
    Read {
        attributes: attributes.iter().map(|a| a.key.clone()).collect(),
        sizes: attributes.iter().map(|a| (a.key.clone(), a.size)).collect(),
        indexes: collection.indexes().unwrap().iter().map(|i| i.key.clone()).collect(),
        name: collection.name().to_owned(),
    }
}

#[test]
fn every_schema_change_is_served_from_the_cached_definition() {
    block_on(async {
        let adapter = Intercepted::new();
        let db = database(adapter.clone(), cache(), "definitions_every").await;
        assert_eq!(cached_read(&db, &adapter).await.attributes, ["title"]);

        db.create_attribute("books", Attribute::integer("pages")).await.unwrap();
        assert_eq!(cached_read(&db, &adapter).await.attributes, ["title", "pages"]);

        db.update_attribute("books", "title", Update { size: Some(128), ..Update::default() }).await.unwrap();
        let read = cached_read(&db, &adapter).await;
        assert_eq!(read.sizes[0], ("title".to_owned(), Some(128)));

        db.rename_attribute("books", "pages", "length").await.unwrap();
        assert_eq!(cached_read(&db, &adapter).await.attributes, ["title", "length"]);

        db.create_index("books", Index::key("by_length", ["length"], Vec::new(), Vec::new()).unwrap()).await.unwrap();
        assert_eq!(cached_read(&db, &adapter).await.indexes, ["by_length"]);

        db.delete_index("books", "by_length").await.unwrap();
        db.delete_attribute("books", "length").await.unwrap();
        let read = cached_read(&db, &adapter).await;
        assert_eq!(read.attributes, ["title"]);
        assert!(read.indexes.is_empty());
    });
}

#[test]
fn a_schema_change_by_another_instance_is_served() {
    block_on(async {
        let adapter = Intercepted::new();
        let cache = cache();
        let reader = database(adapter.clone(), cache.clone(), "definitions_other").await;
        let writer = Database::new(adapter.clone(), cache);
        writer.authorization().add_role("any");
        writer.set_database(&reader.database()).set_namespace(&reader.namespace());

        assert_eq!(cached_read(&reader, &adapter).await.attributes, ["title"]);
        writer.create_attribute("books", Attribute::boolean("lent")).await.unwrap();
        assert_eq!(cached_read(&reader, &adapter).await.attributes, ["title", "lent"]);
    });
}

#[test]
fn changing_a_read_model_does_not_change_the_next_read() {
    block_on(async {
        let adapter = Intercepted::new();
        let db = database(adapter.clone(), cache(), "definitions_model").await;
        let expected = cached_read(&db, &adapter).await;
        let mut collection = db.get_collection("books").await.unwrap();
        collection.set("name", crate::value::Value::Str("changed".into())).unwrap();
        collection.set("attributes", crate::value::Value::Array(crate::value::Array::new())).unwrap();
        assert_eq!(cached_read(&db, &adapter).await, expected);
    });
}

#[test]
fn the_cached_definition_serves_its_current_permissions() {
    block_on(async {
        let adapter = Intercepted::new();
        let db = database(adapter.clone(), cache(), "definitions_permissions").await;
        cached_read(&db, &adapter).await;
        assert_eq!(
            db.get_collection("books").await.unwrap().permissions_by_type(PermissionType::Read.as_str()).unwrap(),
            ["any"]
        );

        let permissions = vec![Permission::read(&Role::users("")), Permission::update(&Role::any())];
        db.update_collection(
            "books",
            CollectionUpdate { permissions: Some(permissions), document_security: Some(false) },
        )
        .await
        .unwrap();
        cached_read(&db, &adapter).await;
        let collection = db.get_collection("books").await.unwrap();
        assert_eq!(collection.permissions_by_type(PermissionType::Read.as_str()).unwrap(), ["users"]);
        assert_eq!(collection.permissions_by_type(PermissionType::Update.as_str()).unwrap(), ["any"]);
    });
}

#[test]
fn the_metadata_collection_needs_no_read() {
    block_on(async {
        let adapter = Intercepted::new();
        let db = database(adapter.clone(), cache(), "definitions_metadata").await;
        adapter.clear_calls();
        db.get_collection(METADATA).await.unwrap();
        assert_eq!(metadata_reads(&adapter), 0);
    });
}
