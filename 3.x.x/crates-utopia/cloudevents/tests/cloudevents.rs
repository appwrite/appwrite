//! PHP `Utopia\CloudEvents\CloudEvent` tests.

use std::collections::BTreeMap;

use serde_json::{json, Map, Value};
use utopia_cloudevents::{CloudEvent, CloudEventError, ExtensionValue};

fn obj(pairs: &[(&str, Value)]) -> Map<String, Value> {
    pairs
        .iter()
        .map(|(k, v)| ((*k).to_string(), v.clone()))
        .collect()
}

#[test]
fn constructor() {
    let event = CloudEvent::new(
        "test.event",
        "test-service",
        "test-id-123",
        "1.0",
        Some("test-subject".into()),
        Some("2025-11-07T10:00:00Z".into()),
        Some("application/json".into()),
        json!({"key": "value"}),
        None,
        BTreeMap::default(),
    )
    .unwrap();
    assert_eq!(event.specversion, "1.0");
    assert_eq!(event.r#type, "test.event");
    assert_eq!(event.source, "test-service");
    assert_eq!(event.subject.as_deref(), Some("test-subject"));
    assert_eq!(event.id, "test-id-123");
    assert_eq!(event.time.as_deref(), Some("2025-11-07T10:00:00Z"));
    assert_eq!(event.datacontenttype.as_deref(), Some("application/json"));
    assert_eq!(event.data, json!({"key": "value"}));
}

#[test]
fn constructor_with_defaults() {
    let event = CloudEvent::create("test.event", "test-service", "test-id");
    assert_eq!(event.specversion, "1.0");
    assert!(event.subject.is_none());
    assert!(event.time.is_none());
    assert_eq!(event.datacontenttype.as_deref(), Some("application/json"));
    assert!(event.data.is_null());
}

#[test]
fn datacontenttype_allows_explicit_null() {
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.datacontenttype = None;
    assert!(event.datacontenttype.is_none());
    assert!(!event.to_array().contains_key("datacontenttype"));
}

#[test]
fn from_array() {
    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("user.created")),
        ("source", json!("user-service")),
        ("subject", json!("user-123")),
        ("id", json!("event-456")),
        ("time", json!("2025-11-07T10:00:00Z")),
        ("datacontenttype", json!("application/json")),
        (
            "data",
            json!({"userId": "123", "email": "test@example.com"}),
        ),
    ]))
    .unwrap();
    assert_eq!(event.r#type, "user.created");
    assert_eq!(event.data["userId"], "123");
}

#[test]
fn from_array_with_missing_optional_fields() {
    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
    ]))
    .unwrap();
    assert!(event.subject.is_none());
    assert!(event.time.is_none());
    assert!(event.datacontenttype.is_none());
    assert!(event.data.is_null());
}

#[test]
fn from_array_missing_specversion() {
    let err = CloudEvent::from_array(&obj(&[
        ("type", json!("test.event")),
        ("source", json!("test-service")),
    ]))
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Missing required field: specversion"));
}

#[test]
fn from_array_invalid_specversion() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("2.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
    ]))
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Unsupported CloudEvents spec version: 2.0"));
}

#[test]
fn from_array_missing_source() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("id", json!("test-id")),
    ]))
    .unwrap_err();
    assert!(err.to_string().contains("Missing required field: source"));
}

#[test]
fn from_array_missing_id() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
    ]))
    .unwrap_err();
    assert!(err.to_string().contains("Missing required field: id"));
}

#[test]
fn from_array_empty_source() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("")),
        ("id", json!("test-id")),
    ]))
    .unwrap_err();
    assert!(err.to_string().contains("Missing required field: source"));
}

#[test]
fn from_array_accepts_zero_string_type() {
    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("0")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
    ]))
    .unwrap();
    assert_eq!(event.r#type, "0");
}

#[test]
fn from_array_missing_type() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("source", json!("test-service")),
    ]))
    .unwrap_err();
    assert!(err.to_string().contains("Missing required field: type"));
}

#[test]
fn from_array_empty_type() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
    ]))
    .unwrap_err();
    assert!(err.to_string().contains("Missing required field: type"));
}

#[test]
fn to_array_omits_absent_optional_attributes() {
    let event = CloudEvent::create("test.event", "test-service", "test-id");
    let array = event.to_array();
    assert_eq!(array["specversion"], "1.0");
    assert_eq!(array["type"], "test.event");
    assert!(!array.contains_key("subject"));
    assert!(!array.contains_key("time"));
    assert!(!array.contains_key("data"));
    assert_eq!(array["datacontenttype"], "application/json");
}

#[test]
fn data_accepts_any_type() {
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.datacontenttype = Some("text/plain".into());
    event.data = json!("plain text payload");
    assert_eq!(event.data, json!("plain text payload"));
    assert_eq!(event.to_array()["data"], "plain text payload");

    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
        ("data", json!(42)),
    ]))
    .unwrap();
    assert_eq!(event.data, json!(42));
}

