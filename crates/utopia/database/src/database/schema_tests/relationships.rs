//! PHP: the stub-backed parts of `Relationships\RelationshipValidationTest`
//! and `Relationships\RelationshipSchemaTest` (their relationships stored as
//! the fixture instead of created through the engine).

use super::{Fixture, definition, describe, sequenced};
use crate::attribute::Attribute;
use crate::database::testing::{Call, Reply, block_on};
use crate::error::{Error, Kind};
use crate::index::Index;
use crate::relationship::{Relationship, RelationshipSide, Update};

/// `books.author` many-to-one `authors.books`, two-way, stored with the
/// index `createRelationship()` makes.
fn library() -> Fixture {
    let relationship = Relationship::many_to_one("authors").two_way(true).key("author").two_way_key("books");
    let author = Attribute::relationship("author", relationship.clone(), RelationshipSide::Parent).unwrap();
    let books = Attribute::relationship("books", relationship.inverse("books"), RelationshipSide::Child).unwrap();
    let f = Fixture::new(vec![
        sequenced(
            "books",
            "1",
            vec![Attribute::string("title", 64), author],
            vec![Index::key("_index_author", ["author"], Vec::new(), Vec::new()).unwrap()],
        ),
        sequenced("authors", "2", vec![Attribute::string("name", 64), books], Vec::new()),
    ]);
    f.adapter.with_relationships();
    f
}

#[test]
fn relationship_schema_changes_need_the_relationships_feature() {
    block_on(async {
        let f = Fixture::new(Vec::new());
        let errors = [
            f.db.create_relationship("books", Relationship::many_to_one("authors")).await.unwrap_err(),
            f.db.update_relationship("books", "author", Update { key: Some("writer".to_owned()), ..Update::default() })
                .await
                .unwrap_err(),
            f.db.delete_relationship("books", "author").await.unwrap_err(),
        ];
        for error in errors {
            assert_eq!(describe(&error), (Kind::Database, "Adapter does not support relationships".to_owned()));
        }
    });
}

#[test]
fn creating_a_relationship_on_a_missing_collection_is_not_found() {
    block_on(async {
        let f = Fixture::new(vec![definition("test", Vec::new(), Vec::new())]);
        f.adapter.with_relationships();
        let error =
            f.db.create_relationship("missing", Relationship::one_to_many("missing").two_way(true)).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Collection not found".to_owned()));
        let error =
            f.db.create_relationship("test", Relationship::one_to_many("missing").two_way(true)).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Related collection not found".to_owned()));
    });
}

#[test]
fn creating_a_relationship_onto_a_used_key_is_a_duplicate() {
    block_on(async {
        let f = library();
        // The key defaults to the related collection's id.
        let error =
            f.db.create_relationship("books", Relationship::one_to_many("authors").key("AUTHOR")).await.unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Attribute already exists".to_owned()));
        let error =
            f.db.create_relationship("books", Relationship::one_to_many("authors").key("writer").two_way_key("BOOKS"))
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Related attribute already exists".to_owned()));
        assert!(f.adapter.calls_of("createRelationship").is_empty());
    });
}

