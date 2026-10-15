//! Indexes (and SQLite's FTS5 tables).
//!
//! PHP: `createIndex()`, `deleteIndex()`, `renameIndex()` of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use utopia_query::schema::ColumnType;

use crate::error::Result;
use crate::index::Index;

use super::Sql;

impl Sql {
    /// `createIndex()`
    pub(crate) async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        todo!("port `createIndex()`")
    }

    /// `deleteIndex()`
    pub(crate) async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        todo!("port `deleteIndex()`")
    }

    /// `renameIndex()`
    pub(crate) async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        todo!("port `renameIndex()`")
    }
}
