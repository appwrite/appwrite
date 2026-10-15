//! `deleteDocument()` and `deleteDocuments()`.

use super::super::{BoxFuture, Callback, Database};
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

impl Database {
    /// Deletes the document `id` (`deleteDocument()`), applying the
    /// on-delete rule of each of its relationships first; whether it was
    /// deleted.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for a missing document,
    /// ([`crate::Kind::Authorization`]) without delete permission,
    /// ([`crate::Kind::Restricted`]) when a restricting relationship holds
    /// it.
    pub fn delete_document<'a>(&'a self, collection: &'a str, id: &'a str) -> BoxFuture<'a, Result<bool>> {
        Box::pin(async move {
            let _ = (collection, id);
            todo!("writes area: deleteDocument")
        })
    }

    /// Deletes every document matching `queries`, in batches of at most
    /// `batch_size` (`deleteDocuments()`), handing `on_next` each deleted
    /// document; returns how many were deleted.
    pub async fn delete_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<Document>>,
    ) -> Result<i64> {
        let _ = (collection, queries, batch_size, on_next);
        todo!("writes area: deleteDocuments")
    }
}
