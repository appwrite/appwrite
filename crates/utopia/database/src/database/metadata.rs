//! Persisting collection metadata with retries, and the cleanups that roll
//! schema changes back when it fails.
//!
//! Area: schema.

// TEMPORARY (phase A skeleton): the `todo!()` bodies leave parameters and
// helpers unused until the schema area is implemented.
#![allow(unused_variables, dead_code)]

use std::future::Future;

use futures_util::future::BoxFuture;

use super::Database;
use crate::document::Document;
use crate::error::Result;

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
    Errors(BoxFuture<'a, Vec<String>>),
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
        operation: F,
        max_attempts: u32,
        initial_delay_ms: u64,
        multiplier: f64,
    ) -> Result<()>
    where
        F: FnMut() -> Fut,
        Fut: Future<Output = Result<()>>,
    {
        todo!("schema")
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
        todo!("schema")
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
        todo!("schema")
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
    ) -> Vec<String> {
        todo!("schema")
    }

    /// Drops a collection's table, with retries.
    ///
    /// PHP: `Database::cleanupCollection()` (private).
    pub(crate) async fn cleanup_collection(&self, collection_id: &str, max_attempts: u32) -> Result<()> {
        todo!("schema")
    }

    /// Drops an index, with retries.
    ///
    /// PHP: `Database::cleanupIndex()` (private).
    pub(crate) async fn cleanup_index(&self, collection_id: &str, index_id: &str, max_attempts: u32) -> Result<()> {
        todo!("schema")
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
        todo!("schema")
    }

    /// Removes the attributes `attribute_ids` from `collection`'s
    /// `attributes` (in memory).
    ///
    /// PHP: `Database::rollbackAttributeMetadata()` (private).
    pub(crate) fn rollback_attribute_metadata(&self, collection: &mut Document, attribute_ids: &[String]) {
        todo!("schema")
    }
}
