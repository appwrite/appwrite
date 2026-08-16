//! Ports `tests/ValueTest.php`.

use serde_json::json;
use utopia_openapi::json::JsonNumber;
use utopia_openapi::{InvalidSpecification, Json, OpenApiError, Value};

fn err_msg(err: OpenApiError) -> String {
    err.to_string()
}

#[test]
fn empty_arrays_read_as_both_object_and_list() {
    let empty_arr = Json::Array(vec![]);
    let empty_obj = Json::Object(indexmap::IndexMap::new());
    assert!(Value::object(&empty_arr, "#/x").unwrap().is_empty());
    assert!(Value::list(&empty_obj, "#/x").unwrap().is_empty());
}

#[test]
fn object_rejects_lists_and_scalars() {
    let mut map = indexmap::IndexMap::new();
    map.insert("a".into(), Json::Number(JsonNumber::Int(1)));
    let obj = Json::Object(map);
    assert_eq!(
        Value::object(&obj, "#/x").unwrap().get("a"),
        Some(&Json::Number(JsonNumber::Int(1)))
    );

    for not_an_object in [
        Json::Array(vec![
            Json::Number(JsonNumber::Int(1)),
            Json::Number(JsonNumber::Int(2)),
        ]),
        Json::String("text".into()),
        Json::Number(JsonNumber::Int(7)),
        Json::Bool(true),
        Json::Null,
    ] {
        let err = Value::object(&not_an_object, "#/paths").unwrap_err();
        assert_eq!(err_msg(err), "Expected an object at #/paths");
    }
}

#[test]
fn object_accepts_std_class() {
    let mut map = indexmap::IndexMap::new();
    map.insert("a".into(), Json::Number(JsonNumber::Int(1)));
    let value = Json::Object(map);
    assert_eq!(
        Value::object(&value, "#/x").unwrap().get("a"),
        Some(&Json::Number(JsonNumber::Int(1)))
    );
}

#[test]
fn list_rejects_maps_and_scalars() {
    let list = Json::Array(vec![
        Json::Number(JsonNumber::Int(1)),
        Json::Number(JsonNumber::Int(2)),
    ]);
    assert_eq!(Value::list(&list, "#/x").unwrap().len(), 2);

    let mut map = indexmap::IndexMap::new();
    map.insert("a".into(), Json::Number(JsonNumber::Int(1)));
    for not_a_list in [
        Json::Object(map),
        Json::String("text".into()),
        Json::Number(JsonNumber::Int(7)),
        Json::Null,
    ] {
        let err = Value::list(&not_a_list, "#/tags").unwrap_err();
        assert_eq!(err_msg(err), "Expected a list at #/tags");
    }
}

#[test]
fn required_string_names_the_missing_key() {
    let mut data = indexmap::IndexMap::new();
    data.insert("title".into(), Json::String("Pets".into()));
    assert_eq!(
        Value::required_string(&data, "title", "#/info").unwrap(),
        "Pets"
    );

    let empty = indexmap::IndexMap::new();
    let err = Value::required_string(&empty, "title", "#/info").unwrap_err();
    assert_eq!(err.to_string(), "Expected string #/info/title");
    assert!(matches!(
        err,
        OpenApiError::Invalid(InvalidSpecification(_))
    ));
}

#[test]
fn required_string_rejects_non_strings() {
    let mut data = indexmap::IndexMap::new();
    data.insert("title".into(), Json::Number(JsonNumber::Int(7)));
    let err = Value::required_string(&data, "title", "#/info").unwrap_err();
    assert!(matches!(err, OpenApiError::Invalid(_)));
}

#[test]
fn optional_string_treats_missing_and_null_alike() {
    let mut data = indexmap::IndexMap::new();
    data.insert("title".into(), Json::String("Pets".into()));
    assert_eq!(
        Value::optional_string(&data, "title").unwrap().as_deref(),
        Some("Pets")
    );
    assert_eq!(
        Value::optional_string(&indexmap::IndexMap::new(), "title").unwrap(),
        None
    );
    let mut nulls = indexmap::IndexMap::new();
    nulls.insert("title".into(), Json::Null);
    assert_eq!(Value::optional_string(&nulls, "title").unwrap(), None);
}

#[test]
fn optional_string_still_rejects_wrong_types() {
    let mut data = indexmap::IndexMap::new();
    data.insert("title".into(), Json::Number(JsonNumber::Int(7)));
    let err = Value::optional_string(&data, "title").unwrap_err();
    assert!(matches!(err, OpenApiError::Invalid(_)));
}

#[test]
fn nullable_int_accepts_only_integers() {
    assert_eq!(Value::nullable_int(&Json::Null, "#/x").unwrap(), None);
    assert_eq!(
        Value::nullable_int(&Json::Number(JsonNumber::Int(3)), "#/x").unwrap(),
        Some(3)
    );
    let err =
        Value::nullable_int(&Json::Number(JsonNumber::Float(3.5)), "#/x/minLength").unwrap_err();
    assert_eq!(err_msg(err), "Expected integer at #/x/minLength");
}

#[test]
fn nullable_number_accepts_integers_and_floats() {
    assert!(Value::nullable_number(&Json::Null, "#/x")
        .unwrap()
        .is_none());
    assert_eq!(
        Value::nullable_number(&Json::Number(JsonNumber::Int(3)), "#/x")
            .unwrap()
            .unwrap()
            .as_i64(),
        Some(3)
    );
    match Value::nullable_number(&Json::Number(JsonNumber::Float(3.5)), "#/x")
        .unwrap()
        .unwrap()
    {
        utopia_openapi::JsonNumberOrInt::Float(f) => assert!((f - 3.5).abs() < f64::EPSILON),
        utopia_openapi::JsonNumberOrInt::Int(v) => panic!("expected float, got Int({v})"),
    }
    let err = Value::nullable_number(&Json::String("3".into()), "#/x/minimum").unwrap_err();
    assert_eq!(err_msg(err), "Expected number at #/x/minimum");
}

#[test]
fn extensions_keep_only_prefixed_keys_and_are_case_insensitive() {
    let value = Json::from_serde(json!({
        "title": "Pets",
        "x-owner": "team",
        "X-Trace": true,
        "xenon": 1
    }));
    let Json::Object(map) = value else {
        panic!("object");
    };
    let ext = Value::extensions(&map);
    assert_eq!(ext.get("x-owner"), Some(&Json::String("team".into())));
    assert_eq!(ext.get("X-Trace"), Some(&Json::Bool(true)));
    assert!(!ext.contains_key("title"));
    assert!(!ext.contains_key("xenon"));
}
