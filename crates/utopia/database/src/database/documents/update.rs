//! `updateDocument()` and `updateDocuments()`.

use super::super::{BoxFuture, Callback, Database};
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

impl Database {
    /// Updates the document `id` with the attributes `document` carries
    /// (`updateDocument()`): operators applied, related documents related;
    /// returns it as stored and decoded.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing document,
    /// ([`crate::Kind::Authorization`]) without update permission,
    /// ([`crate::Kind::Conflict`]) for a document changed after the request
    /// timestamp, ([`crate::Kind::Structure`]) for an invalid result.
    pub fn update_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        document: Document,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let _ = (collection, id, document);
            todo!("writes area: updateDocument")
        })
    }

    /// Applies `updates` to every document matching `queries`, in batches of
    /// at most `batch_size` (`updateDocuments()`), handing `on_next` each
    /// updated document and the document before; returns how many were
    /// updated.
    pub async fn update_documents(
        &self,
        collection: &str,
        updates: Document,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let _ = (collection, updates, queries, batch_size, on_next);
        todo!("writes area: updateDocuments")
    }
}
