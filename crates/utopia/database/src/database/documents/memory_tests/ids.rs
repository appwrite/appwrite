//! PHP: `Documents/LiteralUniqueIdTest` and `Documents/CaseOnlyRenameTest`
//! (their Memory cases; Memory has no upserts).

use std::sync::Arc;

use super::s;
use crate::attribute::Attribute;
use crate::database::testing::block_on;
use crate::database::testing_memory::{collection, doc, memory};
use crate::database::{Database, Hook};
use crate::document::Document;
use crate::error::Kind;
use crate::hook::relationships::Relationships;
use crate::permission::{Permission, PermissionType};
use crate::relationship::Relationship;
use crate::role::Role;
use crate::value::{ArrayKey, Value, list};

const LITERAL_ID: &str = "unique()";
const WIDGETS: &str = "widgets";

async fn database(collections: &[&str]) -> Database {
    let db = memory("literal_unique_id").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::new(true)))).unwrap();
    let any = Role::any();
    for id in collections {
        let permissions = vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)];
        collection(&db, id, vec![Attribute::string("name", 64)], permissions, true).await;
    }
    db
}

async fn ids(db: &Database, collection: &str) -> Vec<String> {
    let found = db.find(collection, Vec::new(), PermissionType::Read).await.unwrap();
    found.iter().map(|d| d.id().to_owned()).collect()
}

async fn assert_stored_under_literal_id(db: &Database, collection: &str, name: &str) {
    let stored = db.get_document(collection, LITERAL_ID, Vec::new(), false).await.unwrap();
    assert_eq!(stored.get("name"), Some(&s(name)));
    assert_eq!(ids(db, collection).await, [LITERAL_ID], "nothing may be stored under a generated id");
}

async fn assert_generated_ids(db: &Database, ids: &[String], count: usize) {
    assert_eq!(ids.len(), count);
    let mut unique = ids.to_vec();
    unique.sort();
    unique.dedup();
    assert_eq!(unique.len(), count, "each document without an id gets its own id");
    for id in ids {
        assert!(!id.is_empty());
        assert_eq!(db.get_document(WIDGETS, id, Vec::new(), false).await.unwrap().id(), id);
    }
}

#[test]
fn create_document_stores_the_literal_id() {
    block_on(async {
        let db = database(&[WIDGETS]).await;
        let created =
            db.create_document(WIDGETS, doc(vec![("$id", s(LITERAL_ID)), ("name", s("created"))])).await.unwrap();
        assert_eq!(created.id(), LITERAL_ID);
        assert_stored_under_literal_id(&db, WIDGETS, "created").await;
    });
}

#[test]
fn create_documents_stores_the_literal_id() {
    block_on(async {
        let db = database(&[WIDGETS]).await;
        let mut created = Vec::new();
        let mut on_next = |(document, _): (Document, Option<Document>)| {
            created.push(document.id().to_owned());
            Ok(())
        };
        let batch = vec![doc(vec![("$id", s(LITERAL_ID)), ("name", s("batched"))])];
        assert_eq!(db.create_documents(WIDGETS, batch, 1000, Some(&mut on_next)).await.unwrap(), 1);
        assert_eq!(created, [LITERAL_ID]);
        assert_stored_under_literal_id(&db, WIDGETS, "batched").await;
    });
}

#[test]
fn a_nested_related_document_stores_the_literal_id() {
    block_on(async {
        let db = database(&["albums", "artists"]).await;
        db.create_relationship("albums", Relationship::many_to_one("artists").key("artist")).await.unwrap();
        let artist: Value = Value::Array(
            [(ArrayKey::from("$id"), s(LITERAL_ID)), (ArrayKey::from("name"), s("nested"))].into_iter().collect(),
        );
        let album = doc(vec![("$id", s("album")), ("name", s("album")), ("artist", artist)]);
        db.create_document("albums", album).await.unwrap();
        let stored = db.get_document("albums", "album", Vec::new(), false).await.unwrap();
        let artist = stored.document("artist").unwrap();
        assert_eq!(artist.id(), LITERAL_ID, "the parent must link to the literal id");
        assert_stored_under_literal_id(&db, "artists", "nested").await;
    });
}

