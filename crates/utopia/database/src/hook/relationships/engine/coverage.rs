//! PHP: `tests/unit/Relationships/RelationshipHookCoverageTest.php` and
//! `TwoWayOneToOneLinkTest.php` (the Memory cases).

use super::*;
use crate::operator::Operator;

async fn library() -> Database {
    let db = database_on(Arc::new(Memory::new()), "relationship_hook_coverage").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    for (id, attribute) in [("books", "title"), ("authors", "name"), ("publishers", "name")] {
        collection(&db, id, vec![string(attribute, 64)], crud(), true).await;
    }
    relate(&db, "books", Relationship::many_to_one("authors").two_way(true).key("author").two_way_key("books")).await;
    relate(&db, "authors", Relationship::many_to_one("publishers").two_way(true).key("publisher").two_way_key("authors"))
        .await;
    create(&db, "publishers", doc(&[("$id", s("acme")), ("name", s("Acme"))])).await;
    create(&db, "authors", doc(&[("$id", s("ada")), ("name", s("Ada")), ("publisher", s("acme"))])).await;
    create(&db, "books", doc(&[("$id", s("notes")), ("title", s("Notes")), ("author", s("ada"))])).await;
    db
}

async fn find_ids(db: &Database, collection: &str, queries: Vec<Query>) -> Vec<String> {
    ids_of(&db.find(collection, queries, PermissionType::Read).await.unwrap())
}

async fn add_countries(db: &Database, countries: &[(&str, &str)]) {
    collection(db, "countries", vec![string("name", 64)], crud(), true).await;
    relate(db, "publishers", Relationship::many_to_one("countries").two_way(true).key("country").two_way_key("publishers"))
        .await;
    for (id, name) in countries {
        create(db, "countries", doc(&[("$id", s(id)), ("name", s(name))])).await;
    }
    update(db, "publishers", "acme", doc(&[("country", s("home"))])).await;
}

#[test]
fn a_nested_path_through_a_plain_attribute_matches_nothing() {
    block_on(async {
        let db = library().await;
        assert_eq!(find_ids(&db, "books", vec![Query::equal("author.publisher.name", ["Acme"])]).await, names(&["notes"]));
        assert!(find_ids(&db, "books", vec![Query::equal("author.name.first", ["Ada"])]).await.is_empty());
    });
}

#[test]
fn a_nested_path_whose_hop_finds_no_parent_matches_nothing() {
    block_on(async {
        let db = library().await;
        create(&db, "publishers", doc(&[("$id", s("lonely")), ("name", s("Lonely"))])).await;
        assert!(find_ids(&db, "books", vec![Query::equal("author.publisher.name", ["Lonely"])]).await.is_empty());
        assert!(find_ids(&db, "books", vec![Query::equal("author.publisher.name", ["Nobody"])]).await.is_empty());

        add_countries(&db, &[("nowhere", "Nowhere"), ("home", "Home")]).await;
        assert_eq!(
            find_ids(&db, "books", vec![Query::equal("author.publisher.country.name", ["Home"])]).await,
            names(&["notes"])
        );
        assert!(
            find_ids(&db, "books", vec![Query::equal("author.publisher.country.name", ["Nowhere"])]).await.is_empty(),
            "a hop that finds nothing ends the path before the next hop"
        );
    });
}

#[test]
fn removing_a_value_that_is_not_an_identifier_keeps_the_links() {
    block_on(async {
        let db = library().await;
        create(&db, "books", doc(&[("$id", s("essays")), ("title", s("Essays")), ("author", s("ada"))])).await;
        db.skip_validation(db.update_document(
            "authors",
            "ada",
            doc(&[("books", Value::from(Operator::array_remove(Value::Int(5))))]),
        ))
        .await
        .unwrap();
        assert_eq!(related_ids(&get(&db, "authors", "ada").await, "books"), names(&["essays", "notes"]));
        assert_eq!(related_id(&get(&db, "books", "essays").await, "author"), "ada");
    });
}

#[test]
fn a_relationship_change_nested_past_the_maximum_depth_is_dropped() {
    block_on(async {
        let db = library().await;
        add_countries(&db, &[("home", "Home"), ("away", "Away")]).await;
        update(
            &db,
            "books",
            "notes",
            doc(&[(
                "author",
                d(&[
                    ("$id", s("ada")),
                    ("name", s("Ada Lovelace")),
                    (
                        "publisher",
                        d(&[
                            ("$id", s("acme")),
                            ("name", s("Acme Press")),
                            ("country", d(&[("$id", s("away")), ("name", s("Abroad"))])),
                        ]),
                    ),
                ]),
            )]),
        )
        .await;
        assert_eq!(get(&db, "authors", "ada").await.get("name"), Some(&s("Ada Lovelace")));
        assert_eq!(get(&db, "publishers", "acme").await.get("name"), Some(&s("Acme Press")));
        assert_eq!(
            find_ids(&db, "publishers", vec![Query::equal("country", ["home"])]).await,
            names(&["acme"]),
            "the link three levels down is past the maximum depth and stays"
        );
        assert_eq!(get(&db, "countries", "away").await.get("name"), Some(&s("Away")));
    });
}

