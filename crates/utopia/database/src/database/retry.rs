//! `withRetries()`, `cleanup()` and `updateMetadata()`: how schema changes
//! persist their definitions and undo what a failed persist leaves.

use std::future::Future;
use std::time::Duration;

use super::{BoxFuture, Database, METADATA};
use crate::collection::Collection;
use crate::error::{Error, Kind, Result};

/// What a failed metadata persist undoes, and how
/// (`updateMetadata()`'s `$rollbackOperation` and its flags).
///
/// Each undo is a future, which runs only when the persist failed.
pub(crate) enum Rollback<'a> {
    /// Nothing to undo.
    None,
    /// An undo whose failure fails the call as a cleanup failure (the
    /// default).
    Throwing(BoxFuture<'a, Result<()>>),
    /// An undo whose failure is reported after the persist error
    /// (`$silentRollback = true`).
    Silent(BoxFuture<'a, Result<()>>),
    /// An undo that returns the errors it met (`$rollbackReturnsErrors = true`);
    /// an error it raises itself reaches the caller unchanged.
    Errors(BoxFuture<'a, Result<Vec<String>>>),
}

impl Database {
    /// Runs `operation` up to `attempts` times, waiting `delay` ms (doubled
    /// each time) between attempts, while it fails with an error another
    /// attempt can clear (`withRetries()`). The last failure is returned.
    pub(crate) async fn with_retries<F, Fut>(&self, mut operation: F, attempts: u32, delay: u64) -> Result<()>
    where
        F: FnMut() -> Fut,
        Fut: Future<Output = Result<()>>,
    {
        let mut attempt = 0;
        let mut delay = delay;
        let mut last = Error::database("All retry attempts failed");
        while attempt < attempts {
            match operation().await {
                Ok(()) => return Ok(()),
                Err(e) => {
                    if !self.is_retryable_failure(&e) {
                        return Err(e);
                    }
                    last = e;
                    attempt += 1;
                    if attempt >= attempts {
                        break;
                    }
                    utopia_async::sleep(Duration::from_millis(delay)).await;
                    delay *= 2;
                }
            }
        }
        Err(last)
    }

    /// Whether another attempt can clear `error` (`isRetryable()`): not when
    /// its writes may be stored, not when a transaction already retried it,
    /// and not for any typed failure of this library but a transaction (or
    /// contention) failure.
    pub(crate) fn is_retryable_failure(&self, error: &Error) -> bool {
        if self.may_have_committed(error) || self.retried_by_transaction(error) {
            return false;
        }
        const DETERMINISTIC: [Kind; 19] = [
            Kind::Authorization,
            Kind::Character,
            Kind::Conflict,
            Kind::Dependency,
            Kind::Duplicate,
            Kind::Index,
            Kind::Limit,
            Kind::NotFound,
            Kind::Operator,
            Kind::Order,
            Kind::Query,
            Kind::Refused,
            Kind::Relationship,
            Kind::Restricted,
            Kind::Structure,
            Kind::Timeout,
            Kind::Truncate,
            Kind::Type,
            Kind::Unconfirmed,
        ];
        !DETERMINISTIC.iter().any(|k| error.is_a(*k))
    }

    /// [`Database::with_retries`] of a cleanup step, logging a final
    /// failure (`cleanup()`).
    pub(crate) async fn cleanup<F, Fut>(&self, operation: F, resource: &str, id: &str, attempts: u32) -> Result<()>
    where
        F: FnMut() -> Fut,
        Fut: Future<Output = Result<()>>,
    {
        match self.with_retries(operation, attempts, 100).await {
            Ok(()) => Ok(()),
            Err(e) => {
                super::console_error(format!(
                    "Failed to cleanup {resource} '{id}' after {attempts} attempts: {}",
                    e.message()
                ));
                Err(e)
            }
        }
    }

    /// Persists a collection's definition in the metadata collection,
    /// silently and with retries (`updateMetadata()`). When it fails, and
    /// `should_rollback`, runs `rollback`; a failure raised after the
    /// definition committed, or a commit that could not be confirmed, is
    /// returned unchanged and rolls nothing back.
    ///
    /// Fails ([`Kind::Database`]) with `Failed to persist metadata after
    /// retries for <description>: <message>` (with the cleanup's failure
    /// appended or reported, as `rollback` says).
    pub(crate) async fn update_metadata(
        &self,
        collection: &Collection,
        rollback: Rollback<'_>,
        should_rollback: bool,
        description: &str,
    ) -> Result<()> {
        if collection.id() == METADATA {
            return Ok(());
        }
        let result = self
            .with_retries(
                || async {
                    self.silent(self.update_document(METADATA, collection.id(), collection.document().clone()))
                        .await
                        .map(|_| ())
                },
                3,
                100,
            )
            .await;
        let Err(e) = result else { return Ok(()) };
        if self.may_have_committed(&e) {
            return Err(e);
        }
        let mut cleanup = String::new();
        if should_rollback {
            match rollback {
                Rollback::None => {}
                Rollback::Errors(undo) => {
                    let errors = undo.await?;
                    if !errors.is_empty() {
                        return Err(Error::database(format!(
                            "Failed to persist metadata after retries and cleanup encountered errors for {description}: {} | Cleanup errors: {}",
                            e.message(),
                            errors.join(", ")
                        ))
                        .with_previous(e));
                    }
                }
                Rollback::Silent(undo) => {
                    if let Err(c) = undo.await {
                        cleanup = format!(" | Cleanup error: {}", c.message());
                    }
                }
                Rollback::Throwing(undo) => {
                    if let Err(c) = undo.await {
                        return Err(Error::database(format!(
                            "Failed to persist metadata after retries and cleanup failed for {description}: {} | Cleanup error: {}",
                            e.message(),
                            c.message()
                        ))
                        .with_previous(e));
                    }
                }
            }
        }
        Err(Error::database(format!(
            "Failed to persist metadata after retries for {description}: {}{cleanup}",
            e.message()
        ))
        .with_previous(e))
    }
}
