//! Unit tests of the crate's own API. PHP parity is checked by `bin/compat`
//! (`tests/compat/validators`), whose recorded cases `cargo test -p compat` replays.

use php_std::zval::{Object, Zval};
use serde_json::{Value, json};

use crate::*;

/// A validator written outside the crate, against the request model only
/// (the Rust side of the PHP package's `OldValidator`).
struct Old;

impl Validator for Old {
    fn description(&self) -> String {
        "Value must be \"old\".".to_owned()
    }

    fn is_valid(&self, value: &Value) -> bool {
        value == "old"
    }
}

#[test]
fn formats_numbers_like_php() {
    assert_eq!(number_format(0), "0");
    assert_eq!(number_format(128), "128");
    assert_eq!(number_format(3600), "3,600");
    assert_eq!(number_format(31536000), "31,536,000");
    assert_eq!(number_format(-2147483648), "-2,147,483,648");
    assert_eq!(number_format(9223372036854775807), "9,223,372,036,854,775,807");
}

#[test]
fn third_party_validators_take_both_models() {
    assert!(Old.is_valid(&json!("old")));
    assert!(Old.validate(Input::Zval(&Zval::String(b"old".to_vec()))).unwrap().valid);
    assert!(!Old.validate(Input::Zval(&Zval::String(vec![0xff]))).unwrap().valid);
    assert_eq!(Old.kind(), Type::Mixed);
    assert_eq!(Old.check(&json!("new")), Err("Value must be \"old\".".to_owned()));
    let all = AllOf::new(vec![Box::new(Text::new(3)), Box::new(Old)]);
    assert_eq!(all.check(&json!("new")), Err("Value must be \"old\".".to_owned()));
}

#[test]
fn text() {
    assert_eq!(
        Text::new(128).description(),
        "Value must be a valid string and at least 1 chars and no longer than 128 chars"
    );
    assert_eq!(Text::with_min(128, 0).description(), "Value must be a valid string and no longer than 128 chars");
    let t = Text::new(36).with_allow_list(Text::NUMBERS);
    assert_eq!(
        t.description(),
        "Value must be a valid string and at least 1 chars and no longer than 36 chars and only consist of '0, 1, 2, 3, 4, 5, 6, 7, 8, 9' chars"
    );
    assert!(t.is_valid(&json!("123")));
    assert!(!t.is_valid(&json!("12a")));
    let t = Text::new(3);
    assert!(!t.is_valid(&json!("")));
    assert!(t.is_valid(&json!("äöü")));
    assert!(!t.is_valid(&json!("abcd")));
    assert!(!t.is_valid(&json!(1)));
    assert!(!Text::new(0).non_blank().is_valid(&json!("\u{200b} ")));
    assert!(Identifier::new(0).is_valid(&json!("_a1")));
    assert!(!Identifier::new(0).is_valid(&json!("1a")));
}

#[test]
fn scalars() {
    assert!(Boolean::LOOSE.is_valid(&json!("false")));
    assert!(Boolean::LOOSE.is_valid(&json!(0)));
    assert!(!Boolean::STRICT.is_valid(&json!("false")));
    assert!(!Boolean::LOOSE.is_valid(&json!(2)));
    let i = Integer::default();
    assert!(i.is_valid(&json!(8)));
    assert!(!i.is_valid(&json!("8")));
    assert!(!i.is_valid(&json!(8.0)));
    assert_eq!(i.description(), "Value must be a valid signed 32-bit integer between -2,147,483,648 and 2,147,483,647");
    assert_eq!(
        Integer::new(false, 64, false).unwrap().description(),
        "Value must be a valid signed 64-bit integer between -9,223,372,036,854,775,808 and 9,223,372,036,854,775,808"
    );
    assert_eq!(Integer::new(false, 8, true).unwrap().format(), "uint8");
    assert_eq!(Integer::new(false, 7, false).unwrap_err().php_class(), "InvalidArgumentException");
    let r = Range::new(4, 128);
    assert!(r.is_valid(&json!("15")));
    assert!(!r.is_valid(&json!("1.5")));
    assert_eq!(Range::new(60, 31536000).description(), "Value must be a valid range between 60 and 31,536,000");
    let inf = Range::with_format(php::Number::Int(0), php::Number::Float(f64::INFINITY), Type::Integer);
    assert!(inf.validate(Input::Zval(&Zval::Float(f64::INFINITY))).unwrap().valid);
    assert!(!inf.validate(Input::Zval(&Zval::Float(f64::NAN))).unwrap().valid);
    assert!(Float { loose: true }.is_valid(&json!("1e3")));
    assert!(Numeric.is_valid(&json!(" 1")));
}

