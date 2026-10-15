//! The document cache: per-collection epochs that writes block and
//! activate, purges, `withCache()`, and the helpers of cached reads.
//!
//! PHP: the cache section of `Trait\Documents` (`purgeCachedCollection()`
//! through `withCache()`, and `loadCachedDocument()` through
//! `isTtlExpired()`). Keys, fields and stored values are byte for byte
//! PHP's: the Appwrite PHP API shares this cache.

use std::future::Future;

use super::super::Database;
use crate::cache::{Epoch, Tokens};
use crate::collection::Collection;
use crate::document::Document;
use crate::error::Result;
use crate::value::{Array, Value};

/// A cached collection definition's entry, as a read may serve it
/// (`loadCachedDefinition()`'s array): the definition, and the epoch its
/// collection's documents are cached under (`None` when blocked).
#[derive(Debug, Clone, PartialEq)]
pub(crate) struct CachedDefinition {
    /// `document`.
    pub(crate) document: Array,
    /// `collectionEpoch`.
    pub(crate) collection_epoch: Option<String>,
}

/// What a cached definition's entry records about the state it was filled
/// under (`saveCachedDefinition()`'s `$validity`).
#[derive(Debug, Clone, PartialEq, Default)]
pub(crate) struct Validity {
    /// `collectionEpoch`.
    pub(crate) collection_epoch: Option<String>,
    /// `blockedAt`.
    pub(crate) blocked_at: Option<i64>,
    /// `checkedAt`.
    pub(crate) checked_at: Option<i64>,
}

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

    /// The epoch a collection's documents may be cached under, or none
    /// while a write to it is in flight (`loadDocumentCacheState()`).
    pub(crate) async fn load_document_cache_state(&self, collection_key: &str) -> Epoch {
        let _ = collection_key;
        todo!("cache area: loadDocumentCacheState")
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
        let tenant = document.tenant();
        match tenant {
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

    /// Whether a read inside a transaction may serve the document's cached
    /// copy: only when the open scope started the transaction and has not
    /// written the document (`isCachedInTransaction()`).
    pub(crate) fn is_cached_in_transaction(&self, document_key: &str) -> bool {
        let _ = document_key;
        todo!("cache area: isCachedInTransaction")
    }

    /// What a read under `epoch` may serve from a document's cache slot
    /// (`loadCachedDocument()`): its copy (or the absence marker).
    pub(crate) async fn load_cached_document(
        &self,
        document_key: &str,
        field: &str,
        epoch: &str,
    ) -> Result<Option<Array>> {
        let _ = (document_key, field, epoch);
        todo!("cache area: loadCachedDocument")
    }

    /// Caches a document's copy (or the absence marker) under `epoch`
    /// (`saveCachedDocument()`).
    pub(crate) async fn save_cached_document(
        &self,
        document_key: &str,
        field: &str,
        epoch: &str,
        document: Array,
        generation: &str,
    ) -> Result<()> {
        let _ = (document_key, field, epoch, document, generation);
        todo!("cache area: saveCachedDocument")
    }

    /// The collection model of a definition's cached copy
    /// (`createDefinitionInstance()`).
    pub(crate) fn create_definition_instance(&self, document_key: &str, cached: Array) -> Result<Document> {
        let _ = (document_key, cached);
        todo!("cache area: createDefinitionInstance")
    }

    /// What a read may serve from a definition's cache slot
    /// (`loadCachedDefinition()`).
    pub(crate) async fn load_cached_definition(
        &self,
        document_key: &str,
        field: &str,
    ) -> Result<Option<CachedDefinition>> {
        let _ = (document_key, field);
        todo!("cache area: loadCachedDefinition")
    }

    /// Caches a definition's copy (`saveCachedDefinition()`); with
    /// `is_current` (the collection key the definition describes, and the
    /// epoch it was read under: PHP's `$isCurrent` closure), a fill made
    /// without generations is dropped when that epoch moved on.
    pub(crate) async fn save_cached_definition(
        &self,
        document_key: &str,
        field: &str,
        document: Array,
        validity: Validity,
        generation: &str,
        is_current: Option<(&str, Option<&str>)>,
    ) -> Result<()> {
        let _ = (document_key, field, document, validity, generation, is_current);
        todo!("cache area: saveCachedDefinition")
    }

    /// Whether a TTL index of the collection has expired the document
    /// (`isTtlExpired()`).
    pub(crate) fn is_ttl_expired(&self, collection: &Collection, document: &Document) -> Result<bool> {
        let _ = (collection, document);
        todo!("cache area: isTtlExpired")
    }
}
