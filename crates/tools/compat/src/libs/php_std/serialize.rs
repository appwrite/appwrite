//! `serialize.*`: serialize/unserialize and var_export formats.

use php_std::serialize::{self, Error, Options};
use php_std::zval::Zval;
use serde_json::{Value, json};

use super::json::{wire, zval};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "serialize.serialize",
    "serialize.serialize_value",
    "serialize.var_export",
    "serialize.var_export_value",
    "serialize.unserialize",
    "serialize.roundtrip",
];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "serialize.serialize" => bytes_value(&serialize::serialize(&zval(a.value("v")?)?)),
        "serialize.serialize_value" => bytes_value(&serialize::serialize(a.value("v")?)),
        "serialize.var_export" => bytes_value(&serialize::var_export(&zval(a.value("v")?)?)),
        "serialize.var_export_value" => bytes_value(&serialize::var_export(a.value("v")?)),
        "serialize.unserialize" | "serialize.roundtrip" => {
            let options = match a.opt("options") {
                Some(o) => Options {
                    max_depth: o.get("max_depth").and_then(Value::as_i64).unwrap_or(Options::default().max_depth),
                },
                None => Options::default(),
            };
            let (value, warnings) = match serialize::unserialize(&a.bytes("s")?, &options) {
                Ok(u) => (u.value, u.warnings),
                Err(Error::Failed { warnings, .. }) => (Zval::Bool(false), warnings),
                Err(e) => return Ok(Outcome::err(e.php_class(), e.to_string())),
            };
            if op == "serialize.roundtrip" {
                json!({"serialized": bytes_value(&serialize::serialize(&value)), "warnings": warnings})
            } else {
                json!({"value": wire(&value)?, "warnings": warnings})
            }
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}