#[test]
fn lists() {
    let w = WhiteList::new(&["email", "sms", "push"]);
    assert!(w.is_valid(&json!("EMAIL")));
    assert!(!w.is_valid(&json!("fax")));
    assert_eq!(w.description(), "Value must be one of (email, sms, push)");
    let err = w.validate(Input::Json(&json!({}))).unwrap_err();
    assert_eq!(
        (err.php_class(), err.to_string().as_str()),
        ("Error", "Object of class stdClass could not be converted to string")
    );
    let strict = WhiteList::with(vec![Zval::Int(1), Zval::String(b"a".to_vec())], true, Type::Integer).unwrap();
    assert!(strict.is_valid(&json!(1)));
    assert!(!strict.is_valid(&json!("1")));
    let a = Assoc::default();
    assert!(a.is_valid(&json!({"a": 1})));
    assert!(a.is_valid(&json!([])));
    assert!(!a.is_valid(&json!({})));
    assert!(!a.is_valid(&json!(["a"])));
    let v = ArrayList::new(Text::new(3), 2);
    assert!(v.is_valid(&json!(["a", "b"])));
    assert!(!v.is_valid(&json!(["a", "b", "c"])));
    assert_eq!(v.kind(), Type::String);
    assert_eq!(
        v.description(),
        "Value must a valid array no longer than 2 items and Value must be a valid string and at least 1 chars and no longer than 3 chars"
    );
}

#[test]
fn composites_describe_the_deciding_rule() {
    let nullable = Nullable(AllOf::new(vec![Box::new(Text::new(3)), Box::new(HexColor)]));
    assert_eq!(nullable.check(&json!("ggg")), Err("Value must be a valid Hex color code or null".to_owned()));
    assert_eq!(nullable.check(&Value::Null), Ok(()));
    let any = AnyOf::new(vec![Box::new(Integer::default()), Box::new(Text::new(2))]);
    let v = any.validate(Input::Json(&json!("ab"))).unwrap();
    assert!(v.valid);
    assert_eq!(v.description.as_deref(), Some(Text::new(2).description().as_str()));
    let mut multiple = Multiple::new(vec![Box::new(Text::new(3))]);
    multiple.add_rule(Box::new(HexColor));
    assert_eq!(
        multiple.description(),
        "1. Value must be a valid string and at least 1 chars and no longer than 3 chars \n2. Value must be a valid Hex color code \n"
    );
    assert!(multiple.is_valid(&json!("fff")));
    let list = ArrayList::new(json::Fcm, 0);
    let v = list.validate(Input::Json(&json!([{}]))).unwrap();
    assert_eq!(
        v.description.as_deref(),
        Some(
            "Value must a valid array and FCM service account JSON must include a non-empty 'type' field, which identifies the credentials as a Google service account."
        )
    );
}

#[test]
fn exact_values() {
    let mut object = Object::new();
    object.set(b"a".to_vec(), Zval::Int(1));
    let object = Zval::Object(object);
    assert!(json::Object::default().validate(Input::Zval(&object)).unwrap().valid);
    assert!(!json::Array::default().validate(Input::Zval(&object)).unwrap().valid);
    assert!(!Json.validate(Input::Zval(&Zval::String(vec![b'"', 0xff, b'"']))).unwrap().valid);
    assert!(Hostname::default().validate(Input::Zval(&Zval::String(vec![0xff]))).unwrap().valid);
    assert_eq!(Phone::normalize(b"%2B1234567"), b"+1234567");
    assert_eq!(Phone::normalize(b"%FF"), vec![0xff]);
}

#[test]
fn network() {
    assert!(Domain::default().is_valid(&json!("example.com")));
    assert!(!Domain::default().is_valid(&json!("api-.appwrite.io")));
    let restricted = Domain {
        restrictions: vec![Restriction::new("appwrite.network", Some(3), vec!["preview-".into()])],
        ..Domain::default()
    };
    assert!(restricted.is_valid(&json!("google.appwrite.network")));
    assert!(!restricted.is_valid(&json!("preview-a.appwrite.network")));
    assert!(Ip::new(IpVersion::V4).is_valid(&json!("127.0.0.1")));
    assert!(!Ip::new(IpVersion::V6).is_valid(&json!("127.0.0.1")));
    assert_eq!(IpVersion::from_php("v5").unwrap_err().php_class(), "Exception");
    let url = Url { allow_private_use_schemes: true, https_or_loopback: true, ..Url::default() };
    assert!(url.is_valid(&json!("com.example.app:/oauth")));
    assert!(url.is_valid(&json!("http://127.0.0.1:3000/cb")));
    assert!(!url.is_valid(&json!("http://example.com/cb")));
    let host = Host::new(vec!["*.appwrite.io".into()]);
    assert!(host.is_valid(&json!("https://me.appwrite.io/x")));
    assert!(!host.is_valid(&json!("https://appwrite.io/x")));
    let h = Hostname::new(vec!["*.appwrite.io".into(), "localhost".into()]);
    assert!(h.matches("cloud.appwrite.io"));
    assert!(!h.matches("appwrite.io"));
    assert!(!h.matches("localhost:3000"));
}

#[test]
fn strings() {
    let contains = Contains::new(vec!["[skip ci]".into()], false).unwrap();
    assert!(contains.is_valid(&json!("Docs [SKIP CI]")));
    assert_eq!(Contains::new(Vec::new(), false).unwrap_err().to_string(), "Patterns array cannot be empty");
    let glob = Globstar::new(vec!["src/**".into(), "!src/vendor/**".into()]);
    assert!(glob.is_valid(&json!("src/a/b.rs")));
    assert!(!glob.is_valid(&json!("src/vendor/x")));
    assert!(HexColor.is_valid(&json!("fFf")));
    assert!(Phone { allow_empty: false, normalize: true }.is_valid(&json!(" 1234567")));
    assert!(Wildcard.is_valid(&json!({})));
}
