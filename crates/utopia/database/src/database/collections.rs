//! Collections.
//!
//! PHP: `Trait\Collections`.

use super::Database;
use crate::collection::{Collection, Update};
use crate::document::Document;
use crate::error::Result;

impl Database {
    /// Creates a collection: its storage, then its definition in the
    /// metadata collection (`createCollection()`).
    ///
    /// Fails ([`crate::Kind::Duplicate`]) for an existing collection,
    /// ([`crate::Kind::Limit`]) beyond the adapter's limits, and as the
    /// definition validators do.
    pub async fn create_collection(&self, collection: Collection) -> Result<Collection> {
        let _ = collection;
        todo!("schema area: createCollection")
    }

    /// Changes a collection's permissions or document security
    /// (`updateCollection()`).
    pub async fn update_collection(&self, collection: &str, update: Update) -> Result<Collection> {
        let _ = (collection, update);
        todo!("schema area: updateCollection")
    }

    /// The collection's definition (`getCollection()`).
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing collection
    /// (`Collection not found`).
    pub async fn get_collection(&self, collection: &str) -> Result<Collection> {
        let _ = collection;
        todo!("schema area: getCollection")
    }

    /// The collection's definition, `None` when missing (`findCollection()`).
    pub async fn find_collection(&self, collection: &str) -> Result<Option<Collection>> {
        let _ = collection;
        todo!("schema area: findCollection")
    }

    /// The collections' definitions (`listCollections()`).
    pub async fn list_collections(&self, limit: i64, offset: i64) -> Result<Vec<Collection>> {
        let _ = (limit, offset);
        todo!("schema area: listCollections")
    }

    /// The size of a collection's data (`getSizeOfCollection()`).
    pub async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        let _ = collection;
        todo!("schema area: getSizeOfCollection")
    }

    /// The size of a collection on disk (`getSizeOfCollectionOnDisk()`).
    pub async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        let _ = collection;
        todo!("schema area: getSizeOfCollectionOnDisk")
    }

    /// Refreshes a collection's planner statistics (`analyzeCollection()`).
    pub async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        let _ = collection;
        todo!("schema area: analyzeCollection")
    }

    /// Deletes a collection, its relationships and its storage
    /// (`deleteCollection()`).
    pub async fn delete_collection(&self, collection: &str) -> Result<()> {
        let _ = collection;
        todo!("schema area: deleteCollection")
    }

    /// A metadata document as a [`Collection`] (`toCollection()`).
    pub(crate) fn to_collection(&self, definition: Document) -> Result<Collection> {
        let _ = definition;
        todo!("schema area: toCollection")
    }
}
