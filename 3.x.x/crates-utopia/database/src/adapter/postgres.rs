//! Postgres adapter.

use super::sql::{quote_ansi, SqlAdapter};
use crate::error::Result;
use crate::impl_sql_engine;
use crate::sql_client::SqlClient;

/// Postgres adapter.
#[derive(Debug)]
pub struct Postgres {
    pub(crate) inner: SqlAdapter,
}

impl Postgres {
    /// Connect using host/port credentials.
    pub fn connect(host: &str, port: u16, user: &str, pass: &str, db: &str) -> Result<Self> {
        let client = SqlClient::postgres(host, port, user, pass, db)?;
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
        quote_ansi(ident)
    }
}

impl_sql_engine!(Postgres, "postgres");
