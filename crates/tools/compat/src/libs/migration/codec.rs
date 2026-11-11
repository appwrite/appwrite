//! PHP values across the wire, as `tests/compat/php/Codec.php` decodes and
//! encodes them, plus the migration adapter's encodings (Values.php).

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value};
use serde_json::{Map, Value as Json, json};
use utopia_migration::cache::{Entries, Entry};
use utopia_migration::error::Error;
use utopia_migration::{Exception, ResourceRef, Warning};

use crate::adapter::{Fault, Outcome, bytes_value, float_value};

/// An argument as a PHP value (`Codec::decode`).
pub fn decode(json: &Json) -> Result<Value, Fault> {
    Ok(match json {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => Value::Float(n.as_f64().unwrap_or(0.0)),
        },
        Json::String(s) => Value::Str(Str::from(s.as_str())),
        Json::Array(items) => Value::Array(items.iter().map(decode).collect::<Result<Array, Fault>>()?),
        Json::Object(o) if o.is_empty() => Value::Object(Array::new()),
        Json::Object(o) => {
            if o.len() == 1 {
                if let Some(b) = o.get("$bytes").and_then(Json::as_str) {
                    return Ok(Value::Str(Str::from(BASE64.decode(b).map_err(|_| Fault::new("invalid $bytes"))?)));
                }
                if let Some(f) = o.get("$float").and_then(Json::as_str) {
                    return Ok(Value::Float(match f {
                        "INF" => f64::INFINITY,
                        "-INF" => f64::NEG_INFINITY,
                        "NAN" => f64::NAN,
                        _ => return Err(Fault::new("invalid $float")),
                    }));
                }
            }
            Value::Array(
                o.iter().map(|(k, v)| Ok((ArrayKey::normalize(Str::from(k.as_str())), decode(v)?))).collect::<Result<
                    Array,
                    Fault,
                >>(
                )?,
            )
        }
    })
}

/// An argument that must be an array (`{}` is empty).
pub fn decode_array(json: &Json) -> Result<Array, Fault> {
    match decode(json)? {
        Value::Array(a) => Ok(a),
        Value::Object(a) if a.is_empty() => Ok(Array::new()),
        other => Err(Fault::new(format!("expected an array, got {}", other.type_name()))),
    }
}

/// A result as JSON (`Codec::encode`).
pub fn encode(value: &Value) -> Json {
    match value {
        Value::Null => Json::Null,
        Value::Bool(b) => Json::Bool(*b),
        Value::Int(i) => Json::from(*i),
        Value::Float(f) => float_value(*f),
        Value::Str(s) => bytes_value(s.as_bytes()),
        Value::Array(a) => encode_array(a),
        Value::Object(a) => Json::Object(object(a)),
        Value::Ext(_) => Json::Null,
    }
}

fn object(a: &Array) -> Map<String, Json> {
    let mut m = Map::with_capacity(a.len());
    for (k, v) in a.iter() {
        let key = match k {
            KeyRef::Int(i) => i.to_string(),
            KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        m.insert(key, encode(v));
    }
    m
}

/// An array as PHP's `json_encode` shapes it: a list, or an object.
pub fn encode_array(a: &Array) -> Json {
    if a.is_list() {
        return Json::Array(a.values().map(encode).collect());
    }
    Json::Object(object(a))
}

/// The summary of a resource (`Values::summary`).
pub fn summary(r: &ResourceRef) -> Json {
    let b = r.base_snapshot();
    json!({ "$resource": r.name(), "id": b.id, "status": b.status, "message": b.message })
}

/// Cache entries (`Values::entries`): a list of `{"key", "entry"}`.
pub fn entries(e: &Entries) -> Json {
    Json::Array(
        e.iter()
            .map(|(k, v)| {
                let key = match k {
                    ArrayKey::Int(i) => Json::from(*i),
                    ArrayKey::Str(s) => bytes_value(s.as_bytes()),
                };
                let entry = match v {
                    Entry::Resource(r) => summary(r),
                    Entry::Count(c) => Json::String(c.clone()),
                };
                json!({ "key": key, "entry": entry })
            })
            .collect(),
    )
}

/// An exception (`Values::exception`).
pub fn exception(e: &Exception) -> Json {
    json!({
        "code": e.code,
        "message": e.message,
        "resourceName": e.resource_name,
        "resourceGroup": e.resource_group,
        "resourceId": e.resource_id(),
        "json": encode(&e.json()),
        "previous": e.previous.as_ref().map(|p| json!({ "class": p.php_class(), "message": p.message() })),
    })
}

/// A warning (`Values::warning`).
pub fn warning(w: &Warning) -> Json {
    json!({
        "resourceName": w.resource_name,
        "resourceGroup": w.resource_group,
        "resourceId": w.resource_id,
        "message": w.message,
    })
}

/// An error as the driver reports it.
pub fn error(e: &Error) -> Outcome {
    Outcome::err(e.php_class(), e.message())
}

/// A string argument of a PHP value.
pub fn text(v: &Value) -> String {
    match v {
        Value::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
        Value::Int(i) => i.to_string(),
        other => other.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default(),
    }
}
