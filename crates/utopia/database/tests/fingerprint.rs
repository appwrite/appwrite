//! `Collection::fingerprint()` against PHP 8.1.3's (values recorded from
//! `hash('xxh128', serialize([...]))` of the same definition).

use utopia_database::attribute::{Format, IntegerWidth};
use utopia_database::relationship::RelationshipSide;
use utopia_database::value::{Array, ArrayKey, Value, list, string};
use utopia_database::{Attribute, Collection, Index, Relationship};
use utopia_query::OrderDirection;

#[test]
fn fingerprint_matches_php() {
    let mut options = Array::new();
    options.set(ArrayKey::from("elements"), list([string("a"), string("b")]));
    let collection = Collection::create(
        "movies",
        "",
        vec![
            Attribute::string("title", 128)
                .required(true)
                .default(string("x"))
                .format(Format::new("enum", options).unwrap()),
            Attribute::integer("year").width(IntegerWidth::Bits64).signed(false),
            Attribute::datetime("at"),
            Attribute::float("score").default(Value::Float(1.5)),
            Attribute::relationship(
                "reviews",
                Relationship::one_to_many("reviews").two_way(true).two_way_key("movie"),
                RelationshipSide::Parent,
            )
            .unwrap(),
        ],
        vec![
            Index::key("idx_year", ["year", "title"], vec![None, Some(32)], vec![Some(OrderDirection::Desc), None])
                .unwrap(),
            Index::fulltext("ft", ["title"]),
            Index::ttl("exp", "at", 3600).unwrap(),
        ],
        Some(vec!["read(\"any\")".into(), "create(\"users\")".into()]),
        false,
        Array::new(),
    )
    .unwrap();
    assert_eq!(collection.fingerprint().unwrap(), "5ac6c8dc4bfcbdafda678e2db89aacd5");

    // The same definition read back from its stored documents.
    let stored = Collection::from_document(&collection.to_document()).unwrap();
    assert_eq!(stored.fingerprint().unwrap(), "5ac6c8dc4bfcbdafda678e2db89aacd5");
}