#[test]
fn dataschema() {
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.dataschema = Some("https://example.com/schemas/user.json".into());
    assert!(event.validate().unwrap());
    let restored = CloudEvent::from_array(&event.to_array()).unwrap();
    assert_eq!(event.dataschema, restored.dataschema);
}

#[test]
fn validate_empty_dataschema() {
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.dataschema = Some(String::new());
    let err = event.validate().unwrap_err();
    assert!(err
        .to_string()
        .contains("Event dataschema must not be empty when present"));
}

#[test]
fn validate_rejects_blank_datacontenttype() {
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.datacontenttype = Some("   ".into());
    let err = event.validate().unwrap_err();
    assert!(err
        .to_string()
        .contains("Event datacontenttype must not be empty when present"));
}

#[test]
fn from_array_does_not_fabricate_datacontenttype() {
    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
    ]))
    .unwrap();
    assert!(event.datacontenttype.is_none());
    assert!(!event.to_array().contains_key("datacontenttype"));
}

#[test]
fn validate_ok() {
    let event = CloudEvent::new(
        "test.event",
        "test-service",
        "test-id",
        "1.0",
        None,
        Some("2025-11-07T10:00:00Z".into()),
        Some("application/json".into()),
        Value::Null,
        None,
        BTreeMap::default(),
    )
    .unwrap();
    assert!(event.validate().unwrap());
}

#[test]
fn validate_invalid_specversion() {
    let event = CloudEvent::new(
        "test.event",
        "test-service",
        "test-id",
        "2.0",
        None,
        Some("2025-11-07T10:00:00Z".into()),
        None,
        Value::Null,
        None,
        BTreeMap::default(),
    )
    .unwrap();
    let err = event.validate().unwrap_err();
    assert!(err
        .to_string()
        .contains("Unsupported CloudEvents spec version: 2.0"));
}

#[test]
fn validate_empty_fields() {
    let mut event = CloudEvent::create("", "test-service", "test-id");
    event.r#type.clear();
    assert!(event
        .validate()
        .unwrap_err()
        .to_string()
        .contains("Event type is required"));

    let mut event = CloudEvent::create("test.event", "", "test-id");
    event.source.clear();
    assert!(event
        .validate()
        .unwrap_err()
        .to_string()
        .contains("Event source is required"));

    let mut event = CloudEvent::create("test.event", "test-service", "");
    event.id.clear();
    assert!(event
        .validate()
        .unwrap_err()
        .to_string()
        .contains("Event id is required"));

    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.subject = Some(String::new());
    assert!(event
        .validate()
        .unwrap_err()
        .to_string()
        .contains("Event subject must not be empty when present"));
}

#[test]
fn now_format() {
    let time = CloudEvent::now();
    assert_eq!(time.len(), 24, "{time}");
    assert!(time.ends_with('Z'), "{time}");
    assert_eq!(&time[4..5], "-");
    assert_eq!(&time[10..11], "T");
    assert_eq!(&time[19..20], ".");
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event.time = Some(time);
    assert!(event.validate().unwrap());
}

#[test]
fn extensions() {
    let mut extensions = BTreeMap::new();
    extensions.insert(
        "traceparent".into(),
        ExtensionValue::from("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01"),
    );
    extensions.insert("sequence".into(), ExtensionValue::from(42_i64));
    extensions.insert("sampled".into(), ExtensionValue::from(true));
    let event = CloudEvent::new(
        "test.event",
        "test-service",
        "test-id",
        "1.0",
        None,
        None,
        Some("application/json".into()),
        Value::Null,
        None,
        extensions,
    )
    .unwrap();
    assert_eq!(
        event.extensions["traceparent"],
        ExtensionValue::from("00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01")
    );
    assert!(event.validate().unwrap());
}

#[test]
fn to_array_includes_extensions() {
    let mut event = CloudEvent::create("test.event", "test-service", "test-id");
    event
        .extensions
        .insert("partitionkey".into(), ExtensionValue::from("shard-1"));
    assert_eq!(event.to_array()["partitionkey"], "shard-1");
}

#[test]
fn from_array_collects_extensions() {
    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
        ("traceparent", json!("00-abc-def-01")),
        ("sequence", json!(7)),
    ]))
    .unwrap();
    assert_eq!(
        event.extensions["traceparent"],
        ExtensionValue::from("00-abc-def-01")
    );
    assert_eq!(event.extensions["sequence"], ExtensionValue::from(7_i64));
}

#[test]
fn from_array_rejects_invalid_extension_name() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
        ("Trace_Parent", json!("value")),
    ]))
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Extension attribute name must contain only lowercase letters and digits"));
}

#[test]
fn from_array_rejects_invalid_extension_value() {
    let err = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
        ("myext", json!({"nested": "array"})),
    ]))
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Extension attribute \"myext\" must be a boolean, integer or string"));
}

