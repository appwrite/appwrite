//! PDO's mysql driver (over mysqlnd) with emulated prepares.
//!
//! PDO substitutes the placeholders client side with quoted literals and
//! sends the statement as text (`mysql_real_query`, several queries
//! allowed), reading results buffered; with `ATTR_STRINGIFY_FETCHES` every
//! fetched value is a string:
//!
//! - placeholders are found by the mysql driver's scanner (quoted strings
//!   with backslash escapes, backquoted identifiers and comments are not
//!   placeholders; a `:name` right after a letter or digit is not one);
//! - `PARAM_BOOL` becomes `1`/`0`, `PARAM_INT` an integer literal, `null`
//!   `NULL`, anything else its PHP string quoted like
//!   `mysql_real_escape_string_quote` with utf8mb4 (`\0`, `\n`, `\r`, `\\`,
//!   `\'`, `\"`, `\Z` escaped, as is the lead byte of an invalid multibyte
//!   sequence);
//! - fetched values are the text the server sent, `NULL` is `null`;
//! - a statement of several queries fails at `execute()` only when its first
//!   query fails; the results of the others are read with `nextRowset()`,
//!   which fails with the error of a query that failed; until they are read
//!   (or the cursor closed) the connection refuses other statements
//!   (`2014 Cannot execute queries while there are pending result sets`);
//! - `exec()` reports the first query's affected rows and the error of any
//!   query; an `exec()` whose first query returns rows leaves them unread,
//!   and the connection then refuses every statement with error 2014, as
//!   mysqlnd does;
//! - errors are `PDOException`s with PDO's message:
//!   `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ...`;
//!   a lost connection is `SQLSTATE[HY000]: General error: 2006 MySQL server
//!   has gone away`;
//! - `inTransaction()` is the server status of the last successful result
//!   (an error keeps it, as mysqlnd keeps it).
//!
//! PHP: `\PDO` / `\PDOStatement` with the mysql driver (`ext/pdo_mysql`)
//! and mysqlnd.

use std::time::Duration;

use mysql_async::prelude::Queryable;
use mysql_async::{Conn as Connection, OptsBuilder};

use super::emulate::{self, Found, Placeholder};
use super::{Binding, Control, PdoError, Results, Rowset, dsn};
use crate::error::{Error, Result};
use crate::value::{Str, Value};

/// `SERVER_STATUS_IN_TRANS`.
const SERVER_STATUS_IN_TRANS: u16 = 0x0001;

/// The message of error 2014 while results of a statement are pending.
const PENDING: &str = "Cannot execute queries while there are pending result sets. Consider unsetting the previous PDOStatement or calling PDOStatement::closeCursor()";

/// The message of error 2014 while a result is left unread.
const UNBUFFERED: &str = "Cannot execute queries while other unbuffered queries are active.  Consider using PDOStatement::fetchAll().  Alternatively, if your code is only ever going to run against mysql, you may enable query buffering by setting the Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY attribute.";

/// One connection.
pub(crate) struct Conn {
    conn: Option<Connection>,
    /// The server status of the last successful result (mysqlnd's
    /// `upsert_status->server_status`).
    status: u16,
    /// An `exec()` left a result unread: whether more results followed it.
    unread: Option<bool>,
}

