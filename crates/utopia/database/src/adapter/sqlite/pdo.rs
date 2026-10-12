//! Statements as PHP's PDO sqlite driver runs them.
//!
//! The adapter writes SQL with named placeholders (`:_uid`) and binds values
//! with a PDO parameter type. The sqlite driver prepares the statement as it
//! is (`sqlite3_prepare_v2`: SQLite reads `:name` placeholders itself, and
//! text after the first statement is ignored) and binds each value when the
//! statement executes, so [`Pdo`] does the same over `rusqlite`:
//!
//! - `PARAM_INT` and `PARAM_BOOL` bind the value as an integer
//!   (`zval_get_long`), `PARAM_NULL` and `null` bind `NULL`, anything else
//!   binds its PHP string as text (`true` is `"1"`, floats print with
//!   precision 14);
//! - a bound name the statement does not have fails the execution with
//!   `SQLSTATE[HY000]: General error: 25 column index out of range`;
//!   placeholders left unbound are `NULL`;
//! - fetched integers and floats become strings (`ATTR_STRINGIFY_FETCHES`;
//!   floats as PHP prints them, precision 14), text and blobs their bytes,
//!   `NULL` `null`;
//! - errors are `PDOException`s with PDO's message:
//!   `SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint
//!   failed: ...`, the SQLSTATE derived from SQLite's primary result code.
//!
//! PHP: `\PDO` / `\PDOStatement` with the sqlite driver (`ext/pdo`,
//! `ext/pdo_sqlite`).

use std::time::Duration;

use rusqlite::fallible_iterator::FallibleIterator;
use rusqlite::types::ValueRef;
use rusqlite::{Batch, Connection, ffi};

use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Str, Value};

/// A PDO parameter type (`PDO::PARAM_*`).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum ParamType {
    /// `PDO::PARAM_NULL`.
    Null,
    /// `PDO::PARAM_INT`.
    Int,
    /// `PDO::PARAM_STR`.
    Str,
    /// `PDO::PARAM_BOOL`.
    Bool,
}

/// A driver error (`PDOException`): its SQLSTATE, SQLite's result code
/// (`errorInfo[1]`) and its message.
#[derive(Clone, Debug, PartialEq)]
pub struct PdoError {
    /// `getCode()` / `errorInfo[0]`.
    pub sqlstate: String,
    /// `errorInfo[1]`: SQLite's primary result code.
    pub native: Option<i64>,
    /// `getMessage()`.
    pub message: String,
}

impl PdoError {
    /// An error SQLite reported (`pdo_sqlite_error`): `code` is its result
    /// code (extended codes are reduced to the primary one, as the driver
    /// does not enable them) and `message` what `sqlite3_errmsg` says.
    pub fn sqlite(code: i32, message: &str) -> Self {
        let code = code & 0xff;
        let sqlstate = match code {
            ffi::SQLITE_NOTFOUND => "42S02",
            ffi::SQLITE_INTERRUPT => "01002",
            ffi::SQLITE_NOLFS => "HYC00",
            ffi::SQLITE_TOOBIG => "22001",
            ffi::SQLITE_CONSTRAINT => "23000",
            _ => "HY000",
        };
        Self {
            sqlstate: sqlstate.to_owned(),
            native: Some(i64::from(code)),
            message: format!("SQLSTATE[{sqlstate}]: {}: {code} {message}", describe(sqlstate)),
        }
    }

    /// An exception PDO throws with only a message: code `0`.
    pub fn plain(message: &str) -> Self {
        Self { sqlstate: "0".to_owned(), native: None, message: message.to_owned() }
    }

    /// The error as the crate's error (`PDOException`).
    pub fn into_error(self) -> Error {
        Error::new(Kind::Pdo, self.message)
    }

    /// Whether this is SQLite error `code` with SQLSTATE `sqlstate`
    /// (`$e->getCode() === $sqlstate && $e->errorInfo[1] === $code`).
    pub fn is(&self, sqlstate: &str, code: i64) -> bool {
        self.sqlstate == sqlstate && self.native == Some(code)
    }
}

/// The description PDO gives the SQLSTATEs the sqlite driver raises
/// (`pdo_sqlstate_state_to_description`).
fn describe(sqlstate: &str) -> &'static str {
    match sqlstate {
        "01002" => "Disconnect error",
        "22001" => "String data, right truncated",
        "23000" => "Integrity constraint violation",
        "42S02" => "Base table or view not found",
        "HYC00" => "Optional feature not implemented",
        _ => "General error",
    }
}

