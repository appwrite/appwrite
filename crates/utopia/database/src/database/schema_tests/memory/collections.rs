//! PHP: `Collections\FindCollectionTest`, `Collections\GetCollectionThrowsTest`,
//! `Collections\ExtrasTest`, `Collections\CollectionDefinitionTest`,
//! `CollectionDefinitionTest`, the Memory cases of
//! `Collections\CollectionGuardsTest`, `CreateCollectionCleanupTest`,
//! `CreateCollectionRaceTest`, `UnconfirmedSchemaChangeTest`.

use utopia_query::schema::ColumnType;

use super::{collection, memory};
use crate::attribute::Attribute;
use crate::collection::{self, Collection, Update};
use crate::database::testing::{Call, Reply, block_on};
use crate::database::{LENGTH_KEY, METADATA, collection_definition};
use crate::document;
use crate::error::{Error, Kind};
use crate::permission::Permission;
use crate::role::Role;
use crate::value::{Array, ArrayKey, Str, Value};

fn keys(attributes: &[Attribute]) -> Vec<String> {
    attributes.iter().map(|a| a.key.clone()).collect()
}

// ---- FindCollectionTest -------------------------------------------------

#[test]
fn find_collection_returns_the_stored_collection() {
    block_on(async {
        let (_, db) = memory().await;
        let books = Collection::create("books", "Books", vec![Attribute::string("title", 64)], Vec::new(), None, true, Array::new());
        db.create_collection(books.unwrap()).await.unwrap();
        let found = db.find_collection("books").await.unwrap().unwrap();
        assert_eq!(found.id(), "books");
        assert_eq!(found.name(), "Books");
        assert_eq!(keys(found.attributes().unwrap()), ["title"]);
    });
}

#[test]
fn find_collection_returns_none_for_a_missing_or_deleted_collection() {
    block_on(async {
        let (_, db) = memory().await;
        assert!(db.find_collection("missing").await.unwrap().is_none());
        assert_eq!(db.find_collection(METADATA).await.unwrap().unwrap().id(), METADATA);
        db.create_collection(collection("books", Vec::new(), Vec::new())).await.unwrap();
        db.delete_collection("books").await.unwrap();
        assert!(db.find_collection("books").await.unwrap().is_none());
    });
}

#[test]
fn another_tenants_collection_is_not_found() {
    block_on(async {
        let (_, db) = memory().await;
        db.set_shared_tables(true);
        db.set_tenant(Value::Int(1));
        db.create_collection(collection("books", Vec::new(), Vec::new())).await.unwrap();
        assert_eq!(db.find_collection("books").await.unwrap().unwrap().id(), "books");
        db.set_tenant(Value::Int(2));
        assert!(db.find_collection("books").await.unwrap().is_none());
        let error = db.get_collection("books").await.unwrap_err();
        assert_eq!(error.message(), "Collection not found");
    });
}

// ---- GetCollectionThrowsTest -------------------------------------------

#[test]
fn missing_collections_are_not_found_everywhere() {
    block_on(async {
        let (_, db) = memory().await;
        assert!(db.get_collection("missing").await.unwrap_err().is_a(Kind::NotFound));
        let update = Update { permissions: None, document_security: Some(false) };
        assert!(db.update_collection("missing", update).await.unwrap_err().is_a(Kind::NotFound));
        assert!(db.delete_collection("missing").await.unwrap_err().is_a(Kind::NotFound));
        assert!(db.size_of_collection("missing").await.unwrap_err().is_a(Kind::NotFound));
        db.create_collection(collection("books", Vec::new(), Vec::new())).await.unwrap();
        assert_eq!(db.get_collection("books").await.unwrap().id(), "books");
    });
}

#[test]
fn update_collection_changes_only_the_given_fields() {
    block_on(async {
        let (_, db) = memory().await;
        let permissions = vec![Permission::read(&Role::any())];
        let books = Collection::create("books", "", Vec::new(), Vec::new(), Some(permissions.clone()), true, Array::new());
        db.create_collection(books.unwrap()).await.unwrap();

        let updated =
            db.update_collection("books", Update { permissions: None, document_security: Some(false) }).await.unwrap();
        assert!(!updated.document_security());
        assert_eq!(updated.permissions().unwrap(), permissions);
        let stored = db.get_collection("books").await.unwrap();
        assert!(!stored.document_security());
        assert_eq!(stored.permissions().unwrap(), permissions);
    });
}

