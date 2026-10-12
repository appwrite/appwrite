//! validator.*: the validators under `Utopia\Database\Validator`.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

/// Operations of this area (must match `spec.d/` and `ops/validators.php`).
pub const OPS: &[&str] = &[];

pub async fn call(op: &str, _args: &Value, _session: &mut Session) -> OpResult {
    Err(Fault::new(format!("database: unknown operation `{op}`")))
}
