//! Ported from utopia-php/database 8.1.3 `tests/unit/Model/IndexTest.php` and
//! `Model/IndexFactoryTest.php`.
//!
//! Not ported:
//! - `testConstructorIsPrivate`, `testPropertiesAreReadonly`: PHP visibility
//!   rules (a Rust `Index` is immutable unless bound `mut`).
//! - `testFromArrayToleratesSchemaOrderCases`, `testFromArrayAcceptsTypedValues`:
//!   enum cases stored as values, which a Rust [`Value`] cannot hold.
//! - `testWithLengthsRejectsANonIntegerLength`,
//!   `testFactoryRejectsAnOrderThatIsNotADirection`,
//!   `testFactoryRejectsALengthThatIsNotAnInteger`,
//!   `testFactoryRejectsAnAttributeThatIsNotAString`: PHP type confusion the
//!   Rust signatures rule out at compile time. The same checks on stored
//!   values are covered through `from_array` below.

mod common;

use common::{arr, assert_error, document, list, v};
use utopia_database::value::{Array, Value};
use utopia_database::{Index, Kind};
use utopia_query::OrderDirection;
use utopia_query::schema::IndexType;

const ASC: Option<OrderDirection> = Some(OrderDirection::Asc);
const DESC: Option<OrderDirection> = Some(OrderDirection::Desc);

// IndexTest

#[test]
fn test_from_document_reads_the_stored_shape() {
    let index = Index::from_document(&document! {
        "$id" => "by_name",
        "key" => "by_name",
        "type" => "unique",
        "attributes" => list!["first", "last"],
        "lengths" => list![64, ()],
        "orders" => list!["ASC", ()],
    })
    .unwrap();
    assert_eq!(index.key, "by_name");
    assert_eq!(index.kind, IndexType::Unique);
    assert_eq!(index.attributes, ["first", "last"]);
    assert_eq!(index.lengths, [Some(64), None]);
    assert_eq!(index.orders, [ASC, None]);
    assert_eq!(index.ttl, None);
}

#[test]
fn test_from_document_drops_the_legacy_lengths_and_orders_of_a_fulltext_index() {
    let index = Index::from_document(&document! {
        "$id" => "search",
        "type" => "fulltext",
        "attributes" => list!["title"],
        "lengths" => list![()],
        "orders" => list!["ASC"],
        "ttl" => 1,
    })
    .unwrap();
    assert_eq!(index, Index::fulltext("search", ["title"]));
}

#[test]
fn test_from_document_drops_the_legacy_orders_of_a_ttl_index() {
    let index = Index::from_document(&document! {
        "$id" => "expiry",
        "type" => "ttl",
        "attributes" => list!["expiresAt"],
        "lengths" => list![],
        "orders" => list!["ASC"],
        "ttl" => 60,
    })
    .unwrap();
    assert_eq!(index, Index::ttl("expiry", "expiresAt", 60).unwrap());
}

#[test]
fn test_from_document_reads_the_legacy_index_type_as_key() {
    let index =
        Index::from_document(&document! {"$id" => "legacy", "type" => "index", "attributes" => list!["age"]}).unwrap();
    assert_eq!(index.kind, IndexType::Key);
    assert_eq!(index.to_document().get("type"), Some(&v("key")));
}

#[test]
fn test_from_document_ignores_the_legacy_ttl_on_non_ttl_indexes() {
    let index =
        Index::from_document(&document! {"$id" => "by_age", "type" => "key", "attributes" => list!["age"], "ttl" => 1})
            .unwrap();
    assert_eq!(index.ttl, None);
    assert!(!index.to_document().is_set("ttl"));
}

#[test]
fn test_from_document_tolerates_lowercase_and_empty_orders() {
    let index = Index::from_document(&document! {
        "$id" => "by_name",
        "type" => "key",
        "attributes" => list!["first", "middle", "last"],
        "orders" => list!["asc", "desc", ""],
    })
    .unwrap();
    assert_eq!(index.orders, [ASC, DESC, None]);
}

