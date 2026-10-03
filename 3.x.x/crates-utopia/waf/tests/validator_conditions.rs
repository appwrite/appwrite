//! Port of `tests/Validator/ConditionsTest.php`.

use serde_json::json;
use std::sync::Arc;
use utopia_validators::Validator;
use utopia_waf::validator::Conditions;
use utopia_waf::{Condition, Ip};

#[test]
fn returns_array_type() {
    let validator = Conditions::new();
    assert!(validator.is_array());
    assert_eq!(validator.value_type(), Conditions::TYPE_ARRAY);
}

#[test]
fn rejects_empty_condition_list() {
    let validator = Conditions::new();
    assert!(!validator.is_valid(&json!([])));
}

#[test]
fn accepts_condition_arrays() {
    let validator = Conditions::new();
    assert!(validator.is_valid(&json!([
        Condition::equal("ip", vec![json!("198.51.100.5")]).to_array(),
        Condition::starts_with("path", "/v1").to_array(),
    ])));
}

#[test]
fn accepts_encoded_condition_strings() {
    let validator = Conditions::new();
    assert!(
        validator.is_valid(&json!([Condition::starts_with("path", "/v1")
            .encode()
            .unwrap()]))
    );
}

#[test]
fn rejects_invalid_condition_strings() {
    let validator = Conditions::new();
    assert!(!validator.is_valid(&json!(["{\"method\":"])));
}

#[test]
fn rejects_invalid_condition_array() {
    let validator = Conditions::new();
    assert!(!validator.is_valid(&json!([{
        "method": "unknown",
        "attribute": "ip",
        "values": ["1.2.3.4"],
    }])));
}

#[test]
fn rejects_mixed_condition_types() {
    let validator = Conditions::new();
    assert!(!validator.is_valid(&json!([
        Condition::starts_with("path", "/v1").to_array(),
        null,
    ])));
    assert!(!validator.is_valid(&json!([123])));
}

#[test]
fn rejects_too_many_conditions() {
    let validator = Conditions::new().max_conditions(1);
    assert!(!validator.is_valid(&json!([
        Condition::equal("ip", vec![json!("198.51.100.5")]).to_array(),
        Condition::starts_with("path", "/v1").to_array(),
    ])));
}

#[test]
fn rejects_too_many_nested_conditions() {
    let validator = Conditions::new().max_conditions(2);
    assert!(!validator.is_valid(&json!([Condition::and(vec![
        Condition::equal("ip", vec![json!("198.51.100.5")]),
        Condition::starts_with("path", "/v1"),
    ])
    .to_array()])));
}

#[test]
fn rejects_too_many_nested_encoded_conditions() {
    let validator = Conditions::new().max_conditions(2);
    assert!(!validator.is_valid(&json!([Condition::and(vec![
        Condition::equal("ip", vec![json!("198.51.100.5")]),
        Condition::starts_with("path", "/v1"),
    ])
    .encode()
    .unwrap()])));
}

#[test]
fn rejects_long_condition_arrays() {
    let validator = Conditions::new().max_payload_length(64);
    let long = "1".repeat(128);
    assert!(!validator.is_valid(&json!([
        Condition::equal("ip", vec![json!(long)]).to_array()
    ])));
}

#[test]
fn rejects_long_condition_strings() {
    let validator = Conditions::new().max_payload_length(64);
    let long = "1".repeat(128);
    assert!(
        !validator.is_valid(&json!([Condition::equal("ip", vec![json!(long)])
            .encode()
            .unwrap()]))
    );
}

#[test]
fn rejects_empty_logical_conditions() {
    let validator = Conditions::new();
    assert!(!validator.is_valid(&json!([{
        "method": Condition::TYPE_AND,
        "values": [],
    }])));
}

