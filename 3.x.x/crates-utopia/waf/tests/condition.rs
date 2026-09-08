//! Port of `tests/ConditionTest.php` plus extra error-path coverage.

mod common;

use common::attrs;
use serde_json::{json, Value};
use std::sync::{Arc, Mutex};
use utopia_waf::{exception, Attribute, AttributeTypes, Condition, ConditionError, Ip};

#[test]
fn equality_operators() {
    let equal = Condition::equal("ip", vec![json!("127.0.0.1"), json!("10.0.0.1")]);
    let not_equal = Condition::not_equal("method", "POST");

    assert!(equal.matches(&attrs(json!({"ip": "127.0.0.1"}))));
    assert!(!equal.matches(&attrs(json!({"ip": "1.1.1.1"}))));

    assert!(not_equal.matches(&attrs(json!({"method": "GET"}))));
    assert!(!not_equal.matches(&attrs(json!({"method": "POST"}))));
}

#[test]
fn comparison_operators() {
    let less_than = Condition::less_than("count", 10);
    let less_than_equal = Condition::less_than_equal("count", 10);
    let greater_than = Condition::greater_than("count", 5);
    let greater_than_equal = Condition::greater_than_equal("count", 5);

    assert!(less_than.matches(&attrs(json!({"count": 9}))));
    assert!(!less_than.matches(&attrs(json!({"count": 10}))));

    assert!(less_than_equal.matches(&attrs(json!({"count": 10}))));
    assert!(greater_than.matches(&attrs(json!({"count": 6}))));
    assert!(!greater_than.matches(&attrs(json!({"count": 5}))));
    assert!(greater_than_equal.matches(&attrs(json!({"count": 5}))));
}

#[test]
fn contains_operators() {
    let string_contains = Condition::contains("path", vec![json!("admin"), json!("dashboard")]);
    let array_contains = Condition::contains("tags", vec![json!("security")]);
    let not_contains = Condition::not_contains("path", vec![json!("forbidden")]);

    assert!(string_contains.matches(&attrs(json!({"path": "/admin/users"}))));
    assert!(!string_contains.matches(&attrs(json!({"path": "/public"}))));

    assert!(array_contains.matches(&attrs(json!({"tags": ["security", "waf"]}))));
    assert!(!array_contains.matches(&attrs(json!({"tags": ["network"]}))));

    assert!(not_contains.matches(&attrs(json!({"path": "/allowed"}))));
    assert!(!not_contains.matches(&attrs(json!({"path": "/forbidden"}))));
}

#[test]
fn range_operators() {
    let between = Condition::between("latency", 100, 200);
    let not_between = Condition::not_between("latency", 100, 200);

    assert!(between.matches(&attrs(json!({"latency": 150}))));
    assert!(not_between.matches(&attrs(json!({"latency": 50}))));
    assert!(!not_between.matches(&attrs(json!({"latency": 150}))));
}

#[test]
fn relational_operators_with_null_values() {
    let less_than = Condition::less_than("count", 5);
    let greater_than = Condition::greater_than("count", 5);
    let between = Condition::between("count", 1, 10);

    assert!(!less_than.matches(&attrs(json!({"count": null}))));
    assert!(!less_than.matches(&attrs(json!({}))));

    assert!(!greater_than.matches(&attrs(json!({"count": null}))));
    assert!(!greater_than.matches(&attrs(json!({}))));

    assert!(!between.matches(&attrs(json!({"count": null}))));
    assert!(!between.matches(&attrs(json!({}))));
}

#[test]
fn starts_and_ends_operators() {
    let starts_with = Condition::starts_with("path", "/api");
    let not_starts_with = Condition::not_starts_with("path", "/admin");
    let ends_with = Condition::ends_with("path", ".json");
    let not_ends_with = Condition::not_ends_with("path", ".php");

    assert!(starts_with.matches(&attrs(json!({"path": "/api/v1"}))));
    assert!(!starts_with.matches(&attrs(json!({"path": "/web"}))));

    assert!(not_starts_with.matches(&attrs(json!({"path": "/public"}))));
    assert!(!not_starts_with.matches(&attrs(json!({"path": "/admin"}))));

    assert!(ends_with.matches(&attrs(json!({"path": "/status.json"}))));
    assert!(!ends_with.matches(&attrs(json!({"path": "/status.xml"}))));

    assert!(not_ends_with.matches(&attrs(json!({"path": "/status"}))));
    assert!(!not_ends_with.matches(&attrs(json!({"path": "/index.php"}))));
}

