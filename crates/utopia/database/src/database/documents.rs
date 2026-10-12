//! Documents: get, create, update, upsert, increase/decrease, delete,
//! singly and in batches.
//!
//! Area: documents.
//!
//! Validators these call (being ported in `crate::validator`):
//! `Structure` / `PartialStructure` (`Structure::new(..).is_valid(&document)`),
//! `Authorization` (`Input::new(action, permissions)`), `DocumentValidator`
//! / `DocumentsValidator` for queries, `Operator`.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the documents area is implemented.
#![allow(unused_variables, dead_code)]

use super::{Callback, Database};
use crate::document::Document;
use crate::error::{Error, Result};
use crate::query::Query;
use crate::value::Value;

impl Database {
    /// The document `id` of `collection` (empty when it does not exist or
    /// is not readable), honouring `select` queries, the cache, TTL
    /// indexes and relationships. `for_update` locks the row (inside a
    /// transaction).
    ///
    /// PHP: `Database::getDocument($collection, $id, $queries = [], $forUpdate = false)`.
    pub async fn get_document(
        &self,
        collection: &str,
        id: &str,
        queries: Vec<Query>,
        for_update: bool,
    ) -> Result<Document> {
        todo!("documents")
    }

    /// Whether a TTL index of `collection` says `document` expired.
    ///
    /// PHP: `Database::isTtlExpired()` (private).
    pub(crate) fn is_ttl_expired(&self, collection: &Document, document: &Document) -> bool {
        if !self.inner.adapter.support_for_ttl_indexes() {
            return false;
        }
        let indexes = match collection.get("indexes") {
            Some(Value::Array(a)) => a.values().cloned().collect::<Vec<_>>(),
            _ => Vec::new(),
        };
        for index in &indexes {
            if crate::validator::index(index, "type") != &Value::Str(Self::INDEX_TTL.into()) {
                continue;
            }
            let ttl = crate::adapter::postgres::pdo::to_long(crate::validator::index(index, "ttl"));
            let attribute = crate::validator::nth(crate::validator::index(index, "attributes"), 0);
            if ttl <= 0 || !attribute.truthy() {
                return false;
            }
            if let Some(Value::Str(start)) = document.get(&crate::value::to_text(attribute)) {
                let utc = php_std::datetime::TzInfo::utc();
                let (Ok(mut expiry), Ok(now)) = (
                    php_std::datetime::DateTime::new(start.as_bytes(), None, &utc),
                    php_std::datetime::DateTime::new(b"now", None, &utc),
                ) else {
                    return false;
                };
                if expiry.modify(format!("+{ttl} seconds").as_bytes()).is_err() {
                    return false;
                }
                return now > expiry;
            }
        }
        false
    }

    /// Creates a document (and its related documents); triggers
    /// [`Database::EVENT_DOCUMENT_CREATE`].
    ///
    /// PHP: `Database::createDocument()`.
    pub async fn create_document(&self, collection: &str, document: Document) -> Result<Document> {
        todo!("documents")
    }

