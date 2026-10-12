//! Persisting collection metadata with retries, and the cleanups that roll
//! schema changes back when it fails.
//!
//! Area: schema.

use std::future::Future;

use futures_util::future::BoxFuture;

use super::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::value::{Array, Ext, Value};

/// How [`Database::update_metadata`] undoes a schema change whose metadata
/// could not be persisted. The future runs only on failure.
///
/// PHP: `updateMetadata()`'s `$rollbackOperation` with its
/// `$rollbackReturnsErrors` and `$silentRollback` flags.
pub(crate) enum Rollback<'a> {
    /// The rollback raises on failure (both flags off): its error is
    /// reported with the persistence error.
    Throwing(BoxFuture<'a, Result<()>>),
    /// The rollback returns the messages of the cleanups that failed
    /// (`$rollbackReturnsErrors`).
    Errors(BoxFuture<'a, Result<Vec<String>>>),
    /// The rollback's errors are swallowed (`$silentRollback`).
    Silent(BoxFuture<'a, Result<()>>),
}

impl Database {
    /// Runs `operation` until it succeeds, at most `max_attempts` times,
    /// sleeping `initial_delay_ms` (multiplied by `multiplier` after each
    /// failure) in between; raises the last error.
    ///
    /// PHP: `Database::withRetries($operation, $maxAttempts = 3,
    /// $initialDelayMs = 100, $multiplier = 2.0)` (private).
    pub(crate) async fn with_retries<F, Fut>(
        &self,
        mut operation: F,
        max_attempts: u32,
        initial_delay_ms: u64,
        multiplier: f64,
    ) -> Result<()>
    where
        F: FnMut() -> Fut,
        Fut: Future<Output = Result<()>>,
    {
        let mut attempt = 0;
        let mut delay_ms = initial_delay_ms;
        let mut last = None;
        while attempt < max_attempts {
            match operation().await {
                Ok(()) => return Ok(()),
                Err(e) => {
                    last = Some(e);
                    attempt += 1;
                    if attempt >= max_attempts {
                        break;
                    }
                    tokio::time::sleep(std::time::Duration::from_millis(delay_ms)).await;
                    delay_ms = (delay_ms as f64 * multiplier) as u64;
                }
            }
        }
        // PHP throws `null` (an `Error`) when no attempt ran.
        Err(last.unwrap_or_else(|| Error::new(Kind::Error, "Can only throw objects")))
    }

    /// [`Database::with_retries`] logging a failure to clean up
    /// `resource_type` `resource_id` before raising it.
    ///
    /// PHP: `Database::cleanup()` (private).
    pub(crate) async fn cleanup<F, Fut>(
        &self,
        operation: F,
        resource_type: &str,
        resource_id: &str,
        max_attempts: u32,
    ) -> Result<()>
    where
        F: FnMut() -> Fut,
        Fut: Future<Output = Result<()>>,
    {
        let result = self.with_retries(operation, max_attempts, 100, 2.0).await;
        if let Err(e) = &result {
            tracing::error!(
                "Failed to cleanup {resource_type} '{resource_id}' after {max_attempts} attempts: {}",
                e.message()
            );
        }
        result
    }

    /// Drops an attribute's column, with retries.
    ///
    /// PHP: `Database::cleanupAttribute()` (private).
    pub(crate) async fn cleanup_attribute(
        &self,
        collection_id: &str,
        attribute_id: &str,
        max_attempts: u32,
    ) -> Result<()> {
        let adapter = self.adapter();
        self.cleanup(
            || async move { adapter.delete_attribute(collection_id, attribute_id).await.map(|_| ()) },
            "attribute",
            attribute_id,
            max_attempts,
        )
        .await
    }

    /// Drops the columns of `attributes`, with retries; returns the
    /// messages of those that failed.
    ///
    /// PHP: `Database::cleanupAttributes()` (private).
    pub(crate) async fn cleanup_attributes(
        &self,
        collection_id: &str,
        attributes: &[Document],
        max_attempts: u32,
    ) -> Result<Vec<String>> {
        let mut errors = Vec::new();
        for attribute in attributes {
            match self.cleanup_attribute(collection_id, attribute.id(), max_attempts).await {
                Ok(()) => {}
                // PHP catches `DatabaseException` only.
                Err(e) if e.is_a(Kind::Database) => errors.push(e.message().to_owned()),
                Err(e) => return Err(e),
            }
        }
        Ok(errors)
    }

    /// Drops a collection's table, with retries.
    ///
    /// PHP: `Database::cleanupCollection()` (private).
    pub(crate) async fn cleanup_collection(&self, collection_id: &str, max_attempts: u32) -> Result<()> {
        let adapter = self.adapter();
        self.cleanup(
            || async move { adapter.delete_collection(collection_id).await.map(|_| ()) },
            "collection",
            collection_id,
            max_attempts,
        )
        .await
    }

    /// Drops an index, with retries.
    ///
    /// PHP: `Database::cleanupIndex()` (private).
    pub(crate) async fn cleanup_index(&self, collection_id: &str, index_id: &str, max_attempts: u32) -> Result<()> {
        let adapter = self.adapter();
        self.cleanup(
            || async move { adapter.delete_index(collection_id, index_id).await.map(|_| ()) },
            "index",
            index_id,
            max_attempts,
        )
        .await
    }

    /// Persists `collection`'s metadata document (with retries, silently);
    /// on failure runs `rollback` when `should_rollback`, then raises a
    /// database error naming `description`.
    ///
    /// PHP: `Database::updateMetadata($collection, $rollbackOperation,
    /// $shouldRollback, $operationDescription = 'operation',
    /// $rollbackReturnsErrors = false, $silentRollback = false)` (private).
    pub(crate) async fn update_metadata(
        &self,
        collection: &Document,
        rollback: Option<Rollback<'_>>,
        should_rollback: bool,
        description: &str,
    ) -> Result<()> {
        if collection.id() == Self::METADATA {
            return Ok(());
        }
        let persisted = self
            .with_retries(
                || async {
                    self.silent(|| self.update_document(Self::METADATA, collection.id(), collection.clone()), None)
                        .await
                        .map(|_| ())
                },
                3,
                100,
                2.0,
            )
            .await;
        let Err(e) = persisted else {
            return Ok(());
        };
        if should_rollback && let Some(rollback) = rollback {
            match rollback {
                Rollback::Errors(cleanup) => {
                    let errors = cleanup.await?;
                    if !errors.is_empty() {
                        return Err(Error::database(format!(
                            "Failed to persist metadata after retries and cleanup encountered errors for {description}: {} | Cleanup errors: {}",
                            e.message(),
                            errors.join(", ")
                        ))
                        .with_previous(e));
                    }
                }
                Rollback::Silent(cleanup) => {
                    let _ = cleanup.await;
                }
                Rollback::Throwing(cleanup) => {
                    if let Err(ex) = cleanup.await {
                        return Err(Error::database(format!(
                            "Failed to persist metadata after retries and cleanup failed for {description}: {} | Cleanup error: {}",
                            ex.message(),
                            e.message()
                        ))
                        .with_previous(e));
                    }
                }
            }
        }
        Err(Error::database(format!("Failed to persist metadata after retries for {description}: {}", e.message()))
            .with_previous(e))
    }

    /// Removes the attributes `attribute_ids` from `collection`'s
    /// `attributes` (in memory).
    ///
    /// PHP: `Database::rollbackAttributeMetadata()` (private).
    pub(crate) fn rollback_attribute_metadata(&self, collection: &mut Document, attribute_ids: &[String]) {
        let kept: Vec<Document> = documents_at(collection, "attributes")
            .into_iter()
            .filter(|a| !attribute_ids.iter().any(|id| id == a.id()))
            .collect();
        set_documents(collection, "attributes", kept);
    }
}

/// The entries of a collection's `attributes` or `indexes` (or another
/// list of documents) as documents, plain arrays read as documents; empty
/// when the key is missing or not an array.
pub(crate) fn documents_at(document: &Document, key: &str) -> Vec<Document> {
    match document.get(key) {
        Some(Value::Array(a)) => a.values().map(as_entry).collect(),
        _ => Vec::new(),
    }
}

/// One entry of a collection's `attributes` or `indexes` as a document.
pub(crate) fn as_entry(value: &Value) -> Document {
    match value {
        Value::Ext(Ext::Document(d)) => (**d).clone(),
        Value::Array(a) => Document::from_array_unchecked(a.clone()),
        _ => Document::empty(),
    }
}

/// Sets a list attribute of `document` (`attributes`, `indexes`) to
/// `documents`.
pub(crate) fn set_documents(document: &mut Document, key: &str, documents: Vec<Document>) {
    document.set(key, Value::Array(documents.into_iter().map(Value::from).collect::<Array>()));
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::value::{ArrayKey, string};

    fn ids(collection: &Document) -> Vec<String> {
        documents_at(collection, "attributes").iter().map(|d| d.id().to_owned()).collect()
    }

    #[test]
    fn set_documents_round_trips() {
        let attribute = |id: &str| Document::from_pairs([("$id", string(id))]).unwrap();
        let mut collection = Document::empty();
        set_documents(&mut collection, "attributes", vec![attribute("a"), attribute("b")]);
        assert_eq!(ids(&collection), ["a", "b"]);
    }

    #[test]
    fn plain_arrays_read_as_documents() {
        let entry: Array = [(ArrayKey::from("$id"), string("x"))].into_iter().collect();
        assert_eq!(as_entry(&Value::Array(entry)).id(), "x");
        assert!(as_entry(&Value::Int(1)).is_empty());
    }
}
