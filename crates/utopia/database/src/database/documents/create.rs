//! `createDocument()`, `createDocuments()`, `prepareCreate()`,
//! `createPrepared()` and the `authorize*()` checks.

use super::super::{BoxFuture, Callback, Database, Resolve};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;

impl Database {
    /// Creates a document (`createDocument()`): id, timestamps, tenant and
    /// permissions set, encoded and validated, its related documents
    /// related; returns it as stored and decoded.
    ///
    /// Fails ([`crate::Kind::Authorization`]) without create permission,
    /// ([`crate::Kind::Structure`]) for an invalid document,
    /// ([`crate::Kind::Duplicate`]) for an existing id.
    pub fn create_document<'a>(&'a self, collection: &'a str, document: Document) -> BoxFuture<'a, Result<Document>> {
        Box::pin(async move {
            let _ = (collection, document);
            todo!("writes area: createDocument")
        })
    }

    /// Creates documents in batches of at most `batch_size`
    /// (`createDocuments()`), handing `on_next` each created document (and
    /// `None`); returns how many were created.
    ///
    /// Fails ([`crate::Kind::Limit`]) for a batch above
    /// [`super::super::BATCH_SIZE`], and as [`Database::create_document`] does.
    pub async fn create_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
    ) -> Result<i64> {
        let _ = (collection, documents, batch_size, on_next);
        todo!("writes area: createDocuments")
    }

    /// Applies to a document everything [`Database::create_document`] does
    /// before writing it (`prepareCreate()`, internal).
    pub async fn prepare_create(&self, collection: &Collection, document: Document) -> Result<Document> {
        let _ = (collection, document);
        todo!("writes area: prepareCreate")
    }

    /// Writes documents [`Database::prepare_create`] prepared, one at a time
    /// in order, under one invalidation scope (`createPrepared()`, internal).
    pub async fn create_prepared(&self, documents: Vec<(Collection, Document)>) -> Result<()> {
        let _ = documents;
        todo!("writes area: createPrepared")
    }

    /// Checks, without writing, that the caller may create `document`, and
    /// relate every related document it carries (`authorizeCreate()`).
    pub async fn authorize_create(
        &self,
        collection: &str,
        document: &Document,
        resolve: Option<Resolve>,
    ) -> Result<()> {
        let _ = (collection, document, resolve);
        todo!("writes area: authorizeCreate")
    }

    /// Checks, without writing, that the caller may update `old` with
    /// `document` (`authorizeUpdate()`).
    pub async fn authorize_update(
        &self,
        collection: &str,
        old: &Document,
        document: &Document,
        resolve: Option<Resolve>,
    ) -> Result<()> {
        let _ = (collection, old, document, resolve);
        todo!("writes area: authorizeUpdate")
    }

    /// Checks only the grants of the related documents nested in `document`
    /// (`authorizeGrants()`).
    pub async fn authorize_grants(
        &self,
        collection: &str,
        document: &Document,
        resolve: Option<Resolve>,
    ) -> Result<()> {
        let _ = (collection, document, resolve);
        todo!("writes area: authorizeGrants")
    }
}
