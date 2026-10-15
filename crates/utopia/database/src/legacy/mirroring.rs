//! [`Filter`]: what a [`crate::mirror::Mirror`] runs around the writes it
//! copies to its destination.
//!
//! PHP: `Utopia\Database\Mirroring\Filter` (an abstract class whose methods
//! all default to passing their input through).

use async_trait::async_trait;

use crate::database::Database;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;

/// Hooks around the writes a [`crate::mirror::Mirror`] copies to its
/// destination: a `before*` hook may change what is written there (or, for
/// a collection or attribute, what the mirror returns), an `after*` hook
/// sees what was written. Every hook defaults to passing its input through;
/// an error from one is reported to the mirror's error callbacks like a
/// failed destination write.
///
/// PHP: `Utopia\Database\Mirroring\Filter`.
#[async_trait]
#[allow(unused_variables)]
pub trait Filter: Send + Sync {
    /// PHP: `init()`. The mirror never calls it: it is the owner's.
    async fn init(&self, source: &Database, destination: Option<&Database>) -> Result<()> {
        Ok(())
    }

    /// PHP: `shutdown()`. The mirror never calls it: it is the owner's.
    async fn shutdown(&self, source: &Database, destination: Option<&Database>) -> Result<()> {
        Ok(())
    }

    /// Before a collection is created on the destination: the collection the
    /// mirror returns.
    async fn before_create_collection(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        collection: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(collection)
    }

    /// Before a collection is updated on the destination: the collection the
    /// mirror returns.
    async fn before_update_collection(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        collection: Option<Document>,
    ) -> Result<Option<Document>> {
        Ok(collection)
    }

    /// After a collection is deleted on the destination (PHP calls it after,
    /// despite its name).
    async fn before_delete_collection(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// Before an attribute is created on the destination: the attribute to
    /// create there.
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

    /// Before an attribute is updated on the destination: the attribute to
    /// update there (and the one the mirror returns).
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
    /// there.
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

    /// PHP: `beforeUpdateIndex()`. The mirror never calls it (it copies no
    /// index update).
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

    /// After an index is deleted on the destination (PHP calls it after,
    /// despite its name).
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
    async fn before_create_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// After a document was created on the destination.
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
    async fn before_update_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// After a document was updated on the destination.
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

    /// After documents were updated on the destination.
    async fn after_update_documents(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        updates: Document,
        queries: &[Query],
    ) -> Result<()> {
        Ok(())
    }

    /// Before a document is deleted on the destination.
    async fn before_delete_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document_id: &str,
    ) -> Result<()> {
        Ok(())
    }

    /// After a document was deleted on the destination.
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
    async fn before_delete_documents(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        queries: &[Query],
    ) -> Result<()> {
        Ok(())
    }

    /// After documents were deleted on the destination.
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
    /// write there.
    async fn before_create_or_update_document(
        &self,
        source: &Database,
        destination: &Database,
        collection_id: &str,
        document: Document,
    ) -> Result<Document> {
        Ok(document)
    }

    /// After a document was upserted on the destination.
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
