//! PHP: `tests/unit/Relationships/DeleteActionTest.php`,
//! `LinkPreservedDatesTest.php` and `ManyToManyDeleteTest.php` (the Memory
//! cases).

use utopia_query::schema::ForeignKeyAction;

use super::*;
use crate::database::METADATA;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipSide};

/// The foreign key actions a relationship cannot express.
const UNSUPPORTED: [&str; 2] = ["setDefault", "noAction"];

async fn database() -> Database {
    let db = database_on(Arc::new(Memory::new()), "delete_action").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    collection(&db, "artists", Vec::new(), crud(), false).await;
    collection(&db, "albums", Vec::new(), crud(), false).await;
    db
}

/// Rewrites the stored `onDelete` of `collection.key` (`storeDeleteAction()`).
async fn store_delete_action(db: &Database, collection: &str, key: &str, action: &str) {
    let authorization = db.authorization();
    let mut metadata = authorization.skip(db.get_document(METADATA, collection, Vec::new(), false)).await.unwrap();
    let mut attributes = Vec::new();
    for attribute in metadata.documents("attributes").unwrap() {
        let mut attribute = attribute;
        if attribute.id() == key {
            let mut options = match attribute.get("options") {
                Some(Value::Ext(value::Ext::Document(d))) => d.array_copy(),
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            options.set(value::ArrayKey::from("onDelete"), s(action));
            attribute.put("options", Value::Array(options));
        }
        attributes.push(attribute);
    }
    metadata.put("attributes", docs(attributes));
    authorization.skip(db.silent(db.update_document(METADATA, collection, metadata))).await.unwrap();
    db.purge_cached_collection(collection).await.unwrap();
}

async fn artist_with_album(db: &Database) {
    db.create_relationship(
        "artists",
        Relationship::one_to_many("albums")
            .key("albums")
            .two_way(true)
            .two_way_key("artist")
            .on_delete(OnDelete::Cascade),
    )
    .await
    .unwrap();
    create(db, "albums", doc(&[("$id", s("b1"))])).await;
    create(db, "artists", doc(&[("$id", s("a1")), ("albums", strs(&["b1"]))])).await;
}

#[test]
fn a_delete_action_cannot_express_an_unsupported_action() {
    for action in UNSUPPORTED {
        assert_eq!(OnDelete::try_from_value(action), None);
    }
}

#[test]
fn a_stored_unsupported_action_is_rejected() {
    for action in UNSUPPORTED {
        let options = |pairs: &[(&str, Value)]| -> Array {
            pairs.iter().map(|(k, v)| (value::ArrayKey::from(*k), v.clone())).collect()
        };
        let stored = options(&[
            ("relatedCollection", s("albums")),
            ("relationType", s("oneToMany")),
            ("twoWay", Value::Bool(true)),
            ("twoWayKey", s("artist")),
            ("onDelete", s(action)),
        ]);
        let error = failure(Relationship::from_array(&stored), Kind::Relationship);
        assert!(error.message().contains(&format!("\"{action}\"")), "{}", error.message());
        let error = failure(Relationship::from_document(&Document::new(stored).unwrap()), Kind::Relationship);
        assert!(error.message().contains(&format!("\"{action}\"")), "{}", error.message());
    }
}

#[test]
fn a_supported_action_maps_to_its_foreign_key_action() {
    for (action, foreign) in [
        (OnDelete::Cascade, ForeignKeyAction::Cascade),
        (OnDelete::Restrict, ForeignKeyAction::Restrict),
        (OnDelete::SetNull, ForeignKeyAction::SetNull),
    ] {
        assert_eq!(action.to_foreign_key_action(), foreign);
        let stored: Array = [
            (value::ArrayKey::from("relatedCollection"), s("albums")),
            (value::ArrayKey::from("relationType"), s("oneToMany")),
            (value::ArrayKey::from("onDelete"), s(action.as_str())),
        ]
        .into_iter()
        .collect();
        assert_eq!(Relationship::from_array(&stored).unwrap().on_delete, action);
    }
}

#[test]
fn deleting_a_parent_with_a_stored_unsupported_action_throws_and_leaves_children_linked() {
    for action in UNSUPPORTED {
        block_on(async {
            let db = database().await;
            artist_with_album(&db).await;
            store_delete_action(&db, "artists", "albums", action).await;

            failure(db.delete_document("artists", "a1").await, Kind::Relationship);

            store_delete_action(&db, "artists", "albums", OnDelete::Cascade.as_str()).await;
            let artist = db.skip_relationships(db.get_document("artists", "a1", Vec::new(), false)).await.unwrap();
            let album = db.skip_relationships(db.get_document("albums", "b1", Vec::new(), false)).await.unwrap();
            assert!(!artist.is_empty(), "the parent survives the refused delete");
            assert!(!album.is_empty(), "the child survives the refused delete");
            assert_eq!(album.get("artist"), Some(&s("a1")), "the child stays linked to its parent");
        });
    }
}

#[test]
fn creating_a_collection_with_an_unsupported_action_is_refused() {
    for action in UNSUPPORTED {
        block_on(async {
            let db = database().await;
            let mut options = Relationship::one_to_many("albums")
                .key("releases")
                .two_way(true)
                .two_way_key("artist")
                .to_document()
                .array_copy();
            options.set(value::ArrayKey::from("onDelete"), s(action));
            options.set(value::ArrayKey::from("side"), s("parent"));
            let attribute: Array = [
                ("key", s("releases")),
                ("type", s("relationship")),
                ("size", Value::Int(0)),
                ("required", Value::Bool(false)),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(false)),
                ("filters", value::list([])),
                ("options", Value::Array(options)),
            ]
            .into_iter()
            .map(|(k, v)| (value::ArrayKey::from(k), v))
            .collect();
            let definition: Array = [
                (value::ArrayKey::from("$id"), s("labels")),
                (value::ArrayKey::from("attributes"), value::list([Value::Array(attribute)])),
            ]
            .into_iter()
            .collect();

            let result = match Collection::from_array(definition) {
                Ok(collection) => db.create_collection(collection).await.map(|_| ()),
                Err(e) => Err(e),
            };
            let error = failure(result, Kind::Relationship);
            assert!(error.message().contains(&format!("\"{action}\"")), "{}", error.message());
            assert!(db.find_collection("labels").await.unwrap().is_none());
        });
    }
}