#[test]
fn test_from_document_reads_numeric_string_lengths_and_ttl() {
    let key = Index::from_document(
        &document! {"$id" => "by_name", "type" => "key", "attributes" => list!["name"], "lengths" => list!["32"]},
    )
    .unwrap();
    let ttl =
        Index::from_document(&document! {"$id" => "expiry", "type" => "ttl", "attributes" => list!["expiresAt"], "ttl" => "60"})
            .unwrap();
    assert_eq!(key.lengths, [Some(32)]);
    assert_eq!(ttl.ttl, Some(60));
}

#[test]
fn test_from_document_falls_back_to_the_id_for_the_key() {
    let index =
        Index::from_document(&document! {"$id" => "by_age", "type" => "key", "attributes" => list!["age"]}).unwrap();
    assert_eq!(index.key, "by_age");
}

#[test]
fn test_from_document_reads_a_seven_x_junction_index_by_its_id() {
    let index = Index::from_document(
        &document! {"$id" => "_index_tags", "key" => "index_tags", "type" => "key", "attributes" => list!["a"]},
    )
    .unwrap();
    assert_eq!(index.key, "_index_tags");
    let index =
        Index::from_document(&document! {"$id" => "x", "key" => "y", "type" => "key", "attributes" => list!["a"]}).unwrap();
    assert_eq!(index.key, "y");
}

#[test]
fn test_from_document_defaults_a_missing_type_to_key() {
    let index = Index::from_document(&document! {"$id" => "by_age", "attributes" => list!["age"]}).unwrap();
    assert_eq!(index.kind, IndexType::Key);
}

#[test]
fn test_from_document_reads_an_unknown_type_as_key() {
    let index =
        Index::from_document(&document! {"$id" => "odd", "type" => "bitmap", "attributes" => list!["age"]}).unwrap();
    assert_eq!(index.kind, IndexType::Key);
    assert_eq!(index.attributes, ["age"]);
}

#[test]
fn test_from_document_rejects_a_stored_random_order() {
    assert_error(
        Index::from_document(
            &document! {"$id" => "by_age", "type" => "key", "attributes" => list!["age"], "orders" => list!["RANDOM"]},
        ),
        Kind::Index,
        "Index orders cannot be random",
    );
}

#[test]
fn test_from_document_rejects_an_unknown_order() {
    assert_error(
        Index::from_document(
            &document! {"$id" => "by_age", "type" => "key", "attributes" => list!["age"], "orders" => list!["sideways"]},
        ),
        Kind::Index,
        "Unknown index order \"sideways\"",
    );
}

#[test]
fn test_from_document_rejects_a_ttl_index_without_a_ttl() {
    assert_error(
        Index::from_document(&document! {"$id" => "expiry", "type" => "ttl", "attributes" => list!["expiresAt"]}),
        Kind::Index,
        "TTL must be at least 1 second",
    );
}

#[test]
fn test_from_array_prefers_the_key_over_the_id() {
    let index =
        Index::from_array(&arr! {"$id" => "old", "key" => "new", "type" => "key", "attributes" => list!["age"]}).unwrap();
    assert_eq!(index.key, "new");
}

#[test]
fn test_from_array_rejects_an_unknown_type_string() {
    assert_error(
        Index::from_array(&arr! {"key" => "odd", "type" => "bitmap", "attributes" => list!["age"]}),
        Kind::Index,
        "Unknown index type: bitmap. Must be one of key, unique, fulltext, spatial, object, hnsw_euclidean, hnsw_cosine, hnsw_dot, trigram, ttl",
    );
}

#[test]
fn test_hydration_rejects_malformed_stored_values() {
    for (data, message) in [
        (arr! {"key" => 5, "type" => "key"}, "Index key must be a string"),
        (arr! {"key" => "k", "type" => "key", "attributes" => list![1]}, "Index attributes must be strings"),
        (arr! {"key" => "k", "type" => "key", "lengths" => list!["wide"]}, "Index lengths must be integers or null"),
        (arr! {"key" => "k", "type" => "key", "orders" => list![1]}, "Index orders must be OrderDirection cases or null"),
    ] {
        assert_error(Index::from_array(&data), Kind::Index, message);
    }
    // Lengths and orders a type does not store are not checked.
    let index = Index::from_array(
        &arr! {"key" => "k", "type" => "fulltext", "lengths" => list!["wide"], "orders" => list![1]},
    )
    .unwrap();
    assert!(index.lengths.is_empty() && index.orders.is_empty());
}