#[test]
fn typed_value_validation() {
    let mut types = utopia_waf::AttributeTypes::new();
    types.insert("ip".into(), Arc::new(Ip));
    let validator = Conditions::new().attribute_types(types);

    assert!(validator.is_valid(&json!([Condition::equal(
        "ip",
        vec![json!("203.0.113.10"), json!("10.0.0.0/8")]
    )
    .to_array()])));

    assert!(!validator.is_valid(&json!([
        Condition::equal("ip", vec![json!("10.0.0.0/33")]).to_array()
    ])));
    assert!(!validator.is_valid(&json!([
        Condition::equal("ip", vec![json!("not-an-ip")]).to_array()
    ])));

    assert!(!validator.is_valid(&json!([Condition::equal(
        "requestIp",
        vec![json!("not-an-ip")]
    )
    .to_array()])));

    let aliased = Conditions::new().attribute_type("requestIp", Ip);
    assert!(!aliased.is_valid(&json!([
        Condition::equal("ip", vec![json!("not-an-ip")]).to_array()
    ])));
    assert!(!aliased.is_valid(&json!([
        Condition::equal("IP", vec![json!("10.0.0.0/33")]).to_array()
    ])));
    assert!(aliased.is_valid(&json!([
        Condition::equal("ip", vec![json!("10.0.0.0/8")]).to_array()
    ])));

    assert!(!validator.is_valid(&json!([{
        "method": Condition::TYPE_OR,
        "values": [
            Condition::equal("ip", vec![json!("bogus")]).to_array(),
            Condition::equal("country", vec![json!("US")]).to_array(),
        ],
    }])));

    assert!(validator.is_valid(&json!([Condition::equal(
        "path",
        vec![json!("/v1/health")]
    )
    .to_array()])));

    assert!(Conditions::new().is_valid(&json!([
        Condition::equal("ip", vec![json!("not-an-ip")]).to_array()
    ])));
}

#[test]
fn allowed_attributes_validation() {
    let validator = Conditions::new().allowed_attributes(["ip", "method", "headers.", "query."]);

    assert!(validator.is_valid(&json!([
        Condition::equal("ip", vec![json!("203.0.113.10")]).to_array(),
        Condition::equal("method", vec![json!("POST")]).to_array(),
        Condition::contains("headers.x-canary", vec![json!("1")]).to_array(),
        Condition::equal("query.debug", vec![json!("true")]).to_array(),
    ])));

    assert!(validator.is_valid(&json!([
        Condition::equal("requestIp", vec![json!("203.0.113.10")]).to_array(),
        Condition::equal("METHOD", vec![json!("POST")]).to_array(),
    ])));

    assert!(!validator.is_valid(&json!([
        Condition::equal("userId", vec![json!("abc")]).to_array()
    ])));

    assert!(!validator.is_valid(&json!([
        Condition::equal("headers.", vec![json!("x")]).to_array()
    ])));

    assert!(!validator.is_valid(&json!([{
        "method": Condition::TYPE_OR,
        "values": [
            Condition::equal("ip", vec![json!("203.0.113.10")]).to_array(),
            Condition::equal("bogus", vec![json!("x")]).to_array(),
        ],
    }])));

    assert!(!validator.is_valid(&json!([{
        "method": Condition::TYPE_EQUAL,
        "values": ["x"],
    }])));

    assert!(Conditions::new().is_valid(&json!([Condition::equal(
        "anything-at-all",
        vec![json!("x")]
    )
    .to_array()])));

    let aliased = Conditions::new().allowed_attributes(["requestIp"]);
    assert!(aliased.is_valid(&json!([Condition::equal(
        "ip",
        vec![json!("203.0.113.10")]
    )
    .to_array()])));
}

#[test]
fn description_and_non_array_rejected() {
    let validator = Conditions::new();
    assert_eq!(
        validator.description(),
        "Array of at least one WAF condition definition."
    );
    assert!(!validator.is_valid(&json!("not-an-array")));
    assert!(!validator.is_valid(&json!(null)));
}
