//! Document and query caching: keys, purges and the read-through
//! `with_cache`.
//!
//! Area: schema.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the schema area is implemented.
#![allow(unused_variables, dead_code)]

use std::future::Future;

use super::Database;
use crate::document::Document;
use crate::error::Result;
use crate::query::Query;
use crate::value::{Array, Value, to_text};

impl Database {
    /// The collection key and the document key (`""` without a document)
    /// of the document cache:
    /// `{cacheName}-cache-{hostname}:{namespace}:{tenant}:collection:{collectionId}[:{documentId}]`.
    ///
    /// PHP: `Database::getCacheBaseKeys()`.
    pub fn cache_base_keys(&self, collection_id: &str, document_id: Option<&str>) -> (String, String) {
        todo!("schema")
    }

    /// The collection key, the document key and the document hash key (the
    /// document key with the md5 of the relationship flag, the filter
    /// signatures and the sorted selections).
    ///
    /// PHP: `Database::getCacheKeys()`.
    pub fn cache_keys(
        &self,
        collection_id: &str,
        document_id: Option<&str>,
        selects: &[String],
    ) -> (String, String, String) {
        todo!("schema")
    }

    /// The cache key of a collection's cached queries (in `namespace`, the
    /// database's by default).
    ///
    /// PHP: `Database::getQueryCacheKey()`.
    pub fn query_cache_key(&self, collection_id: &str, namespace: Option<&str>) -> String {
        todo!("schema")
    }

    /// The cache field of one query result: a hash of the collection's
    /// schema and of the queries, authorization, database, relationship
    /// flag and filter signatures, then `field`. `None` for permissions
    /// other than `read` (never cached).
    ///
    /// PHP: `Database::getQueryCacheField()`.
    pub fn query_cache_field(
        &self,
        collection: Option<&Document>,
        queries: &[Query],
        field: &str,
        for_permission: &str,
    ) -> Result<Option<String>> {
        todo!("schema")
    }

    /// A query as the query cache field hashes it: method, attribute (when
    /// not empty) and values (nested queries serialized the same way).
    ///
    /// PHP: `Database::serializeQueryCacheQuery()` (private).
    pub(crate) fn serialize_query_cache_query(&self, query: &Query) -> Array {
        todo!("schema")
    }

    /// A query value with documents turned into arrays, recursively.
    ///
    /// PHP: `Database::normalizeQueryCacheQueryValue()` (private).
    pub(crate) fn normalize_query_cache_query_value(&self, value: Value) -> Value {
        todo!("schema")
    }

    /// Purges every cached document of a collection, and the collection
    /// key.
    ///
    /// PHP: `Database::purgeCachedCollection()`.
    pub async fn purge_cached_collection(&self, collection_id: &str) -> Result<bool> {
        todo!("schema")
    }

    /// Purges a cached document (all its hash variants) without triggering
    /// the purge event.
    ///
    /// PHP: `Database::purgeCachedDocumentInternal()` (protected).
    pub(crate) async fn purge_cached_document_internal(&self, collection_id: &str, id: Option<&str>) -> Result<bool> {
        todo!("schema")
    }

    /// Purges a cached document and triggers
    /// [`Database::EVENT_DOCUMENT_PURGE`].
    ///
    /// PHP: `Database::purgeCachedDocument()`.
    pub async fn purge_cached_document(&self, collection_id: &str, id: Option<&str>) -> Result<bool> {
        todo!("schema")
    }

    /// Purges a collection's cached queries.
    ///
    /// PHP: `Database::purgeCachedQueries()`.
    pub async fn purge_cached_queries(&self, collection: &str, namespace: Option<&str>) -> Result<bool> {
        todo!("schema")
    }

    /// Read-through cache: the value cached under `key` / `hash` when there
    /// is a valid one (documents restored, cast, checked for TTL expiry and
    /// read permission), otherwise `f()`'s value, saved with a lease unless
    /// it is `false`. `hash: None` bypasses the cache. Cache failures only
    /// warn.
    ///
    /// PHP: `Database::withCache()`.
    pub async fn with_cache<F, Fut>(&self, key: &str, f: F, hash: Option<&str>) -> Result<Value>
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = Result<Value>>,
    {
        todo!("schema")
    }

    /// Runs `f` with the document's tenant when each document carries its
    /// own tenant on shared tables, otherwise as is.
    ///
    /// PHP: `Database::withDocumentTenant()` (private).
    pub(crate) async fn with_document_tenant<T, F, Fut>(&self, document: &Document, f: F) -> T
    where
        F: FnOnce() -> Fut,
        Fut: Future<Output = T>,
    {
        if self.shared_tables() && self.tenant_per_document() {
            self.with_tenant(document.tenant().unwrap_or(Value::Null), f).await
        } else {
            f().await
        }
    }

    /// A document's identity: `"{tenant}:{id}"` when each document carries
    /// its own tenant on shared tables, otherwise its id.
    ///
    /// PHP: `Database::tenantKey()` (private).
    pub(crate) fn tenant_key(&self, document: &Document) -> String {
        if self.shared_tables() && self.tenant_per_document() {
            format!("{}:{}", document.tenant().map(|t| to_text(&t)).unwrap_or_default(), document.id())
        } else {
            document.id().to_owned()
        }
    }
}