#[test]
fn an_adapter_that_does_not_create_the_relationship_fails_the_create() {
    block_on(async {
        let f = library();
        f.on(|call| matches!(call, Call::CreateRelationship { .. }).then_some(Reply::Bool(false)));
        let error =
            f.db.create_relationship("books", Relationship::one_to_one("authors").key("editor").two_way_key("edited"))
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to create relationship".to_owned()));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn deleting_a_missing_relationship_is_not_found() {
    block_on(async {
        let f = library();
        let error = f.db.delete_relationship("books", "title").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Relationship not found".to_owned()));
        let error = f.db.delete_relationship("books", "missing").await.unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Relationship not found".to_owned()));
    });
}

#[test]
fn updating_a_relationship_onto_an_existing_key_is_a_duplicate() {
    block_on(async {
        let f = library();
        let error =
            f.db.update_relationship("books", "author", Update { key: Some("title".to_owned()), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Relationship already exists".to_owned()));
        let error =
            f.db.update_relationship(
                "books",
                "author",
                Update { two_way_key: Some("name".to_owned()), ..Update::default() },
            )
            .await
            .unwrap_err();
        assert_eq!(describe(&error), (Kind::Duplicate, "Related attribute already exists".to_owned()));
    });
}

#[test]
fn an_update_without_changes_is_accepted_and_an_unknown_relationship_is_not_found() {
    block_on(async {
        let f = library();
        let current = f.db.update_relationship("books", "author", Update::default()).await.unwrap();
        assert_eq!(current, Relationship::many_to_one("authors").two_way(true).key("author").two_way_key("books"));
        assert_eq!(f.write_count(), 0);
        let error =
            f.db.update_relationship("books", "missing", Update { key: Some("other".to_owned()), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::NotFound, "Relationship not found".to_owned()));
    });
}

#[test]
fn an_adapter_that_does_not_update_the_relationship_refuses_the_update() {
    block_on(async {
        let f = library();
        f.on(|call| matches!(call, Call::UpdateRelationship { .. }).then_some(Reply::Bool(false)));
        let error =
            f.db.update_relationship("books", "author", Update { key: Some("writer".to_owned()), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(describe(&error), (Kind::Refused, "Failed to update relationship 'author'".to_owned()));
        assert!(error.previous().is_none());
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn an_update_the_adapter_fails_is_wrapped_with_its_cause() {
    block_on(async {
        let f = library();
        f.on(|call| {
            matches!(call, Call::UpdateRelationship { .. })
                .then(|| Reply::Error(Error::new(Kind::Runtime, "the engine failed the rename")))
        });
        let error =
            f.db.update_relationship("books", "author", Update { key: Some("writer".to_owned()), ..Update::default() })
                .await
                .unwrap_err();
        assert_eq!(
            describe(&error),
            (Kind::Database, "Failed to update relationship 'author': the engine failed the rename".to_owned())
        );
        assert!(error.previous().is_some_and(|p| p.kind() == Kind::Runtime));
        assert_eq!(f.write_count(), 0);
    });
}

#[test]
fn a_typed_update_failure_is_the_adapters_own() {
    block_on(async {
        for failure in [Error::not_found("Attribute not found"), Error::duplicate("Attribute already exists")] {
            let f = library();
            let reply = failure.clone();
            f.on(move |call| matches!(call, Call::UpdateRelationship { .. }).then(|| Reply::Error(reply.clone())));
            let error = f
                .db
                .update_relationship("books", "author", Update { key: Some("writer".to_owned()), ..Update::default() })
                .await
                .unwrap_err();
            assert_eq!(describe(&error), describe(&failure));
        }
    });
}

#[test]
fn renaming_the_parent_key_from_the_child_side_renames_its_index() {
    block_on(async {
        let f = library();
        let renamed =
            f.db.update_relationship(
                "authors",
                "books",
                Update { two_way_key: Some("writer".to_owned()), ..Update::default() },
            )
            .await
            .unwrap();
        assert_eq!(renamed.two_way_key.as_deref(), Some("writer"));
        let books = f.stored("books");
        let index = books.indexes().unwrap().iter().find(|i| i.key == "_index_writer").cloned().unwrap();
        assert_eq!(index.attributes, ["writer"]);
        assert!(books.indexes().unwrap().iter().all(|i| i.key != "_index_author"));
    });
}

#[test]
fn deleting_a_relationship_drops_its_index_storage_and_both_definitions() {
    block_on(async {
        let f = library();
        f.db.delete_relationship("books", "author").await.unwrap();
        assert!(f.stored("books").attributes().unwrap().iter().all(|a| a.key != "author"));
        assert!(f.stored("authors").attributes().unwrap().iter().all(|a| a.key != "books"));
        assert_eq!(f.adapter.calls_of("deleteRelationship").len(), 1);
        assert!(f.stored("books").indexes().unwrap().is_empty());
    });
}

#[test]
fn a_relationship_only_in_the_schema_is_adopted() {
    block_on(async {
        let f = library();
        f.on(|call| {
            matches!(call, Call::CreateRelationship { .. })
                .then(|| Reply::Error(Error::duplicate("Relationship already exists in the schema")))
        });
        let created =
            f.db.create_relationship("books", Relationship::one_to_one("authors").key("editor").two_way_key("edited"))
                .await
                .unwrap();
        assert_eq!(created.key.as_deref(), Some("editor"));
        assert!(f.stored("books").attributes().unwrap().iter().any(|a| a.key == "editor"));
        assert!(f.stored("authors").attributes().unwrap().iter().any(|a| a.key == "edited"));
    });
}
