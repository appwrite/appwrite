use serde_json::json;
use utopia_validators::prelude::*;

#[test]
fn text_length() {
    let v = Text::new(5);
    assert!(v.is_valid(&json!("hello")));
    assert!(!v.is_valid(&json!("toolong")));
    assert!(!v.is_valid(&json!(1)));
}

#[test]
fn text_min() {
    let v = Text::new(10).with_min(3);
    assert!(!v.is_valid(&json!("ab")));
    assert!(v.is_valid(&json!("abc")));
}

#[test]
fn wildcard_always() {
    assert!(Wildcard.is_valid(&json!(null)));
    assert!(Wildcard.is_valid(&json!({"a":1})));
}

#[test]
fn integer_bounds() {
    let v = Integer::new().bits(8);
    assert!(v.is_valid(&json!(127)));
    assert!(!v.is_valid(&json!(128)));
    assert!(v.is_valid(&json!(-128)));
    assert!(!v.is_valid(&json!(-129)));
}

#[test]
fn integer_loose() {
    let v = Integer::new().loose(true);
    assert!(v.is_valid(&json!("42")));
    assert!(!Integer::new().is_valid(&json!("42")));
}

#[test]
fn boolean_loose() {
    let v = Boolean::new().loose(true);
    assert!(v.is_valid(&json!(true)));
    assert!(v.is_valid(&json!("true")));
    assert!(v.is_valid(&json!("1")));
}

#[test]
fn white_list() {
    let v = WhiteList::new(["GET", "POST"]).strict(false);
    assert!(v.is_valid(&json!("get")));
    assert!(!v.is_valid(&json!("PUT")));
}

#[test]
fn range_and_numeric() {
    assert!(Range::new(1.0, 10.0).is_valid(&json!(5.5)));
    assert!(!Range::new(1.0, 10.0).is_valid(&json!(11)));
    assert!(Numeric.is_valid(&json!("3.14")));
}

#[test]
fn url_and_ip() {
    assert!(Url::new().is_valid(&json!("https://example.com/x")));
    assert!(!Url::new().is_valid(&json!("ftp://example.com")));
    assert!(Ip::v4().is_valid(&json!("127.0.0.1")));
    assert!(!Ip::v4().is_valid(&json!("::1")));
    assert!(Ip::v6().is_valid(&json!("::1")));
}

#[test]
fn domain_hostname_host() {
    assert!(Domain.is_valid(&json!("example.com")));
    assert!(!Domain.is_valid(&json!("not_a_domain")));
    assert!(Hostname::new()
        .allow_local(true)
        .is_valid(&json!("localhost")));
    assert!(Host::new(["api.example.com"]).is_valid(&json!("API.example.com")));
}

#[test]
fn nullable_json_array() {
    let v = Nullable::new(Text::new(5));
    assert!(v.is_valid(&json!(null)));
    assert!(v.is_valid(&json!("hi")));
    assert!(Json.is_valid(&json!("{\"a\":1}")));
    assert!(ArrayList::new(Integer::new()).is_valid(&json!([1, 2, 3])));
    assert!(!ArrayList::new(Integer::new()).is_valid(&json!([1, "x"])));

    // PHP's `$length` is a maximum, not an exact count.
    let capped = ArrayList::with_length(Integer::new(), 2);
    assert!(capped.is_valid(&json!([])));
    assert!(capped.is_valid(&json!([1, 2])));
    assert!(!capped.is_valid(&json!([1, 2, 3])));
    assert_eq!(
        capped.description(),
        format!(
            "Value must a valid array no longer than 2 items and {}",
            Integer::new().description()
        )
    );
}

#[test]
fn combinators() {
    let all = AllOf::new(vec![
        std::sync::Arc::new(Text::new(10)),
        std::sync::Arc::new(Contains::new("el")),
    ]);
    assert!(all.is_valid(&json!("hello")));
    assert!(!all.is_valid(&json!("world")));

    let any = AnyOf::new(vec![
        std::sync::Arc::new(Integer::new()),
        std::sync::Arc::new(Text::new(3)),
    ]);
    assert!(any.is_valid(&json!(1)));
    assert!(any.is_valid(&json!("hi")));
}

#[test]
fn hex_phone_id_glob() {
    assert!(HexColor.is_valid(&json!("#fff")));
    assert!(HexColor.is_valid(&json!("#AABBCC")));
    assert!(Phone.is_valid(&json!("+15551234567")));
    assert!(Identifier.is_valid(&json!("user_1")));
    assert!(Globstar::new("foo/**/bar").is_valid(&json!("foo/a/b/bar")));
    assert!(!Globstar::new("foo/*/bar").is_valid(&json!("foo/a/b/bar")));
}

#[test]
fn assoc_and_multiple() {
    assert!(Assoc.is_valid(&json!({"a":1})));
    assert!(!Assoc.is_valid(&json!([1, 2])));
    assert!(Multiple::new(Integer::new()).is_valid(&json!([1, 2])));
}