#[test]
fn null_operators_and_attribute_resolution() {
    let is_null = Condition::is_null("payload.signature");
    let is_not_null = Condition::is_not_null("payload.signature");

    let attributes = attrs(json!({
        "payload": { "signature": "abc" }
    }));

    assert!(!is_null.matches(&attributes));
    assert!(is_null.matches(&attrs(json!({"payload": {}}))));
    assert!(is_null.matches(&attrs(json!({"payload": []}))));

    assert!(is_not_null.matches(&attributes));
    assert!(!is_not_null.matches(&attrs(json!({"payload": {}}))));
}

#[test]
fn logical_operators_nested() {
    let nested = Condition::and(vec![
        Condition::equal("method", vec![json!("POST")]),
        Condition::or(vec![
            Condition::equal("path", vec![json!("/admin")]),
            Condition::starts_with("path", "/internal"),
        ]),
        Condition::not_contains("headers.user-agent", vec![json!("bot")]),
    ]);

    assert!(nested.matches(&attrs(json!({
        "method": "POST",
        "path": "/internal/tools",
        "headers": { "user-agent": "Mozilla" }
    }))));

    assert!(!nested.matches(&attrs(json!({
        "method": "POST",
        "path": "/public",
        "headers": { "user-agent": "Mozilla" }
    }))));

    assert!(!nested.matches(&attrs(json!({
        "method": "POST",
        "path": "/internal/ops",
        "headers": { "user-agent": "bot" }
    }))));
}

#[test]
fn condition_serialization_round_trip() {
    let condition = Condition::and(vec![
        Condition::equal("ip", vec![json!("127.0.0.1")]),
        Condition::or(vec![
            Condition::starts_with("path", "/api"),
            Condition::ends_with("path", ".json"),
        ]),
    ]);

    let json = condition.encode().unwrap();
    let parsed = Condition::decode(&json).unwrap();

    assert!(parsed.matches(&attrs(json!({"ip": "127.0.0.1", "path": "/api/users"}))));
    assert!(parsed.matches(&attrs(json!({"ip": "127.0.0.1", "path": "/status.json"}))));
    assert!(!parsed.matches(&attrs(json!({"ip": "127.0.0.1", "path": "/web"}))));
}

#[test]
fn matching_is_case_insensitive() {
    let country = Condition::equal("country", vec![json!("in")]);
    assert!(country.matches(&attrs(json!({"country": "IN"}))));
    assert!(country.matches(&attrs(json!({"country": "In"}))));
    assert!(!country.matches(&attrs(json!({"country": "US"}))));

    let not_country = Condition::not_equal("country", "in");
    assert!(!not_country.matches(&attrs(json!({"country": "IN"}))));
    assert!(not_country.matches(&attrs(json!({"country": "US"}))));

    let string_contains = Condition::contains("userAgent", vec![json!("CURL")]);
    assert!(string_contains.matches(&attrs(json!({"userAgent": "curl/8.4"}))));

    let array_contains = Condition::contains("tags", vec![json!("Security")]);
    assert!(array_contains.matches(&attrs(json!({"tags": ["security", "waf"]}))));

    let numeric_contains = Condition::contains("codes", vec![json!(200)]);
    assert!(numeric_contains.matches(&attrs(json!({"codes": ["200", "404"]}))));

    let starts_with = Condition::starts_with("path", "/API");
    assert!(starts_with.matches(&attrs(json!({"path": "/api/v1"}))));

    let ends_with = Condition::ends_with("path", ".JSON");
    assert!(ends_with.matches(&attrs(json!({"path": "/status.json"}))));

    let numeric = Condition::equal("count", vec![json!(10)]);
    assert!(numeric.matches(&attrs(json!({"count": 10}))));
    assert!(!numeric.matches(&attrs(json!({"count": "10"}))));

    let between = Condition::between("name", "BANANA", "CHERRY");
    assert!(!between.matches(&attrs(json!({"name": "banana"}))));
    assert!(between.matches(&attrs(json!({"name": "CANARY"}))));
}

#[test]
fn invalid_method_throws_exception() {
    let err: exception::Condition = Condition::from_array(&json!({
        "method": "unknown",
        "attribute": "ip",
        "values": []
    }))
    .unwrap_err();
    assert!(matches!(err, ConditionError::UnsupportedMethod(_)));
}

