//! Databases (schemas) and collections.
//!
//! Area: schema.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters
// unused until the schema area is implemented.
#![allow(unused_variables)]

use super::Database;
use crate::document::Document;
use crate::error::Result;

impl Database {
    /// Creates the database (schema) `database` (the current one by
    /// default) and its `_metadata` collection; triggers
    /// [`Database::EVENT_DATABASE_CREATE`].
    ///
    /// PHP: `Database::create()`.
    pub async fn create(&self, database: Option<&str>) -> Result<bool> {
        todo!("schema")
    }

    /// Whether the database (the current one by default) exists, or, with
    /// `collection`, whether that collection does.
    ///
    /// PHP: `Database::exists()`.
    pub async fn exists(&self, database: Option<&str>, collection: Option<&str>) -> Result<bool> {
        todo!("schema")
    }

    /// The databases; triggers [`Database::EVENT_DATABASE_LIST`].
    ///
    /// PHP: `Database::list()`.
    pub async fn list(&self) -> Result<Vec<Document>> {
        todo!("schema")
    }

    /// Drops the database (the current one by default) and flushes the
    /// cache; triggers [`Database::EVENT_DATABASE_DELETE`].
    ///
    /// PHP: `Database::delete()`.
    pub async fn delete(&self, database: Option<&str>) -> Result<bool> {
        todo!("schema")
    }

    /// Creates collection `id` with its attributes and indexes (validated
    /// against the adapter's limits), permissions (`None`: any user can
    /// create) and document security; triggers
    /// [`Database::EVENT_COLLECTION_CREATE`].
    ///
    /// PHP: `Database::createCollection()`. Validators: `Index`,
    /// `IndexDependency`, `Permissions` (`Permissions::new(..).is_valid(..)`).
    pub async fn create_collection(
        &self,
        id: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
        permissions: Option<Vec<String>>,
        document_security: bool,
    ) -> Result<Document> {
        todo!("schema")
    }

    /// Updates a collection's permissions and document security; triggers
    /// [`Database::EVENT_COLLECTION_UPDATE`].
    ///
    /// PHP: `Database::updateCollection()`.
    pub async fn update_collection(
        &self,
        id: &str,
        permissions: Vec<String>,
        document_security: bool,
    ) -> Result<Document> {
        todo!("schema")
    }

    /// The collection document `id` (empty when it does not exist, or
    /// belongs to another tenant); triggers
    /// [`Database::EVENT_COLLECTION_READ`].
    ///
    /// PHP: `Database::getCollection()`.
    pub async fn get_collection(&self, id: &str) -> Result<Document> {
        todo!("schema")
    }

    /// A page of collections; triggers [`Database::EVENT_COLLECTION_LIST`].
    ///
    /// PHP: `Database::listCollections($limit = 25, $offset = 0)`.
    pub async fn list_collections(&self, limit: i64, offset: i64) -> Result<Vec<Document>> {
        todo!("schema")
    }

    /// The size of a collection's data, in bytes.
    ///
    /// PHP: `Database::getSizeOfCollection()`.
    pub async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        todo!("schema")
    }

    /// The size of a collection on disk, in bytes.
    ///
    /// PHP: `Database::getSizeOfCollectionOnDisk()`.
    pub async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        todo!("schema")
    }

    /// Refreshes a collection's statistics.
    ///
    /// PHP: `Database::analyzeCollection()`.
    pub async fn analyze_collection(&self, collection: &str) -> Result<bool> {
        todo!("schema")
    }

    /// Deletes a collection, its relationships and its metadata; triggers
    /// [`Database::EVENT_COLLECTION_DELETE`].
    ///
    /// PHP: `Database::deleteCollection()`.
    pub async fn delete_collection(&self, id: &str) -> Result<bool> {
        todo!("schema")
    }
}
