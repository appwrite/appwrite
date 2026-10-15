//! PHP: `tests/unit/Validator/IndexTest.php` (utopia-php/database 8.1.3).
//!
//! `testStoredIndexOfAnUnknownTypeIsReadLeniently` also checks
//! `Validator\Queries\Indexed`, which is not ported yet: only its
//! `Index::fromDocument()` assertions are here.

mod common;

use common::{P, assert_error, doc, dv};
use utopia_database::adapter::Profile;
use utopia_database::capability::Capability;
use utopia_database::validator::index_definition::IndexDefinition;
use utopia_database::{Attribute, Index, Kind};
use utopia_query::OrderDirection;
use utopia_query::schema::IndexType;

/// What an index validator supported by default before it took a profile.
const CAPABILITIES: [Capability; 6] = [
    Capability::DefinedAttributes,
    Capability::IndexFulltextMultiple,
    Capability::IndexIdentical,
    Capability::IndexKey,
    Capability::IndexUnique,
    Capability::IndexFulltext,
];

fn profile(capabilities: &[Capability]) -> Profile {
    P { capabilities: capabilities.to_vec(), index_length: 768, ..P::default() }.build()
}

fn standard() -> Profile {
    profile(&CAPABILITIES)
}

fn with(extra: &[Capability]) -> Vec<Capability> {
    let mut all = CAPABILITIES.to_vec();
    all.extend_from_slice(extra);
    all
}

fn key(name: &str, attributes: &[&str]) -> Index {
    Index::key(name, attributes.iter().copied(), vec![], vec![]).unwrap()
}

fn valid(validator: &IndexDefinition, index: &Index) -> bool {
    validator.check(index).unwrap()
}

#[test]
fn attribute_not_found() {
    let indexes = vec![key("index1", &["not_exist"])];
    let validator = IndexDefinition::new(vec![Attribute::string("title", 255)], indexes.clone(), standard());
    assert!(!valid(&validator, &indexes[0]));
    assert_eq!(validator.description(), "Invalid index attribute \"not_exist\" not found");
}

#[test]
fn fulltext_with_non_string() {
    let indexes = vec![Index::fulltext("index1", ["title", "date"])];
    let validator = IndexDefinition::new(
        vec![Attribute::string("title", 255), Attribute::datetime("date")],
        indexes.clone(),
        standard(),
    );
    assert!(!valid(&validator, &indexes[0]));
    assert_eq!(
        validator.description(),
        "Attribute \"date\" cannot be part of a fulltext index, must be of type string"
    );
}

#[test]
fn index_length() {
    let indexes = vec![key("index1", &["title"])];
    let validator = IndexDefinition::new(vec![Attribute::string("title", 769)], indexes.clone(), standard());
    assert!(!valid(&validator, &indexes[0]));
    assert_eq!(validator.description(), "Index length is longer than the maximum: 768");
}

#[test]
fn multiple_index_length() {
    let indexes = vec![Index::fulltext("index1", ["title"])];
    let validator = IndexDefinition::new(
        vec![Attribute::string("title", 256), Attribute::string("description", 1024)],
        indexes.clone(),
        standard(),
    );
    assert!(valid(&validator, &indexes[0]));

    // The validator does not track new indexes: only the new one is checked.
    assert!(!valid(&validator, &key("index2", &["title", "description"])));
    assert_eq!(validator.description(), "Index length is longer than the maximum: 768");
}

#[test]
fn empty_attributes() {
    let indexes = vec![Index::new("index1", IndexType::Key, vec![], vec![], vec![], None).unwrap()];
    let validator = IndexDefinition::new(vec![Attribute::string("title", 769)], indexes.clone(), standard());
    assert!(!valid(&validator, &indexes[0]));
    assert_eq!(validator.description(), "No attributes provided for index");
}

