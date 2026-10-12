//! Statements as PHP's PDO mysql driver (over mysqlnd) runs them with
//! emulated prepares.
//!
//! The adapter writes SQL with named placeholders (`:_uid`) and binds values
//! with a PDO parameter type. PDO (`ATTR_EMULATE_PREPARES`) substitutes the
//! placeholders client side with quoted literals and sends the statement as
//! text (`mysql_real_query`, multi statements allowed); with
//! `ATTR_STRINGIFY_FETCHES` every fetched value is a string. [`Pdo`]
//! reproduces that over `mysql_async`'s text protocol, so the server sees
//! the same bytes and the adapter reads the same values:
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
//! - `execute()` reports the first statement's failure only: the results of
//!   further statements are discarded, their errors swallowed, as mysqlnd
//!   does when the statement is freed; `exec()` reports them;
//! - errors are `PDOException`s with PDO's message:
//!   `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ...`;
//! - `inTransaction()` is the server status of the last successful result
//!   (an error keeps it, as mysqlnd keeps it).
//!
//! PHP: `\PDO` / `\PDOStatement` with the mysql driver (`ext/pdo`,
//! `ext/pdo_mysql`) and mysqlnd.

use mysql_async::prelude::Queryable;
use mysql_async::{Conn, OptsBuilder};

use crate::error::{Error, Kind, Result};
use crate::value::{Array, ArrayKey, Str, Value};

/// `SERVER_STATUS_IN_TRANS`.
const SERVER_STATUS_IN_TRANS: u16 = 0x0001;

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

/// A driver error (`PDOException`): its SQLSTATE, the driver's native code
/// (`errorInfo[1]`) and its message.
#[derive(Clone, Debug, PartialEq)]
pub struct PdoError {
    /// `getCode()` / `errorInfo[0]`.
    pub sqlstate: String,
    /// `errorInfo[1]`.
    pub native: Option<i64>,
    /// `errorInfo[2]`: the driver's own message.
    pub info: String,
    /// `getMessage()`.
    pub message: String,
}

impl PdoError {
    /// An error PDO raises itself (`pdo_raise_impl_error`).
    pub fn implementation(sqlstate: &str, supplement: &str) -> Self {
        let message = format!("SQLSTATE[{sqlstate}]: {}: {supplement}", describe(sqlstate));
        Self { sqlstate: sqlstate.to_owned(), native: None, info: supplement.to_owned(), message }
    }

    /// An exception PDO throws with only a message (`There is no active
    /// transaction`): code `0`.
    pub fn plain(message: &str) -> Self {
        Self { sqlstate: "0".to_owned(), native: None, info: String::new(), message: message.to_owned() }
    }

    /// A driver error (`pdo_mysql_error`): `SQLSTATE[state]: description:
    /// code message`.
    pub fn driver(sqlstate: &str, code: i64, info: &str) -> Self {
        let message = format!("SQLSTATE[{sqlstate}]: {}: {code} {info}", describe(sqlstate));
        Self { sqlstate: sqlstate.to_owned(), native: Some(code), info: info.to_owned(), message }
    }

    /// The error as the crate's error (`PDOException`).
    pub fn into_error(self) -> Error {
        Error::new(Kind::Pdo, self.message)
    }
}

/// `pdo_sqlstate_state_to_description`.
pub use crate::adapter::sqlstate::describe;

/// A prepared statement: SQL with named placeholders and bound values.
#[derive(Clone, Debug, Default)]
pub struct Statement {
    sql: Vec<u8>,
    params: Vec<(Vec<u8>, Value, ParamType)>,
}

impl Statement {
    /// `$pdo->prepare($sql)`.
    pub fn new(sql: impl Into<Vec<u8>>) -> Self {
        Self { sql: sql.into(), params: Vec::new() }
    }

    /// The SQL as prepared.
    pub fn sql(&self) -> &[u8] {
        &self.sql
    }

