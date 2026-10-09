//! `types.*`: the PHP value model (`php_std::types`).

use php_std::types::{ArrayKey, KeyRef, Str, Value};
use serde_json::{Value as Json, json};

use super::typed::{decode, decode_array, dump};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};

pub const OPS: &[&str] =
    &["types.value", "types.from_json", "types.to_json", "types.juggle", "types.compare", "types.array_ops"];

/// `$a[$k]`: an integer, or a string normalised like PHP's offsets.
fn key(k: Option<&Json>) -> Result<ArrayKey, Fault> {
    Ok(match k {
        None | Some(Json::Null) => ArrayKey::Str(Str::EMPTY),
        Some(Json::Number(n)) => ArrayKey::Int(n.as_i64().ok_or_else(|| Fault::new("invalid key"))?),
        Some(other) => ArrayKey::normalize(Str::from(bytes(other).ok_or_else(|| Fault::new("invalid key"))?)),
    })
}

fn key_json(k: KeyRef<'_>) -> Json {
    match k {
        KeyRef::Int(i) => Json::from(i),
        KeyRef::Str(s) => bytes_value(s),
    }
}

pub async fn call(op: &str, args: &Json, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "types.value" => dump(&decode(a.value("v")?)?),
        "types.from_json" => dump(&Value::from_json(a.value("v")?)),
        "types.to_json" => decode(a.value("v")?)?.to_json(),
        "types.juggle" => {
            let v = decode(a.value("v")?)?;
            let string = match v.to_php_string() {
                Ok(s) => bytes_value(&s),
                Err(e) => json!({"$error": {"class": e.class, "message": e.message}}),
            };
            json!({
                "type": v.type_name(),
                "truthy": v.truthy(),
                "string": string,
                "double": dump(&Value::Float(v.to_double())),
            })
        }
        "types.compare" => {
            let (x, y) = (decode(a.value("a")?)?, decode(a.value("b")?)?);
            json!({
                "loose": x.loose_eq(&y),
                "strict": x.strict_eq(&y),
                "cmp": x.compare(&y),
                "rcmp": y.compare(&x),
                "lt": x.compare(&y) < 0,
                "gt": y.compare(&x) < 0,
            })
        }
        "types.array_ops" => {
            let mut array = decode_array(a.value("a")?)?;
            let mut results = Vec::new();
            for step in a.array("ops")? {
                let k = step.get("k");
                let v = || -> Result<Value, Fault> { decode(step.get("v").unwrap_or(&Json::Null)) };
                results.push(match step.get("op").and_then(Json::as_str) {
                    Some("set") => {
                        array.set(key(k)?, v()?);
                        Json::Null
                    }
                    Some("push") => match array.push(v()?) {
                        Ok(()) => array.iter().next_back().map(|(k, _)| key_json(k)).unwrap_or(Json::Null),
                        Err(e) => json!({"$error": e.to_string()}),
                    },
                    Some("unset") => {
                        array.remove(&key(k)?);
                        Json::Null
                    }
                    Some("get") => dump(array.get(&key(k)?).unwrap_or(&Value::Null)),
                    Some("exists") => Json::Bool(array.contains_key(&key(k)?)),
                    _ => return Err(Fault::new("unknown step")),
                });
            }
            json!({"array": dump(&Value::Array(array.clone())), "results": results, "list": array.is_list()})
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}