#[test]
fn object_index_validation() {
    let attributes = vec![Attribute::object("data").required(true), Attribute::string("name", 255)];
    let validator = IndexDefinition::new(attributes.clone(), vec![], profile(&with(&[Capability::IndexObject])));

    let valid_index = Index::object("idx_gin_valid", "data");
    assert!(valid(&validator, &valid_index));

    assert!(!valid(&validator, &Index::object("idx_gin_invalid_type", "name")));
    assert!(validator.description().contains("Object index can only be created on object attributes"));

    let multi =
        Index::new("idx_gin_multi", IndexType::Object, vec!["data".into(), "name".into()], vec![], vec![], None)
            .unwrap();
    assert!(!valid(&validator, &multi));
    assert!(validator.description().contains("Object index can be created on a single object attribute"));

    let ordered = Index::new(
        "idx_gin_order",
        IndexType::Object,
        vec!["data".into()],
        vec![],
        vec![Some(OrderDirection::Asc)],
        None,
    )
    .unwrap();
    assert!(!valid(&validator, &ordered));
    assert!(validator.description().contains("Object index do not support explicit orders"));

    let no_support = IndexDefinition::new(
        attributes,
        vec![],
        profile(&[
            Capability::IndexFulltextMultiple,
            Capability::IndexIdentical,
            Capability::IndexKey,
            Capability::IndexUnique,
            Capability::IndexFulltext,
        ]),
    );
    assert!(!valid(&no_support, &valid_index));
    assert_eq!(no_support.description(), "Object indexes are not supported");
}

#[test]
fn nested_object_path_index_validation() {
    let validator = IndexDefinition::new(
        vec![Attribute::object("data").required(true), Attribute::object("metadata"), Attribute::string("name", 255)],
        vec![],
        profile(&with(&[Capability::IndexObject, Capability::Objects])),
    );

    // An object index on a path is refused.
    assert!(!valid(&validator, &Index::object("idx_nested_object", "data.key.nestedKey")));

    assert!(valid(&validator, &Index::unique("idx_nested_unique", ["data.key.nestedKey"], vec![], vec![]).unwrap()));
    assert!(valid(&validator, &key("idx_nested_key", &["metadata.user.id"])));

    assert!(!valid(&validator, &Index::object("idx_invalid_nested", "name.key")));
    assert!(validator.description().contains("Index attribute \"name.key\" is only supported on object attributes"));

    assert!(!valid(&validator, &Index::object("idx_invalid_base", "nonexistent.key")));
    assert!(validator.description().contains("Invalid index attribute"));

    assert!(valid(&validator, &key("idx_multi_nested", &["data.key1", "data.key2"])));
}

#[test]
fn duplicated_attributes() {
    let indexes = vec![Index::fulltext("index1", ["title", "title"])];
    let validator = IndexDefinition::new(vec![Attribute::string("title", 255)], indexes.clone(), standard());
    assert!(!valid(&validator, &indexes[0]));
    assert_eq!(validator.description(), "Duplicate attributes provided");
}

#[test]
fn duplicated_attributes_different_order() {
    let indexes = vec![Index::fulltext("index1", ["title", "title"])];
    let validator = IndexDefinition::new(vec![Attribute::string("title", 255)], indexes.clone(), standard());
    assert!(!valid(&validator, &indexes[0]));
}

#[test]
fn reserved_index_key() {
    let indexes = vec![Index::fulltext("primary", ["title"])];
    let validator = IndexDefinition::new(
        vec![Attribute::string("title", 255)],
        indexes.clone(),
        P {
            capabilities: CAPABILITIES.to_vec(),
            index_length: 768,
            internal_index_keys: vec!["PRIMARY".into()],
            ..P::default()
        }
        .build(),
    );
    assert!(!valid(&validator, &indexes[0]));
    assert_eq!(validator.description(), "Index key name is reserved");
}

#[test]
fn index_with_no_attribute_support() {
    let indexes = vec![key("index1", &["new"])];
    let attributes = vec![Attribute::string("title", 769)];
    let validator = IndexDefinition::new(attributes.clone(), indexes.clone(), standard());
    assert!(!valid(&validator, &indexes[0]));

    let validator = IndexDefinition::new(
        attributes,
        indexes.clone(),
        profile(&[
            Capability::IndexFulltextMultiple,
            Capability::IndexIdentical,
            Capability::IndexKey,
            Capability::IndexUnique,
            Capability::IndexFulltext,
        ]),
    );
    assert!(valid(&validator, &indexes[0]), "{}", validator.description());
}