#[test]
fn test_to_document_round_trips_through_from_document() {
    let index = Index::key("by_name", ["first", "last"], vec![Some(32), None], vec![DESC, None]).unwrap();
    let document = index.to_document();
    let read = Index::from_document(&document).unwrap();
    assert_eq!(index, read);
    assert_eq!(document.array_copy(), read.to_document().array_copy());
}

#[test]
fn test_with_key_returns_a_renamed_copy() {
    let index = Index::key("by_age", ["age"], vec![Some(8)], vec![ASC]).unwrap();
    let renamed = index.with_key("by_years");
    assert_eq!(renamed.key, "by_years");
    assert_eq!(renamed.to_document().id(), "by_years");
    assert_eq!(renamed.attributes, ["age"]);
    assert_eq!(renamed.lengths, [Some(8)]);
    assert_eq!(renamed.orders, [ASC]);
    assert_eq!(index.key, "by_age");
}

#[test]
fn test_with_lengths_returns_a_copy_with_new_lengths() {
    let index = Index::key("by_name", ["first", "last"], vec![Some(16), Some(16)], vec![]).unwrap();
    let changed = index.with_lengths(vec![None, Some(64)]).unwrap();
    assert_eq!(changed.lengths, [None, Some(64)]);
    assert_eq!(index.lengths, [Some(16), Some(16)]);
}

#[test]
fn test_with_orders_returns_a_copy_with_new_orders() {
    let index = Index::key("by_name", ["first", "last"], vec![], vec![ASC, ASC]).unwrap();
    let changed = index.with_orders(vec![None, DESC]).unwrap();
    assert_eq!(changed.orders, [None, DESC]);
    assert_eq!(index.orders, [ASC, ASC]);
}

#[test]
fn test_with_orders_rejects_a_random_order() {
    assert_error(
        Index::key("by_age", ["age"], vec![], vec![]).unwrap().with_orders(vec![Some(OrderDirection::Random)]),
        Kind::Index,
        "Index orders cannot be random",
    );
}

fn orderless() -> [Index; 2] {
    [Index::fulltext("search", ["title"]), Index::ttl("expiry", "expiresAt", 60).unwrap()]
}

#[test]
fn test_with_orders_rejects_orders_the_index_cannot_store() {
    for (index, kind) in orderless().into_iter().zip(["fulltext", "ttl"]) {
        assert_error(index.with_orders(vec![ASC]), Kind::Index, &format!("A {kind} index does not take orders"));
    }
}

#[test]
fn test_with_orders_accepts_no_orders_on_an_orderless_index() {
    for index in orderless() {
        assert!(index.with_orders(vec![]).unwrap().orders.is_empty());
    }
}

#[test]
fn test_with_lengths_rejects_lengths_on_a_fulltext_index() {
    assert_error(
        Index::fulltext("search", ["title"]).with_lengths(vec![Some(16)]),
        Kind::Index,
        "A fulltext index does not take lengths",
    );
}

#[test]
fn test_with_lengths_accepts_no_lengths_on_a_fulltext_index() {
    assert!(Index::fulltext("search", ["title"]).with_lengths(vec![]).unwrap().lengths.is_empty());
}

#[test]
fn test_with_lengths_on_a_ttl_index_are_persisted() {
    let index = Index::ttl("expiry", "expiresAt", 60).unwrap().with_lengths(vec![Some(8)]).unwrap();
    assert_eq!(index.to_document().get("lengths"), Some(&v(list![8])));
}

// IndexFactoryTest

