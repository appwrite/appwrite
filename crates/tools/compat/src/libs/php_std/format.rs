//! `format.*`: Number formatting and casts: sprintf/printf formats, number_format, round, intval/floatval/(int) casts, intdiv, fmod.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
