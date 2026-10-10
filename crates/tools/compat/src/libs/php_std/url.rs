//! `url.*`: `parse_url` and `Uri\Rfc3986\Uri` (`php_std::url`).

use php_std::url::{self, Component, ComponentValue, rfc3986::Uri};
use serde_json::{Map, Value};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &["url.parse_url", "url.parse_url_component", "url.rfc3986_parse"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "url.parse_url" => {
            let input = a.bytes("url")?;
            match url::parse_url(&input) {
                None => Value::Bool(false),
                Some(u) => {
                    let mut m = Map::new();
                    let mut put = |k: &str, v: Option<Value>| {
                        if let Some(v) = v {
                            m.insert(k.into(), v);
                        }
                    };
                    put("scheme", u.scheme().map(|v| bytes_value(&v)));
                    put("host", u.host().map(|v| bytes_value(&v)));
                    put("port", u.port().map(Value::from));
                    put("user", u.user().map(|v| bytes_value(&v)));
                    put("pass", u.pass().map(|v| bytes_value(&v)));
                    put("path", u.path().map(|v| bytes_value(&v)));
                    put("query", u.query().map(|v| bytes_value(&v)));
                    put("fragment", u.fragment().map(|v| bytes_value(&v)));
                    if m.is_empty() { Value::Array(Vec::new()) } else { Value::Object(m) }
                }
            }
        }
        "url.parse_url_component" => {
            let input = a.bytes("url")?;
            let component = Component::from_php(a.i64("component")?)
                .ok_or_else(|| Fault::new("component must be a PHP_URL_* constant"))?;
            match url::parse_url(&input) {
                None => Value::Bool(false),
                Some(u) => match u.component(component) {
                    None => Value::Null,
                    Some(ComponentValue::Str(s)) => bytes_value(&s),
                    Some(ComponentValue::Port(p)) => Value::from(p),
                },
            }
        }
        "url.rfc3986_parse" => {
            let input = a.bytes("uri")?;
            match Uri::parse(&input) {
                None => Value::Null,
                Some(uri) => uri_value(&uri),
            }
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}

/// Every getter of a parsed `Uri\Rfc3986\Uri`, in the PHP ops file's order.
fn uri_value(uri: &Uri) -> Value {
    let s = |v: Option<std::borrow::Cow<'_, [u8]>>| v.map_or(Value::Null, |v| bytes_value(&v));
    let mut m = Map::new();
    m.insert("scheme".into(), s(uri.scheme()));
    m.insert("rawScheme".into(), s(uri.raw_scheme()));
    m.insert("userInfo".into(), s(uri.user_info()));
    m.insert("rawUserInfo".into(), s(uri.raw_user_info()));
    m.insert("username".into(), s(uri.username()));
    m.insert("rawUsername".into(), s(uri.raw_username()));
    m.insert("password".into(), s(uri.password()));
    m.insert("rawPassword".into(), s(uri.raw_password()));
    m.insert("host".into(), s(uri.host()));
    m.insert("rawHost".into(), s(uri.raw_host()));
    m.insert("port".into(), uri.port().map_or(Value::Null, Value::from));
    m.insert("path".into(), bytes_value(&uri.path()));
    m.insert("rawPath".into(), bytes_value(&uri.raw_path()));
    m.insert("query".into(), s(uri.query()));
    m.insert("rawQuery".into(), s(uri.raw_query()));
    m.insert("fragment".into(), s(uri.fragment()));
    m.insert("rawFragment".into(), s(uri.raw_fragment()));
    m.insert("string".into(), bytes_value(&uri.to_string_normalized()));
    m.insert("rawString".into(), bytes_value(&uri.to_raw_string()));
    Value::Object(m)
}
