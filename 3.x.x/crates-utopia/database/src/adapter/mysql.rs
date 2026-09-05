//! MySQL / MariaDB adapters.

use super::sql::{quote_mysql, SqlAdapter};
use crate::error::Result;
use crate::impl_sql_engine;
use crate::sql_client::SqlClient;

/// MySQL adapter.
#[derive(Debug)]
pub struct Mysql {
    pub(crate) inner: SqlAdapter,
}

impl Mysql {
    /// Connect using host/port credentials (no default database selected).
    pub fn connect(host: &str, port: u16, user: &str, pass: &str) -> Result<Self> {
        Self::connect_db(host, port, user, pass, None)
    }

    /// Connect and optionally select a default database/schema.
    pub fn connect_db(
        host: &str,
        port: u16,
        user: &str,
        pass: &str,
        database: Option<&str>,
    ) -> Result<Self> {
        let client = SqlClient::mysql(host, port, user, pass, database, false)?;
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

impl_sql_engine!(Mysql, "mysql");

/// MariaDB adapter.
#[derive(Debug)]
pub struct MariaDb {
    pub(crate) inner: SqlAdapter,
}

impl MariaDb {
    /// Connect using host/port credentials (no default database selected).
    pub fn connect(host: &str, port: u16, user: &str, pass: &str) -> Result<Self> {
        Self::connect_db(host, port, user, pass, None)
    }

    /// Connect and optionally select a default database/schema.
    pub fn connect_db(
        host: &str,
        port: u16,
        user: &str,
        pass: &str,
        database: Option<&str>,
    ) -> Result<Self> {
        let client = SqlClient::mysql(host, port, user, pass, database, true)?;
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

impl_sql_engine!(MariaDb, "mariadb");
