//! PHP: the Memory cases of `Relationships\RelationshipSchemaTest`, and the
//! Memory versions of the stub tests that needed integer sequences.

use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};

use super::{attribute_keys, index_keys, memory};
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::testing::{Call, Reply, block_on};
use crate::database::testing_memory::Intercepted;
use crate::database::{Database, Hook, METADATA};
use crate::document::{self, Document};
use crate::error::{Error, Kind};
use crate::hook::relationships::Relationships;
use crate::index::Index;
use crate::permission::Permission;
use crate::relationship::{Relationship, Update};
use crate::role::Role;
use crate::value::{Array, Str, Value};

/// `books` and `authors` with the relationships hook (`prepare()`).
async fn library() -> (Arc<Intercepted>, Database) {
    let (adapter, db) = memory().await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    let any = Role::any();
    let permissions =
        vec![Permission::create(&any), Permission::read(&any), Permission::update(&any), Permission::delete(&any)];
    for (id, attribute) in [("books", "title"), ("authors", "name")] {
        let definition = Collection::create(
            id,
            "",
            vec![Attribute::string(attribute, 64)],
            Vec::new(),
            Some(permissions.clone()),
            true,
            Array::new(),
        )
        .unwrap();
        db.create_collection(definition).await.unwrap();
    }
    (adapter, db)
}

fn many_to_one() -> Relationship {
    Relationship::many_to_one("authors").two_way(true).key("author").two_way_key("books")
}

async fn relationship(db: &Database, collection: &str, key: &str) -> Relationship {
    db.get_collection(collection)
        .await
        .unwrap()
        .attributes()
        .unwrap()
        .iter()
        .find(|a| a.key == key)
        .and_then(|a| a.relationship.clone())
        .unwrap_or_else(|| panic!("{collection} has no relationship {key}"))
}

async fn index(db: &Database, collection: &str, key: &str) -> Option<Index> {
    db.get_collection(collection).await.unwrap().indexes().unwrap().iter().find(|i| i.key == key).cloned()
}

fn rename(key: &str) -> Update {
    Update { key: Some(key.to_owned()), ..Update::default() }
}

fn doc(pairs: &[(&str, &str)]) -> Document {
    let mut d = Document::empty();
    for (k, v) in pairs {
        d.set(*k, Value::Str(Str::from(*v))).unwrap();
    }
    d
}

#[test]
fn creating_a_many_to_one_relationship_stores_both_sides_and_its_index() {
    block_on(async {
        let (_, db) = library().await;
        let created = db.create_relationship("books", many_to_one()).await.unwrap();
        assert_eq!(created, many_to_one());
        assert_eq!(attribute_keys(&db, "books").await, ["title", "author"]);
        assert_eq!(attribute_keys(&db, "authors").await, ["name", "books"]);
        assert_eq!(index(&db, "books", "_index_author").await.unwrap().attributes, ["author"]);
        assert_eq!(relationship(&db, "authors", "books").await.two_way_key.as_deref(), Some("author"));
    });
}

#[test]
fn renaming_a_relationship_whose_index_is_gone_keeps_the_old_key() {
    block_on(async {
        let (_, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();
        db.delete_index("books", "_index_author").await.unwrap();
        let error = db.update_relationship("books", "author", rename("writer")).await.unwrap_err();
        assert_eq!(error.message(), "Failed to update relationship indexes for 'author': Index not found");
        assert!(error.previous().is_some_and(|p| p.is_a(Kind::NotFound)));
        let keys = attribute_keys(&db, "books").await;
        assert!(keys.contains(&"author".to_owned()) && !keys.contains(&"writer".to_owned()));
        assert_eq!(relationship(&db, "authors", "books").await.two_way_key.as_deref(), Some("author"));
    });
}

#[test]
fn indexes_of_the_metadata_collection_cannot_be_renamed() {
    block_on(async {
        let (_, db) = library().await;
        let error = db.rename_index(METADATA, "_key_title", "renamed").await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::NotFound, "Index not found"));
        let error = db.rename_index("books", "missing", "renamed").await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::NotFound, "Index not found"));
    });
}

