use utopia_cache::adapter::redis::{
    Client, ConnectionError, ConnectionException, Envelope, NoScript, ParseOutcome, RedisError,
    RespValue,
};
use utopia_cache::CacheValue;

fn parse(buffer: &str) -> (ParseOutcome, usize) {
    let mut offset = 0;
    let value = Client::parse(buffer, &mut offset).unwrap();
    (value, offset)
}

fn expect_value(buffer: &str) -> (RespValue, usize) {
    let (outcome, offset) = parse(buffer);
    match outcome {
        ParseOutcome::Value(v) => (v, offset),
        ParseOutcome::Incomplete => panic!("expected value, got Incomplete"),
    }
}

#[test]
fn encode_builds_resp_array_of_bulk_strings() {
    assert_eq!(Client::encode_strings(&["PING"]), "*1\r\n$4\r\nPING\r\n");
    assert_eq!(
        Client::encode_strings(&["SET", "foo", "bar"]),
        "*3\r\n$3\r\nSET\r\n$3\r\nfoo\r\n$3\r\nbar\r\n"
    );
}

#[test]
fn encode_coerces_integers_to_strings() {
    assert_eq!(
        Client::encode_strings(&["SELECT", "3"]),
        "*2\r\n$6\r\nSELECT\r\n$1\r\n3\r\n"
    );
}

#[test]
fn encode_preserves_binary_payload_by_byte_length() {
    let payload = "café\n\0bytes";
    let encoded = Client::encode(&[b"SET".as_ref(), b"k".as_ref(), payload.as_bytes()]);
    let expected = format!(
        "*3\r\n$3\r\nSET\r\n$1\r\nk\r\n${}\r\n{}\r\n",
        payload.len(),
        payload
    );
    assert_eq!(String::from_utf8_lossy(&encoded), expected);
}

#[test]
fn parse_simple_string() {
    let (v, offset) = expect_value("+OK\r\n");
    assert_eq!(v, RespValue::Simple("OK".into()));
    assert_eq!(offset, 5);
}

#[test]
fn parse_integer() {
    let (v, offset) = expect_value(":42\r\n");
    assert_eq!(v, RespValue::Integer(42));
    assert_eq!(offset, 5);
}

#[test]
fn parse_negative_integer() {
    let (v, _) = expect_value(":-7\r\n");
    assert_eq!(v, RespValue::Integer(-7));
}

#[test]
fn parse_bulk_string() {
    let (v, offset) = expect_value("$5\r\nhello\r\n");
    assert_eq!(v, RespValue::Bulk("hello".into()));
    assert_eq!(offset, 11);
}

#[test]
fn parse_bulk_string_with_embedded_crlf() {
    let payload = "line1\r\nline2";
    let buffer = format!("${}\r\n{}\r\n", payload.len(), payload);
    let (v, _) = expect_value(&buffer);
    assert_eq!(v, RespValue::Bulk(payload.into()));
}

#[test]
fn parse_empty_bulk_string() {
    let (v, offset) = expect_value("$0\r\n\r\n");
    assert_eq!(v, RespValue::Bulk(String::new()));
    assert_eq!(offset, 6);
}

#[test]
fn parse_null_bulk_string() {
    let (v, offset) = expect_value("$-1\r\n");
    assert_eq!(v, RespValue::Nil);
    assert_eq!(offset, 5);
}

#[test]
fn parse_array_of_mixed_types() {
    let buffer = "*3\r\n$3\r\nfoo\r\n:42\r\n+OK\r\n";
    let (v, offset) = expect_value(buffer);
    assert_eq!(
        v,
        RespValue::Array(vec![
            RespValue::Bulk("foo".into()),
            RespValue::Integer(42),
            RespValue::Simple("OK".into()),
        ])
    );
    assert_eq!(offset, buffer.len());
}

#[test]
fn parse_empty_array() {
    let (v, _) = expect_value("*0\r\n");
    assert_eq!(v, RespValue::Array(vec![]));
}

#[test]
fn parse_null_array() {
    let (v, _) = expect_value("*-1\r\n");
    assert_eq!(v, RespValue::Nil);
}