/// A `rusqlite` error as the driver error PDO raises for it.
fn from_rusqlite(e: rusqlite::Error) -> PdoError {
    match e {
        rusqlite::Error::SqliteFailure(error, message) => {
            let message = message.unwrap_or_else(|| error.to_string());
            PdoError::sqlite(error.extended_code, &message)
        }
        rusqlite::Error::SqlInputError { error, msg, .. } => PdoError::sqlite(error.extended_code, &msg),
        other => PdoError::sqlite(ffi::SQLITE_ERROR, &other.to_string()),
    }
}

/// A prepared statement: SQL with named placeholders and bound values.
#[derive(Clone, Debug, Default)]
pub struct Statement {
    sql: String,
    params: Vec<(String, Value, ParamType)>,
}

impl Statement {
    /// `$pdo->prepare($sql)`.
    pub fn new(sql: impl Into<String>) -> Self {
        Self { sql: sql.into(), params: Vec::new() }
    }

    /// The SQL as prepared.
    pub fn sql(&self) -> &str {
        &self.sql
    }

    /// `bindValue($name, $value, $type)`: a name without its leading `:`
    /// gets one; binding a name again replaces its value.
    pub fn bind(&mut self, name: &str, value: Value, ty: ParamType) {
        let key = if name.starts_with(':') { name.to_owned() } else { format!(":{name}") };
        match self.params.iter_mut().find(|(k, _, _)| *k == key) {
            Some(slot) => {
                slot.1 = value;
                slot.2 = ty;
            }
            None => self.params.push((key, value, ty)),
        }
    }

    /// `bindValue($name, $value)` (`PARAM_STR`).
    pub fn bind_str(&mut self, name: &str, value: Value) {
        self.bind(name, value, ParamType::Str);
    }
}

/// What a bound value binds as.
enum Bound {
    Null,
    Int(i64),
    Text(Vec<u8>),
}

/// The value a parameter binds (`pdo_sqlite_stmt_param_hook`).
fn bound(value: &Value, ty: ParamType) -> Result<Bound> {
    if matches!(value, Value::Null) || ty == ParamType::Null {
        return Ok(Bound::Null);
    }
    match ty {
        ParamType::Int | ParamType::Bool => Ok(Bound::Int(to_long(value))),
        _ => Ok(Bound::Text(value.to_php_string().map_err(Error::from)?.as_bytes().to_vec())),
    }
}

