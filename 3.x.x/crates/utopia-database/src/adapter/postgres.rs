//! Postgres adapter (PHP `Adapter\Postgres`).

use super::sql::{quote_ansi, SqlAdapter};
use crate::error::Result;
use crate::impl_sql_engine;
use crate::pdo::Pdo;

/// Postgres adapter.
#[derive(Debug)]
pub struct Postgres {
    pub(crate) inner: SqlAdapter,
}

impl Postgres {
    /// Connect using host/port credentials.
    pub fn connect(host: &str, port: u16, user: &str, pass: &str, db: &str) -> Result<Self> {
        let pdo = Pdo::postgres(host, port, user, pass, db)?;
        Ok(Self {
            inner: SqlAdapter::new(pdo),
        })
    }

    /// Wrap an existing PDO.
    #[must_use]
    pub fn new(pdo: Pdo) -> Self {
        Self {
            inner: SqlAdapter::new(pdo),
        }
    }

    /// Quote an identifier.
    #[must_use]
    pub fn quote(&self, ident: &str) -> String {
        quote_ansi(ident)
    }
}

impl_sql_engine!(Postgres, "postgres");
