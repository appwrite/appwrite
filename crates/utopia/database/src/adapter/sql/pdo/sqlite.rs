//! PDO's sqlite driver.
//!
//! The sqlite driver prepares statements natively (`sqlite3_prepare_v2`:
//! SQLite reads `?` and `:name` placeholders itself, and text after the
//! first statement is ignored) and binds each value when the statement
//! executes:
//!
//! - `PARAM_INT` and `PARAM_BOOL` bind the value as an integer
//!   (`zval_get_long`), `PARAM_NULL` and `null` bind `NULL`, anything else
//!   binds its PHP string as text;
//! - a position or name the statement does not have fails the execution
//!   with `SQLSTATE[HY000]: General error: 25 column index out of range`;
//!   placeholders left unbound are `NULL`;
//! - fetched integers and floats become strings (`ATTR_STRINGIFY_FETCHES`;
//!   floats as PHP prints them, precision 14), text and blobs their bytes,
//!   `NULL` `null`;
//! - errors are `PDOException`s with PDO's message:
//!   `SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint
//!   failed: ...`, the SQLSTATE derived from SQLite's primary result code.
//!
//! PHP: `\PDO` / `\PDOStatement` with the sqlite driver (`ext/pdo_sqlite`).

use std::time::Duration;

use rusqlite::fallible_iterator::FallibleIterator;
use rusqlite::functions::FunctionFlags;
use rusqlite::types::ValueRef;
use rusqlite::{Batch, Connection, OpenFlags, ffi};

use super::{Binding, Control, Param, ParamType, PdoError, Results, Rowset};
use crate::error::{Error, Kind, Result};
use crate::value::{Str, Value};

/// `SQLite::REGEXP_MAX_PATTERN_LENGTH`: longer patterns never match.
const REGEXP_MAX_PATTERN_LENGTH: usize = 512;

/// The SQLSTATE `pdo_sqlite_error` gives a primary result code.
fn sqlstate(code: i32) -> &'static str {
    match code {
        ffi::SQLITE_NOTFOUND => "42S02",
        ffi::SQLITE_INTERRUPT => "01002",
        ffi::SQLITE_NOLFS => "HYC00",
        ffi::SQLITE_TOOBIG => "22001",
        ffi::SQLITE_CONSTRAINT => "23000",
        _ => "HY000",
    }
}

/// An error SQLite reported (`pdo_sqlite_error`): extended codes are reduced
/// to the primary one, as the driver does not enable them.
fn error(code: i32, message: &str) -> Error {
    let code = code & 0xff;
    PdoError::driver(sqlstate(code), i64::from(code), message).into_error()
}

/// A `rusqlite` error as the driver error PDO raises for it.
fn from_rusqlite(e: rusqlite::Error) -> Error {
    match e {
        rusqlite::Error::SqliteFailure(failure, message) => {
            let message = message.unwrap_or_else(|| failure.to_string());
            error(failure.extended_code, &message)
        }
        rusqlite::Error::SqlInputError { error: failure, msg, .. } => error(failure.extended_code, &msg),
        other => error(ffi::SQLITE_ERROR, &other.to_string()),
    }
}

/// One database connection.
pub(crate) struct Conn {
    conn: Connection,
}

impl Conn {
    /// `new PDO("sqlite:$path")`: opens (or creates) the file (`:memory:`
    /// for an in-memory database, `file:` for a URI), with `timeout` as the
    /// busy timeout (`ATTR_TIMEOUT`).
    pub(crate) fn open(path: &str, timeout: Duration) -> Result<Conn> {
        let mut flags =
            OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE | OpenFlags::SQLITE_OPEN_NO_MUTEX;
        if path.starts_with("file:") {
            flags |= OpenFlags::SQLITE_OPEN_URI;
        }
        let conn = Connection::open_with_flags(path, flags).map_err(|e| {
            let (code, message) = match e {
                rusqlite::Error::SqliteFailure(failure, message) => {
                    (failure.extended_code & 0xff, message.unwrap_or_else(|| failure.to_string()))
                }
                other => (ffi::SQLITE_ERROR, other.to_string()),
            };
            PdoError::connect(sqlstate(code), i64::from(code), &message).into_error()
        })?;
        conn.busy_timeout(timeout).map_err(from_rusqlite)?;
        Ok(Conn { conn })
    }

    /// `inTransaction()`: whether SQLite is outside autocommit mode.
    pub(crate) fn in_transaction(&self) -> bool {
        !self.conn.is_autocommit()
    }

    /// `$pdo->exec($sql)`: runs every statement of `sql` (`sqlite3_exec`),
    /// returning `sqlite3_changes()`.
    pub(crate) fn exec(&self, sql: &str) -> Result<i64> {
        let mut batch = Batch::new(&self.conn, sql);
        while let Some(mut stmt) = batch.next().map_err(from_rusqlite)? {
            let mut rows = stmt.raw_query();
            while rows.next().map_err(from_rusqlite)?.is_some() {}
        }
        Ok(self.conn.changes() as i64)
    }

