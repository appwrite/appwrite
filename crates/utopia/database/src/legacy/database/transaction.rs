//! Transactions.
//!
//! Area: schema.

use std::future::Future;

use super::Database;
use crate::error::Result;

impl Database {
    /// Runs `f` in a transaction on the adapter's connection: commits when
    /// it succeeds, rolls back when it fails, and calls it again (up to
    /// twice) on failures other than duplicate, restricted, authorization,
    /// relationship, conflict, limit and timeout errors. `f` must be safe
    /// to run again.
    ///
    /// PHP: `Database::withTransaction()`.
    pub async fn with_transaction<T, F, Fut>(&self, f: F) -> Result<T>
    where
        F: FnMut() -> Fut,
        Fut: Future<Output = Result<T>>,
    {
        crate::adapter::with_transaction(&*self.inner.adapter, f).await
    }
}
