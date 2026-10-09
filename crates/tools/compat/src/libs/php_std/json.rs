//! `json.*`: json_encode (flags) and json_decode (validity, depth, objects vs arrays, error codes).

use php_std::json::{self, Error, Flags};
use php_std::zval::{Key, Object, Zval};
use serde_json::{Map, Value, json};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

pub const OPS: &[&str] = &[
    "json.encode",
    "json.encode_throw",
    "json.encode_value",
    "json.decode",
    "json.decode_throw",
    "json.decode_value",
    "json.validate",
];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let flags = || -> Result<Flags, Fault> { Ok(Flags::from_php(a.opt_i64("flags")?.unwrap_or(0))) };
    let depth = || -> Result<i64, Fault> { Ok(a.opt_i64("depth")?.unwrap_or(json::DEFAULT_DEPTH)) };
    Ok(Outcome::Ok(match op {
        "json.encode" => encoded(json::encode(&zval(a.value("v")?)?, flags()?, depth()?))?,
        "json.encode_value" => encoded(json::encode(a.value("v")?, flags()?, depth()?))?,
        "json.encode_throw" => match json::encode(&zval(a.value("v")?)?, flags()? | Flags::THROW_ON_ERROR, depth()?) {
            Ok(s) | Err(Error::Partial { json: s, .. }) => Value::String(s),
            Err(e) => return Ok(Outcome::err(e.php_class(), e.message())),
        },
        "json.decode" => {
            let assoc = a.opt_bool("assoc")?;
            match json::decode(&a.bytes("s")?, assoc, depth()?, flags()?) {
                Ok(v) => json!({"value": wire(&v)?, "error": 0, "message": json::ErrorCode::None.message()}),
                Err(Error::Json(code)) => json!({"value": null, "error": code.code(), "message": code.message()}),
                Err(e) => return Ok(Outcome::err(e.php_class(), e.message())),
            }
        }
        "json.decode_throw" => {
            let assoc = a.opt_bool("assoc")?;
            match json::decode(&a.bytes("s")?, assoc, depth()?, flags()? | Flags::THROW_ON_ERROR) {
                Ok(v) => wire(&v)?,
                Err(e) => return Ok(Outcome::err(e.php_class(), e.message())),
            }
        }
        "json.decode_value" => match json::decode_value(&a.bytes("s")?, depth()?, flags()?) {
            Ok(v) => json!({"value": v, "error": 0, "message": json::ErrorCode::None.message()}),
            Err(Error::Json(code)) => json!({"value": null, "error": code.code(), "message": code.message()}),
            Err(e) => return Ok(Outcome::err(e.php_class(), e.message())),
        },
        "json.validate" => match json::validate(&a.bytes("s")?, depth()?, flags()?) {
            Ok(()) => json!({"valid": true, "error": 0, "message": json::ErrorCode::None.message()}),
            Err(Error::Json(code)) => json!({"valid": false, "error": code.code(), "message": code.message()}),
            Err(e) => return Ok(Outcome::err(e.php_class(), e.message())),
        },
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}

/// `['json' => json_encode(...), 'error' => json_last_error(), 'message' => json_last_error_msg()]`.
fn encoded(result: Result<String, Error>) -> Result<Value, Fault> {
    Ok(match result {
        Ok(s) => json!({"json": s, "error": 0, "message": json::ErrorCode::None.message()}),
        Err(Error::Partial { json, code }) => json!({"json": json, "error": code.code(), "message": code.message()}),
        Err(Error::Json(code)) => json!({"json": false, "error": code.code(), "message": code.message()}),
        Err(e) => return Err(Fault::new(format!("unexpected error {e:?}"))),
    })
}

/// A PHP value from the wire, as `Tests\Compat\Codec::decode` builds it:
/// objects are arrays except empty ones (`stdClass`), `{"$bytes"}` is a
/// binary string, `{"$float"}` a non-finite float.
pub(super) fn zval(value: &Value) -> Result<Zval, Fault> {
    Ok(match value {
        Value::Object(o) if o.is_empty() => Zval::Object(Object::new()),
        Value::Object(o) if o.len() == 1 && o.get("$bytes").is_some_and(Value::is_string) => {
            Zval::String(bytes(value).ok_or_else(|| Fault::new("invalid $bytes"))?)
        }
        Value::Object(o) if o.len() == 1 && o.get("$float").is_some_and(Value::is_string) => {
            Zval::Float(float(value).ok_or_else(|| Fault::new("invalid $float"))?)
        }
        Value::Object(o) => Zval::Array(
            o.iter().map(|(k, v)| Ok((Key::from_bytes(k.as_bytes()), zval(v)?))).collect::<Result<_, Fault>>()?,
        ),
        Value::Array(a) => Zval::Array(a.iter().map(zval).collect::<Result<_, Fault>>()?),
        other => Zval::from(other),
    })
}

/// A PHP value onto the wire, as `Tests\Compat\Codec::encode` writes it.
pub(super) fn wire(value: &Zval) -> Result<Value, Fault> {
    Ok(match value {
        Zval::String(s) => bytes_value(s),
        Zval::Float(f) => float_value(*f),
        Zval::Array(a) if a.is_list() => Value::Array(a.iter().map(|(_, v)| wire(v)).collect::<Result<_, _>>()?),
        Zval::Array(a) => {
            let mut m = Map::new();
            for (k, v) in a.iter() {
                let key = match k {
                    Key::Int(i) => i.to_string(),
                    Key::Str(s) => String::from_utf8(s.clone()).map_err(|_| Fault::new("binary array key"))?,
                };
                m.insert(key, wire(v)?);
            }
            Value::Object(m)
        }
        Zval::Object(o) => {
            let mut m = Map::new();
            // The driver's json_encode skips properties named "\0..." (mangled names).
            for (k, v) in o.iter().filter(|(k, _)| k.first() != Some(&0)) {
                let key = String::from_utf8(k.clone()).map_err(|_| Fault::new("binary property name"))?;
                m.insert(key, wire(v)?);
            }
            Value::Object(m)
        }
        Zval::Null => Value::Null,
        Zval::Bool(b) => Value::Bool(*b),
        Zval::Int(i) => Value::from(*i),
    })
}