fn persisted_shapes() -> Vec<(Index, Array)> {
    let base = |id: &str, kind: &str, attributes: Array| {
        arr! {"$id" => id, "key" => id, "type" => kind, "attributes" => attributes}
    };
    let with = |mut a: Array, pairs: Vec<(&str, Value)>| {
        for (k, value) in pairs {
            a.set(k.into(), value);
        }
        a
    };
    let empty = || v(list![]);
    vec![
        (
            Index::key("by_name", ["first", "last"], vec![Some(64), None], vec![ASC, DESC]).unwrap(),
            with(
                base("by_name", "key", list!["first", "last"]),
                vec![("lengths", v(list![64, ()])), ("orders", v(list!["ASC", "DESC"]))],
            ),
        ),
        (
            Index::key("by_age", ["age"], vec![], vec![]).unwrap(),
            with(base("by_age", "key", list!["age"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::unique("by_email", ["email"], vec![Some(128)], vec![None]).unwrap(),
            with(base("by_email", "unique", list!["email"]), vec![("lengths", v(list![128])), ("orders", v(list![()]))]),
        ),
        (Index::fulltext("search", ["title", "body"]), base("search", "fulltext", list!["title", "body"])),
        (
            Index::trigram("fuzzy", ["name"]),
            with(base("fuzzy", "trigram", list!["name"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::spatial("where", "location", None).unwrap(),
            with(base("where", "spatial", list!["location"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::spatial("where", "location", DESC).unwrap(),
            with(base("where", "spatial", list!["location"]), vec![("lengths", empty()), ("orders", v(list!["DESC"]))]),
        ),
        (
            Index::object("payload", "data"),
            with(base("payload", "object", list!["data"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::hnsw_euclidean("near", "embedding"),
            with(base("near", "hnsw_euclidean", list!["embedding"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::hnsw_cosine("near", "embedding"),
            with(base("near", "hnsw_cosine", list!["embedding"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::hnsw_dot("near", "embedding"),
            with(base("near", "hnsw_dot", list!["embedding"]), vec![("lengths", empty()), ("orders", empty())]),
        ),
        (
            Index::ttl("expiry", "expiresAt", 3600).unwrap(),
            with(base("expiry", "ttl", list!["expiresAt"]), vec![("lengths", empty()), ("ttl", v(3600))]),
        ),
    ]
}

#[test]
fn test_factory_persists_its_shape() {
    for (index, expected) in persisted_shapes() {
        assert_eq!(index.to_document().array_copy(), expected, "{}", index.key);
    }
}

#[test]
fn test_persisted_shape_round_trips() {
    for (index, expected) in persisted_shapes() {
        assert_eq!(Index::from_document(&index.to_document()).unwrap().to_document().array_copy(), expected);
        assert_eq!(Index::from_array(&expected).unwrap().to_document().array_copy(), expected);
    }
}

#[test]
fn test_only_ttl_indexes_carry_a_ttl() {
    assert_eq!(Index::ttl("expiry", "expiresAt", 3600).unwrap().ttl, Some(3600));
    assert_eq!(Index::key("by_age", ["age"], vec![], vec![]).unwrap().ttl, None);
    assert_eq!(Index::fulltext("search", ["title"]).ttl, None);
}

#[test]
fn test_factories_expose_typed_properties() {
    let index = Index::unique("by_name", ["first", "last"], vec![Some(32), None], vec![ASC, None]).unwrap();
    assert_eq!(index.key, "by_name");
    assert_eq!(index.kind, IndexType::Unique);
    assert_eq!(index.attributes, ["first", "last"]);
    assert_eq!(index.lengths, [Some(32), None]);
    assert_eq!(index.orders, [ASC, None]);
}

#[test]
fn test_random_order_is_rejected() {
    let random = Some(OrderDirection::Random);
    for result in [
        Index::key("by_age", ["age"], vec![], vec![random]),
        Index::unique("by_age", ["age"], vec![], vec![ASC, random]),
        Index::spatial("where", "location", random),
    ] {
        assert_error(result, Kind::Index, "Index orders cannot be random");
    }
}

#[test]
fn test_ttl_below_one_is_rejected() {
    for ttl in [0, -1] {
        assert_error(Index::ttl("expiry", "expiresAt", ttl), Kind::Index, "TTL must be at least 1 second");
    }
}

#[test]
fn test_ttl_of_one_second_is_accepted() {
    assert_eq!(Index::ttl("expiry", "expiresAt", 1).unwrap().ttl, Some(1));
}

#[test]
fn test_hydrated_lists_are_reindexed() {
    let index = Index::from_array(&arr! {
        "key" => "by_name",
        "type" => "key",
        "attributes" => arr! {3 => "first", 7 => "last"},
        "lengths" => arr! {2 => 16},
        "orders" => arr! {5 => "desc"},
    })
    .unwrap();
    assert_eq!(index.attributes, ["first", "last"]);
    assert_eq!(index.lengths, [Some(16)]);
    assert_eq!(index.orders, [DESC]);
}