impl Conn {
    /// `new PDO('mysql:...')`: TCP, utf8mb4 (or the DSN's `charset`), the
    /// DSN's `dbname` selected, multi statements allowed.
    pub(crate) async fn connect(
        params: &dsn::Mysql,
        username: Option<&str>,
        password: Option<&str>,
        timeout: Duration,
    ) -> Result<Conn> {
        let host = params.host.clone().filter(|h| !h.is_empty()).unwrap_or_else(|| "localhost".to_owned());
        let port = params.port.as_deref().map_or(3306, |p| php_std::format::str_to_int(p.as_bytes()) as u16);
        let port = if port == 0 { 3306 } else { port };
        let resolved = tokio::net::lookup_host((host.as_str(), port)).await.map(|mut a| a.next());
        if !matches!(resolved, Ok(Some(_))) {
            return Err(PdoError::connect(
                "HY000",
                2002,
                &format!("php_network_getaddresses: getaddrinfo for {host} failed: Name does not resolve"),
            )
            .into_error());
        }
        let mut opts = OptsBuilder::default()
            .ip_or_hostname(host)
            .tcp_port(port)
            .user(username.map(str::to_owned))
            .pass(password.map(str::to_owned))
            .db_name(params.dbname.clone().filter(|d| !d.is_empty()))
            .prefer_socket(false)
            .stmt_cache_size(0)
            .max_allowed_packet(Some(1 << 30))
            .wait_timeout(Some(28800));
        if let Some(charset) = params.charset.as_deref().filter(|c| !c.is_empty() && !c.eq_ignore_ascii_case("utf8mb4"))
        {
            opts = opts.init(vec![format!("SET NAMES {charset}")]);
        }
        let conn = match tokio::time::timeout(timeout, Connection::new(opts)).await {
            Ok(Ok(conn)) => conn,
            Ok(Err(e)) => return Err(connection_error(&e).into_error()),
            Err(_) => return Err(PdoError::connect("HY000", 2002, "Connection timed out").into_error()),
        };
        Ok(Conn { conn: Some(conn), status: 0, unread: None })
    }

    /// `inTransaction()`: `SERVER_STATUS_IN_TRANS` of the last status.
    pub(crate) fn in_transaction(&self) -> bool {
        self.status & SERVER_STATUS_IN_TRANS != 0
    }

    /// Refuses a statement while results are unread: those of a statement
    /// (`pending`) or one an `exec()` left.
    pub(crate) fn check_sync(&self, pending: bool) -> Result<()> {
        match (pending, self.unread) {
            (true, _) | (_, Some(true)) => Err(PdoError::driver("HY000", 2014, PENDING).into_error()),
            (_, Some(false)) => Err(PdoError::driver("HY000", 2014, UNBUFFERED).into_error()),
            _ => Ok(()),
        }
    }

    /// Runs a query string and reads every result: the first query's
    /// failure fails it; a later one's ends the results with it.
    async fn query(&mut self, query: &[u8]) -> Result<Results> {
        let Some(conn) = self.conn.as_mut() else {
            return Err(gone());
        };
        let mut result = match conn.query_iter(query).await {
            Ok(r) => r,
            Err(e) => {
                let e = error_of(e);
                self.settle();
                return Err(e.into_error());
            }
        };
        let mut sets = Vec::new();
        let mut failure = None;
        loop {
            let affected = result.affected_rows() as i64;
            let insert_id = result.last_insert_id().unwrap_or(0);
            let columns: Vec<Str> = result.columns_ref().iter().map(|c| Str::from(c.name_ref().to_vec())).collect();
            let mut rows = Vec::new();
            let mut error = None;
            loop {
                match result.next().await {
                    Ok(Some(row)) => rows.push(row.unwrap_raw().into_iter().map(fetch).collect()),
                    Ok(None) => break,
                    Err(e) => {
                        error = Some(e);
                        break;
                    }
                }
            }
            let has_rows = !columns.is_empty();
            let count = if has_rows { rows.len() as i64 } else { affected };
            let insert_id = if has_rows { 0 } else { insert_id };
            if let Some(e) = error {
                let e = error_of(e).into_error();
                // A failure while reading the rows of a lone query is that
                // query's; otherwise it is the next query's (see the
                // module docs).
                if sets.is_empty() && has_rows && !several(query) {
                    drop(result);
                    self.settle();
                    return Err(e);
                }
                sets.push(Rowset { columns, rows, count, insert_id });
                failure = Some(e);
                break;
            }
            sets.push(Rowset { columns, rows, count, insert_id });
            if result.is_empty() {
                break;
            }
        }
        drop(result);
        self.settle();
        Ok(Results { sets, failure })
    }

