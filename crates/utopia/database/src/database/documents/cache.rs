//! The document cache's writes: per-collection epochs that writes block and
//! activate, purges, and `withCache()`.
//!
//! PHP: the cache section of `Trait\Documents` (`purgeCachedCollection()`
//! through `withCache()`). Keys, fields and stored values are byte for byte
//! PHP's: the Appwrite PHP API shares this cache. What cached reads use is
//! in `cached.rs`.

use std::future::Future;

use super::super::Database;
use crate::cache::Tokens;
use crate::document::Document;
use crate::error::Result;
use crate::value::Value;

impl Database {
    /// Cleans all of a collection's documents from the cache
    /// (`purgeCachedCollection()`): its epoch retired, its query cache
    /// invalidated; for the metadata collection every cached definition.
    pub async fn purge_cached_collection(&self, collection: &str) -> Result<()> {
        let _ = collection;
        todo!("cache area: purgeCachedCollection")
    }

    /// Purges a document's cache slot, and once more after the open
    /// invalidation scope ends (`purgeCachedDocumentInternal()`).
    pub(crate) async fn purge_cached_document_internal(&self, collection: &str, id: Option<&str>) -> Result<bool> {
        let _ = (collection, id);
        todo!("cache area: purgeCachedDocumentInternal")
    }

    /// A batch write retires the documents of their collection at once
    /// (`advanceCollectionCacheEpoch()`); for the metadata collection it
    /// purges the definition written.
    pub(crate) async fn advance_collection_cache_epoch(&self, collection: &str, document: &str) -> Result<bool> {
        let _ = (collection, document);
        todo!("cache area: advanceCollectionCacheEpoch")
    }

    /// The cache key of a collection's definition (`getDefinitionCacheKey()`).
    pub(crate) async fn definition_cache_key(&self, collection: &str) -> Result<String> {
        Ok(self.cache_base_keys(super::super::METADATA, Some(collection)).await?.1)
    }

    /// Purges the documents a transaction wrote once it committed or rolled
    /// back; a failed purge retires the collection's epoch instead
    /// (`purgeWrittenDocuments()`). `documents` are collection keys by
    /// document key; `definitions` definition keys by collection key.
    pub(crate) async fn purge_written_documents(
        &self,
        documents: &[(String, String)],
        definitions: &[(String, String)],
    ) -> Result<()> {
        if documents.is_empty() {
            return Ok(());
        }
        let _ = definitions;
        todo!("cache area: purgeWrittenDocuments")
    }

    /// Blocks a collection's document cache for a write, activating at once
    /// outside an invalidation scope (`advanceDocumentCacheEpoch()`).
    pub(crate) async fn advance_document_cache_epoch(
        &self,
        collection_key: &str,
        definition_key: &str,
    ) -> Result<bool> {
        let _ = (collection_key, definition_key);
        todo!("cache area: advanceDocumentCacheEpoch")
    }

    /// Activates the document cache tombstones of an ended scope
    /// (`activateDocumentInvalidation()`): every one, then the first failure.
    pub(crate) async fn activate_document_invalidation(
        &self,
        tokens: &Tokens,
        definitions: &[(String, String)],
    ) -> Result<()> {
        if tokens.is_empty() {
            return Ok(());
        }
        let _ = definitions;
        todo!("cache area: activateDocumentInvalidation")
    }

    /// Runs a cache operation under the document's tenant when tenant per
    /// document is on and it has one (`withDocumentTenant()`).
    pub(crate) async fn with_document_tenant<F: Future>(&self, document: &Document, future: F) -> F::Output {
        match document.tenant() {
            Some(tenant) if self.shared_tables() && self.tenant_per_document() => {
                self.with_tenant(tenant, future).await
            }
            _ => future.await,
        }
    }

    /// What tells documents apart in a batch (`getDocumentIdentity()`): the
    /// id, with the tenant under tenant per document.
    pub(crate) fn document_identity(&self, document: &Document) -> String {
        if !self.inner.adapter.tenant_per_document() {
            return document.id().to_owned();
        }
        let tenant = document.tenant().map(|t| crate::value::to_text(&t)).unwrap_or_default();
        format!("{tenant}\\0{}", document.id())
    }

    /// Cleans a document from the cache and dispatches
    /// [`crate::Event::DocumentPurge`], whose hooks' failures reach the caller
    /// (`purgeCachedDocument()`).
    pub async fn purge_cached_document(&self, collection: &str, id: &str) -> Result<()> {
        let _ = (collection, id);
        todo!("cache area: purgeCachedDocument")
    }

    /// Announces the purge of a written document once the outermost
    /// transaction commits, or at once outside one (`queueDocumentPurge()`).
    pub(crate) async fn queue_document_purge(&self, collection: &str, id: &str) -> Result<()> {
        let _ = (collection, id);
        todo!("cache area: queueDocumentPurge")
    }

    /// [`Database::queue_document_purge`] of each document, under its
    /// tenant (`queueDocumentPurges()`).
    pub(crate) async fn queue_document_purges(&self, collection: &str, documents: &[Document]) -> Result<()> {
        for document in documents {
            self.with_document_tenant(document, self.queue_document_purge(collection, document.id())).await?;
        }
        Ok(())
    }

    /// Purges every cached query result of a collection namespace: the
    /// query cache and the [`Database::with_cache`] region
    /// (`purgeCachedQueries()`).
    pub async fn purge_cached_queries(&self, collection: &str, namespace: Option<&str>) -> Result<bool> {
        let _ = (collection, namespace);
        todo!("cache area: purgeCachedQueries")
    }

    /// Runs `callback` behind a generation-protected cache-aside lookup
    /// (`withCache()`): the cached value of `key` (and `hash`), else the
    /// callback's, cached.
    pub async fn with_cache<F, Fut>(&self, key: &str, callback: F, hash: Option<&str>) -> Result<Value>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = Result<Value>>,
    {
        let _ = (key, callback, hash);
        todo!("cache area: withCache")
    }
}
