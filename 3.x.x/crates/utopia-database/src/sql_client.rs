//! Engine-specific SQL client used by the SQL adapters.
//!
//! This is the Rust-native connection layer behind
//! [`crate::adapter::postgres::Postgres`], [`crate::adapter::mysql::Mysql`],
//! and [`crate::adapter::sqlite::Sqlite`]. Prefer those adapters (and the
//! high-level [`crate::Database`] API) over talking to [`SqlClient`] directly.
//!
//! Live connections are compiled behind the `mysql`, `postgres`, and
//! `sqlite` features.

use std::collections::HashMap;

use crate::error::DatabaseError;
use crate::value::AttrValue;
use indexmap::IndexMap;
use serde_json::Number;

/// SQL dialect spoken by a live [`SqlClient`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Dialect {
    Mysql,
    Mariadb,
    Postgres,
    Sqlite,
}

/// Bound parameter for live queries.
#[derive(Debug, Clone)]
pub enum SqlParam {
    Null,
    Bool(bool),
    I64(i64),
    F64(f64),
    Text(String),
}

impl SqlParam {
    #[must_use]
    pub fn from_attr(value: &AttrValue) -> Self {
        match value {
            AttrValue::Null => Self::Null,
            AttrValue::Bool(b) => Self::Bool(*b),
            AttrValue::Number(n) => {
                if let Some(i) = n.as_i64() {
                    Self::I64(i)
                } else if let Some(u) = n.as_u64() {
                    Self::I64(i64::try_from(u).unwrap_or(i64::MAX))
                } else if let Some(f) = n.as_f64() {
                    Self::F64(f)
                } else {
                    Self::Text(n.to_string())
                }
            }
            AttrValue::String(s) => Self::Text(s.clone()),
            other => Self::Text(other.to_json().to_string()),
        }
    }

    #[must_use]
    pub fn from_str(value: impl Into<String>) -> Self {
        Self::Text(value.into())
    }
}

/// A prepared statement helper used by SQL adapters.
#[derive(Debug, Default)]
pub struct SqlStatement {
    query: String,
    params: HashMap<String, String>,
}

impl SqlStatement {
    /// Create a statement for `query`.
    pub fn new(query: impl Into<String>) -> Self {
        Self {
            query: query.into(),
            params: HashMap::new(),
        }
    }

    /// Bind a named or positional parameter.
    pub fn bind_value(&mut self, param: impl Into<String>, value: impl ToString) {
        self.params.insert(param.into(), value.to_string());
    }

    /// The SQL string.
    pub fn query(&self) -> &str {
        &self.query
    }

    /// Bound parameters.
    pub fn params(&self) -> &HashMap<String, String> {
        &self.params
    }
}

pub struct SqlClient {
    dsn: String,
    dialect: Dialect,
    last_insert_id: String,
    #[cfg(feature = "mysql")]
    mysql: Option<mysql::Conn>,
    #[cfg(feature = "postgres")]
    postgres: Option<std::sync::Arc<std::sync::Mutex<PostgresGuard>>>,
    #[cfg(feature = "sqlite")]
    sqlite: Option<rusqlite::Connection>,
}

impl std::fmt::Debug for SqlClient {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SqlClient")
            .field("dsn", &self.dsn)
            .field("dialect", &self.dialect)
            .finish_non_exhaustive()
    }
}

impl SqlClient {
    /// Connect using a DSN string (PHP constructor). Live I/O requires features.
    pub fn new(dsn: impl Into<String>) -> Result<Self, DatabaseError> {
        let dsn = dsn.into();
        let dialect = dialect_from_dsn(&dsn);
        Ok(Self {
            dsn,
            dialect,
            last_insert_id: "0".into(),
            #[cfg(feature = "mysql")]
            mysql: None,
            #[cfg(feature = "postgres")]
            postgres: None,
            #[cfg(feature = "sqlite")]
            sqlite: None,
        })
    }

    /// The connection DSN.
    pub fn dsn(&self) -> &str {
        &self.dsn
    }

    /// Active dialect.
    pub fn dialect(&self) -> Dialect {
        self.dialect
    }

    /// Prepare a statement (PHP `prepare`).
    pub fn prepare(&self, query: impl Into<String>) -> SqlStatement {
        SqlStatement::new(query)
    }

    /// Quote a string for interpolation (PHP `quote`).
    pub fn quote(&self, value: &str) -> String {
        format!("'{}'", value.replace('\'', "''"))
    }