    /// `bindValue($name, $value, $type)`: a name without its leading `:`
    /// gets one; binding a name again replaces its value.
    pub fn bind(&mut self, name: &str, value: Value, ty: ParamType) {
        let mut key = Vec::with_capacity(name.len() + 1);
        if !name.starts_with(':') {
            key.push(b':');
        }
        key.extend_from_slice(name.as_bytes());
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

    /// The statement as PDO sends it (`pdo_parse_params`).
    pub fn render(&self) -> Result<Vec<u8>, Rendering> {
        if self.params.is_empty() {
            return Ok(self.sql.clone());
        }
        let placeholders = scan(&self.sql);
        let (named, positional) = placeholders.iter().fold((false, false), |(n, p), plc| match plc.kind {
            Placeholder::Named => (true, p),
            Placeholder::Positional => (n, true),
        });
        if named && positional {
            return Err(Rendering::Pdo(PdoError::implementation("HY093", "mixed named and positional parameters")));
        }
        let bindno = placeholders.len();
        if bindno != self.params.len() {
            let ok = !positional
                && bindno > self.params.len()
                && placeholders.iter().all(|plc| self.param(&self.sql[plc.start..plc.end]).is_some());
            if !ok {
                return Err(Rendering::Pdo(PdoError::implementation(
                    "HY093",
                    "number of bound variables does not match number of tokens",
                )));
            }
        }
        if placeholders.is_empty() {
            return Ok(self.sql.clone());
        }
        let mut out = Vec::with_capacity(self.sql.len() + 32);
        let mut pos = 0;
        let mut index = 0;
        for plc in &placeholders {
            out.extend_from_slice(&self.sql[pos..plc.start]);
            pos = plc.end;
            let quoted = match plc.kind {
                Placeholder::Named => self.param(&self.sql[plc.start..plc.end]),
                Placeholder::Positional => {
                    index += 1;
                    self.params.get(index - 1).map(|(_, v, t)| (v, *t))
                }
            };
            let Some((value, ty)) = quoted else {
                return Err(Rendering::Pdo(PdoError::implementation("HY093", "parameter was not defined")));
            };
            out.extend_from_slice(&quote_param(value, ty).map_err(Rendering::Engine)?);
        }
        out.extend_from_slice(&self.sql[pos..]);
        Ok(out)
    }

    fn param(&self, name: &[u8]) -> Option<(&Value, ParamType)> {
        self.params.iter().find(|(k, _, _)| k == name).map(|(_, v, t)| (v, *t))
    }
}

/// Why a statement could not be rendered.
#[derive(Debug)]
pub enum Rendering {
    /// PDO raised an error (`HY093`).
    Pdo(PdoError),
    /// Converting a value to a string threw.
    Engine(Error),
}

/// The literal a bound value becomes.
fn quote_param(value: &Value, ty: ParamType) -> Result<Vec<u8>> {
    if matches!(value, Value::Null) {
        return Ok(b"NULL".to_vec());
    }
    match ty {
        ParamType::Null => Ok(b"NULL".to_vec()),
        ParamType::Bool => Ok(if value.truthy() { b"1".to_vec() } else { b"0".to_vec() }),
        ParamType::Int => Ok(to_long(value).to_string().into_bytes()),
        ParamType::Str => {
            let s = value.to_php_string().map_err(Error::from)?;
            Ok(quote(s.as_bytes()))
        }
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

/// `$pdo->quote($string)`: the string in single quotes, escaped by
/// `mysqlnd_cset_escape_slashes` for utf8mb4.
pub fn quote(s: &[u8]) -> Vec<u8> {
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
enum Placeholder {
    Named,
    Positional,
}

#[derive(Clone, Copy, Debug)]
struct Found {
    start: usize,
    end: usize,
    kind: Placeholder,
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
        if let Some(close) = find_comment_end(rest) {
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

/// The end (exclusive) of the C comment opening `s`: the first `*/` after
/// the opener.
fn find_comment_end(s: &[u8]) -> Option<usize> {
    let mut i = 2;
    while i + 1 < s.len() {
        if s[i] == b'*' && s[i + 1] == b'/' {
            return Some(i + 2);
        }
        i += 1;
    }
    None
}

/// A statement's result: what `execute()` returned, the rows and the row count.
#[derive(Debug, Default)]
pub struct Executed {
    /// `execute()`'s return value.
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

/// The connection settings of a `mysql://user:password@host:port` URL
/// (PHP's DSN `mysql:host=...;port=...;charset=utf8mb4`).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Config {
    /// `host`.
    pub host: String,
    /// `port` (3306).
    pub port: u16,
    /// The user.
    pub user: String,
    /// The password.
    pub password: String,
}

impl Config {
    /// Parses `mysql://user:password@host[:port][/]`; `None` when it is not
    /// such a URL.
    pub fn parse(url: &str) -> Option<Config> {
        let rest = url.strip_prefix("mysql://").or_else(|| url.strip_prefix("mariadb://"))?;
        let authority = rest.split(['/', '?']).next()?;
        let (credentials, host_port) = match authority.rsplit_once('@') {
            Some((c, h)) => (Some(c), h),
            None => (None, authority),
        };
        let (user, password) = match credentials {
            Some(c) => match c.split_once(':') {
                Some((u, p)) => (percent_decode(u), percent_decode(p)),
                None => (percent_decode(c), String::new()),
            },
            None => ("root".to_owned(), String::new()),
        };
        let (host, port) = match host_port.rsplit_once(':') {
            Some((h, p)) => (h.to_owned(), p.parse().ok()?),
            None => (host_port.to_owned(), 3306),
        };
        if host.is_empty() {
            return None;
        }
        Some(Config { host, port, user, password })
    }
}

fn percent_decode(s: &str) -> String {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%'
            && i + 2 < b.len() + 1
            && let (Some(h), Some(l)) = (b.get(i + 1).and_then(|c| hex(*c)), b.get(i + 2).and_then(|c| hex(*c)))
        {
            out.push(h * 16 + l);
            i += 3;
            continue;
        }
        out.push(b[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn hex(c: u8) -> Option<u8> {
    match c {
        b'0'..=b'9' => Some(c - b'0'),
        b'a'..=b'f' => Some(c - b'a' + 10),
        b'A'..=b'F' => Some(c - b'A' + 10),
        _ => None,
    }
}

/// The PDO handle: one connection.
pub struct Pdo {
    config: Config,
    conn: Option<Conn>,
    /// The server status of the last successful result (mysqlnd's
    /// `upsert_status->server_status`).
    status: u16,
}

impl Pdo {
    /// `new PDO($dsn, ...)`.
    pub async fn connect(config: Config) -> Result<Pdo> {
        let conn = open(&config).await?;
        Ok(Pdo { config, conn: Some(conn), status: 0 })
    }

    /// The connection settings.
    pub fn config(&self) -> &Config {
        &self.config
    }

    /// Replaces the connection with a new one (`Utopia\Database\PDO::reconnect`).
    pub async fn reconnect(&mut self) -> Result<()> {
        if let Some(old) = self.conn.take() {
            let _ = old.disconnect().await;
        }
        self.conn = Some(open(&self.config).await?);
        self.status = 0;
        Ok(())
    }

    /// The connection id (`CONNECTION_ID()`).
    pub fn id(&self) -> u32 {
        self.conn.as_ref().map_or(0, Conn::id)
    }

    /// `inTransaction()`: `SERVER_STATUS_IN_TRANS` of the last status.
    pub fn in_transaction(&self) -> bool {
        self.status & SERVER_STATUS_IN_TRANS != 0
    }

    /// The status the connection's last OK packet reports, kept when there
    /// is none (an error).
    fn track(&mut self) {
        if let Some(ok) = self.conn.as_ref().and_then(Conn::last_ok_packet) {
            self.status = ok.status_flags().bits();
        }
    }

    /// Runs a query string: the first result's rows and affected rows; the
    /// other results are discarded, failing with their error only when
    /// `strict` (`exec()`), as mysqlnd does.
    async fn run(&mut self, query: &[u8], strict: bool) -> Result<(Vec<Array>, i64), PdoError> {
        let Some(conn) = self.conn.as_mut() else {
            return Err(gone());
        };
        let mut result = match conn.query_iter(query).await {
            Ok(r) => r,
            Err(e) => {
                let e = error_of(e);
                drop_if_lost(&mut self.conn);
                return Err(e);
            }
        };
        let affected = result.affected_rows() as i64;
        let columns = result.columns();
        let mut rows = Vec::new();
        let mut failure = None;
        if let Some(columns) = columns.filter(|c| !c.is_empty()) {
            let names: Vec<ArrayKey> =
                columns.iter().map(|c| ArrayKey::normalize(Str::from(c.name_ref().to_vec()))).collect();
            loop {
                match result.next().await {
                    Ok(Some(row)) => {
                        let mut a = Array::with_capacity(names.len());
                        for (name, value) in names.iter().zip(row.unwrap_raw()) {
                            a.set(name.clone(), fetch(value));
                        }
                        rows.push(a);
                    }
                    Ok(None) => break,
                    Err(e) => {
                        failure = Some(error_of(e));
                        break;
                    }
                }
            }
        }
        let count = if rows.is_empty() { affected } else { rows.len() as i64 };
        if failure.is_none()
            && let Err(e) = result.drop_result().await
            && strict
        {
            failure = Some(error_of(e));
        }
        self.track();
        drop_if_lost(&mut self.conn);
        match failure {
            Some(e) => Err(e),
            None => Ok((rows, count)),
        }
    }

    /// `$pdo->exec($sql)`: the affected row count.
    pub async fn exec(&mut self, sql: &str) -> Result<i64, PdoError> {
        self.exec_bytes(sql.as_bytes()).await
    }

    /// `$pdo->exec($sql)` of bytes.
    pub async fn exec_bytes(&mut self, sql: &[u8]) -> Result<i64, PdoError> {
        self.run(sql, true).await.map(|(_, count)| count)
    }

    /// `$statement->execute()` then `fetchAll()` and `rowCount()`.
    pub async fn execute(&mut self, statement: &Statement) -> Result<Executed, PdoFailure> {
        let query = match statement.render() {
            Ok(q) => q,
            Err(Rendering::Pdo(e)) => return Err(PdoFailure::Pdo(e)),
            Err(Rendering::Engine(e)) => return Err(PdoFailure::Other(e)),
        };
        let (rows, count) = self.run(&query, false).await.map_err(PdoFailure::Pdo)?;
        Ok(Executed { ok: true, rows, count })
    }

    /// `beginTransaction()` (`START TRANSACTION`).
    pub async fn begin(&mut self) -> Result<(), PdoError> {
        if self.in_transaction() {
            return Err(PdoError::plain("There is already an active transaction"));
        }
        self.run(b"START TRANSACTION", true).await.map(|_| ())
    }

    /// `commit()`.
    pub async fn commit(&mut self) -> Result<(), PdoError> {
        if !self.in_transaction() {
            return Err(PdoError::plain("There is no active transaction"));
        }
        self.run(b"COMMIT", true).await.map(|_| ())
    }

    /// `rollBack()`.
    pub async fn rollback(&mut self) -> Result<(), PdoError> {
        if !self.in_transaction() {
            return Err(PdoError::plain("There is no active transaction"));
        }
        self.run(b"ROLLBACK", true).await.map(|_| ())
    }

    /// `lastInsertId()` (`mysql_insert_id()`): `"0"` when the last result
    /// generated none.
    pub fn last_insert_id(&self) -> Value {
        let id = self.conn.as_ref().and_then(Conn::last_insert_id).unwrap_or(0);
        Value::Str(Str::from(id.to_string()))
    }
}

/// Opens a connection as mysqlnd does for PDO: TCP, `utf8mb4_general_ci`,
/// multi statements, no database, a 3 second connect timeout
/// (`ATTR_TIMEOUT`).
async fn open(config: &Config) -> Result<Conn> {
    let opts = OptsBuilder::default()
        .ip_or_hostname(config.host.clone())
        .tcp_port(config.port)
        .user(Some(config.user.clone()))
        .pass(Some(config.password.clone()))
        .prefer_socket(false)
        .stmt_cache_size(0)
        .max_allowed_packet(Some(1 << 30))
        .wait_timeout(Some(28800));
    let connecting = Conn::new(opts);
    match tokio::time::timeout(std::time::Duration::from_secs(3), connecting).await {
        Ok(Ok(conn)) => Ok(conn),
        Ok(Err(e)) => Err(connection_error(config, &e)),
        Err(_) => Err(Error::new(
            Kind::Pdo,
            format!("SQLSTATE[HY000] [2002] Connection timed out (connecting to {}:{})", config.host, config.port),
        )),
    }
}

fn connection_error(config: &Config, e: &mysql_async::Error) -> Error {
    match e {
        mysql_async::Error::Server(s) => {
            Error::new(Kind::Pdo, format!("SQLSTATE[{}] [{}] {}", s.state, s.code, s.message))
        }
        other => Error::new(
            Kind::Pdo,
            format!("SQLSTATE[HY000] [2002] {} (connecting to {}:{})", other, config.host, config.port),
        ),
    }
}

/// `CR_SERVER_GONE_ERROR`.
fn gone() -> PdoError {
    PdoError::driver("HY000", 2006, "MySQL server has gone away")
}

/// The `PDOException` of a failed query (`pdo_mysql_error`).
fn error_of(e: mysql_async::Error) -> PdoError {
    match e {
        mysql_async::Error::Server(s) => PdoError::driver(&s.state, i64::from(s.code), &s.message),
        mysql_async::Error::Io(_) | mysql_async::Error::Driver(_) => gone(),
        other => PdoError::driver("HY000", 2000, &other.to_string()),
    }
}

/// Forgets a connection that the last error closed.
fn drop_if_lost(conn: &mut Option<Conn>) {
    if conn.as_ref().is_some_and(Conn::is_disconnected) {
        *conn = None;
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

#[cfg(test)]
mod tests {
    use super::*;

    fn render(sql: &str, binds: &[(&str, Value, ParamType)]) -> String {
        let mut s = Statement::new(sql);
        for (n, v, t) in binds {
            s.bind(n, v.clone(), *t);
        }
        match s.render() {
            Ok(q) => String::from_utf8_lossy(&q).into_owned(),
            Err(Rendering::Pdo(e)) => e.message,
            Err(Rendering::Engine(e)) => e.message().to_owned(),
        }
    }

    #[test]
    fn substitutes_named_placeholders() {
        let s = |v: &str| Value::Str(Str::from(v));
        assert_eq!(
            render(
                "SELECT :a AS x, ':a' AS y, \":a\\\"\" AS z, `:a` -- :d\n, :a # :e\n /* :f */ AND 1--:a",
                &[(":a", s("A"), ParamType::Str)]
            ),
            "SELECT 'A' AS x, ':a' AS y, \":a\\\"\" AS z, `:a` -- :d\n, 'A' # :e\n /* :f */ AND 1--'A'"
        );
        assert_eq!(render("x=:a", &[("a", s("it's"), ParamType::Str)]), "x='it\\'s'");
        assert_eq!(render("x=:a", &[("a", Value::Bool(true), ParamType::Bool)]), "x=1");
        assert_eq!(render("x=:a", &[("a", Value::Bool(true), ParamType::Int)]), "x=1");
        assert_eq!(render("x=:a", &[("a", Value::Null, ParamType::Int)]), "x=NULL");
        assert_eq!(render("x=:a", &[("a", s("a\0b\n\r\x1a\"\\"), ParamType::Str)]), "x='a\\0b\\n\\r\\Z\\\"\\\\'");
        assert!(render("ab:a", &[("a", s("1"), ParamType::Str)]).contains("HY093"));
        assert!(render("SELECT 1", &[("zz", Value::Int(1), ParamType::Int)]).contains("HY093"));
    }

    #[test]
    fn escapes_invalid_multibyte_leads() {
        assert_eq!(quote(b"\xc3\xa9"), b"'\xc3\xa9'".to_vec());
        assert_eq!(quote(b"\xc3a"), b"'\\\xc3a'".to_vec());
        assert_eq!(quote(b"\xff\xfe"), b"'\xff\xfe'".to_vec());
        assert_eq!(quote(b"\xf0\x9f\x98\x80"), b"'\xf0\x9f\x98\x80'".to_vec());
    }

    #[test]
    fn parses_urls() {
        assert_eq!(
            Config::parse("mysql://root:pa%40ss@db:3307"),
            Some(Config { host: "db".into(), port: 3307, user: "root".into(), password: "pa@ss".into() })
        );
        assert_eq!(Config::parse("mysql://u@h").map(|c| c.port), Some(3306));
        assert_eq!(Config::parse("postgres://u@h"), None);
    }
}
