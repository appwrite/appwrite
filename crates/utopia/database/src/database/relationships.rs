//! Relationship attributes: their storage, junction collections and
//! definitions on both sides.
//!
//! PHP: `Trait\Relationships` (`skipRelationships()` and
//! `skipRelationshipsExistCheck()` are in `scopes.rs`).

use super::Database;
use crate::error::Result;
use crate::relationship::{Relationship, Update};

impl Database {
    /// Creates a relationship from `collection` (`createRelationship()`):
    /// the attribute on both sides of a two-way relationship, a junction
    /// collection for many-to-many, the indexes, the storage.
    pub async fn create_relationship(&self, collection: &str, relationship: Relationship) -> Result<Relationship> {
        let _ = (collection, relationship);
        todo!("schema area: createRelationship")
    }

    /// Renames a relationship's keys, or changes its two-way-ness or
    /// on-delete rule (`updateRelationship()`).
    pub async fn update_relationship(&self, collection: &str, key: &str, update: Update) -> Result<Relationship> {
        let _ = (collection, key, update);
        todo!("schema area: updateRelationship")
    }

    /// Deletes a relationship from both sides (`deleteRelationship()`).
    pub async fn delete_relationship(&self, collection: &str, key: &str) -> Result<()> {
        let _ = (collection, key);
        todo!("schema area: deleteRelationship")
    }
}
