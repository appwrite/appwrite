//! `format.*`: number formatting and casts (`php_std::format`).
//!
//! Arguments carry PHP's parameter names; omitted optional arguments take
//! PHP's defaults. Values may be `{"$bytes": ...}` strings or
//! `{"$float": "INF" | "-INF" | "NAN"}`.

use php_std::format::{self, Arg, RoundingMode};
use php_std::value::Number;
use serde_json::Value;

use super::string::{done, opt_bytes};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};

pub const OPS: &[&str] = &[
    "format.sprintf",
    "format.vsprintf",
    "format.number_format",
    "format.round",
    "format.floor",
    "format.ceil",
    "format.abs",
    "format.fmod",
    "format.fdiv",
    "format.intdiv",
    "format.intval",
    "format.floatval",
    "format.boolval",
    "format.max",
    "format.min",
    "format.max_array",
    "format.min_array",
    "format.arith",
    "format.array_sum",
];

/// `$bytes` strings decoded up front, so [`Arg`]s can borrow them.
fn decode(values: &[Value]) -> Vec<Option<Vec<u8>>> {
    values.iter().map(|v| v.get("$bytes").and_then(|_| bytes(v))).collect()
}

fn arg<'a>(value: &'a Value, decoded: &'a Option<Vec<u8>>) -> Arg<'a> {
    if let Some(b) = decoded {
        return Arg::Str(b);
    }
    if value.get("$float").is_some()
        && let Some(f) = float(value)
    {
        return Arg::Float(f);
    }
    Arg::from(value)
}

fn number(value: &Value) -> Result<Number, Fault> {
    if let Some(i) = value.as_i64() {
        return Ok(Number::Int(i));
    }
    float(value).map(Number::Float).ok_or_else(|| Fault::new(format!("expected a number, got {value}")))
}

fn number_value(n: Number) -> Value {
    match n {
        Number::Int(i) => Value::from(i),
        Number::Float(f) => float_value(f),
    }
}

fn numbers(values: &[Value]) -> Result<Vec<Number>, Fault> {
    values.iter().map(number).collect()
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let ok = |v: Value| Ok(Outcome::Ok(v));
    match op {
        "format.sprintf" | "format.vsprintf" => {
            let format = a.bytes("format")?;
            let values = a.array("values")?;
            let decoded = decode(values);
            let list: Vec<Arg<'_>> = values.iter().zip(&decoded).map(|(v, d)| arg(v, d)).collect();
            let f = if op == "format.sprintf" { format::sprintf } else { format::vsprintf };
            done(f(&format, &list).map(|r| bytes_value(&r)))
        }
        "format.number_format" => {
            let point = opt_bytes(&a, "decimal_separator")?.unwrap_or_else(|| b".".to_vec());
            let sep = opt_bytes(&a, "thousands_separator")?.unwrap_or_else(|| b",".to_vec());
            let decimals = a.opt_i64("decimals")?.unwrap_or(0);
            ok(bytes_value(&format::number_format(number(a.value("num")?)?, decimals, &point, &sep)))
        }
        "format.round" => {
            let mode = a.opt_i64("mode")?.unwrap_or(1);
            let mode =
                RoundingMode::from_php(mode).ok_or_else(|| Fault::new("mode outside PHP_ROUND_* (a deviation)"))?;
            let precision = a.opt_i64("precision")?.unwrap_or(0);
            ok(float_value(format::round(number(a.value("num")?)?, precision, mode)))
        }
        "format.floor" => ok(float_value(format::floor(number(a.value("num")?)?))),
        "format.ceil" => ok(float_value(format::ceil(number(a.value("num")?)?))),
        "format.abs" => ok(number_value(format::abs(number(a.value("num")?)?))),
        "format.fmod" => ok(float_value(format::fmod(a.f64("num1")?, a.f64("num2")?))),
        "format.fdiv" => ok(float_value(format::fdiv(a.f64("num1")?, a.f64("num2")?))),
        "format.intdiv" => done(format::intdiv(a.i64("num1")?, a.i64("num2")?).map(Value::from)),
        "format.intval" | "format.floatval" | "format.boolval" => {
            let value = a.value("value")?;
            let decoded = value.get("$bytes").and_then(|_| bytes(value));
            let v = arg(value, &decoded);
            ok(match op {
                "format.intval" => Value::from(format::intval(v, a.opt_i64("base")?.unwrap_or(10))),
                "format.floatval" => float_value(format::floatval(v)),
                _ => Value::Bool(format::boolval(v)),
            })
        }
        "format.max" | "format.min" => {
            let values = numbers(a.array("values")?)?;
            let f = if op == "format.max" { format::max } else { format::min };
            f(&values).map(number_value).map(Outcome::Ok).ok_or_else(|| Fault::new("max/min need values"))
        }
        "format.max_array" | "format.min_array" => {
            let values = numbers(a.array("value")?)?;
            let f = if op == "format.max_array" { format::max_array } else { format::min_array };
            done(f(&values).map(number_value))
        }
        "format.arith" => {
            let (x, y) = (number(a.value("a")?)?, number(a.value("b")?)?);
            Ok(match a.str("op")? {
                "+" => Outcome::Ok(number_value(format::add(x, y))),
                "-" => Outcome::Ok(number_value(format::sub(x, y))),
                "*" => Outcome::Ok(number_value(format::mul(x, y))),
                "/" => match format::div(x, y) {
                    Some(n) => Outcome::Ok(number_value(n)),
                    None => Outcome::err("DivisionByZeroError", "Division by zero"),
                },
                other => return Err(Fault::new(format!("unknown operator {other}"))),
            })
        }
        "format.array_sum" => Ok(Outcome::Ok(number_value(format::array_sum(numbers(a.array("array")?)?)))),
        _ => Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }
}