#[test]
fn a_failed_second_index_rename_reverses_the_first_and_the_definitions() {
    block_on(async {
        let (_, db) = library().await;
        let one_to_one = Relationship::one_to_one("authors").two_way(true).key("library").two_way_key("owner");
        db.create_relationship("books", one_to_one).await.unwrap();
        db.delete_index("authors", "_index_owner").await.unwrap();
        let update = Update { key: Some("shelf".to_owned()), two_way_key: Some("keeper".to_owned()), ..Update::default() };
        let error = db.update_relationship("books", "library", update).await.unwrap_err();
        assert_eq!(error.message(), "Failed to update relationship indexes for 'library': Index not found");
        assert_eq!(index(&db, "books", "_index_library").await.unwrap().attributes, ["library"]);
        assert!(index(&db, "books", "_index_shelf").await.is_none());
        assert!(attribute_keys(&db, "books").await.contains(&"library".to_owned()));
        assert!(!attribute_keys(&db, "books").await.contains(&"shelf".to_owned()));
        assert!(attribute_keys(&db, "authors").await.contains(&"owner".to_owned()));
        assert!(!attribute_keys(&db, "authors").await.contains(&"keeper".to_owned()));
    });
}

#[test]
fn an_adapter_that_does_not_create_the_relationship_fails_the_create() {
    block_on(async {
        let (adapter, db) = library().await;
        adapter.on(|call| match call {
            Call::CreateRelationship { .. } => Some(Reply::Bool(false)),
            Call::DeleteCollection { collection } if collection.starts_with('_') => {
                Some(Reply::Error(Error::new(Kind::Runtime, format!("cannot drop {collection}"))))
            }
            _ => None,
        });
        let many = Relationship::many_to_many("authors").two_way(true).key("writers").two_way_key("works");
        let error = db.create_relationship("books", many).await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Refused, "Failed to create relationship"));
        assert!(!attribute_keys(&db, "books").await.contains(&"writers".to_owned()));
        assert!(!attribute_keys(&db, "authors").await.contains(&"works".to_owned()));
    });
}

#[test]
fn a_relationship_only_in_the_schema_is_adopted() {
    block_on(async {
        let (adapter, db) = library().await;
        adapter.on(|call| {
            matches!(call, Call::CreateRelationship { .. })
                .then(|| Reply::Error(Error::duplicate("Relationship already exists in the schema")))
        });
        assert_eq!(db.create_relationship("books", many_to_one()).await.unwrap().key.as_deref(), Some("author"));
        assert!(attribute_keys(&db, "books").await.contains(&"author".to_owned()));
        assert!(attribute_keys(&db, "authors").await.contains(&"books".to_owned()));
        adapter.off();

        db.create_document("authors", doc(&[(document::ID, "ada"), ("name", "Ada")])).await.unwrap();
        db.create_document("books", doc(&[(document::ID, "notes"), ("title", "Notes"), ("author", "ada")]))
            .await
            .unwrap();
        let book = db.get_document("books", "notes", Vec::new(), false).await.unwrap();
        let author = book.get("author").and_then(crate::value::as_document).expect("the related author");
        assert_eq!(author.id(), "ada");
    });
}

#[test]
fn a_failed_index_rolls_back_and_a_failed_relationship_cleanup_is_logged() {
    block_on(async {
        let (adapter, db) = library().await;
        adapter.on(|call| match call {
            Call::CreateIndex { index, .. } if index.key == "_index_owner" => {
                Some(Reply::Error(Error::new(Kind::Runtime, "cannot index the owner")))
            }
            Call::DeleteRelationship { .. } => Some(Reply::Error(Error::new(Kind::Runtime, "cannot drop the relationship"))),
            _ => None,
        });
        let one_to_one = Relationship::one_to_one("authors").two_way(true).key("library").two_way_key("owner");
        let error = db.create_relationship("books", one_to_one).await.unwrap_err();
        assert_eq!(error.message(), "Failed to create relationship indexes: cannot index the owner");
        assert!(!attribute_keys(&db, "books").await.contains(&"library".to_owned()));
        assert!(!attribute_keys(&db, "authors").await.contains(&"owner".to_owned()));
        assert!(index(&db, "books", "_index_library").await.is_none(), "the index created before is dropped");
    });
}

