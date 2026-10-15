//! Upserts.
//!
//! PHP: `upsertDocument()`, `upsertDocuments()`, `executeUpsertBatch()` of `SQL` and its subclasses.

#![allow(unused_variables, clippy::unused_async)]

use crate::change::Change;
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;

use super::Sql;

impl Sql {
    /// `upsertDocument()`
    pub(crate) async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document> {
        todo!("port `upsertDocument()`")
    }

    /// `upsertDocuments()`
    pub(crate) async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        todo!("port `upsertDocuments()`")
    }
}
