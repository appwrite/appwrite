//! `json.*`: json_encode (flags) and json_decode (validity, depth, objects vs arrays, error codes).

use php_std::json::{self, Error, Flags};
use serde_json::{Value, json};

use super::typed::{decode, dump, wire};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

pub const OPS: &[&str] = &[
    "json.encode",
    "json.encode_throw",
    "json.encode_value",
    "json.decode",
    "json.decode_throw",
    "json.decode_value",
    "json.decode_typed",
    "json.validate",
];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let flags = || -> Result<Flags, Fault> { Ok(Flags::from_php(a.opt_i64("flags")?.unwrap_or(0))) };
    let depth = || -> Result<i64, Fault> { Ok(a.opt_i64("depth")?.unwrap_or(json::DEFAULT_DEPTH)) };
    Ok(Outcome::Ok(match op {
        "json.encode" => encoded(json::encode(&decode(a.value("v")?)?, flags()?, depth()?))?,
        "json.encode_value" => {
            encoded(json::encode(&php_std::Value::<php_std::Never>::from_json(a.value("v")?), flags()?, depth()?))?
        }
        "json.encode_throw" => {
            match json::encode(&decode(a.value("v")?)?, flags()? | Flags::THROW_ON_ERROR, depth()?) {
                Ok(s) | Err(Error::Partial { json: s, .. }) => Value::String(s),
                Err(e) => return Ok(Outcome::err(e.php_class(), e.message())),
            }
        }
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
        "json.decode_typed" => {
            let assoc = a.opt_bool("assoc")?;
            match json::decode(&a.bytes("s")?, assoc, depth()?, flags()?) {
                Ok(v) => json!({"value": dump(&v), "error": 0}),
                Err(Error::Json(code)) => json!({"value": null, "error": code.code()}),
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
