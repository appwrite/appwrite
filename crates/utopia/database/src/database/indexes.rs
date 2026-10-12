//! Indexes: create, rename, delete.
//!
//! Area: schema.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters
// unused until the schema area is implemented.
#![allow(unused_variables)]

use super::Database;
use crate::error::Result;
use crate::value::Value;

impl Database {
    /// Creates index `id` of type `kind` over `attributes`, with optional
    /// per-attribute `lengths` and `orders` (`null` entries for none) and,
    /// for TTL indexes, `ttl` seconds; validates it against the collection
    /// and the adapter's capabilities, then persists the metadata (dropping
    /// the index when that fails); triggers
    /// [`Database::EVENT_INDEX_CREATE`].
    ///
    /// PHP: `Database::createIndex($collection, $id, $type, $attributes,
    /// $lengths = [], $orders = [], $ttl = 1)`. Validator: `Index`.
    #[allow(clippy::too_many_arguments)]
    pub async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: Vec<String>,
        lengths: Vec<Value>,
        orders: Vec<Value>,
        ttl: i64,
    ) -> Result<bool> {
        todo!("schema")
    }

    /// Renames an index; triggers [`Database::EVENT_INDEX_RENAME`].
    ///
    /// PHP: `Database::renameIndex()`.
    pub async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        todo!("schema")
    }

    /// Deletes an index; triggers [`Database::EVENT_INDEX_DELETE`].
    ///
    /// PHP: `Database::deleteIndex()`.
    pub async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        todo!("schema")
    }
}
