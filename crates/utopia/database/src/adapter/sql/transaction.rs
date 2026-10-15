//! Transactions: one per connection, nested ones as savepoints.
//!
//! PHP: `SQL::startTransaction()`, `commitTransaction()`,
//! `rollbackTransaction()`, `abandonTransaction()`, and `Postgres`'s and
//! `SQLite`'s overrides.

use super::{Engine, Sql};
use crate::adapter::Adapter;
use crate::error::{Error, Result};

/// `new TransactionException($prefix.$e->getMessage(), $e->getCode(), $e)`.
fn transaction_error(prefix: &str, e: Error) -> Error {
    let state = e.state().map(|s| s.as_bytes().to_vec());
    let mut error = Error::transaction(format!("{prefix}{}", e.message()));
    if let Some(state) = state {
        error = error.with_string_code(&state);
    }
    error.with_previous(e)
}

impl Sql {
    fn depth(&self) -> i64 {
        self.state().lock().in_transaction
    }

    fn set_depth(&self, depth: i64) {
        self.state().lock().in_transaction = depth;
    }

    /// Runs `sql` as SQLite's transaction statements run (`prepare()`, then
    /// `execute()`).
    async fn sqlite_statement(&self, sql: &str) -> Result<bool> {
        let mut prepared = self.prepare_statement(sql, None).await?;
        self.execute(&mut prepared).await
    }

    /// `startTransaction()`: a transaction, or a savepoint inside one.
    pub(crate) async fn start_transaction(&self) -> Result<bool> {
        let depth = self.depth();
        let result: Result<bool> = async {
            if self.engine == Engine::Sqlite {
                if depth == 0 {
                    if self.connection.lock().await.in_transaction() {
                        self.sqlite_statement("ROLLBACK").await?;
                    }
                    return self.sqlite_statement("BEGIN IMMEDIATE").await;
                }
                return self.sqlite_statement(&format!("SAVEPOINT transaction{depth}")).await;
            }
            if depth == 0 {
                let in_transaction = self.connection.lock().await.in_transaction();
                // A pooled connection can report a transaction it no longer
                // holds: the cleanup rollback is best effort.
                let _ = if in_transaction {
                    self.connection.lock().await.roll_back().await.map(|_| ())
                } else {
                    async {
                        let mut prepared = self.prepare_statement("ROLLBACK", None).await?;
                        let mut pdo = self.connection.lock().await;
                        prepared.statement.execute(&mut pdo).await.map(|_| ())
                    }
                    .await
                };
                self.connection.lock().await.begin_transaction().await
            } else {
                self.connection.lock().await.exec(&format!("SAVEPOINT transaction{depth}")).await.map(|_| true)
            }
        }
        .await;
        match result {
            Err(e) if e.kind() == crate::Kind::Pdo => Err(transaction_error("Failed to start transaction: ", e)),
            Err(e) => Err(e),
            Ok(false) => Err(Error::transaction("Failed to start transaction")),
            Ok(true) => {
                self.set_depth(depth + 1);
                Ok(true)
            }
        }
    }

    /// `commitTransaction()`: commits the outermost transaction, releases a
    /// savepoint (SQLite) or only counts down a nested one.
    pub(crate) async fn commit_transaction(&self) -> Result<bool> {
        let result = self.commit_inner().await;
        if self.engine == Engine::Postgres && self.depth() == 0 {
            self.engine_state().local_timeout = Some(0);
        }
        result
    }

    async fn commit_inner(&self) -> Result<bool> {
        let depth = self.depth();
        if depth == 0 {
            return Ok(false);
        }
        if self.engine == Engine::Sqlite {
            let result = if depth > 1 {
                let r = self.sqlite_statement(&format!("RELEASE SAVEPOINT transaction{}", depth - 1)).await;
                if r.is_ok() {
                    self.set_depth(depth - 1);
                }
                r
            } else {
                let r = self.sqlite_statement("COMMIT").await;
                if r.is_ok() {
                    self.set_depth(0);
                }
                r
            };
            return result.map_err(|e| match e.kind() {
                crate::Kind::Pdo => transaction_error("Failed to commit transaction: ", e),
                _ => e,
            });
        }
        let mut pdo = self.connection.lock().await;
        if !pdo.in_transaction() {
            self.set_depth(0);
            return Err(Error::transaction(
                "Failed to commit transaction: the connection no longer holds the transaction",
            ));
        }
        if depth > 1 {
            self.set_depth(depth - 1);
            return Ok(true);
        }
        match pdo.commit().await {
            Ok(true) => {
                self.set_depth(0);
                Ok(true)
            }
            Ok(false) => {
                self.set_depth(0);
                Err(Error::transaction("Failed to commit transaction"))
            }
            Err(e) if e.kind() == crate::Kind::Pdo => Err(transaction_error("Failed to commit transaction: ", e)),
            Err(e) => Err(e),
        }
    }

    /// `rollbackTransaction()`: rolls back to the innermost savepoint, or
    /// the whole transaction.
    pub(crate) async fn rollback_transaction(&self) -> Result<bool> {
        let result = self.rollback_inner().await;
        if self.engine == Engine::Postgres {
            self.engine_state().local_timeout = if self.depth() == 0 { Some(0) } else { None };
        }
        result
    }

    async fn rollback_inner(&self) -> Result<bool> {
        let depth = self.depth();
        if depth == 0 {
            return Ok(false);
        }
        let result: Result<bool> = async {
            if depth > 1 {
                let statement = format!("ROLLBACK TO transaction{}", depth - 1);
                if self.engine == Engine::Sqlite {
                    self.sqlite_statement(&statement).await?;
                } else {
                    self.connection.lock().await.exec(&statement).await?;
                }
                self.set_depth(depth - 1);
                return Ok(true);
            }
            let result = if self.engine == Engine::Sqlite {
                self.sqlite_statement("ROLLBACK").await?;
                true
            } else {
                self.connection.lock().await.roll_back().await?
            };
            self.set_depth(0);
            Ok(result || self.engine == Engine::Sqlite)
        }
        .await;
        match result {
            Err(e) => {
                self.set_depth(0);
                let state = e.state().map(|s| s.as_bytes().to_vec());
                let mut error = Error::database(format!("Failed to rollback transaction: {}", e.message()));
                if let Some(state) = state {
                    error = error.with_string_code(&state);
                }
                Err(error.with_previous(e))
            }
            Ok(false) => Err(Error::transaction("Failed to rollback transaction")),
            Ok(true) => Ok(true),
        }
    }

    /// `abandonTransaction()`: rolls back what the connection still holds
    /// of a failed outermost attempt.
    pub(crate) async fn abandon_transaction(&self) {
        let mut pdo = self.connection.lock().await;
        if !pdo.in_transaction() {
            return;
        }
        let _ = pdo.roll_back().await;
    }
}
