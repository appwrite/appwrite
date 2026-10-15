//! Relationship storage.
//!
//! PHP: `createRelationship()`, `updateRelationship()`, `deleteRelationship()`, `getJunctionName()` of `SQL` and `SQLite`.

#![allow(unused_variables, clippy::unused_async)]

use crate::error::Result;
use crate::relationship::{self, Relationship, RelationshipSide};

use super::Sql;

impl Sql {
    /// `createRelationship()`
    pub(crate) async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        todo!("port `createRelationship()`")
    }

    /// `updateRelationship()`
    pub(crate) async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        todo!("port `updateRelationship()`")
    }

    /// `deleteRelationship()`
    pub(crate) async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        todo!("port `deleteRelationship()`")
    }
}