    /// Last insert id (PHP `lastInsertId`).
    pub fn last_insert_id(&self) -> &str {
        &self.last_insert_id
    }

    /// Execute a statement with named parameters (`:name`).
    pub fn exec(&mut self, sql: &str, params: &[(&str, SqlParam)]) -> Result<u64, DatabaseError> {
        let _ = (sql, params);
        #[cfg(feature = "mysql")]
        if self.mysql.is_some() {
            return self.exec_mysql(sql, params);
        }
        #[cfg(feature = "postgres")]
        if self.postgres.is_some() {
            return self.exec_postgres(sql, params);
        }
        #[cfg(feature = "sqlite")]
        if self.sqlite.is_some() {
            return self.exec_sqlite(sql, params);
        }
        Err(DatabaseError::database("SQL client is not connected"))
    }

    /// Query rows with named parameters.
    pub fn query(
        &mut self,
        sql: &str,
        params: &[(&str, SqlParam)],
    ) -> Result<Vec<IndexMap<String, AttrValue>>, DatabaseError> {
        let _ = (sql, params);
        #[cfg(feature = "mysql")]
        if self.mysql.is_some() {
            return self.query_mysql(sql, params);
        }
        #[cfg(feature = "postgres")]
        if self.postgres.is_some() {
            return self.query_postgres(sql, params);
        }
        #[cfg(feature = "sqlite")]
        if self.sqlite.is_some() {
            return self.query_sqlite(sql, params);
        }
        Err(DatabaseError::database("SQL client is not connected"))
    }

    /// `SELECT 1` ping.
    pub fn ping(&mut self) -> Result<bool, DatabaseError> {
        self.query("SELECT 1", &[]).map(|rows| !rows.is_empty())
    }
}

/// Unlike the sync `postgres` crate (see [`postgres_blocking`]), the sync
/// `mysql` crate does its own socket I/O and never calls back into a Tokio
/// runtime, so calling it from an async task cannot panic. It still parks
/// the calling OS thread for the query's full round-trip, though, and with a
/// real connection pool checked out per request (`appwrite_platform::db`)
/// many requests can be doing that at once. `block_in_place` hands the
/// current worker thread back to Tokio's scheduler for that duration instead
/// of one query stalling whatever else that worker would have run, the same
/// reason `postgres_blocking` wraps every `postgres` crate call.
#[cfg(feature = "mysql")]
fn mysql_blocking<T>(f: impl FnOnce() -> T) -> T {
    match tokio::runtime::Handle::try_current() {
        Ok(_) => tokio::task::block_in_place(f),
        Err(_) => f(),
    }
}

#[cfg(feature = "mysql")]
impl SqlClient {
    /// Connect to MySQL / MariaDB.
    pub fn mysql(
        host: &str,
        port: u16,
        user: &str,
        pass: &str,
        db: Option<&str>,
        mariadb: bool,
    ) -> Result<Self, DatabaseError> {
        use mysql::prelude::Queryable;
        use mysql::{OptsBuilder, SslOpts};

        let mut opts = OptsBuilder::new()
            .ip_or_hostname(Some(host))
            .tcp_port(port)
            .user(Some(user))
            .pass(Some(pass))
            .db_name(db)
            .prefer_socket(false)
            .ssl_opts(Option::<SslOpts>::None);
        let _ = &mut opts;
        let mut conn = mysql_blocking(|| mysql::Conn::new(opts))
            .map_err(|e| DatabaseError::database(format!("MySQL connect failed: {e}")))?;
        mysql_blocking(|| conn.query_drop("SET NAMES utf8mb4"))
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        let dsn = format!(
            "mysql:host={host};port={port}{}",
            db.map(|d| format!(";dbname={d}")).unwrap_or_default()
        );
        Ok(Self {
            dsn,
            dialect: if mariadb {
                Dialect::Mariadb
            } else {
                Dialect::Mysql
            },
            last_insert_id: "0".into(),
            mysql: Some(conn),
            #[cfg(feature = "postgres")]
            postgres: None,
            #[cfg(feature = "sqlite")]
            sqlite: None,
        })
    }

