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
    "value.sort_strings",
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
        "value.sort_strings" => {
            let mut values: Vec<String> = a
                .array("v")?
                .iter()
                .map(|v| value::to_string(v).ok_or_else(|| Fault::new("not a scalar")))
                .collect::<Result<_, _>>()?;
            value::sort_strings(&mut values);
            Value::Array(values.into_iter().map(Value::String).collect())
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}
