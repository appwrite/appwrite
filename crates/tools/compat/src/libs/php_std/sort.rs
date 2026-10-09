//! `sort.*`: sorting and set functions (`php_std::sort`).

use php_std::sort::{self, SortFlags};
use php_std::types::{Array, EngineError, KeyRef, Value as Php};
use serde_json::Value;

use super::typed::{decode, decode_array, dump};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "sort.sort",
    "sort.rsort",
    "sort.asort",
    "sort.arsort",
    "sort.ksort",
    "sort.krsort",
    "sort.array_unique",
    "sort.array_diff",
    "sort.array_diff_key",
    "sort.array_intersect",
    "sort.in_array",
    "sort.array_search",
];

fn result(r: Result<Array, EngineError>) -> Outcome {
    match r {
        Ok(a) => Outcome::Ok(dump(&Php::Array(a))),
        Err(e) => Outcome::err(e.class, e.message),
    }
}

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    let flags =
        |default: i64| -> Result<SortFlags, Fault> { Ok(SortFlags::from_php(a.opt_i64("flags")?.unwrap_or(default))) };
    let others = || -> Result<Vec<Array>, Fault> { a.array("others")?.iter().map(decode_array).collect() };
    let sorted = |f: fn(&mut Array, SortFlags) -> Result<(), EngineError>| -> Result<Outcome, Fault> {
        let mut array = decode_array(a.value("a")?)?;
        Ok(result(f(&mut array, flags(0)?).map(|()| array)))
    };
    Ok(match op {
        "sort.sort" => sorted(sort::sort)?,
        "sort.rsort" => sorted(sort::rsort)?,
        "sort.asort" => sorted(sort::asort)?,
        "sort.arsort" => sorted(sort::arsort)?,
        "sort.ksort" | "sort.krsort" => {
            let mut array = decode_array(a.value("a")?)?;
            if op == "sort.ksort" {
                sort::ksort(&mut array, flags(0)?);
            } else {
                sort::krsort(&mut array, flags(0)?);
            }
            Outcome::Ok(dump(&Php::Array(array)))
        }
        "sort.array_unique" => result(sort::array_unique(&decode_array(a.value("a")?)?, flags(2)?)),
        "sort.array_diff" | "sort.array_diff_key" | "sort.array_intersect" => {
            let array = decode_array(a.value("a")?)?;
            let others = others()?;
            let refs: Vec<&Array> = others.iter().collect();
            match op {
                "sort.array_diff" => result(sort::array_diff(&array, &refs)),
                "sort.array_diff_key" => result(Ok(sort::array_diff_key(&array, &refs))),
                _ => result(sort::array_intersect(&array, &refs)),
            }
        }
        "sort.in_array" | "sort.array_search" => {
            let needle = decode(a.value("needle")?)?;
            let haystack = decode_array(a.value("haystack")?)?;
            let strict = a.opt_bool("strict")?.unwrap_or(false);
            if op == "sort.in_array" {
                Outcome::Ok(Value::Bool(sort::in_array(&needle, &haystack, strict)))
            } else {
                Outcome::Ok(match sort::array_search(&needle, &haystack, strict) {
                    Some(KeyRef::Int(i)) => Value::from(i),
                    Some(KeyRef::Str(s)) => bytes_value(s),
                    None => Value::Bool(false),
                })
            }
        }
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    })
}
