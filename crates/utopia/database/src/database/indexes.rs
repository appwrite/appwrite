//! Indexes.
//!
//! PHP: `Trait\Indexes`.

use super::Database;
use crate::error::Result;
use crate::index::Index;

impl Database {
    /// Adds an index to a collection (`createIndex()`).
    pub async fn create_index(&self, collection: &str, index: Index) -> Result<Index> {
        let _ = (collection, index);
        todo!("schema area: createIndex")
    }

    /// Adds indexes to a collection at once (`createIndexes()`).
    pub async fn create_indexes(&self, collection: &str, indexes: Vec<Index>) -> Result<Vec<Index>> {
        let _ = (collection, indexes);
        todo!("schema area: createIndexes")
    }

    /// Renames an index (`renameIndex()`).
    pub async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<()> {
        let _ = (collection, old, new);
        todo!("schema area: renameIndex")
    }

    /// Removes an index (`deleteIndex()`).
    pub async fn delete_index(&self, collection: &str, key: &str) -> Result<()> {
        let _ = (collection, key);
        todo!("schema area: deleteIndex")
    }
}