#[test]
fn parse_nested_arrays() {
    let buffer = "*2\r\n*2\r\n:1\r\n:2\r\n*1\r\n$1\r\nx\r\n";
    let (v, offset) = expect_value(buffer);
    assert_eq!(
        v,
        RespValue::Array(vec![
            RespValue::Array(vec![RespValue::Integer(1), RespValue::Integer(2)]),
            RespValue::Array(vec![RespValue::Bulk("x".into())]),
        ])
    );
    assert_eq!(offset, buffer.len());
}

#[test]
fn parse_redis_error_is_wrapped_not_thrown() {
    let (v, _) = expect_value("-WRONGTYPE wrong kind\r\n");
    assert_eq!(v, RespValue::RedisError("WRONGTYPE wrong kind".into()));
}

#[test]
fn parse_returns_incomplete_when_buffer_empty() {
    let (v, _) = parse("");
    assert_eq!(v, ParseOutcome::Incomplete);
}

#[test]
fn parse_returns_incomplete_when_line_unterminated() {
    let (v, _) = parse("+OK");
    assert_eq!(v, ParseOutcome::Incomplete);
}

#[test]
fn parse_returns_incomplete_for_truncated_bulk_string() {
    let (v, _) = parse("$5\r\nhel");
    assert_eq!(v, ParseOutcome::Incomplete);
}

#[test]
fn parse_returns_incomplete_for_bulk_string_missing_trailing_crlf() {
    let (v, _) = parse("$5\r\nhello");
    assert_eq!(v, ParseOutcome::Incomplete);
}

#[test]
fn parse_returns_incomplete_for_partially_delivered_array_element() {
    let (v, _) = parse("*2\r\n:1\r\n:");
    assert_eq!(v, ParseOutcome::Incomplete);
}

#[test]
fn parse_advances_offset_exactly_one_frame() {
    let buffer = "+OK\r\n+SECOND\r\n";
    let mut offset = 0;
    match Client::parse(buffer, &mut offset).unwrap() {
        ParseOutcome::Value(RespValue::Simple(s)) => assert_eq!(s, "OK"),
        other => panic!("{other:?}"),
    }
    assert_eq!(offset, 5);
    match Client::parse(buffer, &mut offset).unwrap() {
        ParseOutcome::Value(RespValue::Simple(s)) => assert_eq!(s, "SECOND"),
        other => panic!("{other:?}"),
    }
    assert_eq!(offset, buffer.len());
}

#[test]
fn parse_unknown_type_throws() {
    let mut offset = 0;
    let err = Client::parse("?nope\r\n", &mut offset).unwrap_err();
    assert!(err.to_string().contains("Unknown RESP type"));
}

#[test]
fn encode_and_parse_round_trip() {
    let (v, _) = expect_value(":1\r\n");
    assert_eq!(v, RespValue::Integer(1));
    assert_eq!(
        Client::encode_strings(&["HSET", "k", "f", "v"]),
        "*4\r\n$4\r\nHSET\r\n$1\r\nk\r\n$1\r\nf\r\n$1\r\nv\r\n"
    );
}

#[test]
fn unwrap_passes_through_scalars() {
    assert_eq!(
        Client::unwrap_value(RespValue::Simple("OK".into())).unwrap(),
        RespValue::Simple("OK".into())
    );
    assert_eq!(
        Client::unwrap_value(RespValue::Integer(42)).unwrap(),
        RespValue::Integer(42)
    );
    assert_eq!(
        Client::unwrap_value(RespValue::Nil).unwrap(),
        RespValue::Nil
    );
    assert_eq!(
        Client::unwrap_value(RespValue::Bulk(String::new())).unwrap(),
        RespValue::Bulk(String::new())
    );
}

#[test]
fn unwrap_passes_through_arrays_of_scalars() {
    let input = RespValue::Array(vec![
        RespValue::Simple("a".into()),
        RespValue::Integer(1),
        RespValue::Nil,
    ]);
    assert_eq!(Client::unwrap_value(input.clone()).unwrap(), input);
}

#[test]
fn unwrap_throws_redis_error_at_top_level() {
    let err = Client::unwrap_value(RespValue::RedisError("WRONGTYPE".into())).unwrap_err();
    assert!(err.to_string().contains("WRONGTYPE"));
}

#[test]
fn unwrap_throws_connection_error_at_top_level() {
    let err = Client::unwrap_connection(ConnectionError::new(ConnectionException::new(
        "connection lost",
    )))
    .unwrap_err();
    assert!(err.to_string().contains("connection lost"));
    let _ = RedisError::new("WRONGTYPE");
}

