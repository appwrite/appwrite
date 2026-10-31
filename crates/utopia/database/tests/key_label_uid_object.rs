//! PHP: `tests/unit/Validator/KeyTest.php`, `LabelTest.php`, `UIDTest.php`
//! and `ObjectTest.php` (utopia-php/database 8.1.3).

mod common;

use common::{s, val};
use utopia_database::validator::key::Key;
use utopia_database::validator::label::Label;
use utopia_database::validator::object_value::ObjectValue;
use utopia_database::validator::uid::Uid;
use utopia_database::value::{Array, Value};

/// `new Key()`: no internal keys, at most 36 chars.
fn key() -> Key {
    Key::new(false, 36)
}

/// `KeyTest::test_values()` against `object` (a `Key`, or a `UID`).
fn key_values(object: &dyn Fn(&Value) -> bool) {
    // Must be strings.
    assert!(!object(&Value::Bool(false)));
    assert!(!object(&Value::Null));
    assert!(!object(&val(r#"["value"]"#)));
    assert!(!object(&Value::Int(0)));
    assert!(!object(&Value::Float(1.5)));
    assert!(object(&s("asdas7as9as")));
    assert!(object(&s("5f058a8925807")));

    // No empty string.
    assert!(!object(&s("")));
    assert!(object(&s("0")));
    assert!(object(&s("null")));

    // No leading special chars.
    for v in ["_asdasdasdas", ".as5dasdasdas", "-as5dasdasdas"] {
        assert!(!object(&s(v)), "{v}");
    }

    // Special chars allowed: underscore, period, hyphen.
    for v in ["as5dadasdas_", "as_5dasdasdas", "as5dasdasdas.", "as.5dasdasdas", "as5dasdasdas-", "as-5dasdasdas"] {
        assert!(object(&s(v)), "{v}");
    }

    for v in [
        "dasda asdasd",
        "asd\"asd6sdas",
        "asd'as0asdas",
        "as!5dasdasdas",
        "as@5dasdasdas",
        "as#5dasdasdas",
        "as$5dasdasdas",
        "as%5dasdasdas",
        "as^5dasdasdas",
        "as&5dasdasdas",
        "as*5dasdasdas",
        "as(5dasdasdas",
        "as)5dasdasdas",
        "as+5dasdasdas",
        "as=5dasdasdas",
    ] {
        assert!(!object(&s(v)), "{v}");
    }

    // At most 36 chars.
    assert!(object(&s(&"a".repeat(36))));
    assert!(!object(&s(&"a".repeat(256))));
}

fn internal_keys() {
    let validator = Key::new(true, 36);
    assert!(validator.is_valid(&s("appwrite")));
    assert!(validator.is_valid(&s("appwrite_")));
    assert!(!validator.is_valid(&s("_appwrite")));
    assert!(!validator.is_valid(&s("_")));

    assert!(validator.is_valid(&s("$id")));
    assert!(validator.is_valid(&s("$createdAt")));
    assert!(validator.is_valid(&s("$updatedAt")));

    assert!(!validator.is_valid(&s("$appwrite")));
    assert!(!validator.is_valid(&s("$permissions")));
    assert!(!validator.is_valid(&s("$")));
}

#[test]
fn key_test_values() {
    let object = key();
    key_values(&|v| object.is_valid(v));
    internal_keys();
}

/// `UIDTest` extends `KeyTest` without its own `setUp()`, so PHP runs the
/// same assertions on a `Key`; they hold for a `UID` too.
#[test]
fn uid_test_values() {
    let object = key();
    key_values(&|v| object.is_valid(v));
    internal_keys();

    let uid = Uid::new(36);
    key_values(&|v| uid.is_valid(v));
}

fn label() -> Label {
    Label::new(false, 36)
}

#[test]
fn label_test_values() {
    let object = label();
    // Must be strings.
    assert!(!object.is_valid(&Value::Bool(false)));
    assert!(!object.is_valid(&Value::Null));
    assert!(!object.is_valid(&val(r#"["value"]"#)));
    assert!(!object.is_valid(&Value::Int(0)));
    assert!(!object.is_valid(&Value::Float(1.5)));
    assert!(object.is_valid(&s("asdas7as9as")));
    assert!(object.is_valid(&s("5f058a8925807")));

    // No special chars.
    for v in [
        "_asdasdasdas",
        ".as5dasdasdas",
        "-as5dasdasdas",
        "as5dadasdas_",
        "as_5dasdasdas",
        "as5dasdasdas.",
        "as.5dasdasdas",
        "as5dasdasdas-",
        "as-5dasdasdas",
        "dasda asdasd",
        "asd\"asd6sdas",
        "asd'as0asdas",
        "as!5dasdasdas",
        "as@5dasdasdas",
        "as#5dasdasdas",
        "as$5dasdasdas",
        "as%5dasdasdas",
        "as^5dasdasdas",
        "as&5dasdasdas",
        "as*5dasdasdas",
        "as(5dasdasdas",
        "as)5dasdasdas",
        "as+5dasdasdas",
        "as=5dasdasdas",
    ] {
        assert!(!object.is_valid(&s(v)), "{v}");
    }

    assert!(object.is_valid(&s(&"a".repeat(36))));
    assert!(!object.is_valid(&s(&"a".repeat(256))));
}

#[test]
#[allow(clippy::approx_constant)] // PHP's test value, not π
fn label_non_string_values_rejected() {
    let object = label();
    assert!(!object.is_valid(&Value::Int(42)));
    assert!(!object.is_valid(&Value::Null));
    assert!(!object.is_valid(&val(r#"["abc"]"#)));
    assert!(!object.is_valid(&Value::Bool(true)));
    assert!(!object.is_valid(&Value::Float(3.14)));
    assert!(!object.is_valid(&Value::Object(Array::new())));
}

#[test]
fn object_valid_associative_objects() {
    let validator = ObjectValue;
    assert!(validator.is_valid(&val(r#"{"key": "value"}"#)));
    assert!(validator.is_valid(&val(r#"{"a": {"b": {"c": 123}}}"#)));
    assert!(
        validator
            .is_valid(&val(r#"{"author": "Arnab", "metadata": {"rating": 4.5, "info": {"category": "science"}}}"#))
    );
    assert!(validator.is_valid(&val(r#"{"key1": null, "key2": {"nested": null}}"#)));

    let mut meta = Array::new();
    meta.set("x".into(), Value::Int(1));
    let mut outer = Array::new();
    outer.set("meta".into(), Value::Object(meta));
    assert!(validator.is_valid(&Value::Array(outer)));

    assert!(validator.is_valid(&val(r#"{"a": 1, "2": "b"}"#)));
}

#[test]
fn object_invalid_structures() {
    let validator = ObjectValue;
    assert!(!validator.is_valid(&val(r#"["a", "b", "c"]"#)));
    assert!(!validator.is_valid(&s("not an array")));
    assert!(!validator.is_valid(&val(r#"["value"]"#)));
}

#[test]
fn object_scalar_json_strings_are_not_objects() {
    let validator = ObjectValue;
    for v in ["123", "0", "true", "null", "\"str\"", "\"\"", "[1, 2]"] {
        assert!(!validator.is_valid(&s(v)), "{v}");
    }
}

#[test]
fn object_json_object_strings_are_objects() {
    let validator = ObjectValue;
    assert!(validator.is_valid(&s(r#"{"a": 1}"#)));
    assert!(validator.is_valid(&s("{}")));
    assert!(validator.is_valid(&s("[]")), "an empty JSON array matches the empty-array case");
}

#[test]
fn object_empty_cases() {
    let validator = ObjectValue;
    assert!(validator.is_valid(&Value::Array(Array::new())));
    assert!(validator.is_valid(&Value::Object(Array::new())));
    assert!(!validator.is_valid(&s("sldfjsdlfj")));
}