#[test]
fn update_collection_replaces_permissions() {
    block_on(async {
        let (_, db) = memory().await;
        let books = Collection::create("books", "", Vec::new(), Vec::new(), None, false, Array::new());
        db.create_collection(books.unwrap()).await.unwrap();
        let permissions = vec![Permission::read(&Role::users(""))];
        db.update_collection("books", Update { permissions: Some(permissions.clone()), document_security: None })
            .await
            .unwrap();
        let stored = db.get_collection("books").await.unwrap();
        assert_eq!(stored.permissions().unwrap(), permissions);
        assert!(!stored.document_security());
    });
}

// ---- ExtrasTest ---------------------------------------------------------

#[test]
fn known_keys_survive_create_collection() {
    block_on(async {
        let (_, db) = memory().await;
        let key = |k: &str| ArrayKey::Str(Str::from(k));
        let mut data = Array::new();
        data.set(key(document::ID), Value::Str(Str::from("books")));
        data.set(key(collection::NAME), Value::Str(Str::from("Books")));
        data.set(key(collection::DOCUMENT_SECURITY), Value::Bool(false));
        data.set(
            key(collection::ATTRIBUTES),
            Value::Array(Array::from_list(vec![Value::from(Attribute::string("title", 64).to_document().unwrap())])),
        );
        db.create_collection(Collection::from_array(data).unwrap()).await.unwrap();
        let stored = db.get_collection("books").await.unwrap();
        assert_eq!(stored.name(), "Books");
        assert!(!stored.document_security());
        assert_eq!(keys(stored.attributes().unwrap()), ["title"]);
    });
}

#[test]
fn an_unknown_extra_throws_on_create_collection() {
    block_on(async {
        let (_, db) = memory().await;
        let mut metadata = Array::new();
        metadata.set(ArrayKey::Str(Str::from("category")), Value::Str(Str::from("fiction")));
        let books = Collection::create("books", "", Vec::new(), Vec::new(), None, false, metadata).unwrap();
        let error = db.create_collection(books).await.unwrap_err();
        assert!(error.is_a(Kind::Structure));
        assert!(error.message().contains("category"));
        assert!(db.find_collection("books").await.unwrap().is_none());
    });
}

// ---- CollectionDefinitionTest (both) -----------------------------------

#[test]
fn the_metadata_collection_is_defined() {
    let definition = collection_definition();
    assert_eq!(definition.id(), METADATA);
    assert_eq!(definition.name(), "collections");
    assert_eq!(definition.collection(), METADATA);
    assert!(!definition.document_security());
    assert!(definition.indexes().unwrap().is_empty());
    assert!(!definition.is_set("externalId"));
    let shapes: Vec<(String, ColumnType, Option<i64>, bool, Vec<String>)> = definition
        .attributes()
        .unwrap()
        .iter()
        .map(|a| (a.key.clone(), a.kind, a.size, a.required, a.filters.clone()))
        .collect();
    assert_eq!(
        shapes,
        vec![
            ("name".to_owned(), ColumnType::String, Some(256), true, vec![]),
            ("attributes".to_owned(), ColumnType::String, Some(1_000_000), false, vec!["json".to_owned()]),
            ("indexes".to_owned(), ColumnType::String, Some(1_000_000), false, vec!["json".to_owned()]),
            ("documentSecurity".to_owned(), ColumnType::Boolean, None, true, vec![]),
        ]
    );
}

#[test]
fn every_call_returns_an_independent_copy() {
    let mut first = collection_definition();
    first.set("name", Value::Str(Str::from("changed"))).unwrap();
    first.set(collection::ATTRIBUTES, Value::Array(Array::new())).unwrap();
    let second = collection_definition();
    assert_eq!(second.name(), "collections");
    assert_eq!(second.attributes().unwrap().len(), 4);
}

#[test]
fn get_collection_returns_the_definition_for_the_metadata_collection() {
    block_on(async {
        let (_, db) = memory().await;
        let read = db.get_collection(METADATA).await.unwrap();
        assert_eq!(read.document().as_array(), collection_definition().document().as_array());
        assert!(!keys(read.attributes().unwrap()).contains(&"externalId".to_owned()));
    });
}

#[test]
fn internal_attributes_follow_the_shared_tables_setting() {
    block_on(async {
        let (_, db) = memory().await;
        let names = |db: &crate::database::Database| keys(db.internal_attributes());
        assert_eq!(names(&db), ["$id", "$sequence", "$collection", "$createdAt", "$updatedAt", "$permissions"]);
        db.set_shared_tables(true);
        assert_eq!(names(&db), ["$id", "$sequence", "$collection", "$tenant", "$createdAt", "$updatedAt", "$permissions"]);
        db.set_shared_tables(false);
        assert!(!names(&db).contains(&"$tenant".to_owned()));
    });
}