#[test]
fn create_document_still_generates_an_id_for_an_empty_id() {
    block_on(async {
        let db = database(&[WIDGETS]).await;
        let created = db.create_document(WIDGETS, doc(vec![("name", s("generated"))])).await.unwrap();
        assert_generated_ids(&db, &[created.id().to_owned()], 1).await;
    });
}

#[test]
fn create_documents_still_generates_an_id_for_an_empty_id() {
    block_on(async {
        let db = database(&[WIDGETS]).await;
        let mut created = Vec::new();
        let mut on_next = |(document, _): (Document, Option<Document>)| {
            created.push(document.id().to_owned());
            Ok(())
        };
        let batch = vec![doc(vec![("name", s("first"))]), doc(vec![("name", s("second"))])];
        db.create_documents(WIDGETS, batch, 1000, Some(&mut on_next)).await.unwrap();
        assert_generated_ids(&db, &created, 2).await;
    });
}

// ---- CaseOnlyRenameTest ---------------------------------------------------

const RENAMES: &str = "renames";

async fn renames() -> Database {
    let db = memory("case_rename").await;
    let any = Role::any();
    let permissions = vec![Permission::create(&any), Permission::update(&any)];
    collection(&db, RENAMES, vec![Attribute::string("name", 32)], permissions, true).await;
    db
}

fn permissions(items: &[String]) -> Value {
    list(items.iter().map(|p| s(p)))
}

#[test]
fn a_case_only_rename_is_performed() {
    block_on(async {
        let db = renames().await;
        let document = doc(vec![
            ("$id", s("abc")),
            ("$permissions", permissions(&[Permission::read(&Role::any())])),
            ("name", s("renamed")),
        ]);
        db.create_document(RENAMES, document).await.unwrap();
        let renamed = db.update_document(RENAMES, "abc", doc(vec![("$id", s("ABC"))])).await.unwrap();
        assert_eq!(renamed.id(), "ABC");
        assert_eq!(ids(&db, RENAMES).await, ["ABC"]);
        let stored = db.get_document(RENAMES, "ABC", Vec::new(), false).await.unwrap();
        assert_eq!(stored.id(), "ABC");
        assert_eq!(stored.get("name"), Some(&s("renamed")));
    });
}

#[test]
fn a_case_only_rename_keeps_document_level_permissions_with_the_document() {
    block_on(async {
        let db = renames().await;
        let alice = Role::user("alice", "");
        let document = doc(vec![
            ("$id", s("abc")),
            ("$permissions", permissions(&[Permission::read(&alice), Permission::update(&alice)])),
            ("name", s("private")),
        ]);
        db.create_document(RENAMES, document).await.unwrap();
        db.update_document(RENAMES, "abc", doc(vec![("$id", s("ABC"))])).await.unwrap();
        let authorization = db.authorization();
        authorization.clean_roles();
        authorization.add_role(&alice.to_string());
        assert_eq!(db.get_document(RENAMES, "ABC", Vec::new(), false).await.unwrap().id(), "ABC");
        assert_eq!(ids(&db, RENAMES).await, ["ABC"]);
    });
}

#[test]
fn a_rename_onto_another_document_id_in_different_case_still_conflicts() {
    block_on(async {
        let db = renames().await;
        db.create_document(RENAMES, doc(vec![("$id", s("abc"))])).await.unwrap();
        db.create_document(RENAMES, doc(vec![("$id", s("xyz"))])).await.unwrap();
        let err = db.update_document(RENAMES, "abc", doc(vec![("$id", s("XYZ"))])).await.unwrap_err();
        assert!(err.is_a(Kind::Duplicate), "{err:?}");
    });
}
