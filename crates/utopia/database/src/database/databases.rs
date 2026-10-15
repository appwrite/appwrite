//! Databases: `create()`, `exists()`, `collectionExists()`, `list()`,
//! `update()`, `delete()`.
//!
//! PHP: `Trait\Databases`.

use super::Database;
use crate::document::Document;
use crate::error::Result;

impl Database {
    /// Creates the database `name` (this one by default) and its metadata
    /// collection (`create()`).
    pub async fn create(&self, name: Option<&str>) -> Result<bool> {
        let _ = name;
        todo!("cache area: Databases::create")
    }

    /// Whether the database exists (`exists()`).
    pub async fn exists(&self, name: Option<&str>) -> Result<bool> {
        let _ = name;
        todo!("cache area: Databases::exists")
    }

    /// Whether a collection's storage exists in the database
    /// (`collectionExists()`).
    pub async fn collection_exists(&self, collection: &str, database: Option<&str>) -> Result<bool> {
        let _ = (collection, database);
        todo!("cache area: Databases::collectionExists")
    }

    /// The databases (`list()`).
    pub async fn list(&self) -> Result<Vec<Document>> {
        todo!("cache area: Databases::list")
    }

    /// Renames a database (`update()`).
    pub async fn update(&self, database: &str, new: &str) -> Result<bool> {
        let _ = (database, new);
        todo!("cache area: Databases::update")
    }

    /// Deletes the database (this one by default) (`delete()`).
    pub async fn delete(&self, name: Option<&str>) -> Result<bool> {
        let _ = name;
        todo!("cache area: Databases::delete")
    }
}
