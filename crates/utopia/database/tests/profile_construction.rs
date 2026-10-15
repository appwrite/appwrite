//! PHP: `tests/unit/Validator/ProfileConstructionTest.php`
//! (utopia-php/database 8.1.3), the parts on the validators of this
//! layer.
//!
//! Not ported here: `testADocumentsValidatorTakesItsGrammarFromTheProfile`,
//! `testQueryValidatorsTakeTheUidLengthFromTheLimits` and
//! `testADocumentValidatorLetsTheTenantThroughToTheRead`, which test the
//! query validators (`Queries\Documents`, `Queries\Document`, `Narrow`),
//! not ported yet.

mod common;

use common::{P, assert_error, assert_error_contains, doc, dv};
use php_std::datetime::{DateTime, TzInfo};
use utopia_database::adapter::Feature;
use utopia_database::attribute::{self, Attribute};
use utopia_database::capability::Capability;
use utopia_database::validator::attribute_definition::AttributeDefinition;
use utopia_database::validator::index_definition::IndexDefinition;
use utopia_database::validator::structure::Structure;
use utopia_database::value::Value;
use utopia_database::{Collection, Index, Kind};
use utopia_query::schema::ColumnType;

#[test]
fn an_attribute_definition_takes_its_size_caps_from_the_limits() {
    let validator =
        AttributeDefinition::new(vec![], P { string: 100, varchar: 50, integer: 1000, ..P::default() }.build());
    assert!(validator.check(&Attribute::string("title", 100)).unwrap());
    assert_error(
        validator.check(&Attribute::string("title", 101)),
        Kind::Database,
        "Max size allowed for string is: 100",
    );
    assert_error(
        validator.check(&Attribute::varchar("code", 51)),
        Kind::Database,
        "Max size allowed for varchar is: 50",
    );
}

#[test]
fn an_attribute_definition_counts_columns_and_width_against_the_limits() {
    let profile = || P { string: 1000, attributes: 3, document_size: 100, ..P::default() }.build();
    let attribute = Attribute::string("title", 10);

    let within =
        AttributeDefinition::new(vec![], profile()).measures(Some(Box::new(|_| Ok(3))), Some(Box::new(|_| Ok(99))));
    assert!(within.check(&attribute).unwrap());

    let too_many =
        AttributeDefinition::new(vec![], profile()).measures(Some(Box::new(|_| Ok(4))), Some(Box::new(|_| Ok(0))));
    assert_error_contains(too_many.check(&attribute), Kind::Limit, "Current attribute count is 4 but the maximum is 3");
}

#[test]
fn an_attribute_definition_accepts_the_types_the_profile_offers() {
    let bare = AttributeDefinition::new(vec![], P::default().build());
    assert_error(
        bare.check(&Attribute::vector("embedding", 3)),
        Kind::Database,
        "Vector types are not supported by the current database",
    );
    assert_error(bare.check(&Attribute::point("location")), Kind::Database, "Spatial attributes are not supported");
    assert_error(bare.check(&Attribute::object("meta")), Kind::Database, "Object attributes are not supported");

    let capable = AttributeDefinition::new(
        vec![],
        P {
            capabilities: vec![Capability::Vectors, Capability::Objects],
            features: vec![Feature::Spatial],
            ..P::default()
        }
        .build(),
    );
    assert!(capable.check(&Attribute::vector("embedding", 3)).unwrap());
    assert!(capable.check(&Attribute::point("location")).unwrap());
    assert!(capable.check(&Attribute::object("meta")).unwrap());
}

#[test]
fn the_available_types_follow_the_profile() {
    let bare = Attribute::available_types(&P::default().build());
    for absent in
        [ColumnType::Object, ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon, ColumnType::Vector]
    {
        assert!(!bare.contains(&absent));
    }
    assert!(bare.contains(&ColumnType::String));
    assert!(bare.contains(&ColumnType::Relationship));

    let full = Attribute::available_types(
        &P {
            capabilities: vec![Capability::Vectors, Capability::Objects],
            features: vec![Feature::Spatial],
            ..P::default()
        }
        .build(),
    );
    assert_eq!(full, attribute::TYPES);

    let spatial_only = Attribute::available_types(&P { features: vec![Feature::Spatial], ..P::default() }.build());
    let added: Vec<ColumnType> = spatial_only.into_iter().filter(|t| !bare.contains(t)).collect();
    assert_eq!(added, [ColumnType::Point, ColumnType::Linestring, ColumnType::Polygon]);
}

#[test]
fn an_index_definition_takes_its_length_and_reserved_keys_from_the_limits() {
    let attributes = vec![Attribute::string("title", 100)];
    let profile = || {
        P {
            capabilities: vec![Capability::DefinedAttributes, Capability::IndexKey],
            index_length: 99,
            internal_index_keys: vec!["primary".into()],
            ..P::default()
        }
        .build()
    };

    let validator = IndexDefinition::new(attributes.clone(), vec![], profile());
    assert!(!validator.check(&Index::key("title_key", ["title"], vec![], vec![]).unwrap()).unwrap());
    assert_eq!(validator.description(), "Index length is longer than the maximum: 99");

    let validator = IndexDefinition::new(attributes, vec![], profile());
    assert!(!validator.check(&Index::key("primary", ["title"], vec![Some(10)], vec![]).unwrap()).unwrap());
    assert_eq!(validator.description(), "Index key name is reserved");
}

#[test]
fn an_index_definition_accepts_only_the_index_types_the_profile_supports() {
    let attributes = vec![Attribute::string("title", 100)];
    let index = Index::key("title_key", ["title"], vec![], vec![]).unwrap();

    let without = IndexDefinition::new(
        attributes.clone(),
        vec![],
        P { capabilities: vec![Capability::DefinedAttributes], index_length: 768, ..P::default() }.build(),
    );
    assert!(!without.check(&index).unwrap());
    assert_eq!(without.description(), "Key index is not supported");

    let with = IndexDefinition::new(
        attributes,
        vec![],
        P {
            capabilities: vec![Capability::DefinedAttributes, Capability::IndexKey],
            index_length: 768,
            ..P::default()
        }
        .build(),
    );
    assert!(with.check(&index).unwrap(), "{}", with.description());
}

fn date(text: &str) -> DateTime {
    DateTime::new(text.as_bytes(), None, &TzInfo::utc()).unwrap()
}

fn event(at: &str) -> Value {
    let mut event = doc(r#"{"$id": "event", "$collection": "events", "$permissions": [],
            "$createdAt": "2050-01-01 00:00:00", "$updatedAt": "2050-01-01 00:00:00"}"#);
    event.put("at", Value::from(at));
    dv(event)
}

#[test]
fn a_structure_takes_its_datetime_range_from_the_limits() {
    let mut collection =
        Collection::create("events", "", vec![Attribute::datetime("at")], vec![], None, true, Default::default())
            .unwrap();
    collection.set("$collection", Value::from("_metadata")).unwrap();
    let profile = || {
        P {
            capabilities: vec![Capability::DefinedAttributes],
            min_date_time: Some(date("2000-01-01")),
            max_date_time: Some(date("2100-01-01")),
            ..P::default()
        }
        .build()
    };

    let structure = Structure::new(collection.to_document(), profile());
    assert!(structure.is_valid(&event("2050-06-01 00:00:00")).unwrap(), "{}", structure.description());

    let structure = Structure::new(collection.to_document(), profile());
    assert!(!structure.is_valid(&event("1999-12-31 00:00:00")).unwrap());
    assert!(structure.description().contains("2000-01-01"), "{}", structure.description());
}