/// `zval_get_long()`.
pub fn to_long(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

/// One fetched value (`pdo_sqlite_stmt_get_col`, then PDO's stringify).
fn fetch(value: ValueRef<'_>) -> Value {
    match value {
        ValueRef::Null => Value::Null,
        ValueRef::Integer(i) => Value::Str(Str::from(i.to_string())),
        ValueRef::Real(f) => Value::Str(Str::from(php_std::number::to_string(f))),
        ValueRef::Text(t) | ValueRef::Blob(t) => Value::Str(Str::from(t.to_vec())),
    }
}

/// A statement's result: what `execute()` returned, the rows and the row count.
#[derive(Debug, Default)]
pub struct Executed {
    /// `execute()`'s return value (`false` for a statement without SQL).
    pub ok: bool,
    /// `fetchAll()`.
    pub rows: Vec<Array>,
    /// `rowCount()`.
    pub count: i64,
}

impl Executed {
    /// `fetchColumn()`: the first column of the first row.
    pub fn column(&self) -> Option<Value> {
        self.rows.first().and_then(|r| r.values().next().cloned())
    }
}

/// A failed `execute()`: a driver error, or another exception (a value
/// that cannot be converted to a string).
#[derive(Debug)]
pub enum PdoFailure {
    /// A `PDOException`.
    Pdo(PdoError),
    /// Any other exception.
    Other(Error),
}

impl From<PdoError> for PdoFailure {
    fn from(e: PdoError) -> Self {
        PdoFailure::Pdo(e)
    }
}

/// The PDO handle: one SQLite database file.
pub struct Pdo {
    conn: Connection,
}

impl Pdo {
    /// `new PDO("sqlite:$path", null, null, SQLite::getPDOAttributes())`:
    /// opens (or creates) the file, with PDO's busy timeout
    /// (`ATTR_TIMEOUT`, 3 seconds).
    pub fn open(path: &str) -> Result<Pdo> {
        let conn = Connection::open(path).map_err(|e| {
            let e = from_rusqlite(e);
            let code = e.native.unwrap_or(0);
            let message = e.message.split_once(&format!("{code} ")).map_or("", |(_, m)| m).to_owned();
            Error::new(Kind::Pdo, format!("SQLSTATE[{}] [{code}] {message}", e.sqlstate))
        })?;
        conn.busy_timeout(Duration::from_secs(3)).map_err(|e| from_rusqlite(e).into_error())?;
        Ok(Pdo { conn })
    }

    /// The connection, to register functions on.
    pub fn connection(&self) -> &Connection {
        &self.conn
    }

    /// `inTransaction()`: whether SQLite is outside autocommit mode.
    pub fn in_transaction(&self) -> bool {
        !self.conn.is_autocommit()
    }

    /// `$pdo->exec($sql)`: runs every statement of `sql`
    /// (`sqlite3_exec`), returning `sqlite3_changes()`.
    pub fn exec(&self, sql: &str) -> Result<i64, PdoError> {
        let mut batch = Batch::new(&self.conn, sql);
        while let Some(mut stmt) = batch.next().map_err(from_rusqlite)? {
            let mut rows = stmt.raw_query();
            while rows.next().map_err(from_rusqlite)?.is_some() {}
        }
        Ok(self.conn.changes() as i64)
    }

    /// `$pdo->prepare($sql)` on its own: whether the statement compiles,
    /// for adapter code that prepares a statement before it executes it
    /// (a syntax error then escapes before anything ran).
    pub fn prepare(&self, statement: &Statement) -> Result<(), PdoFailure> {
        if statement.sql.is_empty() {
            return Err(PdoFailure::Other(Error::new(
                Kind::ValueError,
                "PDO::prepare(): Argument #1 ($query) must not be empty",
            )));
        }
        let mut batch = Batch::new(&self.conn, &statement.sql);
        batch.next().map(|_| ()).map_err(|e| PdoFailure::Pdo(from_rusqlite(e)))
    }

    /// `$statement->execute()` then `fetchAll()` and `rowCount()`.
    pub fn execute(&self, statement: &Statement) -> Result<Executed, PdoFailure> {
        if statement.sql.is_empty() {
            return Err(PdoFailure::Other(Error::new(
                Kind::ValueError,
                "PDO::prepare(): Argument #1 ($query) must not be empty",
            )));
        }
        let mut batch = Batch::new(&self.conn, &statement.sql);
        let Some(mut stmt) = batch.next().map_err(|e| PdoFailure::Pdo(from_rusqlite(e)))? else {
            return Ok(Executed::default());
        };
        let mut values = Vec::with_capacity(statement.params.len());
        for (name, value, ty) in &statement.params {
            values.push((name, bound(value, *ty).map_err(PdoFailure::Other)?));
        }
        for (name, value) in values {
            let index = stmt.parameter_index(name).map_err(|e| PdoFailure::Pdo(from_rusqlite(e)))?;
            let Some(index) = index else {
                return Err(PdoFailure::Pdo(PdoError::sqlite(ffi::SQLITE_RANGE, "column index out of range")));
            };
            let result = match &value {
                Bound::Null => stmt.raw_bind_parameter(index, rusqlite::types::Null),
                Bound::Int(i) => stmt.raw_bind_parameter(index, *i),
                Bound::Text(t) => {
                    stmt.raw_bind_parameter(index, rusqlite::types::ToSqlOutput::Borrowed(ValueRef::Text(t)))
                }
            };
            result.map_err(|e| PdoFailure::Pdo(from_rusqlite(e)))?;
        }
        let names: Vec<ArrayKey> =
            stmt.column_names().iter().map(|n| ArrayKey::normalize(Str::from(n.as_bytes().to_vec()))).collect();
        let mut out = Vec::new();
        {
            let mut rows = stmt.raw_query();
            while let Some(row) = rows.next().map_err(|e| PdoFailure::Pdo(from_rusqlite(e)))? {
                let mut a = Array::with_capacity(names.len());
                for (i, name) in names.iter().enumerate() {
                    let v = row.get_ref(i).map_err(|e| PdoFailure::Pdo(from_rusqlite(e)))?;
                    a.set(name.clone(), fetch(v));
                }
                out.push(a);
            }
        }
        let count = if out.is_empty() { self.conn.changes() as i64 } else { 0 };
        Ok(Executed { ok: true, rows: out, count })
    }

    /// `lastInsertId()`: `sqlite3_last_insert_rowid()` as a string.
    pub fn last_insert_id(&self) -> Value {
        Value::Str(Str::from(self.conn.last_insert_rowid().to_string()))
    }

    /// `$pdo->quote($string)`: `'...'` with `'` doubled (`sqlite3_mprintf("'%q'")`).
    pub fn quote(s: &[u8]) -> Result<Vec<u8>> {
        if s.contains(&0) {
            return Err(Error::new(Kind::Pdo, "SQLite PDO::quote does not support null bytes"));
        }
        let mut out = Vec::with_capacity(s.len() + 2);
        out.push(b'\'');
        for &b in s {
            if b == b'\'' {
                out.push(b'\'');
            }
            out.push(b);
        }
        out.push(b'\'');
        Ok(out)
    }
}
