//! Area `relationships` of `core`: relationship attributes and the
//! relationship scopes (`database.*` operations on `Database`'s
//! `relationships.rs` and the relationship scopes of `config.rs`).
//!
//! Owner: the relationships agent. See [`super::args`] for the shared API.

use serde_json::Value as Json;

use super::args::{R, fault};
use crate::adapter::Session;

/// Operations of this area.
pub const OPS: &[&str] = &[
    "database.create_relationship",
    "database.update_relationship",
    "database.delete_relationship",
    "database.skip_relationships",
    "database.skip_relationships_exist_check",
];

/// Runs one operation of this area.
pub async fn call(op: &str, _args: &Json, _session: &mut Session) -> R<Json> {
    fault(format!("database: `{op}` is not implemented yet"))
}