    fn exec_mysql(&mut self, sql: &str, params: &[(&str, SqlParam)]) -> Result<u64, DatabaseError> {
        use mysql::prelude::Queryable;
        let conn = self
            .mysql
            .as_mut()
            .ok_or_else(|| DatabaseError::database("MySQL SQL client is not connected"))?;
        let (sql, values) = rewrite_mysql(sql, params);
        mysql_blocking(|| conn.exec_drop(&sql, mysql::Params::Positional(values)))
            .map_err(map_mysql)?;
        self.last_insert_id = conn.last_insert_id().to_string();
        Ok(conn.affected_rows())
    }

    fn query_mysql(
        &mut self,
        sql: &str,
        params: &[(&str, SqlParam)],
    ) -> Result<Vec<IndexMap<String, AttrValue>>, DatabaseError> {
        use mysql::prelude::Queryable;
        let conn = self
            .mysql
            .as_mut()
            .ok_or_else(|| DatabaseError::database("MySQL SQL client is not connected"))?;
        let (sql, values) = rewrite_mysql(sql, params);
        let result: Vec<mysql::Row> =
            mysql_blocking(|| conn.exec(&sql, mysql::Params::Positional(values)))
                .map_err(map_mysql)?;
        Ok(result.into_iter().map(mysql_row_to_map).collect())
    }
}

/// Owns a [`postgres::Client`] and never runs its `Drop`/`close_inner` on a
/// Tokio worker thread. Closing uses the client's private runtime `block_on`,
/// which panics under Hyper ("Cannot start a runtime from within a runtime").
#[cfg(feature = "postgres")]
struct PostgresGuard(Option<postgres::Client>);

#[cfg(feature = "postgres")]
impl PostgresGuard {
    fn new(client: postgres::Client) -> Self {
        Self(Some(client))
    }

    fn with_mut<T>(&mut self, f: impl FnOnce(&mut postgres::Client) -> T) -> T {
        f(self.0.as_mut().expect("postgres client present"))
    }
}

#[cfg(feature = "postgres")]
impl Drop for PostgresGuard {
    fn drop(&mut self) {
        let Some(client) = self.0.take() else {
            return;
        };
        // Isolate close/drop from Hyper's runtime - same reason as
        // [`postgres_blocking`] for queries.
        if tokio::runtime::Handle::try_current().is_ok() {
            let (tx, rx) = std::sync::mpsc::sync_channel(1);
            let _ = std::thread::Builder::new()
                .name("postgres-drop".into())
                .spawn(move || {
                    drop(client);
                    let _ = tx.send(());
                });
            let _ = tokio::task::block_in_place(|| rx.recv());
        } else {
            drop(client);
        }
    }
}

/// The sync `postgres` crate owns a private current-thread Tokio `Runtime`
/// per client. Calling that runtime's `block_on` while the calling thread is
/// entered into Hyper's multi-thread runtime panics with "Cannot start a
/// runtime from within a runtime". [`tokio::task::block_in_place`] and even
/// `std::thread::scope` on a Tokio worker have still left that panic on the
/// worker thread in practice under load.
///
/// So when an ambient Tokio handle is present, every connect/query runs on a
/// detached OS thread that never enters the process runtime (`Client` is held
/// behind a [`std::sync::Mutex`] so the closure can be `'static`). The caller
/// waits under `block_in_place` so Hyper can keep scheduling.
#[cfg(feature = "postgres")]
fn postgres_blocking<T: Send + 'static>(f: impl FnOnce() -> T + Send + 'static) -> T {
    match tokio::runtime::Handle::try_current() {
        Ok(_) => {
            let (tx, rx) = std::sync::mpsc::sync_channel(1);
            std::thread::Builder::new()
                .name("postgres-sql".into())
                .spawn(move || {
                    let _ = tx.send(f());
                })
                .expect("spawn postgres-sql thread");
            tokio::task::block_in_place(|| rx.recv().expect("postgres-sql result"))
        }
        Err(_) => f(),
    }
}

#[cfg(feature = "postgres")]
impl SqlClient {
    /// Connect to Postgres via the `postgres` crate.
    pub fn postgres(
        host: &str,
        port: u16,
        user: &str,
        pass: &str,
        db: &str,
    ) -> Result<Self, DatabaseError> {
        let url = format!("host={host} port={port} user={user} password={pass} dbname={db}");
        let client = postgres_blocking(move || postgres::Client::connect(&url, postgres::NoTls))
            .map_err(|e| DatabaseError::database(format!("Postgres connect failed: {e}")))?;
        Ok(Self {
            dsn: format!("pgsql:host={host};port={port};dbname={db}"),
            dialect: Dialect::Postgres,
            last_insert_id: "0".into(),
            #[cfg(feature = "mysql")]
            mysql: None,
            postgres: Some(std::sync::Arc::new(std::sync::Mutex::new(PostgresGuard::new(client)))),
            #[cfg(feature = "sqlite")]
            sqlite: None,
        })
    }