#[test]
fn internal_attributes_keep_their_stored_shape() {
    block_on(async {
        let (_, db) = memory().await;
        db.set_shared_tables(true);
        let stored: Vec<(String, Vec<Value>)> = db
            .internal_attributes()
            .iter()
            .map(|a| {
                let d = a.to_document().unwrap();
                let get = |k: &str| d.raw(k).cloned().unwrap_or(Value::Null);
                (a.key.clone(), ["type", "size", "required", "default", "signed", "array", "filters"].map(get).to_vec())
            })
            .collect();
        let s = |v: &str| Value::Str(Str::from(v));
        let list = |items: Vec<Value>| Value::Array(Array::from_list(items));
        let expected = vec![
            ("$id", vec![s("string"), Value::Int(LENGTH_KEY), Value::Bool(true), Value::Null, Value::Bool(true), Value::Bool(false), list(vec![])]),
            ("$sequence", vec![s("id"), Value::Int(0), Value::Bool(true), Value::Null, Value::Bool(true), Value::Bool(false), list(vec![])]),
            ("$collection", vec![s("string"), Value::Int(LENGTH_KEY), Value::Bool(true), Value::Null, Value::Bool(true), Value::Bool(false), list(vec![])]),
            ("$tenant", vec![s("id"), Value::Int(0), Value::Bool(false), Value::Null, Value::Bool(true), Value::Bool(false), list(vec![])]),
            ("$createdAt", vec![s("datetime"), Value::Int(0), Value::Bool(false), Value::Null, Value::Bool(false), Value::Bool(false), list(vec![s("datetime")])]),
            ("$updatedAt", vec![s("datetime"), Value::Int(0), Value::Bool(false), Value::Null, Value::Bool(false), Value::Bool(false), list(vec![s("datetime")])]),
            ("$permissions", vec![s("string"), Value::Int(1_000_000), Value::Bool(false), list(vec![]), Value::Bool(true), Value::Bool(false), list(vec![s("json")])]),
        ];
        let expected: Vec<(String, Vec<Value>)> = expected.into_iter().map(|(k, v)| (k.to_owned(), v)).collect();
        assert_eq!(stored, expected);
    });
}

// ---- CollectionGuardsTest ----------------------------------------------

#[test]
fn deleting_the_metadata_collection_drops_its_table() {
    block_on(async {
        let (adapter, db) = memory().await;
        assert!(adapter.memory_exists("utopiaTests", METADATA).await);
        db.delete_collection(METADATA).await.unwrap();
        assert!(!adapter.memory_exists("utopiaTests", METADATA).await);
    });
}

#[test]
fn a_metadata_failure_whose_cleanup_also_fails_keeps_the_metadata_failure() {
    block_on(async {
        let (adapter, db) = memory().await;
        adapter.on(|call| match call {
            Call::CreateDocument { collection, document } if collection.id() == METADATA && document.id() == "failing" => {
                Some(Reply::Error(Error::new(Kind::Runtime, "the definition could not be written")))
            }
            Call::DeleteCollection { .. } => Some(Reply::Error(Error::new(Kind::Runtime, "the table could not be dropped"))),
            _ => None,
        });
        let error = db.create_collection(collection("failing", Vec::new(), Vec::new())).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Database);
        assert_eq!(error.message(), "Failed to create collection metadata for 'failing': the definition could not be written");
        assert!(error.previous().is_some_and(|p| p.kind() == Kind::Runtime));
        // The cleanup was tried (with retries) and its failure logged.
        assert!(adapter.count("deleteCollection") >= 1);
    });
}

#[test]
fn a_definition_that_cannot_be_deleted_restores_the_table() {
    block_on(async {
        let (adapter, db) = memory().await;
        db.create_collection(collection("guarded", vec![Attribute::string("name", 32)], Vec::new())).await.unwrap();
        adapter.on(|call| match call {
            Call::DeleteDocument { collection, .. } if collection.id() == METADATA => {
                Some(Reply::Error(Error::new(Kind::Runtime, "the definition could not be deleted")))
            }
            _ => None,
        });
        let error = db.delete_collection("guarded").await.unwrap_err();
        assert_eq!(
            error.message(),
            "Failed to persist metadata for collection deletion 'guarded': the definition could not be deleted"
        );
        adapter.off();
        assert!(adapter.memory_exists("utopiaTests", "guarded").await, "the table is created again");
        assert!(db.find_collection("guarded").await.unwrap().is_some());
        assert!(db.find("guarded", Vec::new(), crate::permission::PermissionType::Read).await.unwrap().is_empty());
    });
}

