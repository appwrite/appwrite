//! A relationship counts toward a row only on the side that holds its column.
//!
//! PHP: `tests/unit/Adapter/RelationshipColumnEstimateTest.php` (10.2.5). The
//! PHP test stubs `\PDO`, so the compat recorder leaves it out; it is ported
//! here instead.

#![cfg(feature = "sqlite")]

use utopia_database::adapter::Adapter;
use utopia_database::adapter::sql::pdo::Pdo;
use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::relationship::RelationshipSide;
use utopia_database::{Attribute, Collection, Relationship};

/// `strlen` of a relationship column: `VARCHAR(255)` at four bytes a character, plus one.
const RELATIONSHIP_WIDTH: i64 = 1021;

/// A MySQL adapter; its widths never reach the connection, so an in-memory
/// SQLite handle stands in for the server (PHP passes a stub).
async fn mysql() -> Sql {
    Sql::new(Engine::MySql, Pdo::connect("sqlite::memory:", None, None).await.unwrap())
}

fn collection(attributes: Vec<Attribute>) -> Collection {
    Collection::create("books", "", attributes, Vec::new(), None, false, Default::default()).unwrap()
}

#[utopia_async::test]
async fn test_sides_without_column_do_not_widen_the_row() {
    let adapter = mysql().await;
    let strings: Vec<Attribute> = (0..7).map(|i| Attribute::string(format!("string{i}"), 2200)).collect();
    let relationships = vec![
        Attribute::relationship(
            "authors",
            Relationship::many_to_one("authors").two_way(true).two_way_key("book"),
            RelationshipSide::Child,
        )
        .unwrap(),
        Attribute::relationship(
            "tags",
            Relationship::many_to_many("tags").two_way(true).two_way_key("books"),
            RelationshipSide::Child,
        )
        .unwrap(),
        Attribute::relationship(
            "reviews",
            Relationship::many_to_one("reviews").two_way(true).two_way_key("book"),
            RelationshipSide::Child,
        )
        .unwrap(),
    ];

    let without = collection(strings.clone());
    let with = collection([strings, relationships].concat());

    let width = adapter.attribute_width(&with).unwrap();
    assert_eq!(width, 62681, "seven VARCHAR(2200) columns on top of the internal ones, nothing for the relationships");
    assert!(width < adapter.limits().document_size);
    assert_eq!(adapter.attribute_width(&without).unwrap(), width);
    assert_eq!(adapter.count_of_attributes(&without).unwrap(), adapter.count_of_attributes(&with).unwrap());
}

#[utopia_async::test]
async fn test_only_a_side_with_a_column_is_counted() {
    use RelationshipSide::{Child, Parent};

    let sides = [
        ("oneToOne parent", Relationship::one_to_one("other"), Parent, true),
        ("oneToOne two-way child", Relationship::one_to_one("other").two_way(true), Child, true),
        ("oneToOne one-way child", Relationship::one_to_one("other"), Child, false),
        ("oneToMany parent", Relationship::one_to_many("other").two_way(true), Parent, false),
        ("oneToMany child", Relationship::one_to_many("other"), Child, true),
        ("manyToOne parent", Relationship::many_to_one("other"), Parent, true),
        ("manyToOne child", Relationship::many_to_one("other").two_way(true), Child, false),
        ("manyToMany parent", Relationship::many_to_many("other").two_way(true), Parent, false),
        ("manyToMany child", Relationship::many_to_many("other").two_way(true), Child, false),
    ];

    let adapter = mysql().await;
    let empty = collection(Vec::new());
    for (name, relationship, side, has_column) in sides {
        let related = collection(vec![Attribute::relationship("related", relationship.clone(), side).unwrap()]);

        assert_eq!(relationship.has_column(side), has_column, "{name}");
        assert_eq!(
            adapter.attribute_width(&related).unwrap() - adapter.attribute_width(&empty).unwrap(),
            if has_column { RELATIONSHIP_WIDTH } else { 0 },
            "{name}"
        );
        assert_eq!(
            adapter.count_of_attributes(&related).unwrap() - adapter.count_of_attributes(&empty).unwrap(),
            i64::from(has_column),
            "{name}"
        );
    }
}
