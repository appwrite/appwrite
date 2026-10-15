//! [`Permissions`]: keeps the `_perms` side table of a SQL adapter in step
//! with the documents it writes.

use async_trait::async_trait;

use super::{Write, WriteContext};
use crate::change::Change;
use crate::document::Document;
use crate::error::Result;

/// The write hook that stores each document's permissions as rows of the
/// collection's `_perms` table: inserted on create, replaced on update,
/// moved on a renamed id, removed on delete. A SQL adapter registers it.
///
/// PHP: `Utopia\Database\Hook\Permissions`.
#[derive(Debug, Default, Clone)]
pub struct Permissions;

impl Permissions {
    /// The hook (`new Permissions()`).
    pub fn new() -> Self {
        Permissions
    }
}

#[async_trait]
impl Write for Permissions {
    async fn after_document_create(
        &self,
        collection: &str,
        documents: &[Document],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, documents, context);
        todo!("schema area: Hook\\Permissions::afterDocumentCreate")
    }

    async fn after_document_update(
        &self,
        collection: &str,
        id: &str,
        document: &Document,
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, id, document, context);
        todo!("schema area: Hook\\Permissions::afterDocumentUpdate")
    }

    async fn after_document_batch_update(
        &self,
        collection: &str,
        updates: &Document,
        documents: &[Document],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, updates, documents, context);
        todo!("schema area: Hook\\Permissions::afterDocumentBatchUpdate")
    }

    async fn after_document_upsert(
        &self,
        collection: &str,
        changes: &[Change],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, changes, context);
        todo!("schema area: Hook\\Permissions::afterDocumentUpsert")
    }

    async fn after_document_delete(
        &self,
        collection: &str,
        document_ids: &[String],
        context: &dyn WriteContext,
    ) -> Result<()> {
        let _ = (collection, document_ids, context);
        todo!("schema area: Hook\\Permissions::afterDocumentDelete")
    }
}
