//! `serialize.*`: serialize/unserialize and var_export formats.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("php-std: unknown operation `{op}`")))
}