#[test]
fn trigram_index_validation() {
    let attributes =
        vec![Attribute::string("name", 255), Attribute::string("description", 512), Attribute::integer("age")];
    let validator = IndexDefinition::new(
        attributes.clone(),
        vec![],
        profile(&[Capability::IndexTrigram, Capability::IndexKey, Capability::IndexUnique, Capability::IndexFulltext]),
    );

    let valid_index = Index::trigram("idx_trigram_valid", ["name"]);
    assert!(valid(&validator, &valid_index));
    assert!(valid(&validator, &Index::trigram("idx_trigram_multi_valid", ["name", "description"])));

    assert!(!valid(&validator, &Index::trigram("idx_trigram_invalid_type", ["age"])));
    assert!(validator.description().contains("Trigram index can only be created on string type attributes"));

    assert!(!valid(&validator, &Index::trigram("idx_trigram_mixed", ["name", "age"])));
    assert!(validator.description().contains("Trigram index can only be created on string type attributes"));

    let ordered = Index::new(
        "idx_trigram_order",
        IndexType::Trigram,
        vec!["name".into()],
        vec![],
        vec![Some(OrderDirection::Asc)],
        None,
    )
    .unwrap();
    assert!(!valid(&validator, &ordered));
    assert!(validator.description().contains("Trigram indexes do not support orders or lengths"));

    let sized =
        Index::new("idx_trigram_length", IndexType::Trigram, vec!["name".into()], vec![Some(128)], vec![], None)
            .unwrap();
    assert!(!valid(&validator, &sized));
    assert!(validator.description().contains("Trigram indexes do not support orders or lengths"));

    let no_support = IndexDefinition::new(
        attributes,
        vec![],
        profile(&[Capability::IndexKey, Capability::IndexUnique, Capability::IndexFulltext]),
    );
    assert!(!valid(&no_support, &valid_index));
    assert_eq!(no_support.description(), "Trigram indexes are not supported");
}

/// `assertTtlRefused()`.
fn assert_ttl_refused(ttl: i64) {
    assert_error(
        Index::new("idx_ttl", IndexType::Ttl, vec!["expiresAt".into()], vec![], vec![], Some(ttl)),
        Kind::Index,
        "TTL must be at least 1 second",
    );
}

