//! Request parameters in the request model: `serde_json::Value`s where PHP
//! lists are arrays, other PHP arrays are objects and an empty `stdClass`
//! is an empty object (see [`php_std::Value::from_json`]).
//!
//! Query strings and form bodies are parsed by PHP's own rules
//! ([`php_std::encoding::parse_str`]), JSON bodies by
//! [`decode_payload`] (`Utopia\Http\Request::decodePayload`).

use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str};
use serde_json::{Map, Value};

/// Decoded request parameters.
pub type Params = Map<String, Value>;

/// Parses an `application/x-www-form-urlencoded` string (query string or
/// form body) like PHP's `parse_str`.
pub fn parse_query(input: &str) -> Params {
    parse_query_bytes(input.as_bytes())
}

/// [`parse_query`] over bytes.
pub fn parse_query_bytes(input: &[u8]) -> Params {
    array_to_params(&php_std::encoding::parse_str(input))
}

/// `Request::decodePayload($raw)`: a JSON body as params. Objects become
/// associative arrays except empty ones, which stay objects; anything that is
/// not an array or object (or not JSON) is no params.
pub fn decode_payload(raw: &[u8]) -> Params {
    // PHP decodes into objects only when the body can hold an empty object
    // (`/\{\s*\}/`); the two modes differ for property names PHP objects reject.
    let assoc = !has_empty_object(raw);
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

/// A PHP array as params (keys as strings, in order).
pub fn array_to_params(array: &Array) -> Params {
    let mut map = Map::with_capacity(array.len());
    for (k, v) in array.iter() {
        map.insert(key_string(k), value_to_json(v));
    }
    map
}

/// The request-model value of a PHP value. Binary strings are decoded
/// lossily (the model holds UTF-8 only); non-finite floats become `null`.
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

/// Params as the PHP array they model: a list when the keys are `0..n`.
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
