//! PHP: `tests/unit/Validator/StructureTest.php` (utopia-php/database
//! 8.1.3), with `tests/unit/Format.php` (the `email` format).

mod common;

use std::sync::Arc;

use common::{P, caps, doc, dv, s, val};
use utopia_database::Document;
use utopia_database::adapter::Profile;
use utopia_database::capability::Capability;
use utopia_database::operator::Operator;
use utopia_database::validator::structure::{Structure, formats};
use utopia_database::value::{Array, Ext, Value};
use utopia_query::schema::ColumnType;

/// `Tests\Unit\Format`: a `Text` whose rule is `filter_var($value,
/// FILTER_VALIDATE_EMAIL)`.
struct Email;

impl utopia_validators::Validator for Email {
    fn description(&self) -> String {
        "Value must be a valid email address".to_owned()
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        let text = match value {
            serde_json::Value::String(s) => s.clone(),
            serde_json::Value::Number(n) => n.to_string(),
            _ => return false,
        };
        let filtered = php_std::filter::filter_var(
            &php_std::filter::Value::Str(text.into_bytes()),
            php_std::filter::FILTER_VALIDATE_EMAIL,
            &php_std::filter::Options::Flags(0),
        );
        matches!(filtered, Ok(f) if f.value != php_std::filter::Value::Bool(false))
    }
}

/// `setUp()`: the `email` format, and the collection with its `feedback`
/// attribute.
fn collection() -> Document {
    formats::add("email", Arc::new(|_| Box::new(Email)), ColumnType::String);
    doc(r#"{
        "$id": "_metadata", "$collection": "_metadata", "name": "collections",
        "attributes": [
            {"$id": "title", "type": "string", "format": "", "size": 256, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "description", "type": "string", "format": "", "size": 1000000, "required": false, "signed": true, "array": false, "filters": []},
            {"$id": "rating", "type": "integer", "format": "", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "reviews", "type": "integer", "format": "", "size": 5, "required": false, "signed": true, "array": true, "filters": []},
            {"$id": "price", "type": "double", "format": "", "size": 5, "required": true, "signed": false, "array": false, "filters": []},
            {"$id": "published", "type": "boolean", "format": "", "size": 5, "required": true, "signed": true, "array": false, "filters": []},
            {"$id": "tags", "type": "string", "format": "", "size": 55, "required": false, "signed": true, "array": true, "filters": []},
            {"$id": "id", "type": "id", "format": "", "size": 0, "required": false, "signed": false, "array": false, "filters": []},
            {"$id": "varchar_field", "type": "varchar", "format": "", "size": 255, "required": false, "signed": true, "array": false, "filters": []},
            {"$id": "text_field", "type": "text", "format": "", "size": 65535, "required": false, "signed": true, "array": false, "filters": []},
            {"$id": "mediumtext_field", "type": "mediumtext", "format": "", "size": 16777215, "required": false, "signed": true, "array": false, "filters": []},
            {"$id": "longtext_field", "type": "longtext", "format": "", "size": 4294967295, "required": false, "signed": true, "array": false, "filters": []},
            {"$id": "feedback", "type": "string", "format": "email", "size": 55, "required": true, "signed": true, "array": false, "filters": []}
        ],
        "indexes": []
    }"#)
}

fn profile() -> Profile {
    caps(&[Capability::DefinedAttributes, Capability::UnsignedBigInt])
}

fn validator() -> Structure {
    Structure::new(collection(), profile())
}

const BASE: &str = r#"{
    "$collection": "posts", "title": "Demo Title", "description": "Demo description", "rating": 5,
    "price": 1.99, "published": true, "tags": ["dog", "cat", "mouse"], "feedback": "team@appwrite.io",
    "$createdAt": "2000-04-01T12:00:00.000+00:00", "$updatedAt": "2000-04-01T12:00:00.000+00:00"
}"#;

/// The base post with `fields` set (in place, or added at the end) and
/// `removed` left out.
fn post_with(fields: &[(&str, Value)], removed: &[&str]) -> Document {
    let mut document = doc(BASE);
    for key in removed {
        document.remove(key);
    }
    for (key, value) in fields {
        document.put(*key, value.clone());
    }
    document
}

fn post(fields: &[(&str, Value)]) -> Value {
    dv(post_with(fields, &[]))
}

fn check(validator: &Structure, value: &Value) -> bool {
    validator.is_valid(value).expect("no error")
}

