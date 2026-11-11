//! PHP values on the wire, mirroring `tests/compat/php-std/Typed.php` and
//! the driver's `Tests\Compat\Codec`.
//!
//! Input ([`decode`]): what the Codec decodes (lists, objects as arrays with
//! normalised keys, `{}` as `stdClass`, `{"$bytes"}`, `{"$float"}`), plus
//! `{"$array": [pair...]}` (an array, keys assigned like `$a[$k] = $v`) and
//! `{"$object": [pair...]}` (a `stdClass`, property names as given); a pair
//! is `[k, v]` or `{"k": k, "v": v}`.
//!
//! Output: [`wire`] is the Codec's encoding (lossy: a `stdClass` and an array
//! look alike), [`dump`] the exact one (lists as lists, other arrays as
//! `{"$array": [[k, v]...]}`, `stdClass` as `{"$object": [[k, v]...]}`,
//! `-0.0` as `{"$float": "-0"}`).

use php_std::types::{Array, ArrayKey, KeyRef, Str, Value};
use serde_json::{Map, Value as Json, json};

use crate::adapter::{Fault, bytes, bytes_value, float, float_value};

fn tagged<'a>(o: &'a Map<String, Json>, tag: &str) -> Option<&'a Json> {
    if o.len() == 1 { o.get(tag) } else { None }
}

/// A PHP value from the wire.
pub fn decode(v: &Json) -> Result<Value, Fault> {
    Ok(match v {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => Value::Float(n.as_f64().unwrap_or(0.0)),
        },
        Json::String(s) => Value::Str(Str::from(s.as_str())),
        Json::Array(a) => Value::Array(a.iter().map(decode).collect::<Result<_, _>>()?),
        Json::Object(o) if o.is_empty() => Value::Object(Array::new()),
        Json::Object(o) => {
            if tagged(o, "$bytes").is_some_and(Json::is_string) {
                return Ok(Value::Str(Str::from(bytes(v).ok_or_else(|| Fault::new("invalid $bytes"))?)));
            }
            if tagged(o, "$float").is_some_and(Json::is_string) {
                return Ok(Value::Float(float(v).ok_or_else(|| Fault::new("invalid $float"))?));
            }
            if let Some(Json::Array(pairs)) = tagged(o, "$array") {
                let mut a = Array::new();
                for pair in pairs {
                    let (k, v) = self::pair(pair)?;
                    let key = match k {
                        Json::Number(n) => ArrayKey::Int(n.as_i64().ok_or_else(|| Fault::new("invalid key"))?),
                        other => ArrayKey::normalize(Str::from(bytes(other).ok_or_else(|| Fault::new("invalid key"))?)),
                    };
                    a.set(key, decode(v)?);
                }
                return Ok(Value::Array(a));
            }
            if let Some(Json::Array(pairs)) = tagged(o, "$object") {
                let mut a = Array::new();
                for pair in pairs {
                    let (k, v) = self::pair(pair)?;
                    let name = match k {
                        Json::Number(n) => Str::from(n.to_string()),
                        other => Str::from(bytes(other).ok_or_else(|| Fault::new("invalid property name"))?),
                    };
                    a.set(ArrayKey::Str(name), decode(v)?);
                }
                return Ok(Value::Object(a));
            }
            let mut a = Array::new();
            for (k, v) in o {
                a.set(ArrayKey::normalize(Str::from(k.as_str())), decode(v)?);
            }
            Value::Array(a)
        }
    })
}

fn pair(p: &Json) -> Result<(&Json, &Json), Fault> {
    match p {
        Json::Array(kv) if kv.len() == 2 => Ok((&kv[0], &kv[1])),
        Json::Object(o) => match (o.get("k"), o.get("v")) {
            (Some(k), Some(v)) => Ok((k, v)),
            _ => Err(Fault::new("a pair is [k, v] or {\"k\", \"v\"}")),
        },
        _ => Err(Fault::new("a pair is [k, v] or {\"k\", \"v\"}")),
    }
}

/// A PHP array argument.
pub fn decode_array(v: &Json) -> Result<Array, Fault> {
    match decode(v)? {
        Value::Array(a) => Ok(a),
        _ => Err(Fault::new("expected an array")),
    }
}

fn key(k: KeyRef<'_>) -> Json {
    match k {
        KeyRef::Int(i) => Json::from(i),
        KeyRef::Str(s) => bytes_value(s),
    }
}

/// The exact wire form of a value.
pub fn dump(v: &Value) -> Json {
    match v {
        Value::Null => Json::Null,
        Value::Bool(b) => Json::Bool(*b),
        Value::Int(i) => Json::from(*i),
        Value::Float(f) if *f == 0.0 && f.is_sign_negative() => json!({"$float": "-0"}),
        Value::Float(f) => float_value(*f),
        Value::Str(s) => bytes_value(s),
        Value::Array(a) if a.is_list() => Json::Array(a.values().map(dump).collect()),
        Value::Array(a) => json!({"$array": pairs(a)}),
        Value::Object(a) => json!({"$object": pairs(a)}),
        Value::Ext(never) => match *never {},
    }
}

fn pairs(a: &Array) -> Vec<Json> {
    a.iter().map(|(k, v)| Json::Array(vec![key(k), dump(v)])).collect()
}

/// A PHP value onto the wire, as `Tests\Compat\Codec::encode` writes it.
pub fn wire(value: &Value) -> Result<Json, Fault> {
    Ok(match value {
        Value::Str(s) => bytes_value(s),
        Value::Float(f) => float_value(*f),
        Value::Array(a) if a.is_list() => Json::Array(a.values().map(wire).collect::<Result<_, _>>()?),
        Value::Array(a) | Value::Object(a) => {
            let object = matches!(value, Value::Object(_));
            let mut m = Map::new();
            for (k, v) in a.iter() {
                let name = match k {
                    KeyRef::Int(i) => i.to_string(),
                    // The driver's json_encode skips properties named "\0..." (mangled names).
                    KeyRef::Str(s) if object && s.first() == Some(&0) => continue,
                    KeyRef::Str(s) => {
                        String::from_utf8(s.to_vec()).map_err(|_| Fault::new("binary array key or property name"))?
                    }
                };
                m.insert(name, wire(v)?);
            }
            Json::Object(m)
        }
        Value::Null => Json::Null,
        Value::Bool(b) => Json::Bool(*b),
        Value::Int(i) => Json::from(*i),
        Value::Ext(never) => match *never {},
    })
}