    /// Keeps the server status of the last OK packet and forgets a
    /// connection the last error closed.
    fn settle(&mut self) {
        if let Some(ok) = self.conn.as_ref().and_then(Connection::last_ok_packet) {
            self.status = ok.status_flags().bits();
        }
        if self.conn.as_ref().is_some_and(Connection::is_disconnected) {
            self.conn = None;
        }
    }

    /// `$pdo->exec($sql)`: the first query's affected rows and the last
    /// result's insert id.
    pub(crate) async fn exec(&mut self, sql: &str) -> Result<(i64, u64)> {
        let results = self.query(sql.as_bytes()).await?;
        let first = results.sets.first().cloned().unwrap_or_default();
        if !first.columns.is_empty() {
            self.unread = Some(results.sets.len() > 1 || results.failure.is_some());
            return Ok((0, 0));
        }
        if let Some(e) = results.failure {
            return Err(e);
        }
        let insert_id = results.sets.last().map_or(0, |s| s.insert_id);
        Ok((first.count, insert_id))
    }

    /// `$statement->execute()`.
    pub(crate) async fn execute(&mut self, sql: &str, bindings: &[Binding]) -> Result<Option<Results>> {
        let found = scan(sql.as_bytes());
        let quote = |value: &Value, _| Ok(Some(quote(&emulate::php_string(value)?)));
        let Some(query) = emulate::render(sql.as_bytes(), &found, bindings, quote)? else {
            return Ok(None);
        };
        self.query(&query).await.map(Some)
    }

    /// `beginTransaction()` (`START TRANSACTION`), `commit()`, `rollBack()`.
    pub(crate) async fn control(&mut self, op: Control) -> Result<()> {
        let sql = match op {
            Control::Begin => "START TRANSACTION",
            other => other.sql(),
        };
        let results = self.query(sql.as_bytes()).await?;
        match results.failure {
            Some(e) => Err(e),
            None => Ok(()),
        }
    }

    /// Closes the connection.
    pub(crate) async fn close(self) {
        if let Some(conn) = self.conn {
            let _ = conn.disconnect().await;
        }
    }
}

/// Whether `sql` holds more than one query: a `;` outside quotes and
/// comments followed by more than whitespace.
fn several(sql: &[u8]) -> bool {
    let end = sql.iter().position(|b| *b == 0).unwrap_or(sql.len());
    let s = &sql[..end];
    let mut pos = 0;
    while pos < s.len() {
        let (token, len) = next_token(s, pos);
        if token == Token::Text && !matches!(s[pos], b'\'' | b'"' | b'`' | b'#' | b'/' | b'-') {
            for (i, b) in s[pos..pos + len].iter().enumerate() {
                if *b == b';' && s[pos + i + 1..].iter().any(|c| !c.is_ascii_whitespace() && *c != b';') {
                    return true;
                }
            }
        }
        pos += len;
    }
    false
}

/// `CR_SERVER_GONE_ERROR`.
fn gone() -> Error {
    PdoError::driver("HY000", 2006, "MySQL server has gone away").into_error()
}

/// The `PDOException` of a failed query (`pdo_mysql_error`).
fn error_of(e: mysql_async::Error) -> PdoError {
    match e {
        mysql_async::Error::Server(s) => PdoError::driver(&s.state, i64::from(s.code), &s.message),
        mysql_async::Error::Io(_) | mysql_async::Error::Driver(_) => {
            PdoError::driver("HY000", 2006, "MySQL server has gone away")
        }
        other => PdoError::driver("HY000", 2000, &other.to_string()),
    }
}

/// The `PDOException` of a failed connection: `SQLSTATE[HY000] [code] ...`.
fn connection_error(e: &mysql_async::Error) -> PdoError {
    match e {
        mysql_async::Error::Server(s) => PdoError::connect("HY000", i64::from(s.code), &s.message),
        mysql_async::Error::Io(mysql_async::IoError::Io(io)) if io.kind() == std::io::ErrorKind::ConnectionRefused => {
            PdoError::connect("HY000", 2002, "Connection refused")
        }
        other => PdoError::connect("HY000", 2002, &other.to_string()),
    }
}

