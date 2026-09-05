//! SQLite adapter (PHP `Adapter\SQLite`).

use super::sql::{quote_mysql, SqlAdapter};
use crate::error::Result;
use crate::impl_sql_engine;
use crate::sql_client::SqlClient;

/// SQLite adapter.
#[derive(Debug)]
pub struct Sqlite {
    pub(crate) inner: SqlAdapter,
}

impl Sqlite {
    /// Open a SQLite database at `path` (`:memory:` allowed).
    pub fn new(path: impl AsRef<str>) -> Result<Self> {
        Self::open(path)
    }

    /// Open a SQLite database at `path` (`:memory:` allowed).
    pub fn open(path: impl AsRef<str>) -> Result<Self> {
        let client = SqlClient::sqlite(path.as_ref())?;
        Ok(Self {
            inner: SqlAdapter::new(client),
        })
    }

    /// Wrap an existing [`SqlClient`].
    #[must_use]
    pub fn from_client(client: SqlClient) -> Self {
        Self {
            inner: SqlAdapter::new(client),
        }
    }

    /// Quote an identifier.
    #[must_use]
    pub fn quote(&self, ident: &str) -> String {
        quote_mysql(ident)
    }
}

impl_sql_engine!(Sqlite, "sqlite");
