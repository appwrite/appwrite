//! Area `core`: `Utopia\\Database\\Database` itself (`db.*`, `database.*`,
//! `db.authorization.*`), the twin of `tests/compat/database/ops/core.php`.
//! `tests/compat/README.md` (`database`, the core vocabulary) documents
//! every operation.
//!
//! The operations are split by the `Database` area that implements them,
//! one file each, so that they can be worked on independently:
//!
//! | File | Operations |
//! |---|---|
//! | [`schema`] | the session, authorization, settings, scopes, filters, cache, collections, attributes, indexes, transactions: everything not below |
//! | [`documents`] | document CRUD, finds, counts, sums, bulk writes, upserts, increments, query cache, document types |
//! | [`relationships`] | relationship attributes and the relationship scopes |
//!
//! [`args`] is what they share: databases, argument decoding, result
//! encoding, callbacks and fixtures.

use serde_json::Value as Json;

use crate::adapter::{Fault, OpResult, Session};

pub mod args;
mod documents;
mod relationships;
mod schema;

/// Every operation of this area (must match `spec.d/core.json`).
pub const OPS: &[&str] = &[
    "database.add_filter",
    "database.analyze_collection",
    "database.before",
    "database.casting",
    "database.check_attribute",
    "database.clear_all_document_types",
    "database.clear_document_type",
    "database.clear_timeout",
    "database.convert_queries",
    "database.convert_query",
    "database.count",
    "database.create",
    "database.create_attribute",
    "database.create_attributes",
    "database.create_collection",
    "database.create_document",
    "database.create_documents",
    "database.create_index",
    "database.create_relationship",
    "database.decode",
    "database.decrease_document_attribute",
    "database.delete",
    "database.delete_attribute",
    "database.delete_collection",
    "database.delete_document",
    "database.delete_documents",
    "database.delete_index",
    "database.delete_relationship",
    "database.disable_filters",
    "database.disable_validation",
    "database.enable_filters",
    "database.enable_locks",
    "database.enable_validation",
    "database.encode",
    "database.exists",
    "database.find",
    "database.find_one",
    "database.foreach",
    "database.get_adapter",
    "database.get_authorization",
    "database.get_cache",
    "database.get_cache_base_keys",
    "database.get_cache_keys",
    "database.get_cache_name",
    "database.get_collection",
    "database.get_connection_id",
    "database.get_database",
    "database.get_document",
    "database.get_document_type",
    "database.get_drop_unknown_attributes",
    "database.get_global_collections",
    "database.get_id_attribute_type",
    "database.get_instance_filters",
    "database.get_internal_attributes",
    "database.get_keywords",
    "database.get_limit_for_attributes",
    "database.get_limit_for_indexes",
    "database.get_max_query_values",
    "database.get_metadata",
    "database.get_namespace",
    "database.get_preserve_dates",
    "database.get_preserve_sequence",
    "database.get_query_cache_field",
    "database.get_query_cache_key",
    "database.get_schema_attributes",
    "database.get_schema_indexes",
    "database.get_shared_tables",
    "database.get_size_of_collection",
    "database.get_size_of_collection_on_disk",
    "database.get_tenant",
    "database.get_tenant_per_document",
    "database.increase_document_attribute",
    "database.is_migrating",
    "database.iterate",
    "database.list",
    "database.list_collections",
    "database.on",
    "database.ping",
    "database.purge_cached_collection",
    "database.purge_cached_document",
    "database.purge_cached_queries",
    "database.reconnect",
    "database.rename_attribute",
    "database.rename_index",
    "database.reset_global_collections",
    "database.reset_metadata",
    "database.set_authorization",
    "database.set_cache",
    "database.set_cache_name",
    "database.set_database",
    "database.set_document_type",
    "database.set_drop_unknown_attributes",
    "database.set_global_collections",
    "database.set_max_query_values",
    "database.set_metadata",
    "database.set_migrating",
    "database.set_namespace",
    "database.set_preserve_dates",
    "database.set_preserve_sequence",
    "database.set_shared_tables",
    "database.set_tenant",
    "database.set_tenant_per_document",
    "database.set_timeout",
    "database.silent",
    "database.skip_duplicates",
    "database.skip_filters",
    "database.skip_relationships",
    "database.skip_relationships_exist_check",
    "database.skip_validation",
    "database.sum",
    "database.update_attribute",
    "database.update_attribute_default",
    "database.update_attribute_filters",
    "database.update_attribute_format",
    "database.update_attribute_format_options",
    "database.update_attribute_required",
    "database.update_collection",
    "database.update_document",
    "database.update_documents",
    "database.update_relationship",
    "database.upsert_document",
    "database.upsert_documents",
    "database.upsert_documents_with_increase",
    "database.with_cache",
    "database.with_preserve_dates",
    "database.with_preserve_sequence",
    "database.with_request_timestamp",
    "database.with_tenant",
    "database.with_transaction",
    "db.adapter",
    "db.add_format",
    "db.authorization.add_role",
    "db.authorization.clean_roles",
    "db.authorization.disable",
    "db.authorization.enable",
    "db.authorization.get_description",
    "db.authorization.get_roles",
    "db.authorization.get_status",
    "db.authorization.get_type",
    "db.authorization.has_role",
    "db.authorization.is_array",
    "db.authorization.is_valid",
    "db.authorization.remove_role",
    "db.authorization.reset",
    "db.authorization.set_default_status",
    "db.authorization.set_status",
    "db.authorization.skip",
    "db.log",
    "db.open",
    "db.select",
    "db.sql",
];

/// Runs one operation: in the area that owns it.
pub async fn call(op: &str, json: &Json, session: &mut Session) -> OpResult {
    args::enter(session);
    let result = if documents::OPS.contains(&op) {
        documents::call(op, json, session).await
    } else if relationships::OPS.contains(&op) {
        relationships::call(op, json, session).await
    } else if schema::OPS.contains(&op) {
        schema::call(op, json, session).await
    } else {
        return Err(Fault::new(format!("database: unknown operation `{op}`")));
    };
    args::outcome(result)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Every operation belongs to exactly one area.
    #[test]
    fn areas_partition_the_operations() {
        for op in OPS {
            let owners = [schema::OPS, documents::OPS, relationships::OPS].iter().filter(|a| a.contains(op)).count();
            assert_eq!(owners, 1, "{op}");
        }
        assert_eq!(schema::OPS.len() + documents::OPS.len() + relationships::OPS.len(), OPS.len());
    }
}
