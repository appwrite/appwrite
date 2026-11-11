//! `yaml.*`: `yaml_parse` (`php_std::yaml`).

use serde_json::Value;

use super::typed;
use crate::adapter::{Args, Fault, OpResult, Outcome, Session};

pub const OPS: &[&str] = &["yaml.parse"];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    let a = Args(args);
    Ok(Outcome::Ok(match op {
        "yaml.parse" => match php_std::yaml::parse(&a.bytes("input")?) {
            Some(value) => typed::dump(&value),
            None => Value::Bool(false),
        },
        _ => return Err(Fault::new(format!("php-std: unknown operation `{op}`"))),
    }))
}
