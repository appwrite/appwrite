//! What the engine holds for a collection.
//!
//! PHP: `getSchemaAttributes()`, `getSchemaIndexes()`, `getSchemaIndexType()`, `getColumnNames()` of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use utopia_query::schema::IndexType;

use crate::error::Result;
use crate::schema;

use super::Sql;

impl Sql {
    /// `getSchemaAttributes()`
    pub(crate) async fn schema_attributes(&self, collection: &str) -> Result<Vec<schema::Column>> {
        todo!("port `getSchemaAttributes()`")
    }

    /// `getSchemaIndexes()`
    pub(crate) async fn schema_indexes(&self, collection: &str) -> Result<Vec<schema::Index>> {
        todo!("port `getSchemaIndexes()`")
    }

    /// `getSchemaIndexType()`
    pub(crate) fn schema_index_type(&self, kind: IndexType) -> IndexType {
        todo!("port `getSchemaIndexType()`")
    }

    /// `getColumnNames()`
    pub(crate) async fn column_names(&self, collection: &str) -> Result<Vec<String>> {
        todo!("port `getColumnNames()`")
    }
}