#[test]
fn an_update_without_changes_is_accepted_and_an_unknown_relationship_is_not_found() {
    block_on(async {
        let (_, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();
        let before = relationship(&db, "books", "author").await;
        assert_eq!(db.update_relationship("books", "author", Update::default()).await.unwrap(), before);
        assert_eq!(relationship(&db, "books", "author").await, before);
        let error = db.update_relationship("books", "missing", rename("other")).await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::NotFound, "Relationship not found"));
    });
}

#[test]
fn renaming_from_the_child_side_of_a_one_to_many_renames_its_index() {
    block_on(async {
        let (_, db) = library().await;
        let one_to_many = Relationship::one_to_many("books").two_way(true).key("books").two_way_key("author");
        db.create_relationship("authors", one_to_many).await.unwrap();
        db.create_document("authors", doc(&[(document::ID, "ada"), ("name", "Ada")])).await.unwrap();
        db.create_document("books", doc(&[(document::ID, "notes"), ("title", "Notes"), ("author", "ada")]))
            .await
            .unwrap();
        assert_eq!(db.update_relationship("books", "author", rename("writer")).await.unwrap().key.as_deref(), Some("writer"));
        assert_eq!(index(&db, "books", "_index_writer").await.unwrap().attributes, ["writer"]);
        assert!(index(&db, "books", "_index_author").await.is_none());
        let found = db
            .find("books", vec![crate::query::Query::equal("writer", ["ada"])], crate::permission::PermissionType::Read)
            .await
            .unwrap();
        assert_eq!(found.iter().map(|d| d.id().to_owned()).collect::<Vec<_>>(), ["notes"]);
    });
}

