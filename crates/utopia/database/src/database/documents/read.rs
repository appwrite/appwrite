//! `getDocument()` and the reads writes make.

use super::super::{BoxFuture, Database};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

impl Database {
    /// Reads a document (`getDocument()`): an empty document when it does
    /// not exist or the caller cannot read it. `queries` select attributes,
    /// filter on joins, ...; `for_update` locks the row.
    ///
    /// Fails ([`crate::Kind::NotFound`]) for an empty collection id
    /// (`Collection not found`) or a missing collection, and
    /// ([`crate::Kind::Query`]) for invalid queries.
    pub fn get_document<'a>(
        &'a self,
        collection: &'a str,
        id: &'a str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let _ = (collection, id, queries, for_update);
            todo!("reads area: getDocument")
        })
    }

    /// [`Database::get_document`] under a definition the caller already
    /// resolved (`readDocument()`), with the document cache epoch of the
    /// collection's documents when the document read is a collection
    /// definition (PHP attaches it to the definition object).
    pub(crate) fn read_document<'a>(
        &'a self,
        collection: &'a Collection,
        id: &'a str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> BoxFuture<'a, Result<(Document, Option<String>)>> {
        Box::pin(async move {
            let _ = (collection, id, queries, for_update);
            todo!("reads area: readDocument")
        })
    }

    /// The documents read again by their `$sequence`, with `selections`, as
    /// stored, keeping any not found (`refetchDocuments()`).
    ///
    /// Fails ([`crate::Kind::Database`]) for a document without a sequence.
    pub(crate) async fn refetch_documents(
        &self,
        collection: &Collection,
        documents: Vec<Document>,
        selections: Vec<Query>,
    ) -> Result<Vec<Document>> {
        let _ = (collection, documents, selections);
        todo!("reads area: refetchDocuments")
    }

    /// Strips the attributes `select_queries` do not select from each
    /// document, keeping the internal ones (`applySelectFiltersToDocuments()`,
    /// internal).
    pub fn apply_select_filters_to_documents(&self, documents: &mut [Document], select_queries: &[Query]) {
        let _ = (documents, select_queries);
        todo!("reads area: applySelectFiltersToDocuments")
    }
}
