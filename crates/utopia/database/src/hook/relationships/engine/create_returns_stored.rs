//! PHP: `tests/unit/Relationships/CreateReturnsStoredTest.php` (the Memory
//! cases).

use super::*;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipSide, RelationshipType};

async fn database() -> Database {
    let db = database_on(Arc::new(Memory::new()), "create_returns_stored").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    collection(&db, "artists", vec![string("name", 64)], crud(), true).await;
    collection(&db, "albums", vec![string("title", 64)], crud(), true).await;
    db
}

async fn attribute(db: &Database, collection: &str, key: &str) -> Attribute {
    let definition = db.get_collection(collection).await.unwrap();
    definition
        .attributes()
        .unwrap()
        .iter()
        .find(|a| a.key == key)
        .cloned()
        .unwrap_or_else(|| panic!("collection {collection} stores no attribute {key}"))
}

#[test]
fn null_keys_are_derived_from_the_collection_ids() {
    block_on(async {
        let db = database().await;
        let created =
            db.create_relationship("artists", Relationship::one_to_many("albums").two_way(true)).await.unwrap();
        assert_eq!(created.related_collection, "albums");
        assert_eq!(created.kind, RelationshipType::OneToMany);
        assert!(created.two_way);
        assert_eq!(created.key.as_deref(), Some("albums"));
        assert_eq!(created.two_way_key.as_deref(), Some("artists"));
        assert_eq!(created.on_delete, OnDelete::Restrict);
    });
}

#[test]
fn given_keys_are_kept() {
    block_on(async {
        let db = database().await;
        let created = db
            .create_relationship(
                "artists",
                Relationship::many_to_many("albums")
                    .key("records")
                    .two_way(true)
                    .two_way_key("performers")
                    .on_delete(OnDelete::Cascade),
            )
            .await
            .unwrap();
        assert_eq!(created.key.as_deref(), Some("records"));
        assert_eq!(created.two_way_key.as_deref(), Some("performers"));
        assert_eq!(created.on_delete, OnDelete::Cascade);
    });
}

#[test]
fn the_returned_relationship_is_the_stored_parent_side() {
    block_on(async {
        let db = database().await;
        let created = db
            .create_relationship(
                "artists",
                Relationship::many_to_one("albums").two_way(true).on_delete(OnDelete::SetNull),
            )
            .await
            .unwrap();
        let parent = attribute(&db, "artists", "albums").await;
        assert_eq!(parent.side, Some(RelationshipSide::Parent));
        assert_eq!(created.to_document().array_copy(), parent.relationship.unwrap().to_document().array_copy());
    });
}

#[test]
fn the_child_side_is_stored_as_the_inverse() {
    block_on(async {
        let db = database().await;
        let created = db
            .create_relationship(
                "artists",
                Relationship::one_to_one("albums").key("debut").two_way(true).two_way_key("artist"),
            )
            .await
            .unwrap();
        let child = attribute(&db, "albums", "artist").await;
        let relationship = child.relationship.unwrap();
        assert_eq!(child.side, Some(RelationshipSide::Child));
        assert_eq!(created.inverse("artists").to_document().array_copy(), relationship.to_document().array_copy());
        assert_eq!(relationship.related_collection, "artists");
        assert_eq!(relationship.key.as_deref(), Some("artist"));
        assert_eq!(relationship.two_way_key.as_deref(), Some("debut"));
    });
}

#[test]
fn a_one_way_relationship_still_stores_its_child_side() {
    block_on(async {
        let db = database().await;
        let created = db.create_relationship("artists", Relationship::one_to_many("albums")).await.unwrap();
        assert!(!created.two_way);
        let child = attribute(&db, "albums", "artists").await;
        assert_eq!(child.side, Some(RelationshipSide::Child));
        assert!(!child.relationship.unwrap().two_way);
    });
}

#[test]
fn a_missing_collection_is_not_found() {
    block_on(async {
        let db = database().await;
        let error =
            failure(db.create_relationship("missing", Relationship::one_to_many("albums")).await, Kind::NotFound);
        assert_eq!(error.message(), "Collection not found");
    });
}

#[test]
fn a_missing_related_collection_is_not_found() {
    block_on(async {
        let db = database().await;
        let error =
            failure(db.create_relationship("artists", Relationship::one_to_many("missing")).await, Kind::NotFound);
        assert_eq!(error.message(), "Related collection not found");
    });
}

#[test]
fn a_derived_key_that_is_taken_is_a_duplicate() {
    block_on(async {
        let db = database().await;
        db.create_attribute("artists", Attribute::string("albums", 255)).await.unwrap();
        let error =
            failure(db.create_relationship("artists", Relationship::one_to_many("albums")).await, Kind::Duplicate);
        assert_eq!(error.message(), "Attribute already exists");
    });
}

#[test]
fn a_second_relationship_to_the_same_two_way_key_is_a_duplicate() {
    block_on(async {
        let db = database().await;
        db.create_relationship("artists", Relationship::one_to_many("albums").key("albums").two_way_key("artist"))
            .await
            .unwrap();
        let error = failure(
            db.create_relationship("artists", Relationship::one_to_many("albums").key("records").two_way_key("artist"))
                .await,
            Kind::Duplicate,
        );
        assert_eq!(error.message(), "Related attribute already exists");
    });
}
