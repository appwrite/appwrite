//! PHP: `tests/unit/Validator/AttributeTest.php` (utopia-php/database
//! 8.1.3).

mod common;

use std::sync::Arc;

use common::{P, assert_error, assert_error_contains, doc, dv, val};
use utopia_database::adapter::{Feature, Profile};
use utopia_database::attribute::Attribute;
use utopia_database::capability::Capability;
use utopia_database::schema::Column;
use utopia_database::validator::attribute_definition::AttributeDefinition;
use utopia_database::validator::structure::formats;
use utopia_database::value::{Array, Value};
use utopia_database::{Document, Kind};
use utopia_query::schema::ColumnType;

/// `Profiles::of(string: 16777216, varchar: 65535, integer: PHP_INT_MAX)`
/// with `capabilities` and `features`.
fn wide(capabilities: &[Capability], features: &[Feature]) -> Profile {
    P {
        capabilities: capabilities.to_vec(),
        features: features.to_vec(),
        string: 16_777_216,
        varchar: 65_535,
        integer: i64::MAX,
        ..P::default()
    }
    .build()
}

fn standard() -> AttributeDefinition {
    AttributeDefinition::new(vec![], wide(&[], &[]))
}

/// An attribute document: `$id` and `key`, `type`, `size`, then the
/// defaults of the PHP tests (`required` false, `default` null, `signed`
/// true, `array` false, `filters` []) with `rest` overriding them.
fn a(key: &str, kind: &str, size: i64, rest: &str) -> Document {
    let rest = if rest.is_empty() { String::new() } else { format!(", {rest}") };
    doc(&format!(
        r#"{{"$id": "{key}", "key": "{key}", "type": "{kind}", "size": {size}, "required": false, "default": null, "signed": true, "array": false, "filters": []{rest}}}"#
    ))
}

fn ok(validator: &AttributeDefinition, attribute: Document) {
    assert!(validator.is_valid(&dv(attribute)).unwrap(), "{}", validator.description());
}

fn array(json: &str) -> Array {
    match val(json) {
        Value::Array(a) => a,
        other => panic!("{other:?}"),
    }
}

#[test]
fn legacy_big_integer_metadata_normalizes_to_canonical_type() {
    let attribute = Attribute::from_document(&doc(r#"{"$id": "total", "type": "bigint", "size": 8}"#)).unwrap();
    assert_eq!(attribute.kind, ColumnType::BigInteger);
    assert_eq!(attribute.size, None);
    assert_eq!(attribute.to_document().unwrap().get("type"), Some(&Value::from("bigint")));

    let array_attribute = Attribute::from_array(&array(r#"{"$id": "arrayTotal", "type": "bigint", "size": 64}"#)).unwrap();
    assert_eq!(array_attribute.kind, ColumnType::BigInteger);
    assert_eq!(array_attribute.size, None);
}

#[test]
fn big_integer_defaults_support_native_and_string_boundaries() {
    let validator = AttributeDefinition::new(
        vec![],
        P { capabilities: vec![Capability::UnsignedBigInt], integer: 100, ..P::default() }.build(),
    );
    assert!(validator.check(&Attribute::big_integer("signed").default(Value::Int(i64::MAX))).unwrap());
    assert!(validator.check(&Attribute::big_integer("signedMinimum").default("-9223372036854775808")).unwrap());
    assert!(
        validator.check(&Attribute::big_integer("unsigned").default("18446744073709551615").signed(false)).unwrap()
    );
    assert!(
        validator
            .check(&Attribute::big_integer("values").default(val(r#"["-9223372036854775808", 9223372036854775807]"#)).array(true))
            .unwrap()
    );
}

#[test]
fn big_integer_default_rejects_values_outside_signed_range() {
    let validator = AttributeDefinition::new(vec![], P::default().build());
    assert_error_contains(
        validator.check(&Attribute::big_integer("total").default("9223372036854775808")),
        Kind::Database,
        "does not match given type bigint",
    );
}

#[test]
fn big_integer_array_default_validates_every_value() {
    let validator = AttributeDefinition::new(vec![], P { capabilities: vec![Capability::UnsignedBigInt], ..P::default() }.build());
    assert_error_contains(
        validator.check(
            &Attribute::big_integer("totals").default(val(r#"["1", "18446744073709551616"]"#)).signed(false).array(true),
        ),
        Kind::Database,
        "does not match given type bigint",
    );
}

fn existing(key: &str) -> Attribute {
    Attribute::from_document(&a(key, "string", 255, "")).unwrap()
}

#[test]
fn duplicate_attribute_id() {
    let validator = AttributeDefinition::new(vec![existing("title")], wide(&[], &[]));
    assert_error(validator.is_valid(&dv(a("title", "string", 255, ""))), Kind::Duplicate, "Attribute already exists in metadata");
    assert_eq!(validator.description(), "Attribute already exists in metadata");
}

#[test]
fn valid_string_attribute() {
    ok(&standard(), a("title", "string", 255, ""));
}

#[test]
fn string_size_too_large() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 1000, varchar: 65535, integer: i64::MAX, ..P::default() }.build(),
    );
    assert_error(validator.is_valid(&dv(a("title", "string", 2000, ""))), Kind::Database, "Max size allowed for string is: 1,000");
}

#[test]
fn varchar_size_too_large() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 16_777_216, varchar: 1000, integer: i64::MAX, ..P::default() }.build(),
    );
    assert_error(validator.is_valid(&dv(a("title", "varchar", 2000, ""))), Kind::Database, "Max size allowed for varchar is: 1,000");
}

#[test]
fn text_size_too_large() {
    assert_error(standard().is_valid(&dv(a("content", "text", 70000, ""))), Kind::Database, "Max size allowed for text is: 65535");
}

#[test]
fn mediumtext_size_too_large() {
    assert_error(
        standard().is_valid(&dv(a("content", "mediumtext", 20_000_000, ""))),
        Kind::Database,
        "Max size allowed for mediumtext is: 16777215",
    );
}

#[test]
fn integer_size_too_large() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 16_777_216, varchar: 65535, integer: 100, ..P::default() }.build(),
    );
    assert_error(validator.is_valid(&dv(a("count", "integer", 200, ""))), Kind::Database, "Max size allowed for int is: 50");
}