#[test]
fn a_collection_storing_an_unsupported_action_cannot_be_read() {
    for action in UNSUPPORTED {
        block_on(async {
            let db = database().await;
            artist_with_album(&db).await;
            store_delete_action(&db, "artists", "albums", action).await;

            let expected = format!("\"{action}\"");
            let definition = db.get_collection("artists").await.unwrap();
            let error = failure(definition.attributes().map(|_| ()), Kind::Relationship);
            assert!(error.message().contains(&expected), "getCollection: {}", error.message());
            let error = failure(db.get_document("artists", "a1", Vec::new(), false).await, Kind::Relationship);
            assert!(error.message().contains(&expected), "getDocument: {}", error.message());
            let error = failure(db.find("artists", Vec::new(), PermissionType::Read).await, Kind::Relationship);
            assert!(error.message().contains(&expected), "find: {}", error.message());
        });
    }
}

// ----- LinkPreservedDatesTest -----

const STORED: &str = "2020-01-01T00:00:00.000+00:00";

async fn dates_database() -> Database {
    let db = database_on(Arc::new(Memory::new()), "link_dates").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.authorization()
        .skip(async {
            let authors =
                Collection::create("authors", "", vec![string("name", 64)], Vec::new(), None, true, Array::new());
            db.create_collection(authors.unwrap()).await.unwrap();
            let books = Collection::create("books", "", vec![string("name", 64)], Vec::new(), None, true, Array::new());
            db.create_collection(books.unwrap()).await.unwrap();
            relate(
                &db,
                "authors",
                Relationship::one_to_many("books")
                    .key("books")
                    .two_way(true)
                    .two_way_key("author")
                    .on_delete(OnDelete::SetNull),
            )
            .await;
            db.with_preserve_dates(true, async {
                create(
                    &db,
                    "authors",
                    doc(&[
                        ("$id", s("author3")),
                        ("name", s("Linus")),
                        ("$createdAt", s(STORED)),
                        ("$updatedAt", s(STORED)),
                    ]),
                )
                .await;
                for id in ["book4", "book5"] {
                    create(
                        &db,
                        "books",
                        doc(&[("$id", s(id)), ("name", s(id)), ("$createdAt", s(STORED)), ("$updatedAt", s(STORED))]),
                    )
                    .await;
                }
            })
            .await;
        })
        .await;
    db
}