#[test]
fn document_instance() {
    let validator = validator();
    assert!(!check(&validator, &s("string")));
    assert!(!check(&validator, &Value::Null));
    assert!(!check(&validator, &Value::Bool(false)));
    assert!(!check(&validator, &Value::Int(1)));
    assert_eq!(validator.description(), "Invalid document structure: Value must be an instance of Document");
}

#[test]
fn collection_attribute() {
    let validator = validator();
    assert!(!check(&validator, &dv(Document::empty())));
    assert_eq!(validator.description(), "Invalid document structure: Missing collection attribute $collection");
}

#[test]
fn collection_not_found() {
    let validator = Structure::new(Document::empty(), profile());
    assert!(!check(&validator, &post(&[])));
    assert_eq!(validator.description(), "Invalid document structure: Collection not found");
}

#[test]
fn required_keys() {
    let validator = validator();
    assert!(!check(&validator, &dv(post_with(&[], &["title"]))));
    assert_eq!(validator.description(), "Invalid document structure: Missing required attribute \"title\"");
}

#[test]
fn required_key_stored_as_null() {
    let document = post_with(&[], &["title", "tags"]);
    let with_null = || {
        let mut d = document.clone();
        d.put("title", Value::Null);
        dv(d)
    };

    let validator = Structure::new(collection(), profile()).current_document(Some(doc(r#"{"title": null}"#)));
    assert!(check(&validator, &dv(document.clone())), "{}", validator.description());
    assert!(check(&validator, &with_null()), "{}", validator.description());

    let validator = Structure::new(collection(), profile()).current_document(Some(doc(r#"{"title": "My Title"}"#)));
    assert!(!check(&validator, &with_null()));
    assert_eq!(validator.description(), "Invalid document structure: Missing required attribute \"title\"");

    let validator = validator_plain();
    assert!(!check(&validator, &dv(document)));
    assert_eq!(validator.description(), "Invalid document structure: Missing required attribute \"title\"");
}

fn validator_plain() -> Structure {
    Structure::new(collection(), profile())
}

#[test]
fn null_values() {
    let validator = validator();
    assert!(check(&validator, &post(&[("title", s("My Title")), ("description", Value::Null), ("id", s("1000"))])));
    assert!(check(
        &validator,
        &post(&[("title", s("My Title")), ("description", Value::Null), ("tags", val(r#"["dog", null, "mouse"]"#))])
    ));
}

#[test]
fn unknown_keys() {
    let validator = validator();
    let mut document = doc(BASE);
    let mut array = Array::new();
    for (k, v) in document.as_array().iter() {
        array.set(k.to_owned(), v.clone());
        if k == utopia_database::value::KeyRef::Str(b"title") {
            array.set("titlex".into(), s("Unknown Attribute"));
        }
    }
    *document.as_array_mut() = array;
    assert!(!check(&validator, &dv(document)));
    assert_eq!(validator.description(), "Invalid document structure: Unknown attribute: \"titlex\"");
}

const INT32: &str = "Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647";

#[test]
fn integer_as_string() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("rating", s("5"))])));
    assert_eq!(
        validator.description(),
        format!("Invalid document structure: Attribute \"rating\" has invalid type. {INT32}")
    );
}

#[test]
fn valid_document() {
    assert!(check(&validator(), &post(&[])));
}

#[test]
fn string_validation() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("title", Value::Int(5))])));
    assert_eq!(
        validator.description(),
        "Invalid document structure: Attribute \"title\" has invalid type. Value must be a valid string and no longer than 256 chars"
    );
}