#[test]
fn unknown_type() {
    let validator = standard();
    assert_error(
        validator.is_valid(&dv(a("test", "unknown_type", 0, ""))),
        Kind::Database,
        "Unknown attribute type: unknown_type",
    );
    assert_eq!(validator.description(), "Unknown attribute type: unknown_type");
}

#[test]
fn required_filters_for_datetime() {
    assert_error(
        standard().is_valid(&dv(a("created", "datetime", 0, r#""signed": false"#))),
        Kind::Database,
        "Attribute of type: datetime requires the following filters: datetime",
    );
}

#[test]
fn valid_datetime_with_filter() {
    ok(&standard(), a("created", "datetime", 0, r#""signed": false, "filters": ["datetime"]"#));
}

#[test]
fn default_value_on_required_attribute() {
    assert_error(
        standard().is_valid(&dv(a("title", "string", 255, r#""required": true, "default": "default value""#))),
        Kind::Database,
        "Cannot set a default value for a required attribute",
    );
}

#[test]
fn default_value_type_mismatch() {
    assert_error(
        standard().is_valid(&dv(a("count", "integer", 4, r#""default": "not_an_integer""#))),
        Kind::Database,
        "Default value not_an_integer does not match given type integer",
    );
}

#[test]
fn vector_not_supported() {
    assert_error(
        standard().is_valid(&dv(a("embedding", "vector", 128, r#""filters": ["vector"]"#))),
        Kind::Database,
        "Vector types are not supported by the current database",
    );
}

fn vectors() -> AttributeDefinition {
    AttributeDefinition::new(vec![], wide(&[Capability::Vectors], &[]))
}

#[test]
fn vector_cannot_be_array() {
    assert_error(
        vectors().is_valid(&dv(a("embeddings", "vector", 128, r#""array": true, "filters": ["vector"]"#))),
        Kind::Database,
        "Vector type cannot be an array",
    );
}

#[test]
fn vector_invalid_dimensions() {
    assert_error(
        vectors().is_valid(&dv(a("embedding", "vector", 0, r#""filters": ["vector"]"#))),
        Kind::Database,
        "Vector dimensions must be a positive integer",
    );
}

#[test]
fn vector_dimensions_exceeds_max() {
    assert_error(
        vectors().is_valid(&dv(a("embedding", "vector", 20000, r#""filters": ["vector"]"#))),
        Kind::Database,
        "Vector dimensions cannot exceed 16000",
    );
}

#[test]
fn spatial_not_supported() {
    assert_error(
        standard().is_valid(&dv(a("location", "point", 0, r#""filters": ["point"]"#))),
        Kind::Database,
        "Spatial attributes are not supported",
    );
}

fn spatial() -> AttributeDefinition {
    AttributeDefinition::new(vec![], wide(&[], &[Feature::Spatial]))
}

#[test]
fn spatial_cannot_be_array() {
    assert_error(
        spatial().is_valid(&dv(a("locations", "point", 0, r#""array": true, "filters": ["point"]"#))),
        Kind::Database,
        "Spatial attributes cannot be arrays",
    );
}

#[test]
fn spatial_must_have_empty_size() {
    assert_error(
        spatial().is_valid(&dv(a("location", "point", 100, r#""filters": ["point"]"#))),
        Kind::Database,
        "Size must be empty for spatial attributes",
    );
}

#[test]
fn object_not_supported() {
    assert_error(
        standard().is_valid(&dv(a("metadata", "object", 0, r#""filters": ["object"]"#))),
        Kind::Database,
        "Object attributes are not supported",
    );
}

fn objects() -> AttributeDefinition {
    AttributeDefinition::new(vec![], wide(&[Capability::Objects], &[]))
}

#[test]
fn object_cannot_be_array() {
    assert_error(
        objects().is_valid(&dv(a("metadata", "object", 0, r#""array": true, "filters": ["object"]"#))),
        Kind::Database,
        "Object attributes cannot be arrays",
    );
}

#[test]
fn object_must_have_empty_size() {
    assert_error(
        objects().is_valid(&dv(a("metadata", "object", 100, r#""filters": ["object"]"#))),
        Kind::Database,
        "Size must be empty for object attributes",
    );
}

#[test]
fn attribute_limit_exceeded() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 16_777_216, varchar: 65535, integer: i64::MAX, attributes: 5, ..P::default() }.build(),
    )
    .measures(Some(Box::new(|_| Ok(10))), Some(Box::new(|_| Ok(100))));
    assert_error(
        validator.is_valid(&dv(a("title", "string", 255, ""))),
        Kind::Limit,
        "Column limit reached. Cannot create new attribute. Current attribute count is 10 but the maximum is 5. Remove some attributes to free up space.",
    );
}

#[test]
fn row_width_limit_exceeded() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 16_777_216, varchar: 65535, integer: i64::MAX, attributes: 100, document_size: 1000, ..P::default() }
            .build(),
    )
    .measures(Some(Box::new(|_| Ok(5))), Some(Box::new(|_| Ok(1500))));
    assert_error(
        validator.is_valid(&dv(a("title", "string", 255, ""))),
        Kind::Limit,
        "Row width limit reached. Cannot create new attribute. Current row width is 1500 bytes but the maximum is 1000 bytes. Reduce the size of existing attributes or remove some attributes to free up space.",
    );
}

#[test]
fn vector_default_value_not_array() {
    assert_error(
        vectors().is_valid(&dv(a("embedding", "vector", 3, r#""default": "not_an_array", "filters": ["vector"]"#))),
        Kind::Database,
        "Vector default value must be an array",
    );
}

#[test]
fn vector_default_value_wrong_element_count() {
    assert_error(
        vectors().is_valid(&dv(a("embedding", "vector", 3, r#""default": [1.0, 2.0], "filters": ["vector"]"#))),
        Kind::Database,
        "Vector default value must have exactly 3 elements",
    );
}

#[test]
fn vector_default_value_non_numeric_elements() {
    assert_error(
        vectors()
            .is_valid(&dv(a("embedding", "vector", 3, r#""default": [1.0, "not_a_number", 3.0], "filters": ["vector"]"#))),
        Kind::Database,
        "Vector default value must contain only numeric elements",
    );
}

#[test]
fn longtext_size_too_large() {
    assert_error(
        standard().is_valid(&dv(a("content", "longtext", 5_000_000_000, ""))),
        Kind::Database,
        "Max size allowed for longtext is: 4294967295",
    );
}

#[test]
fn valid_attributes() {
    let validator = standard();
    ok(&validator, a("name", "varchar", 255, ""));
    ok(&validator, a("content", "text", 65535, ""));
    ok(&validator, a("content", "mediumtext", 16_777_215, ""));
    ok(&validator, a("content", "longtext", 4_294_967_295, ""));
    ok(&validator, a("price", "double", 0, ""));
    ok(&validator, a("active", "boolean", 0, ""));
    ok(&validator, a("title", "string", 255, r#""default": "default title""#));
    ok(&validator, a("count", "integer", 4, r#""default": 42"#));
    ok(&validator, a("price", "double", 0, r#""default": 19.99"#));
    ok(&validator, a("active", "boolean", 0, r#""default": true"#));
    ok(&validator, a("tags", "string", 255, r#""array": true"#));
    ok(&validator, a("tags", "string", 255, r#""default": ["tag1", "tag2", "tag3"], "array": true"#));
    ok(&validator, a("created", "datetime", 0, r#""default": "2024-01-01T00:00:00.000Z", "signed": false, "filters": ["datetime"]"#));
    ok(&validator, a("name", "varchar", 255, r#""default": "default name""#));
    ok(&validator, a("content", "text", 65535, r#""default": "default content""#));
    ok(&validator, a("count", "integer", 4, ""));
    ok(&validator, a("title", "string", 255, ""));
}

#[test]
fn default_value_type_mismatches() {
    let validator = standard();
    let cases = [
        (a("price", "double", 0, r#""default": "not_a_float""#), "Default value not_a_float does not match given type double"),
        (a("active", "boolean", 0, r#""default": "not_a_boolean""#), "Default value not_a_boolean does not match given type boolean"),
        (a("title", "string", 255, r#""default": 123"#), "Default value 123 does not match given type string"),
        (a("tags", "string", 255, r#""default": ["tag1", 123, "tag3"], "array": true"#), "Default value 123 does not match given type string"),
        (
            a("created", "datetime", 0, r#""default": 12345, "signed": false, "filters": ["datetime"]"#),
            "Default value 12345 does not match given type datetime",
        ),
        (a("name", "varchar", 255, r#""default": 123"#), "Default value 123 does not match given type varchar"),
        (a("content", "text", 65535, r#""default": 123"#), "Default value 123 does not match given type text"),
        (a("content", "mediumtext", 16_777_215, r#""default": 123"#), "Default value 123 does not match given type mediumtext"),
        (a("content", "longtext", 4_294_967_295, r#""default": 123"#), "Default value 123 does not match given type longtext"),
    ];
    for (attribute, message) in cases {
        assert_error(validator.is_valid(&dv(attribute)), Kind::Database, message);
    }
}

#[test]
fn unsigned_integer_size_limit() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 16_777_216, varchar: 65535, integer: 100, ..P::default() }.build(),
    );
    // Unsigned allows double the size.
    ok(&validator, a("count", "integer", 80, r#""signed": false"#));
}

#[test]
fn unsigned_integer_size_too_large() {
    let validator = AttributeDefinition::new(
        vec![],
        P { string: 16_777_216, varchar: 65535, integer: 100, ..P::default() }.build(),
    );
    assert_error(
        validator.is_valid(&dv(a("count", "integer", 150, r#""signed": false"#))),
        Kind::Database,
        "Max size allowed for int is: 100",
    );
}

#[test]
fn duplicate_attribute_id_case_insensitive() {
    let validator = AttributeDefinition::new(vec![existing("Title")], wide(&[], &[]));
    assert_error(validator.is_valid(&dv(a("title", "string", 255, ""))), Kind::Duplicate, "Attribute already exists in metadata");
}

fn column(name: &str) -> Column {
    Column { name: name.to_owned(), kind: "VARCHAR(255)".to_owned(), length: Some(255), nullable: true }
}

#[test]
fn duplicate_in_schema() {
    let validator = AttributeDefinition::new(vec![], wide(&[Capability::SchemaIntrospection], &[]))
        .schema_attributes(&[column("existing_column")]);
    assert_error(
        validator.is_valid(&dv(a("existing_column", "string", 255, ""))),
        Kind::Duplicate,
        "Attribute already exists in schema",
    );
}

#[test]
fn schema_check_skipped_when_migrating() {
    let profile = P {
        capabilities: vec![Capability::SchemaIntrospection],
        shared_tables: true,
        migrating: true,
        string: 16_777_216,
        varchar: 65535,
        integer: i64::MAX,
        ..P::default()
    }
    .build();
    let validator = AttributeDefinition::new(vec![], profile).schema_attributes(&[column("existing_column")]);
    ok(&validator, a("existing_column", "string", 255, ""));
}

#[test]
fn valid_spatial_vector_and_object_attributes() {
    ok(&spatial(), a("route", "linestring", 0, r#""filters": ["linestring"]"#));
    ok(&spatial(), a("area", "polygon", 0, r#""filters": ["polygon"]"#));
    ok(&spatial(), a("location", "point", 0, r#""filters": ["point"]"#));
    ok(&vectors(), a("embedding", "vector", 128, r#""filters": ["vector"]"#));
    ok(&vectors(), a("embedding", "vector", 3, r#""default": [1.0, 2.0, 3.0], "filters": ["vector"]"#));
    ok(&objects(), a("metadata", "object", 0, r#""filters": ["object"]"#));
}

#[test]
fn array_default_on_non_array_attribute() {
    assert_error(
        standard().is_valid(&dv(a("title", "string", 255, r#""default": ["not", "allowed"]"#))),
        Kind::Database,
        "Cannot set an array default value for a non-array attribute",
    );
}

#[test]
fn array_default_allowed_on_json_filter_attribute() {
    ok(&standard(), a("services", "string", 16384, r#""default": [], "filters": ["json"]"#));
}

#[test]
fn object_default_allowed_on_json_filter_attribute() {
    let mut attribute = a("data", "string", 65535, r#""filters": ["json", "encrypt"]"#);
    attribute.put("default", Value::Object(Array::new()));
    ok(&standard(), attribute);
}

#[test]
fn get_type_description_and_is_array() {
    let validator = standard();
    assert_eq!(validator.type_name(), "object");
    assert_eq!(utopia_validators::Validator::kind(&validator), utopia_validators::Type::Object);
    assert_eq!(validator.description(), "Invalid attribute");
    assert!(!utopia_validators::Validator::is_array(&validator));
}

#[test]
fn is_valid_with_attribute_vo_directly() {
    assert!(standard().check(&Attribute::string("directAttr", 255)).unwrap());
}

#[test]
fn a_value_that_is_not_an_attribute_is_invalid() {
    let validator = standard();
    assert!(!validator.is_valid(&Value::from("title")).unwrap());
    assert_eq!(validator.description(), "Value must be an attribute");
}

#[test]
fn attribute_does_not_collide_with_schema() {
    let validator = AttributeDefinition::new(vec![], wide(&[Capability::SchemaIntrospection], &[]))
        .schema_attributes(&[column("existing_column")]);
    ok(&validator, a("new_column", "string", 255, ""));
}

#[test]
fn invalid_format_for_type() {
    formats::add("testformat", Arc::new(|_| Box::new(utopia_validators::Text::new(100))), ColumnType::Integer);
    assert_error(
        standard().is_valid(&dv(a("formatted", "string", 255, r#""format": "testformat""#))),
        Kind::Database,
        "Format (\"testformat\") not available for this attribute type (\"string\")",
    );
}

#[test]
fn id_type_attribute_validation() {
    assert!(standard().check(&Attribute::id("myId")).unwrap());
}

#[test]
fn unknown_column_type_in_check_type() {
    let attribute = Attribute::from_array(&array(
        r#"{"key": "badtype", "type": "enum", "size": 0, "required": false, "default": null, "signed": true, "array": false, "filters": []}"#,
    ));
    assert_error(attribute, Kind::Structure, "Unknown attribute type: enum");
}

#[test]
fn unknown_column_type_with_default_value() {
    let attribute = Attribute::from_array(&array(
        r#"{"key": "baddefault", "type": "enum", "size": 0, "required": false, "default": "somevalue", "signed": true, "array": false, "filters": []}"#,
    ));
    assert_error(attribute, Kind::Structure, "Unknown attribute type: enum");
}

#[test]
fn null_default_value_in_validate_default_types() {
    assert!(standard().check(&Attribute::string("nullableField", 255)).unwrap());
}

#[test]
fn vector_component_non_numeric_default_type() {
    let validator = vectors();
    assert!(validator.check(&Attribute::vector("vec", 3).default(val("[1.0, 2.0, 3.0]"))).unwrap());

    let attribute = Attribute::from_array(&array(
        r#"{"key": "vec2", "type": "vector", "size": 3, "required": false, "default": [1.0, "notANumber", 3.0]}"#,
    ))
    .unwrap();
    assert_error(vectors().check(&attribute), Kind::Database, "Vector default value must contain only numeric elements");
}

#[test]
fn schema_duplicate_check_with_filter_callback() {
    let validator = AttributeDefinition::new(vec![], wide(&[Capability::SchemaIntrospection], &[]))
        .schema_attributes(&[column("_prefix_column")])
        .filter(Box::new(|key| key.replace("_prefix_", "")));
    assert_error(validator.is_valid(&dv(a("column", "string", 255, ""))), Kind::Duplicate, "Attribute already exists in schema");
}

#[test]
fn relationship_type_passes_check_type() {
    let attribute = Attribute::from_array(&array(
        r#"{"key": "parent", "type": "relationship", "size": 0, "required": false, "default": null, "signed": false, "array": false, "filters": [],
            "options": {"relatedCollection": "parents", "relationType": "manyToOne", "side": "child"}}"#,
    ))
    .unwrap();
    assert!(standard().check(&attribute).unwrap());
}

#[test]
fn big_integer_default_rejects_non_numeric_string() {
    let validator = AttributeDefinition::new(vec![], P::default().build());
    assert_error(
        validator.check(&Attribute::big_integer("counter").default("not_a_bigint")),
        Kind::Database,
        "Default value not_a_bigint is not a valid integer string for type bigint",
    );
}