#[test]
fn from_array_drops_null_extensions() {
    let event = CloudEvent::from_array(&obj(&[
        ("specversion", json!("1.0")),
        ("type", json!("test.event")),
        ("source", json!("test-service")),
        ("id", json!("test-id")),
        ("traceparent", Value::Null),
    ]))
    .unwrap();
    assert!(event.extensions.is_empty());
    assert!(!event.to_array().contains_key("traceparent"));
}

#[test]
fn constructor_rejects_invalid_extension_name() {
    let mut extensions = BTreeMap::new();
    extensions.insert("Trace_Parent".into(), ExtensionValue::from("value"));
    let err = CloudEvent::new(
        "test.event",
        "test-service",
        "test-id",
        "1.0",
        None,
        None,
        None,
        Value::Null,
        None,
        extensions,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Extension attribute name must contain only lowercase letters and digits"));
}

#[test]
fn constructor_rejects_reserved_extension_name() {
    let mut extensions = BTreeMap::new();
    extensions.insert("data".into(), ExtensionValue::from("value"));
    let err = CloudEvent::new(
        "test.event",
        "test-service",
        "test-id",
        "1.0",
        None,
        None,
        None,
        Value::Null,
        None,
        extensions,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("Extension attribute name conflicts with a core attribute: data"));
}

#[test]
fn json_round_trip() {
    let mut extensions = BTreeMap::new();
    extensions.insert("traceparent".into(), ExtensionValue::from("00-abc-def-01"));
    let original = CloudEvent::new(
        "payment.processed",
        "https://example.com/payments",
        "event-123",
        "1.0",
        Some("payment-xyz".into()),
        Some("2025-11-07T10:00:00Z".into()),
        Some("application/json".into()),
        json!({"paymentId": "xyz"}),
        Some("https://example.com/schemas/payment.json".into()),
        extensions,
    )
    .unwrap();
    let restored = CloudEvent::from_json(&original.to_json().unwrap()).unwrap();
    assert_eq!(original.r#type, restored.r#type);
    assert_eq!(original.extensions, restored.extensions);
    assert_eq!(original.to_json().unwrap(), restored.to_json().unwrap());
}

#[test]
fn from_json_rejects_array_root() {
    let err = CloudEvent::from_json(r#"[{"specversion":"1.0","type":"t","source":"s","id":"i"}]"#)
        .unwrap_err();
    assert!(err
        .to_string()
        .contains("CloudEvent JSON must decode to an object"));
}

#[test]
fn from_json_invalid_json() {
    let err = CloudEvent::from_json("{not json").unwrap_err();
    assert!(err.to_string().contains("Invalid CloudEvent JSON"));
}

#[test]
fn from_json_non_object() {
    let err = CloudEvent::from_json("\"just a string\"").unwrap_err();
    assert!(err
        .to_string()
        .contains("CloudEvent JSON must decode to an object"));
}

#[test]
fn json_binary_data_round_trip() {
    let binary = b"\x89PNG\r\n\x1a\n\x00\x01\x02\x80\xff".to_vec();
    let event =
        CloudEvent::create("image.uploaded", "storage", "event-1").with_binary_data(binary.clone());
    let decoded: Value = serde_json::from_str(&event.to_json().unwrap()).unwrap();
    assert!(decoded.get("data").is_none());
    assert!(decoded.get("data_base64").and_then(Value::as_str).is_some());
    let restored = CloudEvent::from_json(&event.to_json().unwrap()).unwrap();
    assert_eq!(restored.data_binary.as_deref(), Some(binary.as_slice()));
}

#[test]
fn from_json_rejects_data_and_data_base64() {
    let err = CloudEvent::from_json(
        r#"{"specversion":"1.0","type":"t","source":"s","id":"i","data":"x","data_base64":"eA=="}"#,
    )
    .unwrap_err();
    assert!(err
        .to_string()
        .contains("CloudEvent must not contain both data and data_base64"));
}

#[test]
fn from_json_rejects_invalid_base64() {
    let err = CloudEvent::from_json(
        r#"{"specversion":"1.0","type":"t","source":"s","id":"i","data_base64":"!!!not-base64!!!"}"#,
    )
    .unwrap_err();
    assert!(err.to_string().contains("data_base64 must be valid Base64"));
}

#[test]
fn from_json_preserves_json_data_types() {
    let json =
        r#"{"specversion":"1.0","type":"t","source":"s","id":"i","data":{"empty":{},"list":[]}}"#;
    let restored = CloudEvent::from_json(json).unwrap().to_json().unwrap();
    assert!(restored.contains(r#""empty":{}"#));
    assert!(restored.contains(r#""list":[]"#));
}

#[test]
fn _error_is_invalid_argument() {
    let _err: CloudEventError = CloudEvent::from_json("[]").unwrap_err();
}
