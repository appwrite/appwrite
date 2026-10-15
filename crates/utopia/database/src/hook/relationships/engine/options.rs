//! PHP: `tests/unit/Relationships/TypedOptionsTest.php` and
//! `TwoWayOneToOneDocumentLinkTest.php` (the Memory cases).

use super::*;
use crate::database::METADATA;
use crate::relationship::{RelationshipDeleteAction as OnDelete, RelationshipSide, Update};

async fn artists() -> Database {
    let db = database_on(Arc::new(Memory::new()), "typed_options").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    collection(&db, "artists", vec![string("name", 64)], crud(), false).await;
    collection(&db, "albums", vec![string("title", 64)], crud(), false).await;
    db
}

fn albums() -> Relationship {
    Relationship::one_to_many("albums").key("albums").two_way(true).two_way_key("artist")
}

/// `sorted()`: an array by key.
fn sorted(options: Array) -> Vec<(String, Value)> {
    let mut entries: Vec<(String, Value)> = options
        .iter()
        .map(|(k, v)| {
            let key = match k {
                value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                value::KeyRef::Int(i) => i.to_string(),
            };
            (key, v.clone())
        })
        .collect();
    entries.sort_by(|a, b| a.0.cmp(&b.0));
    entries
}

/// `storedOptions()`.
async fn stored_options(db: &Database, collection: &str, key: &str) -> Vec<(String, Value)> {
    let metadata = db.authorization().skip(db.get_document(METADATA, collection, Vec::new(), false)).await.unwrap();
    for attribute in metadata.documents("attributes").unwrap() {
        if attribute.id() != key {
            continue;
        }
        let options = match attribute.get("options") {
            Some(Value::Ext(value::Ext::Document(d))) => d.array_copy(),
            Some(Value::Array(a)) => a.clone(),
            _ => Array::new(),
        };
        return sorted(options);
    }
    panic!("collection {collection} stores no attribute {key}");
}

#[test]
fn both_sides_store_the_relationship_options_shape() {
    block_on(async {
        let db = artists().await;
        let created = db.create_relationship("artists", albums()).await.unwrap();
        assert_eq!(sorted(created.to_options(RelationshipSide::Parent)), stored_options(&db, "artists", "albums").await);
        assert_eq!(
            sorted(created.inverse("artists").to_options(RelationshipSide::Child)),
            stored_options(&db, "albums", "artist").await
        );
    });
}

#[test]
fn an_update_from_the_child_side_stores_the_options_on_both_sides() {
    block_on(async {
        let db = artists().await;
        db.create_relationship("artists", albums()).await.unwrap();
        let updated = db
            .update_relationship("albums", "artist", Update { on_delete: Some(OnDelete::SetNull), ..Update::default() })
            .await
            .unwrap();
        assert_eq!(updated.related_collection, "artists");
        assert_eq!(updated.key.as_deref(), Some("artist"));
        assert_eq!(updated.two_way_key.as_deref(), Some("albums"));
        assert_eq!(updated.on_delete, OnDelete::SetNull);
        assert_eq!(sorted(updated.to_options(RelationshipSide::Child)), stored_options(&db, "albums", "artist").await);
        assert_eq!(
            sorted(updated.inverse("albums").to_options(RelationshipSide::Parent)),
            stored_options(&db, "artists", "albums").await
        );
    });
}

#[test]
fn deleting_follows_the_stored_delete_action() {
    block_on(async {
        let db = artists().await;
        db.create_relationship("artists", albums()).await.unwrap();
        create(&db, "albums", doc(&[("$id", s("b1")), ("title", s("First"))])).await;
        create(&db, "artists", doc(&[("$id", s("a1")), ("name", s("Ada")), ("albums", strs(&["b1"]))])).await;

        failure(db.delete_document("artists", "a1").await, Kind::Restricted);
        assert!(!get(&db, "artists", "a1").await.is_empty());

        db.update_relationship("albums", "artist", Update { on_delete: Some(OnDelete::SetNull), ..Update::default() })
            .await
            .unwrap();
        db.delete_document("artists", "a1").await.unwrap();

        let album = get(&db, "albums", "b1").await;
        assert!(!album.is_empty());
        assert_eq!(album.get("artist"), None);
    });
}