    fn exec_postgres(
        &mut self,
        sql: &str,
        params: &[(&str, SqlParam)],
    ) -> Result<u64, DatabaseError> {
        let client = self
            .postgres
            .as_ref()
            .ok_or_else(|| DatabaseError::database("Postgres SQL client is not connected"))?
            .clone();
        let (sql, owned) = rewrite_postgres(sql, params);
        let n = postgres_blocking(move || {
            let mut guard = client
                .lock()
                .unwrap_or_else(std::sync::PoisonError::into_inner);
            guard.with_mut(|client| {
                let refs = postgres_refs(&owned);
                client.execute(&sql, refs.as_slice())
            })
        })
        .map_err(|e| map_postgres(&e))?;
        Ok(n)
    }

    fn query_postgres(
        &mut self,
        sql: &str,
        params: &[(&str, SqlParam)],
    ) -> Result<Vec<IndexMap<String, AttrValue>>, DatabaseError> {
        let client = self
            .postgres
            .as_ref()
            .ok_or_else(|| DatabaseError::database("Postgres SQL client is not connected"))?
            .clone();
        let (sql, owned) = rewrite_postgres(sql, params);
        let rows = postgres_blocking(move || {
            let mut guard = client
                .lock()
                .unwrap_or_else(std::sync::PoisonError::into_inner);
            guard.with_mut(|client| {
                let refs = postgres_refs(&owned);
                client.query(&sql, refs.as_slice())
            })
        })
        .map_err(|e| map_postgres(&e))?;
        Ok(rows.iter().map(postgres_row_to_map).collect())
    }

    /// Record a last-insert id from a RETURNING clause.
    pub fn set_last_insert_id(&mut self, id: impl Into<String>) {
        self.last_insert_id = id.into();
    }
}

#[cfg(feature = "sqlite")]
impl SqlClient {
    /// Open SQLite at `path` (`:memory:` allowed) via `rusqlite`.
    pub fn sqlite(path: &str) -> Result<Self, DatabaseError> {
        let conn = rusqlite::Connection::open(path)
            .map_err(|e| DatabaseError::database(format!("SQLite open failed: {e}")))?;
        conn.execute_batch("PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;")
            .map_err(|e| DatabaseError::database(e.to_string()))?;
        Ok(Self {
            dsn: format!("sqlite:{path}"),
            dialect: Dialect::Sqlite,
            last_insert_id: "0".into(),
            #[cfg(feature = "mysql")]
            mysql: None,
            #[cfg(feature = "postgres")]
            postgres: None,
            sqlite: Some(conn),
        })
    }

    fn exec_sqlite(
        &mut self,
        sql: &str,
        params: &[(&str, SqlParam)],
    ) -> Result<u64, DatabaseError> {
        let conn = self
            .sqlite
            .as_mut()
            .ok_or_else(|| DatabaseError::database("SQLite SQL client is not connected"))?;
        let (sql, values) = rewrite_sqlite(sql, params);
        let n = conn
            .execute(&sql, rusqlite::params_from_iter(values.iter()))
            .map_err(map_sqlite)?;
        self.last_insert_id = conn.last_insert_rowid().to_string();
        Ok(n as u64)
    }

    fn query_sqlite(
        &mut self,
        sql: &str,
        params: &[(&str, SqlParam)],
    ) -> Result<Vec<IndexMap<String, AttrValue>>, DatabaseError> {
        let conn = self
            .sqlite
            .as_mut()
            .ok_or_else(|| DatabaseError::database("SQLite SQL client is not connected"))?;
        let (sql, values) = rewrite_sqlite(sql, params);
        let mut stmt = conn.prepare(&sql).map_err(map_sqlite)?;
        let column_names: Vec<String> =
            stmt.column_names().into_iter().map(str::to_owned).collect();
        let mut rows = stmt
            .query(rusqlite::params_from_iter(values.iter()))
            .map_err(map_sqlite)?;
        let mut out = Vec::new();
        while let Some(row) = rows.next().map_err(map_sqlite)? {
            let mut map = IndexMap::new();
            for (i, name) in column_names.iter().enumerate() {
                map.insert(name.clone(), sqlite_value(row, i));
            }
            out.push(map);
        }
        Ok(out)
    }
}