#[test]
fn array_of_strings_validation() {
    let validator = validator();
    let tags = "Invalid document structure: Attribute \"tags['0']\" has invalid type. Value must be a valid string and no longer than 55 chars";

    assert!(!check(&validator, &post(&[("title", s("string")), ("tags", val(r#"[1, "cat", "mouse"]"#))])));
    assert_eq!(validator.description(), tags);

    assert!(!check(&validator, &post(&[("title", s("string")), ("tags", val("[true]"))])));
    assert_eq!(validator.description(), tags);

    assert!(check(&validator, &post(&[("title", s("string")), ("tags", val("[]"))])));

    assert!(!check(
        &validator,
        &post(&[
            ("title", s("string")),
            (
                "tags",
                val(
                    r#"["too-long-tag-name-to-make-sure-the-length-validator-inside-string-attribute-type-fails-properly"]"#
                )
            ),
        ])
    ));
    assert_eq!(validator.description(), tags);
}

#[test]
fn array_as_object_validation() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("title", s("string")), ("tags", val(r#"{"name": "dog"}"#))])));
}

#[test]
fn array_of_objects_validation() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("title", s("string")), ("tags", val(r#"[{"name": "dog"}]"#))])));
}

#[test]
fn integer_validation() {
    let validator = validator();
    let expected = format!("Invalid document structure: Attribute \"rating\" has invalid type. {INT32}");

    assert!(!check(
        &validator,
        &post(&[("title", s("string")), ("rating", Value::Bool(true)), ("published", Value::Bool(false))])
    ));
    assert_eq!(validator.description(), expected);

    assert!(!check(&validator, &post(&[("title", s("string")), ("rating", s("")), ("published", Value::Bool(false))])));
    assert_eq!(validator.description(), expected);
}

#[test]
fn array_of_integers_validation() {
    let validator = validator();
    assert!(check(&validator, &post(&[("title", s("string")), ("reviews", val("[3, 4, 4, 5]"))])));
    assert!(check(&validator, &post(&[("title", s("string")), ("reviews", val("[]"))])));
    assert!(check(&validator, &post(&[("title", s("string")), ("reviews", Value::Null)])));
    assert!(!check(&validator, &post(&[("title", s("string")), ("reviews", val(r#"["", 4, 4, 5]"#))])));
    assert_eq!(
        validator.description(),
        format!("Invalid document structure: Attribute \"reviews['0']\" has invalid type. {INT32}")
    );
}

#[test]
fn float_validation() {
    let validator = validator();
    let expected = "Invalid document structure: Attribute \"price\" has invalid type. Value must be a valid float";

    assert!(!check(
        &validator,
        &post(&[("title", s("string")), ("price", s("2.5")), ("published", Value::Bool(false))])
    ));
    assert_eq!(validator.description(), expected);

    assert!(!check(&validator, &post(&[("title", s("string")), ("price", s("")), ("published", Value::Bool(false))])));
    assert_eq!(validator.description(), expected);
}

#[test]
fn boolean_validation() {
    let validator = validator();
    let expected =
        "Invalid document structure: Attribute \"published\" has invalid type. Value must be a valid boolean";

    assert!(!check(&validator, &post(&[("title", s("string")), ("published", Value::Int(1))])));
    assert_eq!(validator.description(), expected);

    assert!(!check(&validator, &post(&[("title", s("string")), ("published", s(""))])));
    assert_eq!(validator.description(), expected);
}

#[test]
fn format_validation() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("title", s("string")), ("feedback", s("team_appwrite.io"))])));
    assert_eq!(
        validator.description(),
        "Invalid document structure: Attribute \"feedback\" has invalid format. Value must be a valid email address"
    );
}

#[test]
fn integer_max_range() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("title", s("string")), ("rating", Value::Int(i64::MAX))])));
    assert_eq!(
        validator.description(),
        format!("Invalid document structure: Attribute \"rating\" has invalid type. {INT32}")
    );
}

#[test]
fn double_unsigned() {
    let validator = validator();
    assert!(!check(&validator, &post(&[("title", s("string")), ("price", Value::Float(-1.99))])));
    assert!(
        validator
            .description()
            .contains("Invalid document structure: Attribute \"price\" has invalid type. Value must be a valid range between 0 and "),
        "{}",
        validator.description()
    );
}

#[test]
fn double_max_range() {
    let validator = validator();
    assert!(!check(
        &validator,
        &post(&[("title", s("string")), ("rating", Value::Int(1)), ("price", Value::Float(f64::INFINITY))])
    ));
}

#[test]
fn id() {
    let sql_id = s("1000");
    let mongo_id = s("0198fffb-d664-710a-9765-f922b3e81e3d");
    let fields = |id: &Value| post(&[("title", s("My Title")), ("description", Value::Null), ("id", id.clone())]);

    let validator = validator();
    assert!(check(&validator, &fields(&sql_id)));
    assert!(check(&validator, &fields(&mongo_id)));

    let validator = Structure::new(
        collection(),
        P {
            capabilities: vec![Capability::DefinedAttributes, Capability::UnsignedBigInt],
            id_type: ColumnType::Uuid7,
            ..P::default()
        }
        .build(),
    );
    assert!(check(&validator, &fields(&mongo_id)));
    assert!(check(&validator, &fields(&mongo_id)));
}

fn operator(o: Operator) -> Value {
    Value::Ext(Ext::Operator(Box::new(o)))
}

#[test]
fn operators_skipped_during_validation() {
    let validator = validator();
    assert!(
        check(&validator, &post(&[("title", s("My Title")), ("rating", operator(Operator::increment(1, None)))])),
        "{}",
        validator.description()
    );
}

#[test]
fn multiple_operators_skipped_during_validation() {
    let validator = validator();
    assert!(
        check(
            &validator,
            &post(&[
                ("title", operator(Operator::string_concat(" - Updated"))),
                ("rating", operator(Operator::increment(1, None))),
                ("price", operator(Operator::multiply(2, None))),
                ("published", operator(Operator::toggle())),
                ("tags", operator(Operator::array_append([s("new")]))),
            ])
        ),
        "{}",
        validator.description()
    );
}

#[test]
fn operators_learn_their_attribute() {
    let validator = validator();
    let mut document = post_with(&[("rating", operator(Operator::increment(1, None)))], &[]);
    assert!(validator.validate(&mut document).unwrap());
    match document.get("rating") {
        Some(Value::Ext(Ext::Operator(o))) => assert_eq!(o.attribute(), "rating"),
        other => panic!("{other:?}"),
    }
}

#[test]
fn operators_checked_by_the_given_check() {
    let validator = validator().operators(Arc::new(|o: &Operator| {
        Ok((o.attribute() == "rating").then(|| format!("Cannot apply operator to {}", o.attribute())))
    }));
    assert!(!check(&validator, &post(&[("rating", operator(Operator::increment(1, None)))])));
    assert_eq!(validator.description(), "Invalid document structure: Cannot apply operator to rating");
}

#[test]
fn missing_required_field_without_operator() {
    let validator = validator();
    assert!(!check(&validator, &dv(post_with(&[("title", s("My Title"))], &["rating"]))));
    assert_eq!(validator.description(), "Invalid document structure: Missing required attribute \"rating\"");
}

#[test]
fn varchar_validation() {
    let validator = validator();
    let expected = "Invalid document structure: Attribute \"varchar_field\" has invalid type. Value must be a valid string and no longer than 255 chars";
    assert!(check(&validator, &post(&[("varchar_field", s("Short varchar text"))])));

    assert!(!check(&validator, &post(&[("varchar_field", Value::Int(123))])));
    assert_eq!(validator.description(), expected);

    assert!(!check(&validator, &post(&[("varchar_field", s(&"a".repeat(256)))])));
    assert_eq!(validator.description(), expected);
}

#[test]
fn text_validation() {
    let validator = validator();
    let expected = "Invalid document structure: Attribute \"text_field\" has invalid type. Value must be a valid string no longer than 65535 bytes";
    assert!(check(&validator, &post(&[("text_field", s(&"a".repeat(65535)))])));
    // Optional, so a null value is never validated.
    assert!(check(&validator, &post(&[("text_field", Value::Null)])));

    assert!(!check(&validator, &post(&[("text_field", Value::Int(123))])));
    assert_eq!(validator.description(), expected);

    assert!(!check(&validator, &post(&[("text_field", s(&"a".repeat(65536)))])));
    assert_eq!(validator.description(), expected);
}

#[test]
fn mediumtext_validation() {
    let validator = validator();
    assert!(check(&validator, &post(&[("mediumtext_field", s(&"a".repeat(100000)))])));
    assert!(!check(&validator, &post(&[("mediumtext_field", Value::Int(123))])));
    assert_eq!(
        validator.description(),
        "Invalid document structure: Attribute \"mediumtext_field\" has invalid type. Value must be a valid string no longer than 16777215 bytes"
    );
}

const SMALL_BASE: &str = r#"{"$collection": "posts", "$createdAt": "2000-04-01T12:00:00.000+00:00", "$updatedAt": "2000-04-01T12:00:00.000+00:00"}"#;

fn small(key: &str, value: Value) -> Value {
    let mut document = doc(SMALL_BASE);
    document.put(key, value);
    dv(document)
}

#[test]
fn mediumtext_sized_validation() {
    let collection = doc(r#"{
        "$id": "posts", "$collection": "_metadata", "name": "posts",
        "attributes": [{"$id": "mediumtext", "type": "mediumtext", "format": "", "size": 100, "required": false, "signed": true, "array": false, "filters": []}],
        "indexes": []
    }"#);
    let validator = Structure::new(collection, profile());
    let expected = "Invalid document structure: Attribute \"mediumtext\" has invalid type. Value must be a valid string no longer than 100 bytes";

    assert!(check(&validator, &small("mediumtext", s(&"a".repeat(100)))));
    assert!(!check(&validator, &small("mediumtext", s(&"a".repeat(101)))));
    assert_eq!(validator.description(), expected);

    assert!(check(&validator, &small("mediumtext", s(&"📝".repeat(25)))));
    assert!(!check(&validator, &small("mediumtext", s(&"📝".repeat(26)))));
    assert_eq!(validator.description(), expected);
}

#[test]
fn longtext_validation() {
    let validator = validator();
    assert!(check(&validator, &post(&[("longtext_field", s(&"a".repeat(1000000)))])));
    assert!(!check(&validator, &post(&[("longtext_field", Value::Int(123))])));
    assert_eq!(
        validator.description(),
        "Invalid document structure: Attribute \"longtext_field\" has invalid type. Value must be a valid string no longer than 4294967295 bytes"
    );
}

#[test]
fn string_type_array_validation() {
    let collection = doc(r#"{
        "$id": "_metadata", "$collection": "_metadata", "name": "collections",
        "attributes": [
            {"$id": "varchar_array", "type": "varchar", "format": "", "size": 128, "required": false, "signed": true, "array": true, "filters": []},
            {"$id": "text_array", "type": "text", "format": "", "size": 65535, "required": false, "signed": true, "array": true, "filters": []}
        ],
        "indexes": []
    }"#);
    let validator = Structure::new(collection, profile());
    let expected = "Invalid document structure: Attribute \"varchar_array['0']\" has invalid type. Value must be a valid string and no longer than 128 chars";

    assert!(check(&validator, &small("varchar_array", val(r#"["test1", "test2", "test3"]"#))));

    assert!(!check(&validator, &small("varchar_array", val(r#"[123, "test2", "test3"]"#))));
    assert_eq!(validator.description(), expected);

    assert!(!check(
        &validator,
        &small("varchar_array", Value::Array(Array::from_list(vec![s(&"a".repeat(129)), s("test2")])))
    ));
    assert_eq!(validator.description(), expected);
}

#[test]
fn legacy_big_integer_metadata_uses_big_integer_validation() {
    let collection = doc(r#"{
        "$id": "posts", "$collection": "_metadata", "name": "posts",
        "attributes": [
            {"$id": "signed", "type": "bigint", "format": "", "size": 9999, "required": false, "signed": true, "array": false, "filters": []},
            {"$id": "unsigned", "type": "biginteger", "format": "", "size": 0, "required": false, "signed": false, "array": false, "filters": []}
        ],
        "indexes": []
    }"#);
    let validator = Structure::new(collection, profile());
    let mut document =
        doc(r#"{"$collection": "posts", "signed": "-9223372036854775808", "unsigned": "18446744073709551615",
            "$createdAt": "2000-04-01T12:00:00.000+00:00", "$updatedAt": "2000-04-01T12:00:00.000+00:00"}"#);

    assert!(validator.validate(&mut document).unwrap(), "{}", validator.description());
    assert_eq!(document.get("signed"), Some(&Value::Int(i64::MIN)));
    assert_eq!(document.get("unsigned"), Some(&s("18446744073709551615")));
}

#[test]
fn big_int_unsigned_rejects_negative_numeric_string() {
    let collection = doc(r#"{
        "$id": "posts", "$collection": "_metadata", "name": "posts",
        "attributes": [
            {"$id": "bigint_unsigned", "type": "biginteger", "format": "", "size": 0, "required": true, "signed": false, "array": false, "filters": []}
        ],
        "indexes": []
    }"#);
    let validator = Structure::new(collection, profile());
    assert!(!check(&validator, &small("bigint_unsigned", s("-1"))));
    assert_eq!(
        validator.description(),
        "Invalid document structure: Attribute \"bigint_unsigned\" has invalid type. Value must be a valid unsigned 64-bit integer between 0 and 18,446,744,073,709,551,615"
    );
}

#[test]
fn text_validation_uses_column_capacity_not_declared_size() {
    let collection = doc(r#"{
        "$id": "posts", "$collection": "_metadata", "name": "posts",
        "attributes": [{"$id": "text", "type": "text", "format": "", "size": 1048576, "required": false, "signed": true, "array": false, "filters": []}],
        "indexes": []
    }"#);
    let validator = Structure::new(collection, profile());
    let expected = "Invalid document structure: Attribute \"text\" has invalid type. Value must be a valid string no longer than 65535 bytes";

    assert!(
        check(&validator, &small("text", s(&"a".repeat(65535)))),
        "A value filling the full column capacity is accepted"
    );

    assert!(!check(&validator, &small("text", s(&"a".repeat(65536)))));
    assert_eq!(validator.description(), expected);

    assert!(!check(&validator, &small("text", s(&"📝".repeat(20000)))), "80,000 bytes are rejected by byte length");
    assert_eq!(validator.description(), expected);
}
