//! Ports `tests/Audit/QueryTest.php`.

use serde_json::json;
use utopia_audit::Query;
use utopia_query::value::QueryValue;

fn values_as_json(query: &Query) -> Vec<serde_json::Value> {
    query.get_values().iter().map(QueryValue::to_json).collect()
}

#[test]
fn query_static_factory_methods() {
    let query = Query::equal("userId", "123");
    assert_eq!(query.get_method(), Query::TYPE_EQUAL);
    assert_eq!(query.get_attribute(), "userId");
    assert_eq!(values_as_json(&query), vec![json!("123")]);

    let query = Query::less_than("time", "2024-01-01");
    assert_eq!(query.get_method(), Query::TYPE_LESSER);
    assert_eq!(query.get_attribute(), "time");
    assert_eq!(values_as_json(&query), vec![json!("2024-01-01")]);

    let query = Query::greater_than("time", "2023-01-01");
    assert_eq!(query.get_method(), Query::TYPE_GREATER);
    assert_eq!(query.get_attribute(), "time");
    assert_eq!(values_as_json(&query), vec![json!("2023-01-01")]);

    let query = Query::between("time", "2023-01-01", "2024-01-01");
    assert_eq!(query.get_method(), Query::TYPE_BETWEEN);
    assert_eq!(query.get_attribute(), "time");
    assert_eq!(
        values_as_json(&query),
        vec![json!("2023-01-01"), json!("2024-01-01")]
    );

    let query = Query::contains("event", vec!["create", "update", "delete"]);
    assert_eq!(query.get_method(), Query::TYPE_CONTAINS);
    assert_eq!(query.get_attribute(), "event");
    assert_eq!(
        values_as_json(&query),
        vec![json!("create"), json!("update"), json!("delete")]
    );

    let query = Query::order_desc("time");
    assert_eq!(query.get_method(), Query::TYPE_ORDER_DESC);
    assert_eq!(query.get_attribute(), "time");
    assert!(query.get_values().is_empty());

    let query = Query::order_asc("userId");
    assert_eq!(query.get_method(), Query::TYPE_ORDER_ASC);
    assert_eq!(query.get_attribute(), "userId");
    assert!(query.get_values().is_empty());

    let query = Query::limit(10);
    assert_eq!(query.get_method(), Query::TYPE_LIMIT);
    assert_eq!(query.get_attribute(), "");
    assert_eq!(values_as_json(&query), vec![json!(10)]);

    let query = Query::offset(5);
    assert_eq!(query.get_method(), Query::TYPE_OFFSET);
    assert_eq!(query.get_attribute(), "");
    assert_eq!(values_as_json(&query), vec![json!(5)]);
}

#[test]
fn query_parse_and_to_string() {
    let json = r#"{"method":"equal","attribute":"userId","values":["123"]}"#;
    let query = Query::parse(json).unwrap();
    assert_eq!(query.get_method(), Query::TYPE_EQUAL);
    assert_eq!(query.get_attribute(), "userId");
    assert_eq!(values_as_json(&query), vec![json!("123")]);

    let query = Query::equal("event", "create");
    let encoded = query.to_string().unwrap();
    serde_json::from_str::<serde_json::Value>(&encoded).unwrap();
    let parsed = Query::parse(&encoded).unwrap();
    assert_eq!(parsed.get_method(), Query::TYPE_EQUAL);
    assert_eq!(parsed.get_attribute(), "event");
    assert_eq!(values_as_json(&parsed), vec![json!("create")]);

    let array = query.to_array();
    assert_eq!(array["method"], Query::TYPE_EQUAL);
    assert_eq!(array["attribute"], "event");
    assert_eq!(array["values"], json!(["create"]));
}

#[test]
fn query_parse_queries() {
    let queries = [
        r#"{"method":"equal","attribute":"userId","values":["123"]}"#.to_owned(),
        r#"{"method":"greaterThan","attribute":"time","values":["2023-01-01"]}"#.to_owned(),
        r#"{"method":"limit","values":[10]}"#.to_owned(),
    ];
    let parsed = Query::parse_queries(&queries).unwrap();
    assert_eq!(parsed.len(), 3);
    assert_eq!(parsed[0].get_method(), Query::TYPE_EQUAL);
    assert_eq!(parsed[1].get_method(), Query::TYPE_GREATER);
    assert_eq!(parsed[2].get_method(), Query::TYPE_LIMIT);
}

#[test]
fn get_value() {
    let query = Query::equal("userId", "123");
    assert_eq!(query.get_value(), QueryValue::String("123".into()));

    let query = Query::limit(10);
    assert_eq!(query.get_value(), QueryValue::Int(10));

    let query = Query::order_asc("time");
    assert_eq!(query.get_value(), QueryValue::Null);
    assert_eq!(
        query.get_value_or("default"),
        QueryValue::String("default".into())
    );
}

#[test]
fn query_with_empty_attribute() {
    let query = Query::limit(25);
    assert_eq!(query.get_attribute(), "");
    assert_eq!(values_as_json(&query), vec![json!(25)]);

    let query = Query::offset(10);
    assert_eq!(query.get_attribute(), "");
    assert_eq!(values_as_json(&query), vec![json!(10)]);
}

#[test]
fn query_parse_invalid_json() {
    let err = Query::parse(r#"{"method":"equal","attribute":"userId""#).unwrap_err();
    assert!(err.to_string().contains("Invalid query"));
}

#[test]
fn query_parse_non_array() {
    let err = Query::parse(r#""string""#).unwrap_err();
    assert!(err.to_string().contains("Invalid query. Must be an array"));
}

#[test]
fn query_parse_invalid_method_type() {
    let err = Query::parse(r#"{"method":["array"],"attribute":"test","values":[]}"#).unwrap_err();
    assert!(err
        .to_string()
        .contains("Invalid query method. Must be a string"));
}

#[test]
fn query_parse_invalid_attribute_type() {
    let err = Query::parse(r#"{"method":"equal","attribute":123,"values":[]}"#).unwrap_err();
    assert!(err
        .to_string()
        .contains("Invalid query attribute. Must be a string"));
}

#[test]
fn query_parse_invalid_values_type() {
    let err =
        Query::parse(r#"{"method":"equal","attribute":"test","values":"string"}"#).unwrap_err();
    assert!(err
        .to_string()
        .contains("Invalid query values. Must be an array"));
}

#[test]
fn query_to_string_with_complex_values() {
    let query = Query::between("time", "2023-01-01", "2024-12-31");
    let encoded = query.to_string().unwrap();
    let parsed = Query::parse(&encoded).unwrap();
    assert_eq!(parsed.get_method(), Query::TYPE_BETWEEN);
    assert_eq!(parsed.get_attribute(), "time");
    assert_eq!(
        values_as_json(&parsed),
        vec![json!("2023-01-01"), json!("2024-12-31")]
    );
}
