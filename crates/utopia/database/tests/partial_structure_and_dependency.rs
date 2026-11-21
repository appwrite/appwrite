//! `Validator\PartialStructure`, `Validator\IndexDependency` and edge cases
//! of `AttributeDefinition` and `IndexDefinition`: utopia-php/database
//! 8.1.3 has no unit test of their own for these; the expected values were
//! recorded from PHP 8.5 running the library.

mod common;

use common::{P, caps, doc, dv};
use utopia_database::capability::Capability;
use utopia_database::validator::attribute_definition::AttributeDefinition;
use utopia_database::validator::index_definition::IndexDefinition;
use utopia_database::validator::index_dependency::IndexDependency;
use utopia_database::validator::partial_structure::PartialStructure;
use utopia_database::{Attribute, Index, Kind};

#[test]
fn partial_structure() {
    let collection = doc(r#"{"$id": "posts", "$collection": "_metadata", "attributes": [
        {"$id": "title", "type": "string", "size": 10, "required": true, "signed": true, "array": false, "filters": []},
        {"$id": "count", "type": "integer", "size": 0, "required": false, "signed": true, "array": false, "filters": []}
    ], "indexes": []}"#);
    let validator = PartialStructure::new(collection, caps(&[Capability::DefinedAttributes]));
    let cases = [
        (r#"{"count": 5}"#, true, "Invalid document structure: General Error"),
        (
            r#"{"count": "x"}"#,
            false,
            "Invalid document structure: Attribute \"count\" has invalid type. Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647",
        ),
        (
            r#"{"$collection": null, "count": 5}"#,
            false,
            "Invalid document structure: Missing required attribute \"$collection\"",
        ),
        (
            r#"{"title": null}"#,
            false,
            "Invalid document structure: Attribute \"title\" has invalid type. Value must be a valid string and no longer than 10 chars",
        ),
        (r#"{"bogus": 1}"#, false, "Invalid document structure: Unknown attribute: \"bogus\""),
        (
            r#"{"$createdAt": "nope"}"#,
            false,
            "Invalid document structure: Attribute \"$createdAt\" has invalid type. Value must be valid date between 0000-01-01 00:00:00 and 9999-12-31 00:00:00.",
        ),
    ];
    for (document, valid, description) in cases {
        assert_eq!(validator.is_valid(&dv(doc(document))).unwrap(), valid, "{document}");
        assert_eq!(validator.description(), description, "{document}");
    }
}

#[test]
fn index_dependency() {
    let validator = IndexDependency::new(vec![Index::key("i", ["Tags"], vec![], vec![]).unwrap()], true);
    assert!(!validator.check(&Attribute::string("tags", 255).array(true)));
    assert!(validator.check(&Attribute::string("tags", 255)));
    assert!(!validator.is_valid(&dv(Attribute::string("tags", 255).array(true).to_document().unwrap())).unwrap());

    let without_casts = IndexDependency::new(vec![Index::key("i", ["tags"], vec![], vec![]).unwrap()], false);
    assert!(without_casts.check(&Attribute::string("tags", 255).array(true)));
    assert_eq!(validator.description(), "Attribute can't be deleted or renamed because it is used in an index");
}

#[test]
fn attribute_definition_edges() {
    let validator = AttributeDefinition::new(vec![], P { integer: 101, ..P::default() }.build());
    // A signed integer may take half of an odd limit, rounded as number_format() rounds.
    let mut declaration = Attribute::integer("n").to_document().unwrap();
    declaration.put("size", 51.into());
    let error = validator.check_document(&declaration).unwrap_err();
    assert!(error.is_a(Kind::Database));
    assert_eq!(error.message(), "Max size allowed for int is: 51");

    assert!(validator.check(&Attribute::boolean("n").default(false)).unwrap());
    assert!(validator.check(&Attribute::integer("n").default(false)).is_err());
}

#[test]
fn index_definition_edges() {
    let validator = IndexDefinition::new(
        vec![Attribute::string("a", 255), Attribute::string("b", 255), Attribute::string("tags", 64).array(true)],
        vec![Index::key("ab", ["a", "b"], vec![], vec![]).unwrap()],
        P {
            capabilities: vec![
                Capability::DefinedAttributes,
                Capability::IndexKey,
                Capability::IndexUnique,
                Capability::IndexArray,
            ],
            index_length: 768,
            ..P::default()
        }
        .build(),
    );
    let key = |k: &str, attributes: &[&str], lengths: Vec<Option<i64>>| {
        Index::key(k, attributes.iter().copied(), lengths, vec![]).unwrap()
    };
    let cases = [
        (
            Index::unique("ba", ["b", "a"], vec![], vec![]).unwrap(),
            false,
            "There is already an index with the same attributes and orders",
        ),
        (key("x", &["1", "01"], vec![]), false, "Invalid index attribute \"1\" not found"),
        (
            Index::unique("t", ["tags"], vec![Some(10)], vec![]).unwrap(),
            false,
            "\"Unique\" index is forbidden on array attributes",
        ),
        (key("t", &["tags"], vec![]), false, "Index length for array not specified"),
        (key("t", &["a"], vec![Some(-1)]), false, "Negative index length provided for a"),
        // 8.3.6 Validator/IndexDefinition.php:515: no stray quote after the size.
        (key("t", &["a"], vec![Some(300)]), false, "Index length 300 is larger than the size for a: 255"),
        (key("t", &["a", "tags"], vec![None, Some(10)]), true, "Index length 300 is larger than the size for a: 255"),
        (
            key("t", &["a"], vec![Some(1), Some(2)]),
            false,
            "Invalid index lengths. Count of lengths must be equal or less than the number of attributes.",
        ),
    ];
    for (index, valid, description) in cases {
        assert_eq!(validator.check(&index).unwrap(), valid, "{index:?}");
        assert_eq!(validator.description(), description, "{index:?}");
    }
}
