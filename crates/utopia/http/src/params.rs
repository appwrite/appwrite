//! Request parameters as JSON values: parsing query strings, form bodies and
//! JSON bodies into [`Params`].
//!
//! Lists are arrays, maps with other keys are objects, and an empty JSON
//! object stays an empty object.
//!
//! PHP: the values model PHP arrays (lists are arrays, other arrays are
//! objects, an empty `stdClass` is an empty object; see
//! [`php_std::Value::from_json`]). Query strings and form bodies are parsed
//! by PHP's own rules ([`php_std::encoding::parse_str`]), JSON bodies by
//! [`decode_payload`] (`Utopia\Http\Request::decodePayload`).

use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str};
use serde_json::{Map, Value};

/// Decoded request parameters, by name.
pub type Params = Map<String, Value>;

/// Parses an `application/x-www-form-urlencoded` string (a query string or a
/// form body) into params.
///
/// Bracketed names build nested values: `a[]=x&a[]=y` is the array
/// `["x", "y"]` and `b[k]=v` the object `{"k": "v"}`. Dots and spaces in
/// names become underscores.
///
/// PHP: parses like `parse_str()`.
pub fn parse_query(input: &str) -> Params {
    parse_query_bytes(input.as_bytes())
}

/// Parses an `application/x-www-form-urlencoded` byte string into params,
/// like [`parse_query`].
pub fn parse_query_bytes(input: &[u8]) -> Params {
    flat_query(input).unwrap_or_else(|| array_to_params(&php_std::encoding::parse_str(input)))
}

/// `parse_str()` straight into params, for the usual string where no name
/// has a `[` (which builds nested arrays); `None` for any other string.
fn flat_query(input: &[u8]) -> Option<Params> {
    let mut params = Map::new();
    let mut count = 0;
    for var in c_str(input).split(|&b| b == b'&').filter(|t| !t.is_empty()) {
        count += 1;
        if count > php_std::encoding::MAX_INPUT_VARS {
            break;
        }
        let (name, value) = match var.iter().position(|&b| b == b'=') {
            Some(eq) => (&var[..eq], php_std::encoding::urldecode(&var[eq + 1..])),
            None => (var, Vec::new()),
        };
        let name = if name.iter().any(|&b| b == b'%' || b == b'+') {
            std::borrow::Cow::Owned(php_std::encoding::urldecode(name))
        } else {
            std::borrow::Cow::Borrowed(name)
        };
        register_flat(&mut params, &name, value, false)?;
    }
    Some(params)
}

/// `php_register_variable_ex()` into params for a name without `[`: leading
/// spaces dropped, `.` and ` ` as `_`, nothing from a NUL on. `keep_existing`
/// is the `$_COOKIE` rule (the first of two names wins).
///
/// `None` when the name needs PHP's array rules: it has a `[`, or it is not
/// UTF-8 (two such names can share a params key that PHP keeps apart).
pub(crate) fn register_flat(params: &mut Params, name: &[u8], value: Vec<u8>, keep_existing: bool) -> Option<()> {
    let name = c_str(name);
    let name = &name[name.iter().position(|&b| b != b' ').unwrap_or(name.len())..];
    if name.contains(&b'[') {
        return None;
    }
    if name.is_empty() {
        return Some(());
    }
    let mut key = name.to_vec();
    for b in &mut key {
        if matches!(*b, b' ' | b'.') {
            *b = b'_';
        }
    }
    // A PHP key from these bytes prints back as the same text, so the params
    // key is the name itself.
    let key = String::from_utf8(key).ok()?;
    if keep_existing && params.contains_key(&key) {
        return Some(());
    }
    params.insert(key, Value::String(lossy(value)));
    Some(())
}

/// The bytes as UTF-8, invalid sequences replaced; no copy when valid.
fn lossy(bytes: Vec<u8>) -> String {
    String::from_utf8(bytes).unwrap_or_else(|e| String::from_utf8_lossy(e.as_bytes()).into_owned())
}

/// The engine's C string: everything from the first NUL on is ignored.
fn c_str(s: &[u8]) -> &[u8] {
    s.iter().position(|&b| b == 0).map_or(s, |n| &s[..n])
}

