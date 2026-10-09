//! `filter.*`: `filter_var()` with the validation filters and
//! `FILTER_DEFAULT` (`php_std::filter`). Reports what `ops/filter.php`
//! reports: the result and the warning.

use php_std::filter::{self, Key, Options, Value as PhpValue};
use serde_json::{Map, Value};

use super::pcre::php_key;
use crate::adapter::{Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

pub const OPS: &[&str] = &["filter.filter_var"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    match op {
        "filter.filter_var" => {
            let value = args.get("value").map_or(Ok(PhpValue::Null), decode)?;
            let filter = match args.get("filter") {
                None | Some(Value::Null) => filter::FILTER_DEFAULT,
                Some(v) => v.as_i64().ok_or_else(|| Fault::new("argument `filter` must be an int"))?,
            };
            let options = match args.get("options") {
                None | Some(Value::Null) => Options::Flags(0),
                Some(Value::Object(o)) if o.is_empty() => {
                    return Ok(Outcome::err(
                        "TypeError",
                        "filter_var(): Argument #3 ($options) must be of type array|int, stdClass given",
                    ));
                }
                Some(v @ (Value::Array(_) | Value::Object(_))) => match decode(v)? {
                    PhpValue::Array(a) => Options::Array(a),
                    _ => return Err(Fault::new("argument `options` must be an int or an array")),
                },
                Some(v) => Options::Flags(v.as_i64().ok_or_else(|| Fault::new("argument `options` must be an int or an array"))?),
            };
            Ok(match filter::filter_var(&value, filter, &options) {
                Ok(f) => {
                    let mut o = Map::new();
                    o.insert("result".into(), encode(&f.value));
                    o.insert("warning".into(), f.warning.as_deref().map_or(Value::Null, bytes_value));
                    Outcome::Ok(Value::Object(o))
                }
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            })
        }
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}

/// A JSON argument as the PHP driver decodes it.
fn decode(v: &Value) -> Result<PhpValue, Fault> {
    Ok(match v {
        Value::Null => PhpValue::Null,
        Value::Bool(b) => PhpValue::Bool(*b),
        Value::Number(n) => match n.as_i64() {
            Some(i) => PhpValue::Int(i),
            None => PhpValue::Float(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => PhpValue::Str(s.as_bytes().to_vec()),
        Value::Array(list) => {
            PhpValue::Array(list.iter().enumerate().map(|(i, v)| Ok((Key::Int(i as i64), decode(v)?))).collect::<Result<_, Fault>>()?)
        }
        Value::Object(o) => {
            if o.is_empty() {
                PhpValue::Object
            } else if let Some(b) = o.get("$bytes").filter(|_| o.len() == 1) {
                PhpValue::Str(bytes(&Value::Object(Map::from_iter([("$bytes".to_owned(), b.clone())])))
                    .ok_or_else(|| Fault::new("invalid $bytes"))?)
            } else if o.len() == 1 && o.contains_key("$float") {
                PhpValue::Float(float(v).ok_or_else(|| Fault::new("invalid $float"))?)
            } else {
                PhpValue::Array(o.iter().map(|(k, v)| Ok((php_key(k), decode(v)?))).collect::<Result<_, Fault>>()?)
            }
        }
    })
}

/// A PHP value as the PHP driver encodes it.
fn encode(v: &PhpValue) -> Value {
    match v {
        PhpValue::Null => Value::Null,
        PhpValue::Bool(b) => Value::Bool(*b),
        PhpValue::Int(i) => Value::from(*i),
        PhpValue::Float(f) => float_value(*f),
        PhpValue::Str(s) => bytes_value(s),
        PhpValue::Array(items) => {
            if items.iter().enumerate().all(|(i, (k, _))| *k == Key::Int(i as i64)) {
                Value::Array(items.iter().map(|(_, v)| encode(v)).collect())
            } else {
                Value::Object(
                    items
                        .iter()
                        .map(|(k, v)| {
                            let key = match k {
                                Key::Int(i) => i.to_string(),
                                Key::Str(s) => String::from_utf8_lossy(s).into_owned(),
                            };
                            (key, encode(v))
                        })
                        .collect(),
                )
            }
        }
        PhpValue::Object => Value::Object(Map::new()),
    }
}
