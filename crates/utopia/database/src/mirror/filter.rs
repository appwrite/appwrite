//! [`Filter`]: what a [`super::Mirror`] runs around the changes it applies
//! to its destination.
//!
//! PHP: `Utopia\Database\Mirror\Filter` (an abstract class whose methods all
//! default to passing their input through).

use async_trait::async_trait;

use crate::database::Database;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

/// Hooks around the changes a [`super::Mirror`] applies to its destination:
/// a `before*` hook may change what is written there (or, returning `None`
/// for a collection, an attribute or an index, skip it), an `after*` hook
/// sees what was written. Every hook defaults to passing its input through;
/// an error from one is reported to the mirror's
/// [`super::Mirror::on_error`] callbacks like a failed destination write.
///
/// PHP: `Utopia\Database\Mirror\Filter`.
#[async_trait]
#[allow(unused_variables)]
pub trait Filter: Send + Sync {
    /// PHP: `initialize()`. The mirror never calls it: it is the owner's.
    async fn initialize(&self, source: &Database, destination: Option<&Database>) -> Result<()> {
        Ok(())
    }

    /// PHP: `shutdown()`. The mirror never calls it: it is the owner's.
    async fn shutdown(&self, source: &Database, destination: Option<&Database>) -> Result<()> {
        Ok(())
    }

    /// Before a collection is created on the destination: the collection the
    /// mirror returns, or `None` to skip the destination.
    ///
    /// PHP: `beforeCreateCollection()`.
    async fn before_create_collection(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        collection: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(collection)
    }

    /// Before a collection is updated on the destination, or `None` to skip
    /// it.
    ///
    /// PHP: `beforeUpdateCollection()`.
    async fn before_update_collection(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        collection: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(collection)
    }

    /// After a collection is deleted on the destination (the mirror calls it
    /// after, despite its name).
    ///
    /// PHP: `beforeDeleteCollection()`.
    async fn before_delete_collection(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// Before an attribute is created on the destination: the attribute to
    /// create there, or `None` to skip it.
    ///
    /// PHP: `beforeCreateAttribute()`.
    async fn before_create_attribute(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        attribute_id: &str,
        attribute: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(attribute)
    }

    /// Before an attribute is updated on the destination: the attribute as
    /// the destination should have it, or `None` to skip it.
    ///
    /// PHP: `beforeUpdateAttribute()`.
    async fn before_update_attribute(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        attribute_id: &str,
        attribute: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(attribute)
    }

    /// Before an attribute is deleted on the destination.
    ///
    /// PHP: `beforeDeleteAttribute()`.
    async fn before_delete_attribute(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        attribute_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// Before an index is created on the destination: the index to create
    /// there, or `None` to skip it.
    ///
    /// PHP: `beforeCreateIndex()`.
    async fn before_create_index(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        index_id: &str,
        index: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(index)
    }

    /// PHP: `beforeUpdateIndex()`. The mirror never calls it (indexes are
    /// not updated).
    async fn before_update_index(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        index_id: &str,
        index: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(index)
    }

    /// After an index is deleted on the destination (the mirror calls it
    /// after, despite its name).
    ///
    /// PHP: `beforeDeleteIndex()`.
    async fn before_delete_index(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        index_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// Before a document is created on the destination: the document to
    /// create there.
    ///
    /// PHP: `beforeCreateDocument()`.
    async fn before_create_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// After a document is created on the destination.
    ///
    /// PHP: `afterCreateDocument()`.
    async fn after_create_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// Before a document is updated on the destination: the document to
    /// write there.
    ///
    /// PHP: `beforeUpdateDocument()`.
    async fn before_update_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// After a document is updated on the destination.
    ///
    /// PHP: `afterUpdateDocument()`.
    async fn after_update_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// Before documents are updated on the destination: the updates to
    /// apply there.
    ///
    /// PHP: `beforeUpdateDocuments()`.
    async fn before_update_documents(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        updates: Document,
        queries: &[Query],
    ) -> Result<Document> {
        Ok(updates)
    }

    /// After documents are updated on the destination.
    ///
    /// PHP: `afterUpdateDocuments()`.
    async fn after_update_documents(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        updates: &Document,
        queries: &[Query],
    ) -> Result<()> {
        Ok(())
    }

    /// Before a document is deleted on the destination.
    ///
    /// PHP: `beforeDeleteDocument()`.
    async fn before_delete_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// After a document is deleted on the destination.
    ///
    /// PHP: `afterDeleteDocument()`.
    async fn after_delete_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// Before documents are deleted on the destination.
    ///
    /// PHP: `beforeDeleteDocuments()`.
    async fn before_delete_documents(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        queries: &[Query],
    ) -> Result<()> {
        Ok(())
    }

    /// After documents are deleted on the destination.
    ///
    /// PHP: `afterDeleteDocuments()`.
    async fn after_delete_documents(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        queries: &[Query],
    ) -> Result<()> {
        Ok(())
    }

    /// Before a document is upserted on the destination: the document to
    /// upsert there.
    ///
    /// PHP: `beforeCreateOrUpdateDocument()`.
    async fn before_create_or_update_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// After a document is upserted on the destination.
    ///
    /// PHP: `afterCreateOrUpdateDocument()`.
    async fn after_create_or_update_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }
}