fn dialect_from_dsn(dsn: &str) -> Dialect {
    let lower = dsn.to_ascii_lowercase();
    if lower.starts_with("pgsql:") || lower.starts_with("postgres") {
        Dialect::Postgres
    } else if lower.starts_with("sqlite:") {
        Dialect::Sqlite
    } else if lower.contains("mariadb") {
        Dialect::Mariadb
    } else {
        Dialect::Mysql
    }
}

fn rewrite_named(
    sql: &str,
    params: &[(&str, SqlParam)],
    placeholder: impl Fn(usize) -> String,
) -> (String, Vec<SqlParam>) {
    let mut out = String::new();
    let mut values = Vec::new();
    let mut chars = sql.chars().peekable();
    while let Some(c) = chars.next() {
        if c == ':' {
            let mut name = String::new();
            while let Some(&n) = chars.peek() {
                if n.is_ascii_alphanumeric() || n == '_' {
                    name.push(n);
                    chars.next();
                } else {
                    break;
                }
            }
            if name.is_empty() {
                out.push(':');
                continue;
            }
            let key = format!(":{name}");
            if let Some((_, value)) = params.iter().find(|(k, _)| *k == key || *k == name) {
                values.push(value.clone());
                out.push_str(&placeholder(values.len()));
            } else {
                out.push(':');
                out.push_str(&name);
            }
        } else {
            out.push(c);
        }
    }
    (out, values)
}

#[cfg(feature = "mysql")]
fn rewrite_mysql(sql: &str, params: &[(&str, SqlParam)]) -> (String, Vec<mysql::Value>) {
    let (sql, values) = rewrite_named(sql, params, |_| "?".into());
    (sql, values.into_iter().map(to_mysql_value).collect())
}

#[cfg(feature = "mysql")]
fn to_mysql_value(value: SqlParam) -> mysql::Value {
    match value {
        SqlParam::Null => mysql::Value::NULL,
        SqlParam::Bool(b) => mysql::Value::Int(i64::from(b)),
        SqlParam::I64(i) => mysql::Value::Int(i),
        SqlParam::F64(f) => mysql::Value::Double(f),
        SqlParam::Text(s) => mysql::Value::Bytes(s.into_bytes()),
    }
}

#[cfg(feature = "mysql")]
fn mysql_row_to_map(row: mysql::Row) -> IndexMap<String, AttrValue> {
    let columns = row.columns_ref().to_vec();
    let mut map = IndexMap::new();
    for (i, col) in columns.iter().enumerate() {
        let name = col.name_str().to_string();
        let value = row.as_ref(i).map_or(AttrValue::Null, mysql_value_to_attr);
        map.insert(name, value);
    }
    map
}

#[cfg(feature = "mysql")]
fn mysql_value_to_attr(value: &mysql::Value) -> AttrValue {
    match value {
        mysql::Value::NULL => AttrValue::Null,
        mysql::Value::Int(i) => AttrValue::Number((*i).into()),
        mysql::Value::UInt(u) => {
            if let Ok(i) = i64::try_from(*u) {
                AttrValue::Number(i.into())
            } else {
                AttrValue::from(u.to_string())
            }
        }
        mysql::Value::Float(f) => number_from_f64(f64::from(*f)),
        mysql::Value::Double(f) => number_from_f64(*f),
        mysql::Value::Bytes(b) => AttrValue::from(String::from_utf8_lossy(b).into_owned()),
        other => AttrValue::from(format!("{other:?}")),
    }
}

#[cfg(feature = "mysql")]
fn map_mysql(err: mysql::Error) -> DatabaseError {
    let msg = err.to_string();
    if msg.contains("Duplicate") || msg.contains("1062") {
        DatabaseError::duplicate(msg)
    } else {
        DatabaseError::database(msg)
    }
}

#[cfg(feature = "postgres")]
#[derive(Debug)]
struct PgNull;

#[cfg(feature = "postgres")]
#[derive(Debug)]
struct PgFlexible(String);

#[cfg(feature = "postgres")]
impl postgres::types::ToSql for PgNull {
    fn to_sql(
        &self,
        _ty: &postgres::types::Type,
        _out: &mut bytes::BytesMut,
    ) -> Result<postgres::types::IsNull, Box<dyn std::error::Error + Sync + Send>> {
        Ok(postgres::types::IsNull::Yes)
    }