#[test]
fn unwrap_throws_redis_error_nested_in_array() {
    let err = Client::unwrap_value(RespValue::Array(vec![
        RespValue::Simple("ok".into()),
        RespValue::RedisError("NOAUTH".into()),
    ]))
    .unwrap_err();
    assert!(err.to_string().contains("NOAUTH"));
}

#[test]
fn unwrap_throws_error_nested_inside_nested_array() {
    let err = Client::unwrap_value(RespValue::Array(vec![RespValue::Array(vec![
        RespValue::Simple("nested".into()),
        RespValue::Array(vec![
            RespValue::Simple("deeper".into()),
            RespValue::RedisError("deep".into()),
        ]),
    ])]))
    .unwrap_err();
    assert!(err.to_string().contains("deep"));
}

#[test]
fn unwrap_handles_empty_array() {
    assert_eq!(
        Client::unwrap_value(RespValue::Array(vec![])).unwrap(),
        RespValue::Array(vec![])
    );
}

#[test]
fn noscript_matches_leading_code_token() {
    assert!(NoScript::matches(
        "NOSCRIPT No matching script. Please use EVAL."
    ));
    assert!(NoScript::matches("NOSCRIPT"));
}

#[test]
fn noscript_does_not_match_when_not_the_code() {
    assert!(!NoScript::matches(r#"ERR user set key to "NOSCRIPT""#));
    assert!(!NoScript::matches(
        "WRONGTYPE Operation against a key holding the wrong kind of value"
    ));
    assert!(!NoScript::matches(""));
}

#[test]
fn noscript_from_exception_preserves_message() {
    let cause = std::io::Error::new(
        std::io::ErrorKind::Other,
        "NOSCRIPT No matching script. Please use EVAL.",
    );
    let signal = NoScript::from_error(cause);
    assert_eq!(
        signal.message(),
        "NOSCRIPT No matching script. Please use EVAL."
    );
    assert!(signal.previous().is_some());
}

#[test]
fn noscript_from_string_carries_message() {
    let signal = NoScript::from_message("NOSCRIPT No matching script. Please use EVAL.");
    assert_eq!(
        signal.message(),
        "NOSCRIPT No matching script. Please use EVAL."
    );
    assert!(signal.previous().is_none());
}

#[test]
fn lua_scripts_match_php_leasable_source_sha1() {
    use sha1::{Digest, Sha1};
    use utopia_cache::adapter::redis::{LUA_PURGE_BUMP, LUA_PURGE_FIELD, LUA_SAVE_WITH_LEASE};
    fn sha(s: &str) -> String {
        hex::encode(Sha1::digest(s.as_bytes()))
    }
    assert_eq!(
        sha(LUA_SAVE_WITH_LEASE),
        "4cea80f4dfe11fe8af11096845f81de3d059ae9c"
    );
    assert_eq!(
        sha(LUA_PURGE_BUMP),
        "ff870d0b031f8c1ae3c2db850ef3170fb75938ec"
    );
    assert_eq!(
        sha(LUA_PURGE_FIELD),
        "25756e73ba24f12b8e42044efe59f9e65c5b31c7"
    );
}

#[test]
fn envelope_encode_wraps_data_and_time() {
    let encoded = Envelope::encode(&CacheValue::from("hello"), 1_700_000_000).unwrap();
    assert_eq!(encoded, r#"{"time":1700000000,"data":"hello"}"#);
}

#[test]
fn envelope_encode_array_payload() {
    let encoded = Envelope::encode(&CacheValue::Array(serde_json::json!({"a": 1})), 42).unwrap();
    assert_eq!(encoded, r#"{"time":42,"data":{"a":1}}"#);
}

#[test]
fn envelope_decode_returns_data_when_fresh() {
    let encoded = Envelope::encode(&CacheValue::Array(serde_json::json!({"x": 1})), 100).unwrap();
    let decoded = Envelope::decode(&encoded, 60, 130).unwrap();
    assert_eq!(decoded, CacheValue::Array(serde_json::json!({"x": 1})));
}

#[test]
fn envelope_decode_returns_false_when_stale() {
    let encoded = Envelope::encode(&CacheValue::from("value"), 100).unwrap();
    assert!(Envelope::decode(&encoded, 60, 161).is_none());
}

#[test]
fn envelope_decode_boundary_is_exclusive() {
    let encoded = Envelope::encode(&CacheValue::from("value"), 100).unwrap();
    assert!(Envelope::decode(&encoded, 60, 160).is_none());
    assert_eq!(
        Envelope::decode(&encoded, 60, 159).unwrap().as_str(),
        Some("value")
    );
}

#[test]
fn envelope_decode_treats_malformed_json_as_miss() {
    assert!(Envelope::decode("not json", 60, 0).is_none());
    assert!(Envelope::decode("", 60, 0).is_none());
    assert!(Envelope::decode("null", 60, 0).is_none());
}

#[test]
fn envelope_decode_rejects_missing_fields() {
    assert!(Envelope::decode(r#"{"time":100}"#, 60, 0).is_none());
    assert!(Envelope::decode(r#"{"data":"x"}"#, 60, 0).is_none());
    assert!(Envelope::decode("{}", 60, 0).is_none());
}

#[test]
fn envelope_decode_rejects_non_integer_time() {
    assert!(Envelope::decode(r#"{"time":"100","data":"x"}"#, 60, 0).is_none());
    assert!(Envelope::decode(r#"{"time":1.5,"data":"x"}"#, 60, 0).is_none());
}

#[test]
fn envelope_decode_preserves_null_data_as_miss() {
    assert!(Envelope::decode(r#"{"time":100,"data":null}"#, 60, 130).is_none());
}

#[test]
fn envelope_decode_preserves_nested_array_data() {
    let data = serde_json::json!({"a": {"b": {"c": "deep"}}, "list": [1, 2, 3]});
    let encoded = Envelope::encode(&CacheValue::Array(data.clone()), 100).unwrap();
    assert_eq!(
        Envelope::decode(&encoded, 60, 130).unwrap(),
        CacheValue::Array(data)
    );
}

#[test]
fn envelope_decode_preserves_empty_objects() {
    let data = serde_json::json!({
        "empty": {},
        "nested": { "empty": {} },
        "list": [{}, {"x": 1}],
        "emptyArray": []
    });
    let encoded = Envelope::encode(&CacheValue::Array(data), 100).unwrap();
    let decoded = Envelope::decode(&encoded, 60, 130).unwrap();
    assert_eq!(
        serde_json::to_string(&decoded.clone().into_json()).unwrap(),
        r#"{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}"#
    );
    let touched = Envelope::touch(&encoded, 120).unwrap();
    let decoded = Envelope::decode(&touched, 60, 130).unwrap();
    assert_eq!(
        serde_json::to_string(&decoded.into_json()).unwrap(),
        r#"{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}"#
    );
}

#[test]
fn envelope_touch_rewrites_time() {
    let encoded = Envelope::encode(&CacheValue::from("value"), 100).unwrap();
    let touched = Envelope::touch(&encoded, 200).unwrap();
    assert_eq!(
        Envelope::decode(&touched, 60, 250).unwrap().as_str(),
        Some("value")
    );
    assert!(Envelope::decode(&encoded, 60, 250).is_none());
}

#[test]
fn envelope_touch_preserves_array_data() {
    let data = serde_json::json!({"x": 1, "y": [2, 3]});
    let encoded = Envelope::encode(&CacheValue::Array(data.clone()), 100).unwrap();
    let touched = Envelope::touch(&encoded, 200).unwrap();
    assert_eq!(
        Envelope::decode(&touched, 60, 230).unwrap(),
        CacheValue::Array(data)
    );
}

#[test]
fn envelope_touch_returns_false_on_malformed_json() {
    assert!(Envelope::touch("not json", 200).is_none());
}

#[test]
fn envelope_touch_returns_false_when_data_key_missing() {
    assert!(Envelope::touch(r#"{"time":100}"#, 200).is_none());
    assert!(Envelope::touch("{}", 200).is_none());
}

#[test]
fn envelope_touch_accepts_envelope_without_prior_time_field() {
    let touched = Envelope::touch(r#"{"data":"x"}"#, 200).unwrap();
    assert_eq!(
        Envelope::decode(&touched, 60, 230).unwrap().as_str(),
        Some("x")
    );
}