#[test]
fn parse_rejects_invalid_json() {
    let err = Condition::decode("{\"method\":").unwrap_err();
    assert!(matches!(err, ConditionError::InvalidPayload(_)));
}

#[test]
fn equal_with_attribute_type_matches_cidr_blocks() {
    let mut types = AttributeTypes::new();
    types.insert("ip".into(), Arc::new(Ip));

    let equal = Condition::equal("ip", vec![json!("203.0.113.10"), json!("10.0.0.0/8")]);
    assert!(equal.matches_with(&attrs(json!({"ip": "203.0.113.10"})), &types));
    assert!(equal.matches_with(&attrs(json!({"ip": "10.4.20.9"})), &types));
    assert!(!equal.matches_with(&attrs(json!({"ip": "11.0.0.1"})), &types));

    let not_equal = Condition::not_equal("ip", "10.0.0.0/8");
    assert!(!not_equal.matches_with(&attrs(json!({"ip": "10.4.20.9"})), &types));
    assert!(not_equal.matches_with(&attrs(json!({"ip": "11.0.0.1"})), &types));

    let nested = Condition::or(vec![
        Condition::equal("ip", vec![json!("10.0.0.0/8")]),
        Condition::equal("country", vec![json!("US")]),
    ]);
    assert!(nested.matches_with(&attrs(json!({"ip": "10.1.1.1", "country": "IN"})), &types));
    assert!(!nested.matches_with(&attrs(json!({"ip": "11.1.1.1", "country": "IN"})), &types));
}

#[derive(Debug)]
struct ProbeType {
    calls: Mutex<Vec<(String, Value, Value)>>,
}

impl Attribute for ProbeType {
    fn compare(&self, method: &str, value: &Value, expected: &Value) -> Option<bool> {
        self.calls.lock().expect("probe mutex").push((
            method.to_string(),
            value.clone(),
            expected.clone(),
        ));

        let needles: Vec<&Value> = match expected {
            Value::Array(arr) => arr.iter().collect(),
            other => vec![other],
        };

        if needles.iter().any(|n| n.as_str() == Some("MATCH")) {
            return Some(true);
        }
        if needles.iter().any(|n| n.as_str() == Some("BLOCK")) {
            return Some(false);
        }
        None
    }

    fn validate_value(&self, _method: &str, _expected: &Value) -> Option<String> {
        None
    }
}

#[test]
fn attribute_type_is_probed_for_all_operators() {
    let probe = Arc::new(ProbeType {
        calls: Mutex::new(Vec::new()),
    });
    let mut types = AttributeTypes::new();
    let tag_type: Arc<dyn Attribute> = probe.clone();
    types.insert("tag".into(), tag_type);

    assert!(Condition::contains("tag", vec![json!("MATCH")])
        .matches_with(&attrs(json!({"tag": "nothing-alike"})), &types));

    assert!(!Condition::contains("tag", vec![json!("BLOCK")])
        .matches_with(&attrs(json!({"tag": "has BLOCK inside"})), &types));
    assert!(Condition::not_contains("tag", vec![json!("BLOCK")])
        .matches_with(&attrs(json!({"tag": "has BLOCK inside"})), &types));

    assert!(Condition::contains("tag", vec![json!("inside")])
        .matches_with(&attrs(json!({"tag": "has BLOCK inside"})), &types));

    assert!(
        Condition::starts_with("tag", "MATCH").matches_with(&attrs(json!({"tag": "zzz"})), &types)
    );
    assert!(!Condition::not_starts_with("tag", "MATCH")
        .matches_with(&attrs(json!({"tag": "zzz"})), &types));
    assert!(
        Condition::ends_with("tag", "MATCH").matches_with(&attrs(json!({"tag": "zzz"})), &types)
    );

    assert!(
        Condition::less_than("tag", "MATCH").matches_with(&attrs(json!({"tag": "zzz"})), &types)
    );
    assert!(Condition::greater_than_equal("tag", "MATCH")
        .matches_with(&attrs(json!({"tag": "zzz"})), &types));

    probe.calls.lock().expect("probe mutex").clear();
    assert!(Condition::between("tag", "MATCH", "end")
        .matches_with(&attrs(json!({"tag": "zzz"})), &types));
    let calls = probe.calls.lock().expect("probe mutex").clone();
    assert_eq!(
        calls,
        vec![(
            Condition::TYPE_BETWEEN.to_string(),
            json!("zzz"),
            json!(["MATCH", "end"])
        )]
    );
    assert!(!Condition::not_between("tag", "MATCH", "end")
        .matches_with(&attrs(json!({"tag": "zzz"})), &types));

    probe.calls.lock().expect("probe mutex").clear();
    assert!(Condition::is_null("tag").matches_with(&attrs(json!({"tag": null})), &types));
    assert!(Condition::is_not_null("tag").matches_with(&attrs(json!({"tag": "zzz"})), &types));
    assert!(probe.calls.lock().expect("probe mutex").is_empty());
}