    fn accepts(_ty: &postgres::types::Type) -> bool {
        true
    }

    postgres::types::to_sql_checked!();
}

#[cfg(feature = "postgres")]
impl postgres::types::ToSql for PgFlexible {
    fn to_sql(
        &self,
        ty: &postgres::types::Type,
        out: &mut bytes::BytesMut,
    ) -> Result<postgres::types::IsNull, Box<dyn std::error::Error + Sync + Send>> {
        use postgres::types::{Json, Type};
        match *ty {
            Type::JSON | Type::JSONB => {
                let value: serde_json::Value = serde_json::from_str(&self.0)
                    .unwrap_or_else(|_| serde_json::Value::String(self.0.clone()));
                Json(value).to_sql(ty, out)
            }
            Type::TIMESTAMP | Type::TIMESTAMPTZ => {
                let parsed = crate::datetime::parse_php_datetime(&self.0)
                    .ok_or_else(|| format!("invalid timestamp {}", self.0))?;
                parsed.to_sql(ty, out)
            }
            Type::BOOL => {
                let value = self.0 == "1" || self.0.eq_ignore_ascii_case("true");
                value.to_sql(ty, out)
            }
            Type::INT2 => self.0.parse::<i16>()?.to_sql(ty, out),
            Type::INT4 => self.0.parse::<i32>()?.to_sql(ty, out),
            Type::INT8 => self.0.parse::<i64>()?.to_sql(ty, out),
            Type::FLOAT4 => self.0.parse::<f32>()?.to_sql(ty, out),
            Type::FLOAT8 => self.0.parse::<f64>()?.to_sql(ty, out),
            _ => self.0.to_sql(ty, out),
        }
    }

    fn accepts(_ty: &postgres::types::Type) -> bool {
        true
    }

    postgres::types::to_sql_checked!();
}

/// A bound integer. `i64`'s own `ToSql` accepts `INT8` only, so binding one
/// to a narrower column (`tokens.type` is `INT4`) fails with "error
/// serializing parameter N". Widen/narrow against the column type the way the
/// PHP driver's untyped bind does.
#[cfg(feature = "postgres")]
#[derive(Debug)]
struct PgInt(i64);

#[cfg(feature = "postgres")]
impl postgres::types::ToSql for PgInt {
    fn to_sql(
        &self,
        ty: &postgres::types::Type,
        out: &mut bytes::BytesMut,
    ) -> Result<postgres::types::IsNull, Box<dyn std::error::Error + Sync + Send>> {
        use postgres::types::{Json, Type};
        match *ty {
            Type::INT2 => i16::try_from(self.0)?.to_sql(ty, out),
            Type::INT4 => i32::try_from(self.0)?.to_sql(ty, out),
            Type::INT8 => self.0.to_sql(ty, out),
            #[allow(clippy::cast_precision_loss)]
            Type::FLOAT4 => (self.0 as f32).to_sql(ty, out),
            #[allow(clippy::cast_precision_loss)]
            Type::FLOAT8 => (self.0 as f64).to_sql(ty, out),
            Type::BOOL => (self.0 != 0).to_sql(ty, out),
            Type::JSON | Type::JSONB => Json(serde_json::json!(self.0)).to_sql(ty, out),
            _ => self.0.to_string().to_sql(ty, out),
        }
    }

    fn accepts(_ty: &postgres::types::Type) -> bool {
        true
    }

    postgres::types::to_sql_checked!();
}

/// A bound float, widened/narrowed like [`PgInt`] (`f64` accepts `FLOAT8`
/// only, so a `real` column would otherwise fail).
#[cfg(feature = "postgres")]
#[derive(Debug)]
struct PgFloat(f64);

#[cfg(feature = "postgres")]
impl postgres::types::ToSql for PgFloat {
    fn to_sql(
        &self,
        ty: &postgres::types::Type,
        out: &mut bytes::BytesMut,
    ) -> Result<postgres::types::IsNull, Box<dyn std::error::Error + Sync + Send>> {
        use postgres::types::{Json, Type};
        #[allow(clippy::cast_possible_truncation)]
        match *ty {
            Type::FLOAT4 => (self.0 as f32).to_sql(ty, out),
            Type::FLOAT8 => self.0.to_sql(ty, out),
            Type::INT2 => (self.0 as i16).to_sql(ty, out),
            Type::INT4 => (self.0 as i32).to_sql(ty, out),
            Type::INT8 => (self.0 as i64).to_sql(ty, out),
            Type::BOOL => (self.0 != 0.0).to_sql(ty, out),
            Type::JSON | Type::JSONB => Json(serde_json::json!(self.0)).to_sql(ty, out),
            _ => self.0.to_string().to_sql(ty, out),
        }
    }

