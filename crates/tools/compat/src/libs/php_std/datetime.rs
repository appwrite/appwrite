//! `datetime.*`: Date and time: date()/DateTime::format, DateTime parsing and timezones, strtotime, microtime.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
