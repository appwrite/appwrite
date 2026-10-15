//! `upsertDocument()` and `upsertDocuments()`.

use super::super::{Callback, Database};
use crate::document::Document;
use crate::error::Result;

impl Database {
    /// Creates the document, or updates the stored one of its id
    /// (`upsertDocument()`).
    pub async fn upsert_document(&self, collection: &str, document: Document) -> Result<Document> {
        let _ = (collection, document);
        todo!("writes area: upsertDocument")
    }

    /// Upserts documents in batches of at most `batch_size`
    /// (`upsertDocuments()`); with `increase`, an update adds the new
    /// value of that attribute to the stored one. `on_next` is handed each
    /// written document and the stored one it replaced; returns how many
    /// were written.
    pub async fn upsert_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        increase: Option<&str>,
    ) -> Result<i64> {
        let _ = (collection, documents, batch_size, on_next, increase);
        todo!("writes area: upsertDocuments")
    }
}