#[test]
fn renaming_the_parent_key_from_the_child_side_of_a_many_to_one_renames_its_index() {
    block_on(async {
        let (_, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();
        let update = Update { two_way_key: Some("writer".to_owned()), ..Update::default() };
        assert_eq!(db.update_relationship("authors", "books", update).await.unwrap().two_way_key.as_deref(), Some("writer"));
        assert_eq!(index(&db, "books", "_index_writer").await.unwrap().attributes, ["writer"]);
        assert!(index(&db, "books", "_index_author").await.is_none());
        assert_eq!(relationship(&db, "authors", "books").await.two_way_key.as_deref(), Some("writer"));
    });
}

#[test]
fn an_adapter_that_does_not_update_or_fails_the_update_keeps_the_relationship() {
    block_on(async {
        let (adapter, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();

        adapter.on(|call| matches!(call, Call::UpdateRelationship { .. }).then_some(Reply::Bool(false)));
        let error = db.update_relationship("books", "author", rename("writer")).await.unwrap_err();
        assert_eq!((error.kind(), error.message()), (Kind::Refused, "Failed to update relationship 'author'"));

        adapter.on(|call| {
            matches!(call, Call::UpdateRelationship { .. })
                .then(|| Reply::Error(Error::new(Kind::Runtime, "the engine failed the rename")))
        });
        let error = db.update_relationship("books", "author", rename("writer")).await.unwrap_err();
        assert_eq!(error.message(), "Failed to update relationship 'author': the engine failed the rename");

        for failure in [Error::not_found("Attribute not found"), Error::duplicate("Attribute already exists")] {
            let reply = failure.clone();
            adapter.on(move |call| matches!(call, Call::UpdateRelationship { .. }).then(|| Reply::Error(reply.clone())));
            let error = db.update_relationship("books", "author", rename("writer")).await.unwrap_err();
            assert_eq!((error.kind(), error.message()), (failure.kind(), failure.message()));
        }

        let keys = attribute_keys(&db, "books").await;
        assert!(keys.contains(&"author".to_owned()) && !keys.contains(&"writer".to_owned()));
    });
}

#[test]
fn a_relationship_whose_column_is_gone_is_still_deleted() {
    block_on(async {
        let (adapter, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();
        adapter.on(|call| {
            matches!(call, Call::DeleteRelationship { .. })
                .then(|| Reply::Error(Error::not_found("Relationship not found in the schema")))
        });
        db.delete_relationship("books", "author").await.unwrap();
        assert!(!attribute_keys(&db, "books").await.contains(&"author".to_owned()));
        assert!(!attribute_keys(&db, "authors").await.contains(&"books".to_owned()));
    });
}

#[test]
fn a_drop_the_adapter_does_not_make_keeps_the_relationship_its_indexes_and_its_junction() {
    block_on(async {
        for (refuse, expected) in [(true, Kind::Refused), (false, Kind::Runtime)] {
            let (adapter, db) = library().await;
            db.create_relationship("books", many_to_one()).await.unwrap();
            let many = Relationship::many_to_many("authors").two_way(true).key("writers").two_way_key("works");
            db.create_relationship("books", many).await.unwrap();
            adapter.on(move |call| {
                matches!(call, Call::DeleteRelationship { .. }).then(|| {
                    if refuse {
                        Reply::Bool(false)
                    } else {
                        Reply::Error(Error::new(Kind::Runtime, "the engine failed the drop"))
                    }
                })
            });
            let error = db.delete_relationship("books", "author").await.unwrap_err();
            assert_eq!(error.kind(), expected);
            assert!(attribute_keys(&db, "books").await.contains(&"author".to_owned()));
            assert!(attribute_keys(&db, "authors").await.contains(&"books".to_owned()));
            assert_eq!(index(&db, "books", "_index_author").await.unwrap().attributes, ["author"]);

            let error = db.delete_relationship("books", "writers").await.unwrap_err();
            assert_eq!(error.kind(), expected);
            assert!(attribute_keys(&db, "books").await.contains(&"writers".to_owned()));
            let junction = db
                .get_collection("books")
                .await
                .unwrap()
                .sequence()
                .map(crate::value::to_text)
                .zip(db.get_collection("authors").await.unwrap().sequence().map(crate::value::to_text))
                .map(|(b, a)| format!("_{b}_{a}"))
                .unwrap();
            let mut junction_keys = attribute_keys(&db, &junction).await;
            junction_keys.sort();
            assert_eq!(junction_keys, ["works", "writers"]);
        }
    });
}

#[test]
fn a_failed_definition_write_on_delete_keeps_its_error_when_the_rollback_fails() {
    block_on(async {
        let (adapter, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();
        let armed = Arc::new(AtomicBool::new(true));
        let flag = armed.clone();
        adapter.on(move |call| {
            if !flag.load(Ordering::SeqCst) {
                return None;
            }
            match call {
                Call::UpdateDocument { collection, id, document, .. }
                    if collection.id() == METADATA
                        && id == "books"
                        && !Collection::from_document(document)
                            .ok()
                            .and_then(|c| c.attributes().ok().map(|a| a.iter().any(|a| a.key == "author")))
                            .unwrap_or(true) =>
                {
                    Some(Reply::Error(Error::new(Kind::Runtime, "the definitions could not be written")))
                }
                Call::CreateIndex { .. } => Some(Reply::Error(Error::new(Kind::Runtime, "the index could not be restored"))),
                _ => None,
            }
        });
        let error = db.delete_relationship("books", "author").await.unwrap_err();
        assert_eq!(
            error.message(),
            "Failed to persist metadata after retries for relationship deletion 'author': the definitions could not be written"
        );
        armed.store(false, Ordering::SeqCst);
        assert!(attribute_keys(&db, "books").await.contains(&"author".to_owned()));
    });
}

#[test]
fn deleting_a_relationship_drops_its_index_storage_and_both_definitions() {
    block_on(async {
        let (adapter, db) = library().await;
        db.create_relationship("books", many_to_one()).await.unwrap();
        db.delete_relationship("books", "author").await.unwrap();
        assert_eq!(attribute_keys(&db, "books").await, ["title"]);
        assert_eq!(attribute_keys(&db, "authors").await, ["name"]);
        assert!(index_keys(&db, "books").await.is_empty());
        assert_eq!(adapter.count("deleteRelationship"), 1);
    });
}
