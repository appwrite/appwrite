//! PHP: `tests/unit/Validator/AttributeJsonDefaultTest.php`
//! (utopia-php/database 8.1.3).

mod common;

use common::{P, assert_error, doc, dv, val};
use utopia_database::Kind;
use utopia_database::attribute::Attribute;
use utopia_database::filter::Filter;
use utopia_database::validator::attribute_definition::AttributeDefinition;
use utopia_database::value::{Array, Value};

fn validator() -> AttributeDefinition {
    AttributeDefinition::new(vec![], P { string: 16_777_216, varchar: 65535, integer: i64::MAX, ..P::default() }.build())
}

fn meta(default: Value) -> Attribute {
    Attribute::string("meta", 65535).default(default).filters([Filter::Json])
}

/// `scalarDefaults()`.
#[test]
fn scalar_default_must_match_the_storage_type() {
    let cases = [
        (Value::Int(12345), "Default value 12345 does not match given type string"),
        (Value::Float(1.5), "Default value 1.5 does not match given type string"),
        (Value::Bool(true), "Default value 1 does not match given type string"),
    ];
    for (default, message) in cases {
        assert_error(validator().check(&meta(default)), Kind::Database, message);
    }
}

#[test]
fn structured_default_must_be_json_encodable() {
    let mut name = Array::new();
    name.set("name".into(), Value::Str(b"\xB1\x31".as_slice().into()));
    assert_error(
        validator().check(&meta(Value::Array(name))),
        Kind::Database,
        "Default value of json attribute \"meta\" is not JSON-encodable: Malformed UTF-8 characters, possibly incorrectly encoded",
    );
}

#[test]
fn json_filter_does_not_exempt_non_string_types() {
    assert_error(
        validator().check(&Attribute::integer("count").default(val("[]")).filters([Filter::Json])),
        Kind::Database,
        "Cannot set an array default value for a non-array attribute",
    );
}

/// `jsonDocuments()`.
#[test]
fn json_document_default_is_valid() {
    let defaults = [
        val("[]"),
        val(r#"["a", "b"]"#),
        val(r#"{"cost": 12, "memory": 65536}"#),
        Value::Object(Array::new()),
        dv(doc(r#"{"cost": 12}"#)),
        Value::from("{}"),
        Value::from(""),
    ];
    for default in defaults {
        assert!(validator().check(&meta(default.clone())).unwrap(), "{default:?}");
    }
}
