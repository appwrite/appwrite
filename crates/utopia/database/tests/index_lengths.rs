//! PHP: `tests/unit/Validator/IndexFloatLengthTest.php`,
//! `IndexObjectPathTest.php` and `IndexRevalidationTest.php`
//! (utopia-php/database 8.1.3).
//!
//! `IndexRevalidationTest` updates an attribute through a `Database` on the
//! Memory adapter, which is not ported yet; the validator behaviour it
//! relies on (an index is not identical to itself) is checked directly.

mod common;

use common::P;
use utopia_database::adapter::Profile;
use utopia_database::attribute::IntegerWidth;
use utopia_database::capability::Capability;
use utopia_database::validator::index_definition::IndexDefinition;
use utopia_database::{Attribute, Index};

const CAPABILITIES: [Capability; 6] = [
    Capability::DefinedAttributes,
    Capability::IndexFulltextMultiple,
    Capability::IndexIdentical,
    Capability::IndexKey,
    Capability::IndexUnique,
    Capability::IndexFulltext,
];

const MARIADB_MAX_INDEX_LENGTH: i64 = 768;

fn profile(capabilities: &[Capability]) -> Profile {
    P { capabilities: capabilities.to_vec(), index_length: MARIADB_MAX_INDEX_LENGTH, ..P::default() }.build()
}

fn key(name: &str, attributes: &[&str], lengths: Vec<Option<i64>>) -> Index {
    Index::key(name, attributes.iter().copied(), lengths, vec![]).unwrap()
}

/// `eightByteNumbers()`.
fn eight_byte_numbers() -> [Attribute; 2] {
    [Attribute::float("score"), Attribute::double("score")]
}

#[test]
fn index_over_the_byte_limit_is_rejected() {
    for number in eight_byte_numbers() {
        let validator = IndexDefinition::new(vec![Attribute::string("title", 767), number], vec![], profile(&CAPABILITIES));
        assert!(!validator.check(&key("idx_title_score", &["title", "score"], vec![])).unwrap());
        assert_eq!(validator.description(), "Index length is longer than the maximum: 768");
    }
}

#[test]
fn index_at_the_byte_limit_is_valid() {
    for number in eight_byte_numbers() {
        let validator = IndexDefinition::new(vec![Attribute::string("title", 766), number], vec![], profile(&CAPABILITIES));
        assert!(
            validator.check(&key("idx_title_score", &["title", "score"], vec![])).unwrap(),
            "{}",
            validator.description()
        );
    }
}

/// `eightByteIntegers()`.
#[test]
fn big_int_columns_count_eight_bytes() {
    let numbers =
        [Attribute::big_integer("score"), Attribute::id("score"), Attribute::integer("score").width(IntegerWidth::Bits64)];
    for number in numbers {
        let over =
            IndexDefinition::new(vec![Attribute::string("title", 767), number.clone()], vec![], profile(&CAPABILITIES));
        assert!(!over.check(&key("idx_title_score", &["title", "score"], vec![])).unwrap());
        assert_eq!(over.description(), "Index length is longer than the maximum: 768");

        let at = IndexDefinition::new(vec![Attribute::string("title", 766), number], vec![], profile(&CAPABILITIES));
        assert!(at.check(&key("idx_title_score", &["title", "score"], vec![])).unwrap(), "{}", at.description());
    }
}

/// `IndexObjectPathTest::setUp()`.
fn object_paths() -> IndexDefinition {
    IndexDefinition::new(
        vec![Attribute::object("data"), Attribute::string("title", 256)],
        vec![],
        profile(&[
            Capability::DefinedAttributes,
            Capability::IndexFulltextMultiple,
            Capability::IndexIdentical,
            Capability::IndexObject,
            Capability::IndexTrigram,
            Capability::IndexKey,
            Capability::IndexUnique,
            Capability::IndexFulltext,
            Capability::Objects,
        ]),
    )
}

#[test]
fn fulltext_index_on_object_path_is_rejected() {
    let validator = object_paths();
    assert!(!validator.check(&Index::fulltext("idx_fulltext", ["data.title"])).unwrap());
    assert_eq!(validator.description(), "Attribute \"data.title\" cannot be part of a fulltext index, must be of type string");
}

#[test]
fn index_length_on_object_path_is_rejected() {
    let validator = object_paths();
    assert!(!validator.check(&key("idx_key", &["data.title"], vec![Some(128)])).unwrap());
    assert_eq!(validator.description(), "Cannot set a length on \"\" attributes");
}

#[test]
fn trigram_index_on_object_path_is_rejected() {
    let validator = object_paths();
    assert!(!validator.check(&Index::trigram("idx_trigram", ["data.title"])).unwrap());
    assert_eq!(validator.description(), "Trigram index can only be created on string type attributes");
}

/// `acceptedIndexes()`.
#[test]
fn indexes_on_known_types_stay_valid() {
    let accepted = [
        key("idx_key", &["data.title"], vec![]),
        Index::unique("idx_unique", ["data.title"], vec![], vec![]).unwrap(),
        Index::fulltext("idx_fulltext", ["title"]),
        key("idx_length", &["title"], vec![Some(128)]),
        Index::trigram("idx_trigram", ["title"]),
    ];
    let validator = object_paths();
    for index in accepted {
        assert!(validator.check(&index).unwrap(), "{}", validator.description());
    }
}

/// `IndexRevalidationTest`: revalidating an index against a collection
/// that holds it (an adapter without `IndexIdentical`, as Memory) does not
/// compare it with itself, while another index of the same attributes is
/// refused.
#[test]
fn revalidating_an_index_does_not_conflict_with_itself() {
    let by_name = key("by_name", &["name"], vec![]);
    let validator = IndexDefinition::new(
        vec![Attribute::string("name", 128)],
        vec![by_name.clone()],
        profile(&[Capability::DefinedAttributes, Capability::IndexKey, Capability::IndexUnique]),
    );
    assert!(validator.check(&by_name).unwrap(), "{}", validator.description());
    assert!(!validator.check(&key("by_name_again", &["name"], vec![])).unwrap());
    assert_eq!(validator.description(), "There is already an index with the same attributes and orders");
}
