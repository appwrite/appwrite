//! document.*, id.*, role.*, permission.*, datetime.*, authorization.*: Document, the helpers, DateTime and Authorization.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

/// Operations of this area (must match `spec.d/` and `ops/helpers.php`).
pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("database: unknown operation `{op}`")))
}