/// Decodes a JSON body into params.
///
/// A top-level object or array gives its members; objects whose keys are
/// `0..n` become arrays, and empty objects stay objects. Anything else (a
/// scalar, an empty object, invalid JSON) gives no params.
///
/// PHP: `Request::decodePayload($raw)`: objects decode to associative arrays
/// except empty ones, which stay objects.
pub fn decode_payload(raw: &[u8]) -> Params {
    // PHP decodes into objects only when the body can hold an empty object
    // (`/\{\s*\}/`); the two modes differ for property names PHP objects reject.
    let assoc = !has_empty_object(raw);
    if assoc {
        // Straight into params: `json_decode($raw, true)` as JSON values.
        // Only a number PHP decodes to INF needs the PHP value model.
        use php_std::json::{DEFAULT_DEPTH, Error, Flags, decode_value};
        match decode_value(raw, DEFAULT_DEPTH, Flags::NONE) {
            Ok(Value::Object(members)) => return members,
            Ok(Value::Array(list)) => {
                return list.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect();
            }
            Ok(_) => return Map::new(),
            Err(Error::Unrepresentable) => {}
            Err(_) => return Map::new(),
        }
    }
    match php_std::json::decode(raw, Some(assoc), php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE) {
        Ok(php_std::Value::Array(a)) => array_to_params(&a),
        Ok(php_std::Value::Object(o)) if !o.is_empty() => array_to_params(&object_to_array(&o)),
        _ => Map::new(),
    }
}

/// `preg_match('/\{\s*\}/', $raw)`: `\s` is PCRE's ASCII whitespace.
fn has_empty_object(raw: &[u8]) -> bool {
    raw.iter().enumerate().any(|(i, &b)| {
        b == b'{'
            && raw[i + 1..].iter().find(|c| !matches!(c, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r')) == Some(&b'}')
    })
}

/// `(array) $object`: numeric property names become integer keys.
fn object_to_array(object: &Array) -> Array {
    object
        .iter()
        .map(|(k, v)| {
            let key = match k {
                KeyRef::Int(i) => ArrayKey::Int(i),
                KeyRef::Str(s) => ArrayKey::normalize(Str::copy_from(s)),
            };
            (key, v.clone())
        })
        .collect()
}

/// Converts a [`php_std::Array`] into params, with keys as strings, in order.
///
/// PHP: a PHP array as params.
pub fn array_to_params(array: &Array) -> Params {
    let mut map = Map::with_capacity(array.len());
    for (k, v) in array.iter() {
        map.insert(key_string(k), value_to_json(v));
    }
    map
}

/// Converts a [`php_std::Value`] into a JSON value.
///
/// Lists become arrays and other arrays objects. Binary strings are decoded
/// lossily (params hold UTF-8 only); non-finite floats become `null`.
pub fn value_to_json(value: &php_std::Value) -> Value {
    use php_std::Value as Php;
    match value {
        Php::Null => Value::Null,
        Php::Bool(b) => Value::Bool(*b),
        Php::Int(i) => Value::from(*i),
        Php::Float(f) => serde_json::Number::from_f64(*f).map(Value::Number).unwrap_or(Value::Null),
        Php::Str(s) => Value::String(String::from_utf8_lossy(s).into_owned()),
        Php::Array(a) if !a.is_empty() && a.is_list() => Value::Array(a.values().map(value_to_json).collect()),
        Php::Array(a) if a.is_empty() => Value::Array(Vec::new()),
        Php::Array(a) => Value::Object(array_to_params(a)),
        Php::Object(o) if o.is_empty() => Value::Object(Map::new()),
        Php::Object(o) => value_to_json(&Php::Array(object_to_array(o))),
        Php::Ext(never) => match *never {},
    }
}

fn key_string(key: KeyRef<'_>) -> String {
    match key {
        KeyRef::Int(i) => i.to_string(),
        KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}

/// Params as a single JSON value: an array when the keys are `0..n` (or there
/// are none), an object otherwise.
///
/// PHP: the PHP array the params model.
pub fn params_value(params: &Params) -> Value {
    if !params.is_empty()
        && params.keys().enumerate().all(|(i, k)| php_std::types::numeric_key(k.as_bytes()) == Some(i as i64))
    {
        Value::Array(params.values().cloned().collect())
    } else if params.is_empty() {
        Value::Array(Vec::new())
    } else {
        Value::Object(params.clone())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn brackets() {
        let p = parse_query("queries[]=a&queries[]=b&search=x&total=false");
        assert_eq!(Value::Object(p), json!({"queries": ["a", "b"], "search": "x", "total": "false"}));
        let p = parse_query("a[0]=x&a[1]=y&b[k]=v&c[2]=z");
        assert_eq!(Value::Object(p), json!({"a": ["x", "y"], "b": {"k": "v"}, "c": {"2": "z"}}));
        let p = parse_query("a.b=1&labels%5B%5D=vip");
        assert_eq!(Value::Object(p), json!({"a_b": "1", "labels": ["vip"]}));
    }

    #[test]
    fn json_bodies_keep_empty_objects() {
        let p = decode_payload(br#"{"data":{"a":{},"b":{"c":1},"l":[],"n":{"0":"x"}}}"#);
        assert_eq!(Value::Object(p), json!({"data": {"a": {}, "b": {"c": 1}, "l": [], "n": ["x"]}}));
        for body in [&b"{}"[..], b"{ }", b"[]", b"", b"not json", b"\"a string\"", b"5"] {
            assert!(decode_payload(body).is_empty());
        }
    }
}
