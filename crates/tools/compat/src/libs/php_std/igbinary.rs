//! `igbinary.*`: igbinary_serialize / igbinary_unserialize (`php_std::igbinary`).

use bytes::Bytes;
use php_std::Value as Php;
use php_std::igbinary::{self, Error};
use serde_json::{Value, json};

use super::typed::{decode, dump};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &["igbinary.serialize", "igbinary.unserialize", "igbinary.roundtrip"];

/// `[value, warnings]` as PHP reports `igbinary_unserialize`: `false` for
/// empty input, `null` and a warning for invalid data.
fn unserialize(s: &Bytes) -> Result<(Php, Vec<String>), Outcome> {
    match igbinary::unserialize(s) {
        Ok(v) => Ok((v, Vec::new())),
        Err(Error::Empty) => Ok((Php::Bool(false), Vec::new())),
        Err(Error::Invalid(w)) => Ok((Php::Null, vec![w])),
        Err(e) => Err(Outcome::err(e.php_class(), e.to_string())),
    }
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "igbinary.serialize" => bytes_value(&igbinary::serialize(&decode(a.value("v")?)?)),
        "igbinary.unserialize" => match unserialize(&Bytes::from(a.bytes("s")?)) {
            Ok((value, warnings)) => json!({"value": dump(&value), "warnings": warnings}),
            Err(outcome) => return Ok(outcome),
        },
        "igbinary.roundtrip" => {
            let serialized = Bytes::from(igbinary::serialize(&decode(a.value("v")?)?));
            match unserialize(&serialized) {
                Ok((value, warnings)) => json!({
                    "serialized": bytes_value(&serialized),
                    "value": dump(&value),
                    "reserialized": bytes_value(&igbinary::serialize(&value)),
                    "warnings": warnings,
                }),
                Err(outcome) => return Ok(outcome),
            }
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}