// ----- TwoWayOneToOneLinkTest -----

const DUPLICATE: &str = "Document already has a related document";

async fn one_to_one() -> Database {
    let db = database_on(Arc::new(Memory::new()), "two_way_one_to_one").await;
    db.add_hook(Hook::Relationships(Arc::new(Relationships::default()))).unwrap();
    db.add_hook(Hook::write(Arc::new(Permissions::new()))).unwrap();
    collection(&db, "parent", Vec::new(), crud(), false).await;
    collection(&db, "child", Vec::new(), crud(), false).await;
    relate(&db, "parent", Relationship::one_to_one("child").two_way(true).key("partner").two_way_key("parent")).await;
    for id in ["a", "b", "L", "free"] {
        create(&db, "child", doc(&[("$id", s(id))])).await;
    }
    create(&db, "parent", doc(&[("$id", s("x")), ("partner", s("L"))])).await;
    create(&db, "parent", doc(&[("$id", s("a")), ("partner", s("b"))])).await;
    create(&db, "parent", doc(&[("$id", s("c"))])).await;
    db
}

/// `link()`: the id `key` of the stored document holds.
async fn link(db: &Database, collection: &str, id: &str, key: &str) -> Option<String> {
    let document = db
        .authorization()
        .skip(db.skip_relationships(db.get_document(collection, id, Vec::new(), false)))
        .await
        .unwrap();
    assert!(!document.is_empty(), "{collection} {id} is missing");
    match document.get(key)? {
        Value::Ext(value::Ext::Document(d)) => Some(d.id().to_owned()),
        v @ Value::Str(_) => Some(value::to_text(v)),
        other => panic!("not a link: {other:?}"),
    }
}

fn values() -> [(&'static str, fn(&str) -> Value); 2] {
    [("id", |id| s(id)), ("document", |id| d(&[("$id", s(id))]))]
}

fn some(id: &str) -> Option<String> {
    Some(id.to_owned())
}

#[test]
fn linking_a_free_document_whose_id_matches_a_linked_document_of_this_collection_succeeds() {
    for (name, value) in values() {
        block_on(async {
            let db = one_to_one().await;
            update(&db, "parent", "c", doc(&[("partner", value("a"))])).await;
            assert_eq!(link(&db, "parent", "c", "partner").await, some("a"), "{name}");
            assert_eq!(link(&db, "parent", "a", "partner").await, some("b"), "{name}");
            assert_eq!(link(&db, "child", "b", "parent").await, some("a"), "{name}");
        });
    }
}

#[test]
fn linking_a_document_linked_elsewhere_throws_the_relationship_duplicate() {
    for (name, value) in values() {
        block_on(async {
            let db = one_to_one().await;
            let error = failure(db.update_document("parent", "c", doc(&[("partner", value("L"))])).await, Kind::Duplicate);
            assert_eq!(error.kind(), Kind::Duplicate, "{name}");
            assert_eq!(error.message(), DUPLICATE);
            assert_eq!(link(&db, "parent", "c", "partner").await, None, "{name}");
            assert_eq!(link(&db, "child", "L", "parent").await, some("x"), "{name}");
            assert_eq!(link(&db, "parent", "x", "partner").await, some("L"), "{name}");
        });
    }
}

#[test]
fn linking_from_the_child_side_to_a_document_linked_elsewhere_throws_the_relationship_duplicate() {
    for (name, value) in values() {
        block_on(async {
            let db = one_to_one().await;
            let error =
                failure(db.update_document("child", "free", doc(&[("parent", value("x"))])).await, Kind::Duplicate);
            assert_eq!(error.kind(), Kind::Duplicate, "{name}");
            assert_eq!(error.message(), DUPLICATE);
            assert_eq!(link(&db, "child", "free", "parent").await, None, "{name}");
            assert_eq!(link(&db, "parent", "x", "partner").await, some("L"), "{name}");
            assert_eq!(link(&db, "child", "L", "parent").await, some("x"), "{name}");
        });
    }
}

#[test]
fn linking_a_free_document_by_id_whose_id_matches_a_linked_document_of_this_collection_links_both_sides() {
    block_on(async {
        let db = one_to_one().await;
        update(&db, "parent", "c", doc(&[("partner", s("a"))])).await;
        assert_eq!(link(&db, "parent", "c", "partner").await, some("a"));
        assert_eq!(link(&db, "child", "a", "parent").await, some("c"));
    });
}

#[test]
fn unlinking_then_relinking_to_another_document_succeeds() {
    block_on(async {
        let db = one_to_one().await;
        update(&db, "parent", "x", doc(&[("partner", Value::Null)])).await;
        update(&db, "parent", "c", doc(&[("partner", s("L"))])).await;
        assert_eq!(link(&db, "parent", "x", "partner").await, None);
        assert_eq!(link(&db, "parent", "c", "partner").await, some("L"));
        assert_eq!(link(&db, "child", "L", "parent").await, some("c"));
    });
}