/// One fetched value: the text the server sent, `NULL` as `null`.
fn fetch(value: Option<mysql_async::Value>) -> Value {
    match value {
        None | Some(mysql_async::Value::NULL) => Value::Null,
        Some(mysql_async::Value::Bytes(b)) => Value::Str(Str::from(b)),
        Some(other) => Value::Str(Str::from(other.as_sql(true).into_bytes())),
    }
}

/// `$pdo->quote($string)`: the string in single quotes, escaped by
/// `mysqlnd_cset_escape_slashes` for utf8mb4.
pub(crate) fn quote(s: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len() + 2);
    out.push(b'\'');
    let mut i = 0;
    while i < s.len() {
        let c = s[i];
        let mut esc = 0u8;
        if c >= 0x80 {
            let len = utf8_sequence(&s[i..]);
            if len > 1 {
                out.extend_from_slice(&s[i..i + len]);
                i += len;
                continue;
            }
            if utf8_charlen(c) > 1 {
                esc = c;
            }
        }
        if esc == 0 {
            esc = match c {
                0 => b'0',
                b'\n' => b'n',
                b'\r' => b'r',
                b'\\' | b'\'' | b'"' => c,
                0x1a => b'Z',
                _ => 0,
            };
        }
        if esc != 0 {
            out.push(b'\\');
            out.push(esc);
        } else {
            out.push(c);
        }
        i += 1;
    }
    out.push(b'\'');
    out
}

/// `mysqlnd_mbcharlen_utf8`.
fn utf8_charlen(c: u8) -> usize {
    match c {
        0..0x80 => 1,
        0x80..0xC2 => 0,
        0xC2..0xE0 => 2,
        0xE0..0xF0 => 3,
        0xF0..0xF8 => 4,
        _ => 0,
    }
}

/// `check_mb_utf8_sequence`: the length of the valid UTF-8 character
/// starting `s`, or 0.
fn utf8_sequence(s: &[u8]) -> usize {
    let Some(&c) = s.first() else { return 0 };
    let cont = |i: usize| s.get(i).is_some_and(|b| (b ^ 0x80) < 0x40);
    if c < 0x80 {
        return 1;
    }
    if c < 0xC2 {
        return 0;
    }
    if c < 0xE0 {
        return if s.len() >= 2 && cont(1) { 2 } else { 0 };
    }
    if c < 0xF0 {
        return if s.len() >= 3 && cont(1) && cont(2) && (c >= 0xE1 || s[1] >= 0xA0) { 3 } else { 0 };
    }
    if c < 0xF5 {
        return if s.len() >= 4
            && cont(1)
            && cont(2)
            && cont(3)
            && (c >= 0xF1 || s[1] >= 0x90)
            && (c <= 0xF3 || s[1] <= 0x8F)
        {
            4
        } else {
            0
        };
    }
    0
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Token {
    Text,
    Bind,
    BindPos,
}

/// The mysql driver's placeholder scanner (`pdo_mysql_scanner`), then
/// `pdo_parse_params`'s first phase: the placeholders of `sql`, outside
/// quotes, backquoted identifiers and comments.
fn scan(sql: &[u8]) -> Vec<Found> {
    // The scanner stops at the terminating NUL; an embedded one ends it too.
    let end = sql.iter().position(|b| *b == 0).unwrap_or(sql.len());
    let s = &sql[..end];
    let mut out = Vec::new();
    let mut pos = 0;
    while pos < s.len() {
        let (token, len) = next_token(s, pos);
        let start = pos;
        pos += len;
        match token {
            Token::Bind => {
                if start > 0 && s[start - 1].is_ascii_alphanumeric() {
                    continue;
                }
                out.push(Found { start, end: pos, kind: Placeholder::Named });
            }
            Token::BindPos => out.push(Found { start, end: pos, kind: Placeholder::Positional }),
            Token::Text => {}
        }
    }
    out
}

fn is_special(c: u8) -> bool {
    matches!(c, b':' | b'?' | b'"' | b'\'' | b'`' | b'/' | b'#' | b'-')
}

fn is_bind_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_'
}