#[test]
fn population_reads_each_side_from_its_stored_side() {
    block_on(async {
        let db = artists().await;
        db.create_relationship("artists", albums()).await.unwrap();
        create(&db, "albums", doc(&[("$id", s("b1")), ("title", s("First"))])).await;
        create(&db, "albums", doc(&[("$id", s("b2")), ("title", s("Second"))])).await;
        create(&db, "artists", doc(&[("$id", s("a1")), ("name", s("Ada")), ("albums", strs(&["b1", "b2"]))])).await;

        assert_eq!(related_ids(&get(&db, "artists", "a1").await, "albums"), names(&["b1", "b2"]));
        let artist = get(&db, "albums", "b2").await.document("artist").unwrap();
        assert_eq!(artist.id(), "a1");
        assert_eq!(artist.get("name"), Some(&s("Ada")));
    });
}

#[test]
fn a_one_way_child_side_is_not_populated() {
    block_on(async {
        let db = artists().await;
        db.create_relationship("artists", Relationship::one_to_many("albums").key("albums").two_way_key("artist"))
            .await
            .unwrap();
        create(&db, "albums", doc(&[("$id", s("b1")), ("title", s("First"))])).await;
        create(&db, "artists", doc(&[("$id", s("a1")), ("name", s("Ada")), ("albums", strs(&["b1"]))])).await;

        let album = get(&db, "albums", "b1").await;
        assert!(!album.is_empty());
        assert!(!album.has("artist"));
    });
}

// ----- TwoWayOneToOneDocumentLinkTest -----

async fn partners() -> Database {
    let db = database_on(Arc::new(Memory::new()), "two_way_one_to_one_document").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    for id in ["parent", "child", "toy", "part"] {
        collection(&db, id, vec![string("name", 64)], crud(), false).await;
    }
    relate(&db, "parent", Relationship::one_to_one("child").two_way(true).key("partner").two_way_key("parent")).await;
    relate(&db, "child", Relationship::one_to_one("toy").two_way(true).key("toy").two_way_key("owner")).await;
    relate(&db, "toy", Relationship::one_to_one("part").two_way(true).key("part").two_way_key("toy")).await;
    for id in ["c1", "c2", "c3"] {
        create(&db, "child", doc(&[("$id", s(id))])).await;
    }
    create(&db, "parent", doc(&[("$id", s("p1")), ("partner", s("c3"))])).await;
    create(&db, "parent", doc(&[("$id", s("p2"))])).await;
    create(&db, "parent", doc(&[("$id", s("p3"))])).await;
    db
}

fn id_of(value: Option<&Value>) -> Option<String> {
    match value? {
        Value::Ext(value::Ext::Document(d)) => Some(d.id().to_owned()),
        v @ Value::Str(_) => Some(value::to_text(v)),
        _ => None,
    }
}

/// `link()`.
async fn link(db: &Database, collection: &str, id: &str, key: &str) -> Option<String> {
    let document = stored(db, collection, id).await;
    assert!(!document.is_empty(), "{collection} {id} is missing");
    id_of(document.get(key))
}

fn some(id: &str) -> Option<String> {
    Some(id.to_owned())
}

#[test]
fn linking_an_existing_document_by_document_stores_the_back_reference() {
    block_on(async {
        let db = partners().await;
        let updated = update(&db, "parent", "p2", doc(&[("partner", d(&[("$id", s("c1"))]))])).await;
        assert_eq!(id_of(updated.get("partner")), some("c1"));
        assert_eq!(link(&db, "parent", "p2", "partner").await, some("c1"));
        assert_eq!(link(&db, "child", "c1", "parent").await, some("p2"));
    });
}

#[test]
fn linking_an_existing_document_by_id_stores_the_back_reference() {
    block_on(async {
        let db = partners().await;
        update(&db, "parent", "p2", doc(&[("partner", s("c1"))])).await;
        assert_eq!(link(&db, "parent", "p2", "partner").await, some("c1"));
        assert_eq!(link(&db, "child", "c1", "parent").await, some("p2"));
    });
}

