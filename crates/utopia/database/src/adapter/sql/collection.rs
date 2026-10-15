//! Collections: create, delete, analyze, sizes.
//!
//! PHP: `createCollection()`, `deleteCollection()`, `analyzeCollection()`, `getSizeOfCollection()`, `getSizeOfCollectionOnDisk()` of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use crate::attribute::Attribute;
use crate::error::Result;
use crate::index::Index;

use super::Sql;

impl Sql {
    /// `createCollection()`
    pub(crate) async fn create_collection(
        &self,
        collection: &str,
        attributes: &[Attribute],
        indexes: &[Index],
    ) -> Result<bool> {
        todo!("port `createCollection()`")
    }

    /// `deleteCollection()`
    pub(crate) async fn delete_collection(&self, collection: &str) -> Result<bool> {
        todo!("port `deleteCollection()`")
    }

    /// `analyzeCollection()`
    pub(crate) async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        todo!("port `analyzeCollection()`")
    }

    /// `getSizeOfCollection()`
    pub(crate) async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        todo!("port `getSizeOfCollection()`")
    }

    /// `getSizeOfCollectionOnDisk()`
    pub(crate) async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        todo!("port `getSizeOfCollectionOnDisk()`")
    }
}