#[test]
fn linking_children_through_their_parent_keeps_their_updated_at_under_preserved_dates() {
    block_on(async {
        let db = dates_database().await;
        db.authorization()
            .skip(db.with_preserve_dates(
                true,
                db.update_document("authors", "author3", doc(&[("books", strs(&["book4", "book5"]))])),
            ))
            .await
            .unwrap();
        for id in ["book4", "book5"] {
            let book = db.authorization().skip(db.get_document("books", id, Vec::new(), false)).await.unwrap();
            assert_eq!(related_id(&book, "author"), "author3", "{id}");
            assert_eq!(book.updated_at(), Some(STORED), "{id}");
        }
    });
}

#[test]
fn linking_without_preserved_dates_still_stamps_the_children() {
    block_on(async {
        let db = dates_database().await;
        db.authorization()
            .skip(db.update_document("authors", "author3", doc(&[("books", strs(&["book4"]))])))
            .await
            .unwrap();
        let book = db.authorization().skip(db.get_document("books", "book4", Vec::new(), false)).await.unwrap();
        assert_ne!(book.updated_at(), Some(STORED));
    });
}

#[test]
fn unlinking_under_preserved_dates_still_stamps_the_child() {
    block_on(async {
        let db = dates_database().await;
        let authorization = db.authorization();
        for books in [&["book4"][..], &[]] {
            authorization
                .skip(db.with_preserve_dates(
                    true,
                    db.update_document("authors", "author3", doc(&[("books", strs(books))])),
                ))
                .await
                .unwrap();
        }
        let book = db.authorization().skip(db.get_document("books", "book4", Vec::new(), false)).await.unwrap();
        assert_ne!(book.updated_at(), Some(STORED));
    });
}

// ----- ManyToManyDeleteTest -----

fn books_authors() -> Relationship {
    Relationship::many_to_many("authors").key("authors").two_way(true).two_way_key("books")
}

#[test]
fn deleting_a_many_to_many_drops_its_junction() {
    for side in [RelationshipSide::Parent, RelationshipSide::Child] {
        block_on(async {
            let db = database_on(Arc::new(Memory::new()), "many_to_many_delete").await;
            db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
            collection(&db, "books", vec![string("title", 64)], crud(), false).await;
            collection(&db, "authors", vec![string("name", 64)], crud(), false).await;
            relate(&db, "books", books_authors()).await;
            create(&db, "authors", doc(&[("$id", s("a1")), ("name", s("Ada"))])).await;
            create(&db, "books", doc(&[("$id", s("b1")), ("title", s("Notes")), ("authors", strs(&["a1"]))])).await;

            let sequence = |c: &Collection| c.sequence().map(value::to_text).unwrap_or_default();
            let junction = format!(
                "_{}_{}",
                sequence(&db.get_collection("books").await.unwrap()),
                sequence(&db.get_collection("authors").await.unwrap())
            );

            match side {
                RelationshipSide::Parent => db.delete_relationship("books", "authors").await.unwrap(),
                RelationshipSide::Child => db.delete_relationship("authors", "books").await.unwrap(),
            }
            assert!(db.find_collection(&junction).await.unwrap().is_none(), "{side:?}");

            relate(&db, "books", books_authors()).await;
            assert!(db.find_collection(&junction).await.unwrap().is_some(), "{side:?}");
            assert_eq!(get(&db, "books", "b1").await.get("authors"), Some(&value::list([])), "{side:?}");
            assert_eq!(get(&db, "authors", "a1").await.get("books"), Some(&value::list([])), "{side:?}");
        });
    }
}
