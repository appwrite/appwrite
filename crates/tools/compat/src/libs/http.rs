//! Compat adapter for `http`: maps `tests/compat/http/spec.json` operations onto the Rust crate.
//!
//! Not converted yet: no operations. See crates/CONVERSION.md §5.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("http: unknown operation `{op}`")))
}