    fn accepts(_ty: &postgres::types::Type) -> bool {
        true
    }

    postgres::types::to_sql_checked!();
}

/// A bound boolean, which Appwrite also stores in `INT`-typed columns
/// (`Database::VAR_BOOLEAN` maps to `TINYINT` on MySQL) and in JSON.
#[cfg(feature = "postgres")]
#[derive(Debug)]
struct PgBool(bool);

#[cfg(feature = "postgres")]
impl postgres::types::ToSql for PgBool {
    fn to_sql(
        &self,
        ty: &postgres::types::Type,
        out: &mut bytes::BytesMut,
    ) -> Result<postgres::types::IsNull, Box<dyn std::error::Error + Sync + Send>> {
        use postgres::types::{Json, Type};
        let as_int = i64::from(self.0);
        match *ty {
            Type::BOOL => self.0.to_sql(ty, out),
            Type::INT2 => (as_int as i16).to_sql(ty, out),
            Type::INT4 => (as_int as i32).to_sql(ty, out),
            Type::INT8 => as_int.to_sql(ty, out),
            Type::JSON | Type::JSONB => Json(serde_json::json!(self.0)).to_sql(ty, out),
            _ => self.0.to_string().to_sql(ty, out),
        }
    }

    fn accepts(_ty: &postgres::types::Type) -> bool {
        true
    }

    postgres::types::to_sql_checked!();
}

#[cfg(feature = "postgres")]
#[derive(Debug)]
enum PgOwned {
    Null,
    Bool(PgBool),
    I64(PgInt),
    F64(PgFloat),
    Text(PgFlexible),
}

#[cfg(feature = "postgres")]
fn rewrite_postgres(sql: &str, params: &[(&str, SqlParam)]) -> (String, Vec<PgOwned>) {
    let (sql, values) = rewrite_named(sql, params, |i| format!("${i}"));
    let owned = values
        .into_iter()
        .map(|v| match v {
            SqlParam::Null => PgOwned::Null,
            SqlParam::Bool(b) => PgOwned::Bool(PgBool(b)),
            SqlParam::I64(i) => PgOwned::I64(PgInt(i)),
            SqlParam::F64(f) => PgOwned::F64(PgFloat(f)),
            SqlParam::Text(s) => PgOwned::Text(PgFlexible(s)),
        })
        .collect();
    (sql, owned)
}

#[cfg(feature = "postgres")]
fn postgres_refs(owned: &[PgOwned]) -> Vec<&(dyn postgres::types::ToSql + Sync)> {
    static PG_NULL: PgNull = PgNull;
    let mut refs: Vec<&(dyn postgres::types::ToSql + Sync)> = Vec::with_capacity(owned.len());
    for value in owned {
        match value {
            PgOwned::Null => refs.push(&PG_NULL),
            PgOwned::Bool(b) => refs.push(b),
            PgOwned::I64(i) => refs.push(i),
            PgOwned::F64(f) => refs.push(f),
            PgOwned::Text(s) => refs.push(s),
        }
    }
    refs
}

#[cfg(feature = "postgres")]
fn postgres_row_to_map(row: &postgres::Row) -> IndexMap<String, AttrValue> {
    let mut map = IndexMap::new();
    for column in row.columns() {
        let name = column.name().to_owned();
        let value = postgres_cell(row, column);
        map.insert(name, value);
    }
    map
}