    /// Creates documents in batches of `batch_size`, calling `on_next` with
    /// each created document; returns how many were created.
    ///
    /// PHP: `Database::createDocuments($collection, $documents, $batchSize =
    /// self::INSERT_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn create_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<Document>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        todo!("documents")
    }

    /// Updates document `id` with the attributes of `document` (and its
    /// relationships, operators); triggers
    /// [`Database::EVENT_DOCUMENT_UPDATE`].
    ///
    /// PHP: `Database::updateDocument()`.
    pub async fn update_document(&self, collection: &str, id: &str, document: Document) -> Result<Document> {
        todo!("documents")
    }

    /// Applies `updates` to every document matching `queries`, in batches,
    /// calling `on_next` with each `(updated, old)` pair; returns how many
    /// were updated.
    ///
    /// PHP: `Database::updateDocuments($collection, $updates, $queries = [],
    /// $batchSize = self::INSERT_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn update_documents(
        &self,
        collection: &str,
        updates: Document,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Document)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        todo!("documents")
    }

    /// Creates or updates one document.
    ///
    /// PHP: `Database::upsertDocument()`.
    pub async fn upsert_document(&self, collection: &str, document: Document) -> Result<Document> {
        todo!("documents")
    }

    /// Creates or updates documents in batches, calling `on_next` with each
    /// `(document, old)` pair (`old` is `None` for created ones); returns
    /// how many were written.
    ///
    /// PHP: `Database::upsertDocuments($collection, $documents, $batchSize =
    /// self::INSERT_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn upsert_documents(
        &self,
        collection: &str,
        documents: Vec<Document>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        todo!("documents")
    }

    /// [`Database::upsert_documents`] where existing documents get
    /// `attribute` increased by the given value instead of replaced (an
    /// empty `attribute` replaces).
    ///
    /// PHP: `Database::upsertDocumentsWithIncrease($collection, $attribute,
    /// $documents, $onNext = null, $onError = null, $batchSize =
    /// self::INSERT_BATCH_SIZE)`.
    pub async fn upsert_documents_with_increase(
        &self,
        collection: &str,
        attribute: &str,
        documents: Vec<Document>,
        on_next: Option<&mut dyn Callback<(Document, Option<Document>)>>,
        on_error: Option<&mut dyn Callback<Error>>,
        batch_size: i64,
    ) -> Result<i64> {
        todo!("documents")
    }

    /// Atomically increases a numeric attribute by `value` (an int or a
    /// float), up to `max`; triggers [`Database::EVENT_DOCUMENT_INCREASE`].
    ///
    /// PHP: `Database::increaseDocumentAttribute($collection, $id,
    /// $attribute, $value = 1, $max = null)`.
    pub async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        max: Option<Value>,
    ) -> Result<Document> {
        todo!("documents")
    }

    /// Atomically decreases a numeric attribute by `value` (an int or a
    /// float), down to `min`; triggers [`Database::EVENT_DOCUMENT_DECREASE`].
    ///
    /// PHP: `Database::decreaseDocumentAttribute($collection, $id,
    /// $attribute, $value = 1, $min = null)`.
    pub async fn decrease_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: Value,
        min: Option<Value>,
    ) -> Result<Document> {
        todo!("documents")
    }

    /// Deletes a document (applying its relationships' on-delete rules);
    /// triggers [`Database::EVENT_DOCUMENT_DELETE`].
    ///
    /// PHP: `Database::deleteDocument()`.
    pub async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        todo!("documents")
    }

    /// Deletes every document matching `queries`, in batches, calling
    /// `on_next` with each `(deleted, old)` pair; returns how many were
    /// deleted.
    ///
    /// PHP: `Database::deleteDocuments($collection, $queries = [],
    /// $batchSize = self::DELETE_BATCH_SIZE, $onNext = null, $onError = null)`.
    pub async fn delete_documents(
        &self,
        collection: &str,
        queries: Vec<Query>,
        batch_size: i64,
        on_next: Option<&mut dyn Callback<(Document, Document)>>,
        on_error: Option<&mut dyn Callback<Error>>,
    ) -> Result<i64> {
        todo!("documents")
    }

    /// The documents read back (by `$sequence`, keeping `selections`) after
    /// operators computed their values; a document not found stays as is.
    ///
    /// PHP: `Database::refetchDocuments()` (protected).
    pub(crate) async fn refetch_documents(
        &self,
        collection: &Document,
        documents: Vec<Document>,
        selections: &[Query],
    ) -> Result<Vec<Document>> {
        todo!("documents")
    }

    /// Removes from `documents` the attributes `select_queries` do not
    /// select (internal attributes stay).
    ///
    /// PHP: `Database::applySelectFiltersToDocuments()` (private).
    pub(crate) fn apply_select_filters_to_documents(&self, documents: &mut [Document], select_queries: &[Query]) {
        todo!("documents")
    }

    /// Whether `value` and `old` are identical, comparing `stdClass`
    /// values as arrays, recursively.
    ///
    /// PHP: `Database::valuesEqual()` (private static).
    pub(crate) fn values_equal(value: &Value, old: &Value) -> bool {
        todo!("documents")
    }
}