/// The length of a quoted string opening `rest` (`"`/`'`: doubled quotes and
/// backslash escapes; `` ` ``: doubled quotes), or `None` when unterminated.
fn quoted(rest: &[u8], quote: u8, backslash: bool) -> Option<usize> {
    let mut i = 1;
    loop {
        match rest.get(i) {
            None => return None,
            Some(&c) if c == quote => {
                if rest.get(i + 1) == Some(&quote) {
                    i += 2;
                } else {
                    return Some(i + 1);
                }
            }
            Some(b'\\') if backslash => {
                if i + 1 < rest.len() {
                    i += 2;
                } else {
                    return None;
                }
            }
            Some(_) => i += 1,
        }
    }
}

/// The longest token at `pos` (re2c picks the longest match, then the
/// first rule), as `(kind, length)`.
fn next_token(s: &[u8], pos: usize) -> (Token, usize) {
    let rest = &s[pos..];
    let mut best = (Token::Text, 0usize);
    let mut offer = |t: Token, len: usize| {
        if len > best.1 {
            best = (t, len);
        }
    };
    match rest[0] {
        b'"' | b'\'' => {
            if let Some(n) = quoted(rest, rest[0], true) {
                offer(Token::Text, n);
            }
        }
        b'`' => {
            if let Some(n) = quoted(rest, b'`', false) {
                offer(Token::Text, n);
            }
        }
        _ => {}
    }
    // [:]{2,} | [?]{2,}
    for c in *b":?" {
        if rest[0] == c {
            let n = rest.iter().take_while(|b| **b == c).count();
            if n >= 2 {
                offer(Token::Text, n);
            }
        }
    }
    // :[a-zA-Z0-9_]+
    if rest[0] == b':' {
        let n = rest[1..].iter().take_while(|c| is_bind_char(**c)).count();
        if n > 0 {
            offer(Token::Bind, n + 1);
        }
    }
    if rest[0] == b'?' {
        offer(Token::BindPos, 1);
    }
    if is_special(rest[0]) {
        offer(Token::Text, 1);
    }
    // Comments: /* ... */, "-- " (dash dash and a space, tab, vertical tab,
    // form feed or carriage return) or # to the end of the line.
    if rest.starts_with(b"/*") {
        if let Some(close) = emulate::comment_end(rest) {
            offer(Token::Text, close);
        }
    } else if (rest.starts_with(b"--") && rest.get(2).is_some_and(|c| matches!(c, b' ' | b'\t' | 0x0b | 0x0c | b'\r')))
        || rest[0] == b'#'
    {
        let n = rest.iter().position(|c| *c == b'\n').unwrap_or(rest.len());
        offer(Token::Text, n);
    }
    // A run of anything else.
    let n = rest.iter().take_while(|c| !is_special(**c)).count();
    if n > 0 {
        offer(Token::Text, n);
    }
    if best.1 == 0 {
        best = (Token::Text, 1);
    }
    best
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn escapes_invalid_multibyte_leads() {
        assert_eq!(quote(b"\xc3\xa9"), b"'\xc3\xa9'".to_vec());
        assert_eq!(quote(b"\xc3a"), b"'\\\xc3a'".to_vec());
        assert_eq!(quote(b"\xff\xfe"), b"'\xff\xfe'".to_vec());
        assert_eq!(quote(b"\xf0\x9f\x98\x80"), b"'\xf0\x9f\x98\x80'".to_vec());
    }

    #[test]
    fn counts_queries() {
        assert!(!several(b"SELECT 1"));
        assert!(!several(b"SELECT 1;"));
        assert!(!several(b"SELECT ';x' -- ;y\n"));
        assert!(several(b"SELECT 1; SELECT 2"));
    }
}
