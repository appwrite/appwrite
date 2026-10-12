//! query.*, operator.*: Query and Operator.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

/// Operations of this area (must match `spec.d/` and `ops/values.php`).
pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("database: unknown operation `{op}`")))
}
