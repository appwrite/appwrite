use super::*;

fn values(json: &str) -> Array {
    let decoded = php_std::json::decode(json.as_bytes(), Some(true), 512, php_std::json::Flags::NONE).unwrap();
    match crate::value::from_plain(decoded) {
        Value::Array(a) => a,
        _ => unreachable!(),
    }
}

#[test]
fn round_trips_through_json() {
    let q = Query::equal("title", ["Iron Man"]);
    let json = q.to_string().unwrap();
    assert_eq!(json, r#"{"method":"equal","attribute":"title","values":["Iron Man"]}"#);
    assert_eq!(Query::parse(&json).unwrap(), q);
}

#[test]
fn parse_errors_match_php() {
    let err = |s: &str| Query::parse(s).unwrap_err().message().to_owned();
    assert_eq!(err("false"), "Invalid query. Must be an array, got boolean");
    assert_eq!(err("{"), "Invalid query: Syntax error");
    assert_eq!(err(r#"{"method":["equal"]}"#), "Invalid query method. Must be a string, got array");
    assert_eq!(err(r#"{"method":"regex"}"#), "Invalid query method: regex");
    assert_eq!(
        err(r#"{"method":"or","values":[123]}"#),
        "Invalid nested query. Must be an array or string, got integer"
    );
}

#[test]
fn keyed_values_keep_their_keys() {
    let q = Query::parse(r#"{"method":"equal","attribute":"a","values":{"x":1,"0":2}}"#).unwrap();
    assert_eq!(q.values(), &[Value::Int(1), Value::Int(2)]);
    assert_eq!(q.value(), Some(&Value::Int(2)));
    assert_eq!(q.values_array(), values(r#"{"x":1,"0":2}"#));
    assert_eq!(q.to_string().unwrap(), r#"{"method":"equal","attribute":"a","values":[1,2]}"#);
    let or = Query::parse(r#"{"method":"or","values":{"k":{"method":"isNull","attribute":"b"}}}"#).unwrap();
    assert_eq!(
        or.to_string().unwrap(),
        r#"{"method":"or","values":{"k":{"method":"isNull","attribute":"b","values":[]}}}"#
    );
}

#[test]
fn logical_values_that_are_not_queries_fail_like_php() {
    let q = Query::new(Method::And, "", vec![Value::Int(1)]);
    let e = q.to_array().unwrap_err();
    assert_eq!(e.php_class(), "Error");
    assert_eq!(e.message(), "Call to a member function toArray() on int");
    let q = Query::new(Method::Or, "", vec![Value::Bool(false)]);
    assert_eq!(q.to_array().unwrap_err().message(), "Call to a member function toArray() on false");
}

#[test]
fn shapes_and_fingerprints() {
    let deep = Query::and(vec![
        Query::or(vec![Query::equal("a", ["x"]), Query::and(vec![Query::equal("b", ["y"]), Query::less_than("c", 5)])]),
        Query::greater_than("d", 10),
    ]);
    assert_eq!(deep.shape(), "and:(greaterThan:d|or:(and:(equal:b|lessThan:c)|equal:a))");
    assert_eq!(Query::fingerprint(&[]).unwrap(), "d41d8cd98f00b204e9800998ecf8427e");
    let a = Query::fingerprint(&[
        Value::from(Query::equal("name", ["Alice"])),
        Value::from(r#"{"method":"greaterThan","attribute":"age","values":[1]}"#),
    ])
    .unwrap();
    let b =
        Query::fingerprint(&[Value::from(Query::greater_than("age", 42)), Value::from(Query::equal("name", ["Bob"]))])
            .unwrap();
    assert_eq!(a, b);
    assert!(Query::fingerprint(&[Value::Int(42)]).is_err());
}

#[test]
fn group_by_type_keeps_php_quirks() {
    let doc = crate::Document::from_pairs([("$id", Value::from("d"))]).unwrap();
    let g = Query::group_by_type(&[
        Query::new(Method::Offset, "", vec![]),
        Query::limit(5),
        Query::limit(9),
        Query::order_asc(""),
        Query::order_random(),
        Query::new(Method::CursorBefore, "", vec![]),
        Query::cursor_after(doc),
        Query::select(["a"]),
        Query::equal("x", [1i64]),
    ]);
    assert_eq!(g.limit, Some(Value::Int(5)));
    assert_eq!(g.offset, None);
    assert_eq!(g.order_attributes, vec!["$sequence".to_owned()]);
    assert_eq!(g.order_types, vec!["ASC", "RANDOM"]);
    assert_eq!(g.cursor, Some(Value::Int(5)));
    assert_eq!(g.cursor_direction, Some("before"));
    assert_eq!(g.selections.len(), 1);
    assert_eq!(g.filters.len(), 1);
}

#[test]
fn cursor_documents_serialize_as_ids() {
    let doc = crate::Document::from_pairs([("$id", Value::from("abc"))]).unwrap();
    assert_eq!(Query::cursor_after(doc.clone()).to_string().unwrap(), r#"{"method":"cursorAfter","values":["abc"]}"#);
    // Other methods encode a document as an object of its attributes.
    let q = Query::equal("a", [Value::from(doc), Value::from(Query::limit(1))]);
    assert_eq!(q.to_string().unwrap(), r#"{"method":"equal","attribute":"a","values":[{"$id":"abc"},{}]}"#);
}

#[test]
fn constructors_wrap_like_php() {
    assert_eq!(Query::not_equal("a", Value::Array(values("[1,2]"))).values(), &[Value::Int(1), Value::Int(2)]);
    assert_eq!(Query::not_equal("a", Value::Array(values(r#"{"k":1}"#))).values().len(), 1);
    assert_eq!(Query::not_exists("a").values(), &[Value::from("a")]);
    assert_eq!(
        Query::distance_equal("p", [1i64, 2], 3.5, true).to_string().unwrap(),
        r#"{"method":"distanceEqual","attribute":"p","values":[[[1,2],3.5,true]]}"#
    );
    assert!(!Query::is_method("regex"));
    assert!(Query::is_method("elemMatch"));
}