#[test]
fn equal_without_types_keeps_plain_string_semantics() {
    let equal = Condition::equal("ip", vec![json!("10.0.0.0/8")]);
    assert!(!equal.matches(&attrs(json!({"ip": "10.4.20.9"}))));

    let mut types = AttributeTypes::new();
    types.insert("ip".into(), Arc::new(Ip));
    let path = Condition::equal("path", vec![json!("/v1/health")]);
    assert!(path.matches_with(&attrs(json!({"path": "/v1/health"})), &types));
}

#[test]
fn decode_rejects_non_array_payload() {
    assert!(matches!(
        Condition::decode("\"hello\"").unwrap_err(),
        ConditionError::ExpectingArray
    ));
    assert!(matches!(
        Condition::decode("123").unwrap_err(),
        ConditionError::ExpectingArray
    ));
}

#[test]
fn from_array_rejects_non_string_fields() {
    assert!(matches!(
        Condition::from_array(&json!({"method": 1, "attribute": "ip", "values": []})).unwrap_err(),
        ConditionError::InvalidMethodDefinition
    ));
    assert!(matches!(
        Condition::from_array(&json!({"method": "equal", "attribute": 1, "values": []}))
            .unwrap_err(),
        ConditionError::InvalidAttributeDefinition
    ));
    assert!(matches!(
        Condition::from_array(&json!({"method": "equal", "attribute": "ip", "values": "x"}))
            .unwrap_err(),
        ConditionError::InvalidValuesDefinition
    ));
}

#[test]
fn logical_nested_must_be_arrays() {
    let err = Condition::from_array(&json!({
        "method": "and",
        "values": ["not-an-array"]
    }))
    .unwrap_err();
    assert!(matches!(err, ConditionError::InvalidNested));
}

#[test]
fn from_arrays_round_trip() {
    let defs = vec![
        Condition::equal("ip", vec![json!("127.0.0.1")]).to_array(),
        Condition::starts_with("path", "/v1").to_array(),
    ];
    let parsed = Condition::from_arrays(&defs).unwrap();
    assert_eq!(parsed.len(), 2);
    assert!(parsed[0].matches(&attrs(json!({"ip": "127.0.0.1"}))));
}

#[test]
fn getters_and_is_method() {
    let c = Condition::equal("ip", vec![json!("10.0.0.1")]);
    assert_eq!(c.get_method(), Condition::TYPE_EQUAL);
    assert_eq!(c.get_attribute(), "ip");
    assert_eq!(c.get_values(), &[json!("10.0.0.1")]);
    assert!(!c.is_logical());
    assert!(Condition::is_method("equal"));
    assert!(!Condition::is_method("nope"));
    assert!(Condition::and(vec![]).is_logical());
}

#[test]
fn empty_and_is_vacuous_true_empty_or_is_false() {
    assert!(Condition::and(vec![]).matches(&attrs(json!({}))));
    assert!(!Condition::or(vec![]).matches(&attrs(json!({}))));
}

#[test]
fn dotted_attribute_walks_arrays_by_index() {
    let c = Condition::equal("items.0", vec![json!("a")]);
    assert!(c.matches(&attrs(json!({"items": ["a", "b"]}))));
    assert!(!c.matches(&attrs(json!({"items": ["b", "a"]}))));
}

#[test]
fn ipv6_cidr_via_types_on_logical_or() {
    let mut types = AttributeTypes::new();
    types.insert("ip".into(), Arc::new(Ip));
    let nested = Condition::and(vec![
        Condition::equal("ip", vec![json!("2001:db8::/32")]),
        Condition::equal("method", vec![json!("GET")]),
    ]);
    assert!(nested.matches_with(
        &attrs(json!({"ip": "2001:db8::1", "method": "GET"})),
        &types
    ));
    assert!(!nested.matches_with(
        &attrs(json!({"ip": "2001:db9::1", "method": "GET"})),
        &types
    ));
}
