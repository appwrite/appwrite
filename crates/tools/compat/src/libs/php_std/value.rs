//! `value.*`: type juggling (`php_std::value`).

use php_std::value::{self, Number};
use serde_json::Value;

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, float_value};

pub const OPS: &[&str] = &[
    "value.is_numeric",
    "value.to_number",
    "value.to_string",
    "value.truthy",
    "value.empty",
    "value.is_array",
    "value.loose_eq",
];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let v = || a.value("v");
    Ok(Outcome::Ok(match op {
        "value.is_numeric" => Value::Bool(value::numeric(v()?).is_some()),
        "value.to_number" => match value::numeric(v()?) {
            Some(Number::Int(i)) => Value::from(i),
            Some(Number::Float(f)) => float_value(f),
            None => Value::Null,
        },
        "value.to_string" => value::to_string(v()?).map(Value::String).unwrap_or(Value::Null),
        "value.truthy" => Value::Bool(value::truthy(v()?)),
        "value.empty" => Value::Bool(value::empty(v()?)),
        "value.is_array" => Value::Bool(value::is_array(v()?)),
        "value.loose_eq" => Value::Bool(value::loose_eq(a.value("a")?, a.value("b")?)),
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}