#[test]
fn a_lost_creation_race_keeps_the_table() {
    block_on(async {
        let (adapter, db) = memory().await;
        adapter.on(|call| match call {
            Call::CreateDocument { collection, document } if collection.id() == METADATA && document.id() == "raced" => {
                Some(Reply::Error(Error::duplicate("Document already exists")))
            }
            _ => None,
        });
        let error = db.create_collection(collection("raced", Vec::new(), Vec::new())).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Duplicate);
        assert_eq!(error.message(), "Collection raced already exists");
        assert_eq!(error.previous().map(Error::message), Some("Document already exists"));
        assert!(adapter.memory_exists("utopiaTests", "raced").await, "the table the winner described is kept");
    });
}

// ---- CreateCollectionRaceTest ------------------------------------------

#[test]
fn create_collection_does_not_drop_an_uncommitted_peer_table() {
    block_on(async {
        let (adapter, db) = memory().await;
        let name = Attribute::string("name", 128);
        crate::adapter::Adapter::create_collection(adapter.as_ref(), "preCommitCreate", std::slice::from_ref(&name), &[])
            .await
            .unwrap();
        let error = db.create_collection(collection("preCommitCreate", vec![name], Vec::new())).await.unwrap_err();
        assert_eq!(error.kind(), Kind::Duplicate);
        assert_eq!(error.message(), "Collection preCommitCreate already exists");
        assert!(error.previous().is_some_and(|p| p.is_a(Kind::Duplicate)));
        assert_eq!(adapter.count("deleteCollection"), 0);
        assert!(adapter.memory_exists("utopiaTests", "preCommitCreate").await);
    });
}

// ---- CreateCollectionCleanupTest (Memory instead of SQLite) -----------

#[test]
fn create_collection_drops_its_table_when_the_definition_is_not_stored() {
    block_on(async {
        let (adapter, db) = memory().await;
        adapter.on(|call| match call {
            Call::CreateDocument { collection, .. } if collection.id() == METADATA => {
                Some(Reply::Error(Error::new(Kind::Runtime, "refused")))
            }
            _ => None,
        });
        let error = db
            .create_collection(collection("logs", vec![Attribute::string("message", 64)], Vec::new()))
            .await
            .unwrap_err();
        assert_eq!(error.kind(), Kind::Database);
        adapter.off();
        assert!(!adapter.memory_exists("utopiaTests", "logs").await, "a table without a stored definition is dropped");
        assert!(db.find_collection("logs").await.unwrap().is_none());
    });
}

// ---- UnconfirmedSchemaChangeTest (an unconfirmed metadata write) -------

#[test]
fn a_collection_or_attribute_whose_definition_is_unconfirmed_keeps_its_storage() {
    block_on(async {
        let (adapter, db) = memory().await;
        adapter.on(|call| match call {
            Call::CreateDocument { collection, document } if collection.id() == METADATA && document.id() == "logs" => {
                Some(Reply::Error(Error::unconfirmed("Failed to commit transaction: the commit could not be confirmed")))
            }
            _ => None,
        });
        let error = db.create_collection(collection("logs", Vec::new(), Vec::new())).await.unwrap_err();
        assert!(error.is_a(Kind::Unconfirmed), "{error:?}");
        assert_eq!(adapter.count("deleteCollection"), 0, "the table of a definition that may be stored is kept");
        assert_eq!(adapter.count("createDocument"), 1, "an unconfirmed write does not run again");

        adapter.off();
        db.create_collection(collection("items", Vec::new(), Vec::new())).await.unwrap();
        adapter.on(|call| match call {
            Call::UpdateDocument { collection, id, .. } if collection.id() == METADATA && id == "items" => {
                Some(Reply::Error(Error::unconfirmed("Failed to commit transaction: the commit could not be confirmed")))
            }
            _ => None,
        });
        adapter.clear_calls();
        let error = db.create_attribute("items", Attribute::integer("count")).await.unwrap_err();
        assert!(error.is_a(Kind::Unconfirmed), "{error:?}");
        assert_eq!(adapter.count("deleteAttribute"), 0, "the column of a definition that may be stored is kept");
        assert_eq!(adapter.count("updateDocument"), 1);
    });
}