#[test]
fn ttl_index_validation() {
    let attributes = vec![Attribute::datetime("expiresAt"), Attribute::string("name", 255)];
    let validator = IndexDefinition::new(attributes.clone(), vec![], profile(&with(&[Capability::IndexTtl])));

    let valid_index = Index::ttl("idx_ttl_valid", "expiresAt", 3600).unwrap();
    assert!(valid(&validator, &valid_index));

    assert_ttl_refused(0);
    assert_ttl_refused(-100);

    assert!(
        !validator
            .is_valid(&dv(doc(r#"{"$id": "idx_ttl_missing", "type": "ttl", "attributes": ["expiresAt"]}"#)))
            .unwrap()
    );
    assert_eq!(validator.description(), "TTL must be at least 1 second");

    assert!(!valid(&validator, &Index::ttl("idx_ttl_invalid_type", "name", 3600).unwrap()));
    assert!(validator.description().contains("TTL index can only be created on datetime attributes"));

    let multi = Index::new(
        "idx_ttl_multi",
        IndexType::Ttl,
        vec!["expiresAt".into(), "name".into()],
        vec![],
        vec![Some(OrderDirection::Asc), Some(OrderDirection::Asc)],
        Some(3600),
    )
    .unwrap();
    assert!(!valid(&validator, &multi));
    assert!(validator.description().contains("TTL indexes must be created on a single datetime attribute"));

    assert!(valid(&validator, &Index::ttl("idx_ttl_min", "expiresAt", 1).unwrap()));

    let with_existing =
        IndexDefinition::new(attributes.clone(), vec![valid_index.clone()], profile(&with(&[Capability::IndexTtl])));
    assert!(!valid(&with_existing, &Index::ttl("idx_ttl_duplicate", "expiresAt", 7200).unwrap()));
    assert_eq!(with_existing.description(), "There can be only one TTL index in a collection");

    let no_support = IndexDefinition::new(
        attributes,
        vec![valid_index.clone()],
        profile(&[Capability::IndexKey, Capability::IndexUnique, Capability::IndexFulltext]),
    );
    assert!(!valid(&no_support, &valid_index));
    assert_eq!(no_support.description(), "TTL indexes are not supported");
}

#[test]
fn index_without_a_type_is_rejected() {
    let validator = IndexDefinition::new(vec![Attribute::string("title", 64)], vec![], standard());
    assert!(!validator.is_valid(&dv(doc(r#"{"$id": "by_title", "attributes": ["title"]}"#))).unwrap());
    assert!(validator.description().starts_with("Unknown index type: . Must be one of "));
}

#[test]
fn ttl_index_without_a_ttl_is_rejected() {
    let validator =
        IndexDefinition::new(vec![Attribute::datetime("expiresAt")], vec![], profile(&with(&[Capability::IndexTtl])));
    assert!(!validator.is_valid(&dv(doc(r#"{"$id": "expiry", "type": "ttl", "attributes": ["expiresAt"]}"#))).unwrap());
    assert_eq!(validator.description(), "TTL must be at least 1 second");
}

#[test]
fn unknown_index_type_is_a_validation_failure() {
    let validator = IndexDefinition::new(vec![Attribute::string("title", 64)], vec![], standard());
    assert!(!validator.is_valid(&dv(doc(r#"{"$id": "by_title", "type": "bogus", "attributes": ["title"]}"#))).unwrap());
    assert!(validator.description().starts_with("Unknown index type: bogus. Must be one of "));
}

#[test]
fn stored_index_of_an_unknown_type_is_read_leniently() {
    let stored = doc(r#"{"$id": "by_title", "type": "bogus", "attributes": ["title"]}"#);
    let index = Index::from_document(&stored).unwrap();
    assert_eq!(index.kind, IndexType::Key);
    assert_eq!(index.attributes, ["title"]);
}

#[test]
fn text_attribute_without_a_size_is_judged_against_the_text_maximum() {
    let validator = IndexDefinition::new(vec![Attribute::text("body")], vec![], standard());
    assert!(
        valid(&validator, &Index::key("by_body", ["body"], vec![Some(100)], vec![]).unwrap()),
        "{}",
        validator.description()
    );
    assert!(!valid(&validator, &key("by_body", &["body"])));
    assert_eq!(validator.description(), "Index length is longer than the maximum: 768");
}

#[test]
fn key_and_unique_indexes_are_rejected_without_adapter_support() {
    let validator = IndexDefinition::new(
        vec![Attribute::string("title", 64)],
        vec![],
        profile(&[
            Capability::DefinedAttributes,
            Capability::IndexFulltextMultiple,
            Capability::IndexIdentical,
            Capability::IndexFulltext,
        ]),
    );
    let key_index = key("by_title", &["title"]);
    let unique = Index::unique("by_title", ["title"], vec![], vec![]).unwrap();

    assert!(!valid(&validator, &key_index));
    assert_eq!(validator.description(), "Key index is not supported");
    assert!(!validator.check_key_unique_fulltext_support(&key_index));
    assert_eq!(validator.description(), "Key index is not supported");

    assert!(!valid(&validator, &unique));
    assert_eq!(validator.description(), "Unique index is not supported");
    assert!(!validator.check_key_unique_fulltext_support(&unique));
    assert_eq!(validator.description(), "Unique index is not supported");
}

#[test]
fn stored_legacy_index_type_is_validated_as_a_key_index() {
    let validator = IndexDefinition::new(vec![Attribute::string("title", 64)], vec![], standard());
    let stored = doc(r#"{"$id": "by_title", "type": "index", "attributes": ["title"]}"#);
    assert_eq!(Index::from_document(&stored).unwrap().kind, IndexType::Key);
    assert!(validator.is_valid(&dv(stored)).unwrap(), "{}", validator.description());
}

#[test]
fn order_on_an_array_attribute_is_rejected() {
    let validator = IndexDefinition::new(
        vec![Attribute::string("tags", 64).array(true)],
        vec![],
        profile(&with(&[Capability::IndexArray])),
    );
    assert!(!valid(
        &validator,
        &Index::key("by_tags", ["tags"], vec![Some(64)], vec![Some(OrderDirection::Asc)]).unwrap()
    ));
    assert_eq!(
        validator.description(),
        format!("Invalid index order \"{}\" on array attribute \"tags\"", OrderDirection::Asc.as_str())
    );
    assert!(
        valid(&validator, &Index::key("by_tags", ["tags"], vec![Some(64)], vec![]).unwrap()),
        "{}",
        validator.description()
    );
}