    /// `$pdo->prepare($sql)`: compiles the first statement of `sql`.
    pub(crate) fn prepare(&self, sql: &str) -> Result<()> {
        let mut batch = Batch::new(&self.conn, sql);
        batch.next().map(|_| ()).map_err(from_rusqlite)
    }

    /// `$statement->execute()`: `None` for a statement without SQL.
    pub(crate) fn execute(&self, sql: &str, bindings: &[Binding]) -> Result<Option<Results>> {
        let mut batch = Batch::new(&self.conn, sql);
        let Some(mut stmt) = batch.next().map_err(from_rusqlite)? else {
            return Ok(None);
        };
        let range = || error(ffi::SQLITE_RANGE, "column index out of range");
        for binding in bindings {
            let index = match &binding.param {
                Param::Position(n) if *n <= stmt.parameter_count() => *n,
                Param::Position(_) => return Err(range()),
                Param::Name(name) => stmt.parameter_index(name).map_err(from_rusqlite)?.ok_or_else(range)?,
            };
            let null = matches!(binding.value, Value::Null) || binding.ty == ParamType::Null;
            let result = if null {
                stmt.raw_bind_parameter(index, rusqlite::types::Null)
            } else if matches!(binding.ty, ParamType::Int | ParamType::Bool) {
                stmt.raw_bind_parameter(index, crate::value::to_long(&binding.value))
            } else {
                let text = binding.value.to_php_string().map_err(Error::from)?;
                stmt.raw_bind_parameter(index, rusqlite::types::ToSqlOutput::Borrowed(ValueRef::Text(text.as_bytes())))
            };
            result.map_err(from_rusqlite)?;
        }
        let columns: Vec<Str> = stmt.column_names().iter().map(|n| Str::from(n.as_bytes().to_vec())).collect();
        let mut out = Vec::new();
        {
            let mut rows = stmt.raw_query();
            while let Some(row) = rows.next().map_err(from_rusqlite)? {
                let mut values = Vec::with_capacity(columns.len());
                for i in 0..columns.len() {
                    values.push(fetch(row.get_ref(i).map_err(from_rusqlite)?));
                }
                out.push(values);
            }
        }
        let count = if out.is_empty() { self.conn.changes() as i64 } else { 0 };
        Ok(Some(Results { sets: vec![Rowset { columns, rows: out, count, insert_id: 0 }], failure: None }))
    }

    /// `beginTransaction()`, `commit()`, `rollBack()`.
    pub(crate) fn control(&self, op: Control) -> Result<()> {
        self.exec(op.sql()).map(|_| ())
    }

    /// `lastInsertId()`: `sqlite3_last_insert_rowid()` as a string.
    pub(crate) fn last_insert_id(&self) -> Str {
        Str::from(self.conn.last_insert_rowid().to_string())
    }

    /// `registerUserFunctions()`: the `REGEXP` function, matching with PHP's
    /// `preg_match` (a `null` operand, a pattern longer than 512 bytes, or
    /// one that fails, never matches). Returns whether it was registered.
    pub(crate) fn register_regexp(&self) -> bool {
        let regexp = |ctx: &rusqlite::functions::Context<'_>| -> rusqlite::Result<i64> {
            let (Some(pattern), Some(value)) = (udf_string(ctx.get_raw(0)), udf_string(ctx.get_raw(1))) else {
                return Ok(0);
            };
            if pattern.len() > REGEXP_MAX_PATTERN_LENGTH {
                return Ok(0);
            }
            let mut delimited = Vec::with_capacity(pattern.len() + 3);
            delimited.push(0x01);
            delimited.extend_from_slice(&pattern);
            delimited.extend_from_slice(b"\x01u");
            Ok(match php_std::pcre::preg_match_bare(&delimited, &value) {
                Ok(r) if matches!(r.value, php_std::pcre::Value::Int(1)) => 1,
                _ => 0,
            })
        };
        self.conn.create_scalar_function("REGEXP", 2, FunctionFlags::SQLITE_UTF8, regexp).is_ok()
    }
}

/// A UDF argument as a PHP closure's `?string` parameter receives it.
fn udf_string(value: ValueRef<'_>) -> Option<Vec<u8>> {
    match value {
        ValueRef::Null => None,
        ValueRef::Integer(i) => Some(i.to_string().into_bytes()),
        ValueRef::Real(f) => Some(php_std::number::to_string(f).into_bytes()),
        ValueRef::Text(t) | ValueRef::Blob(t) => Some(t.to_vec()),
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

/// `$pdo->quote($string)`: `'...'` with `'` doubled (`sqlite3_mprintf("'%q'")`);
/// a NUL byte fails.
pub(crate) fn quote(s: &[u8]) -> Result<Vec<u8>> {
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