#[cfg(feature = "postgres")]
fn postgres_cell(row: &postgres::Row, column: &postgres::Column) -> AttrValue {
    let idx = column.name();
    if let Ok(v) = row.try_get::<_, Option<i64>>(idx) {
        return v.map_or(AttrValue::Null, |n| AttrValue::Number(n.into()));
    }
    if let Ok(v) = row.try_get::<_, Option<i32>>(idx) {
        return v.map_or(AttrValue::Null, |n| AttrValue::Number(i64::from(n).into()));
    }
    if let Ok(v) = row.try_get::<_, Option<bool>>(idx) {
        return v.map_or(AttrValue::Null, AttrValue::Bool);
    }
    if let Ok(v) = row.try_get::<_, Option<f64>>(idx) {
        return v.map_or(AttrValue::Null, number_from_f64);
    }
    if let Ok(v) = row.try_get::<_, Option<String>>(idx) {
        return v.map_or(AttrValue::Null, AttrValue::from);
    }
    // `datetime`-filtered attributes are real `TIMESTAMP` columns in Postgres
    // (MySQL/MariaDB hand them back as strings). Render them in the same
    // `Y-m-d H:i:s.v` shape the `datetime` filter's decode side expects, so
    // `$createdAt`, `registration`, `accessedAt`, ... do not read back as null.
    if let Ok(v) = row.try_get::<_, Option<chrono::NaiveDateTime>>(idx) {
        return v.map_or(AttrValue::Null, |naive| {
            AttrValue::from(crate::datetime::DateTime::format(naive))
        });
    }
    if let Ok(v) = row.try_get::<_, Option<chrono::DateTime<chrono::Utc>>>(idx) {
        return v.map_or(AttrValue::Null, |utc| {
            AttrValue::from(crate::datetime::DateTime::format(utc.naive_utc()))
        });
    }
    // `array`-typed attributes (`postgres_type`) store as JSON/JSONB, which
    // `postgres`'s `FromSql for String` does not accept -- only
    // `serde_json::Value` (via the `with-serde_json-1` crate feature) reads
    // those OIDs. Without this, every array attribute (`keys.scopes`,
    // `users.labels`, ...) silently decoded as `Null` on read.
    if let Ok(v) = row.try_get::<_, Option<serde_json::Value>>(idx) {
        return v.map_or(AttrValue::Null, AttrValue::from);
    }
    AttrValue::Null
}

#[cfg(feature = "postgres")]
fn map_postgres(err: &postgres::Error) -> DatabaseError {
    let msg = err.as_db_error().map_or_else(
        || err.to_string(),
        |db| {
            let mut msg = db.message().to_owned();
            if let Some(detail) = db.detail() {
                msg.push_str(": ");
                msg.push_str(detail);
            }
            if let Some(hint) = db.hint() {
                msg.push_str(" (");
                msg.push_str(hint);
                msg.push(')');
            }
            msg
        },
    );
    let lower = msg.to_ascii_lowercase();
    if lower.contains("duplicate") || lower.contains("unique") {
        DatabaseError::duplicate(msg)
    } else {
        DatabaseError::database(msg)
    }
}

#[cfg(feature = "sqlite")]
fn rewrite_sqlite(sql: &str, params: &[(&str, SqlParam)]) -> (String, Vec<rusqlite::types::Value>) {
    let (sql, values) = rewrite_named(sql, params, |_| "?".into());
    (
        sql,
        values
            .into_iter()
            .map(|v| match v {
                SqlParam::Null => rusqlite::types::Value::Null,
                SqlParam::Bool(b) => rusqlite::types::Value::Integer(i64::from(b)),
                SqlParam::I64(i) => rusqlite::types::Value::Integer(i),
                SqlParam::F64(f) => rusqlite::types::Value::Real(f),
                SqlParam::Text(s) => rusqlite::types::Value::Text(s),
            })
            .collect(),
    )
}

#[cfg(feature = "sqlite")]
fn sqlite_value(row: &rusqlite::Row<'_>, i: usize) -> AttrValue {
    match row.get_ref(i) {
        Ok(rusqlite::types::ValueRef::Null) => AttrValue::Null,
        Ok(rusqlite::types::ValueRef::Integer(i)) => AttrValue::Number(i.into()),
        Ok(rusqlite::types::ValueRef::Real(f)) => number_from_f64(f),
        Ok(rusqlite::types::ValueRef::Text(t)) => {
            AttrValue::from(String::from_utf8_lossy(t).into_owned())
        }
        Ok(rusqlite::types::ValueRef::Blob(b)) => {
            AttrValue::from(String::from_utf8_lossy(b).into_owned())
        }
        Err(_) => AttrValue::Null,
    }
}

#[cfg(feature = "sqlite")]
fn map_sqlite(err: rusqlite::Error) -> DatabaseError {
    let msg = err.to_string();
    if msg.contains("UNIQUE") || msg.contains("unique") {
        DatabaseError::duplicate(msg)
    } else {
        DatabaseError::database(msg)
    }
}

fn number_from_f64(value: f64) -> AttrValue {
    Number::from_f64(value).map_or(AttrValue::from(value.to_string()), AttrValue::Number)
}
