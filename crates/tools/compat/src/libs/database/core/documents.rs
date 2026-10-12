//! Area `documents` of `core`: document CRUD, finds, counts, sums, bulk
//! writes, upserts, increments, operators, permissions, the query cache and
//! custom document types (`database.*` operations on `Database`'s
//! `documents.rs`, `find.rs` and the document-type part of `config.rs`).
//!
//! Owner: the documents agent. See [`super::args`] for the shared API.

use serde_json::Value as Json;

use super::args::{R, fault};
use crate::adapter::Session;

/// Operations of this area.
pub const OPS: &[&str] = &[
    "database.count",
    "database.create_document",
    "database.create_documents",
    "database.increase_document_attribute",
    "database.decrease_document_attribute",
    "database.delete_document",
    "database.delete_documents",
    "database.find",
    "database.find_one",
    "database.foreach",
    "database.get_document",
    "database.iterate",
    "database.sum",
    "database.update_document",
    "database.update_documents",
    "database.upsert_document",
    "database.upsert_documents",
    "database.upsert_documents_with_increase",
    "database.convert_query",
    "database.convert_queries",
    "database.get_query_cache_field",
    "database.get_query_cache_key",
    "database.purge_cached_document",
    "database.purge_cached_queries",
    "database.skip_duplicates",
    "database.set_document_type",
    "database.get_document_type",
    "database.clear_document_type",
    "database.clear_all_document_types",
];

/// Runs one operation of this area.
pub async fn call(op: &str, _args: &Json, _session: &mut Session) -> R<Json> {
    fault(format!("database: `{op}` is not implemented yet"))
}
