//! SQLite's FTS5 table names, shared by its indexes and its searches.
//!
//! PHP: `SQLite::getFulltextTableName()`, `getFulltextTablePrefix()`,
//! `getTenantSegment()`.

use super::Sql;
use crate::adapter::Adapter;
use crate::value::Value;

/// The suffix of every FTS5 table (`SQLite::FTS_TABLE_SUFFIX`).
pub(crate) const FTS_TABLE_SUFFIX: &str = "_fts";

impl Sql {
    /// `getTenantSegment()`: the tenant, filtered for an identifier.
    pub(crate) fn tenant_segment(&self) -> String {
        let tenant = match self.current_tenant() {
            Value::Null => String::new(),
            other => crate::value::to_text(&other),
        };
        self.filter(&tenant)
    }

    /// `getFulltextTablePrefix()`: the common prefix of the FTS5 tables of a
    /// collection, scoped to the tenant under shared tables.
    pub(crate) fn fulltext_table_prefix(&self, collection: &str) -> String {
        if self.shared_tables() {
            return format!("{}_{}_{}_", self.namespace(), self.tenant_segment(), self.filter(collection));
        }
        format!("{}_{}_", self.namespace(), self.filter(collection))
    }

    /// `getFulltextTableName()`: the FTS5 table of a set of attributes,
    /// keyed off their sorted names.
    pub(crate) fn fulltext_table_name(&self, collection: &str, attributes: &[String]) -> String {
        let mut names: Vec<String> = attributes.iter().map(|a| self.filter(a)).collect();
        names.sort_by(|a, b| php_std::string::strcmp(a.as_bytes(), b.as_bytes()).cmp(&0));
        let hash = php_std::string::hash(b"sha1", names.join("\0").as_bytes()).unwrap_or_default();
        format!("{}{}{FTS_TABLE_SUFFIX}", self.fulltext_table_prefix(collection), &hash[..16.min(hash.len())])
    }
}