#[test]
fn linking_from_the_child_side_by_document_stores_the_back_reference() {
    block_on(async {
        let db = partners().await;
        update(&db, "child", "c1", doc(&[("parent", d(&[("$id", s("p2"))]))])).await;
        assert_eq!(link(&db, "child", "c1", "parent").await, some("p2"));
        assert_eq!(link(&db, "parent", "p2", "partner").await, some("c1"));
    });
}

#[test]
fn linking_by_document_writes_the_documents_own_attributes_with_the_back_reference() {
    block_on(async {
        let db = partners().await;
        update(&db, "parent", "p2", doc(&[("partner", d(&[("$id", s("c1")), ("name", s("renamed"))]))])).await;
        let child = stored(&db, "child", "c1").await;
        assert_eq!(child.get("name"), Some(&s("renamed")));
        assert_eq!(child.get("parent"), Some(&s("p2")));
    });
}

#[test]
fn linking_a_document_linked_elsewhere_by_document_is_refused() {
    block_on(async {
        let db = partners().await;
        failure(db.update_document("parent", "p2", doc(&[("partner", d(&[("$id", s("c3"))]))])).await, Kind::Duplicate);
        assert_eq!(link(&db, "parent", "p2", "partner").await, None);
        assert_eq!(link(&db, "child", "c3", "parent").await, some("p1"));
        assert_eq!(link(&db, "parent", "p1", "partner").await, some("c3"));
    });
}

#[test]
fn unlinking_then_relinking_by_document_stores_the_back_reference() {
    block_on(async {
        let db = partners().await;
        update(&db, "parent", "p1", doc(&[("partner", Value::Null)])).await;
        update(&db, "parent", "p2", doc(&[("partner", d(&[("$id", s("c3"))]))])).await;
        assert_eq!(link(&db, "parent", "p1", "partner").await, None);
        assert_eq!(link(&db, "parent", "p2", "partner").await, some("c3"));
        assert_eq!(link(&db, "child", "c3", "parent").await, some("p2"));
    });
}

#[test]
fn linking_a_new_document_by_document_stores_the_back_reference() {
    block_on(async {
        let db = partners().await;
        update(&db, "parent", "p2", doc(&[("partner", d(&[("$id", s("c9")), ("name", s("new"))]))])).await;
        assert_eq!(link(&db, "parent", "p2", "partner").await, some("c9"));
        assert_eq!(link(&db, "child", "c9", "parent").await, some("p2"));
        assert_eq!(stored(&db, "child", "c9").await.get("name"), Some(&s("new")));
    });
}

/// `['$id' => $id, ...]`: an array, not a document.
fn array(pairs: &[(&str, Value)]) -> Value {
    Value::Array(pairs.iter().map(|(k, v)| (value::ArrayKey::from(*k), v.clone())).collect())
}

#[test]
fn linking_by_document_writes_nested_documents_to_the_same_depth_as_creating() {
    block_on(async {
        let db = partners().await;
        let partner = |child: &str, toy: &str, part: &str| {
            (child.to_owned(), vec![("$id", s(child)), ("toy", array(&[("$id", s(toy)), ("part", array(&[("$id", s(part))]))]))])
        };
        let (_, created) = partner("c8", "t8", "x8");
        create(&db, "parent", doc(&[("$id", s("p8")), ("partner", array(&created))])).await;
        let (_, linked) = partner("c1", "t1", "x1");
        update(&db, "parent", "p2", doc(&[("partner", d(&linked))])).await;
        let (_, new) = partner("c7", "t7", "x7");
        update(&db, "parent", "p3", doc(&[("partner", d(&new))])).await;

        for (case, [parent, child, toy, part]) in
            [("created", ["p8", "c8", "t8", "x8"]), ("linked", ["p2", "c1", "t1", "x1"]), ("new", ["p3", "c7", "t7", "x7"])]
        {
            assert_eq!(link(&db, "parent", parent, "partner").await, some(child), "{case}");
            assert_eq!(link(&db, "child", child, "parent").await, some(parent), "{case}");
            assert_eq!(link(&db, "child", child, "toy").await, some(toy), "{case}");
            assert_eq!(link(&db, "toy", toy, "owner").await, some(child), "{case}");
            assert_eq!(link(&db, "toy", toy, "part").await, None, "{case}");
            assert!(stored(&db, "part", part).await.is_empty(), "{case}: the level past the depth limit was written");
        }
    });
}
