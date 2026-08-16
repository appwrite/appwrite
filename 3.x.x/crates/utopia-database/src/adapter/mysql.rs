//! MySQL / MariaDB adapters (PHP `Adapter\MySQL`, `Adapter\MariaDB`).

use super::sql::{quote_mysql, SqlAdapter};
use crate::error::Result;
use crate::impl_sql_engine;
use crate::pdo::Pdo;

/// MySQL adapter (PHP `Utopia\Database\Adapter\MySQL`).
#[derive(Debug)]
pub struct Mysql {
    pub(crate) inner: SqlAdapter,
}

impl Mysql {
    /// Connect using host/port credentials.
    pub fn connect(host: &str, port: u16, user: &str, pass: &str) -> Result<Self> {
        let pdo = Pdo::mysql(host, port, user, pass, None, false)?;
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
        quote_mysql(ident)
    }
}

impl_sql_engine!(Mysql, "mysql");

/// MariaDB adapter (PHP `Utopia\Database\Adapter\MariaDB`).
#[derive(Debug)]
pub struct MariaDb {
    pub(crate) inner: SqlAdapter,
}

impl MariaDb {
    /// Connect using host/port credentials.
    pub fn connect(host: &str, port: u16, user: &str, pass: &str) -> Result<Self> {
        let pdo = Pdo::mysql(host, port, user, pass, None, true)?;
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
        quote_mysql(ident)
    }
}

impl_sql_engine!(MariaDb, "mariadb");
