//! Statements as PHP's PDO pgsql driver runs them with emulated prepares.
//!
//! The adapter writes SQL with named placeholders (`:_uid`) and binds values
//! with a PDO parameter type. PDO (`ATTR_EMULATE_PREPARES`) substitutes the
//! placeholders client side with quoted literals and sends the statement as
//! plain text with `PQexec`; with `ATTR_STRINGIFY_FETCHES` every fetched
//! value is a string. [`Pdo`] reproduces that, so the server sees the same
//! text and the adapter reads the same values:
//!
//! - placeholders are found by the pgsql driver's scanner (quoted strings,
//!   identifiers, comments, dollar quotes and `::` casts are not
//!   placeholders; a `:name` right after a letter or digit is not one);
//! - `PARAM_BOOL` becomes `'t'`/`'f'`, `PARAM_INT` an integer literal,
//!   `null` `NULL`, anything else its PHP string, quoted like
//!   `PQescapeStringConn` (doubled `'`, cut at the first NUL byte); a string
//!   that is not valid UTF-8 makes the statement fail silently (`execute()`
//!   returns `false` without running it), as PHP 8.5 does;
//! - fetched booleans are `"1"`/`"0"`, floats are reprinted as PHP prints
//!   them (precision 14), `NULL` is `null`, everything else is the text the
//!   server sent;
//! - errors are `PDOException`s with PDO's message:
//!   `SQLSTATE[23505]: Unique violation: 7 ERROR:  ...`, the server's
//!   error rebuilt as libpq formats it (`DETAIL:`, `HINT:`, `LINE n:` with
//!   its caret).
//!
//! PHP: `\PDO` / `\PDOStatement` with the pgsql driver (`ext/pdo`,
//! `ext/pdo_pgsql`) and libpq.

use bytes::Bytes;
use php_std::number;

use super::wire::{Config, Outcome, Status, TxStatus, Wire};
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

/// A driver error (`PDOException`): its SQLSTATE, the driver's native code
/// (`errorInfo[1]`, `7` for server errors) and its message.
#[derive(Clone, Debug, PartialEq)]
pub struct PdoError {
    /// `getCode()` / `errorInfo[0]`.
    pub sqlstate: String,
    /// `errorInfo[1]`.
    pub native: Option<i64>,
    /// `getMessage()`.
    pub message: String,
}

impl PdoError {
    /// An error PDO raises itself (`pdo_raise_impl_error`).
    pub fn implementation(sqlstate: &str, supplement: &str) -> Self {
        let message = format!("SQLSTATE[{sqlstate}]: {}: {supplement}", describe(sqlstate));
        Self { sqlstate: sqlstate.to_owned(), native: Some(0), message }
    }

    /// An exception PDO throws with only a message (`There is no active
    /// transaction`): code `0`.
    pub fn plain(message: &str) -> Self {
        Self { sqlstate: "0".to_owned(), native: None, message: message.to_owned() }
    }

    /// The error as the crate's error (`PDOException`).
    pub fn into_error(self) -> Error {
        Error::new(Kind::Pdo, self.message)
    }
}

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
    /// gets one; binding a name again replaces its value. `PARAM_BOOL`
    /// values become the strings `t`/`f` (the pgsql driver's normalization).
    pub fn bind(&mut self, name: &str, value: Value, ty: ParamType) {
        let mut key = Vec::with_capacity(name.len() + 1);
        if !name.starts_with(':') {
            key.push(b':');
        }
        key.extend_from_slice(name.as_bytes());
        let (value, ty) = match ty {
            ParamType::Bool => (Value::Str(Str::from(if value.truthy() { "t" } else { "f" })), ParamType::Str),
            other => (value, other),
        };
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

    /// The statement as PDO sends it (`pdo_parse_params`): `Ok(None)` when a
    /// value cannot be quoted, which makes `execute()` return `false`.
    pub fn render(&self) -> Result<Option<Vec<u8>>, Rendering> {
        if self.params.is_empty() {
            return Ok(Some(self.sql.clone()));
        }
        let placeholders = scan(&self.sql)?;
        let (named, positional) = placeholders.iter().fold((false, false), |(n, p), plc| match plc.kind {
            Placeholder::Named => (true, p),
            Placeholder::Positional => (n, true),
            Placeholder::Escaped => (n, p),
        });
        if named && positional {
            return Err(Rendering::Pdo(PdoError::implementation("HY093", "mixed named and positional parameters")));
        }
        let bindno = placeholders.iter().filter(|p| p.kind != Placeholder::Escaped).count();
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
            return Ok(Some(self.sql.clone()));
        }
        let mut out = Vec::with_capacity(self.sql.len() + 32);
        let mut pos = 0;
        let mut index = 0;
        for plc in &placeholders {
            out.extend_from_slice(&self.sql[pos..plc.start]);
            pos = plc.end;
            let quoted = match plc.kind {
                Placeholder::Escaped => {
                    out.push(b'?');
                    continue;
                }
                Placeholder::Named => self.param(&self.sql[plc.start..plc.end]),
                Placeholder::Positional => {
                    index += 1;
                    self.params.get(index - 1).map(|(_, v, t)| (v, *t))
                }
            };
            let Some((value, ty)) = quoted else {
                return Err(Rendering::Pdo(PdoError::implementation("HY093", "parameter was not defined")));
            };
            match quote_param(value, ty).map_err(Rendering::Engine)? {
                Some(q) => out.extend_from_slice(&q),
                None => return Ok(None),
            }
        }
        out.extend_from_slice(&self.sql[pos..]);
        Ok(Some(out))
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
fn quote_param(value: &Value, ty: ParamType) -> Result<Option<Vec<u8>>> {
    if matches!(value, Value::Null) {
        return Ok(Some(b"NULL".to_vec()));
    }
    match ty {
        ParamType::Null => Ok(Some(b"NULL".to_vec())),
        ParamType::Bool => Ok(Some(if value.truthy() { b"1".to_vec() } else { b"0".to_vec() })),
        ParamType::Int => Ok(Some(to_long(value).to_string().into_bytes())),
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

/// `$pdo->quote($string)` (`PQescapeStringConn` in quotes): `None` when the
/// string, up to its first NUL, is not valid UTF-8.
pub fn quote(s: &[u8]) -> Option<Vec<u8>> {
    let s = match s.iter().position(|b| *b == 0) {
        Some(i) => &s[..i],
        None => s,
    };
    std::str::from_utf8(s).ok()?;
    let mut out = Vec::with_capacity(s.len() + 2);
    out.push(b'\'');
    for &b in s {
        if b == b'\'' {
            out.push(b'\'');
        }
        out.push(b);
    }
    out.push(b'\'');
    Some(out)
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Placeholder {
    Named,
    Positional,
    Escaped,
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
    EscapedQuestion,
    CustomQuote,
}

/// The pgsql driver's placeholder scanner (`pdo_pgsql_scanner`), then
/// `pdo_parse_params`'s first phase: the placeholders of `sql`, outside
/// quotes, identifiers, comments and dollar-quoted strings.
fn scan(sql: &[u8]) -> Result<Vec<Found>, Rendering> {
    // The scanner stops at the terminating NUL; an embedded one ends it too.
    let end = sql.iter().position(|b| *b == 0).unwrap_or(sql.len());
    let s = &sql[..end];
    let mut out = Vec::new();
    let mut pos = 0;
    let mut custom: Option<(usize, usize)> = None;
    while pos < s.len() {
        let (token, len) = next_token(s, pos);
        let start = pos;
        pos += len;
        if let Some((qs, ql)) = custom {
            if token == Token::CustomQuote && ql == len && s[qs..qs + ql] == s[start..start + len] {
                custom = None;
            } else if token == Token::EscapedQuestion {
                out.push(Found { start, end: pos, kind: Placeholder::Escaped });
            }
            continue;
        }
        match token {
            Token::CustomQuote => custom = Some((start, len)),
            Token::Bind => {
                if start > 0 && s[start - 1].is_ascii_alphanumeric() {
                    continue;
                }
                out.push(Found { start, end: pos, kind: Placeholder::Named });
            }
            Token::BindPos => out.push(Found { start, end: pos, kind: Placeholder::Positional }),
            Token::EscapedQuestion => out.push(Found { start, end: pos, kind: Placeholder::Escaped }),
            Token::Text => {}
        }
    }
    Ok(out)
}

fn is_special(c: u8) -> bool {
    matches!(c, b'$' | b'e' | b'E' | b':' | b'?' | b'"' | b'\'' | b'/' | b'-')
}

fn is_bind_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_'
}

fn is_dolq_start(c: u8) -> bool {
    c.is_ascii_alphabetic() || c >= 0x80 || c == b'_'
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
    // [eE]'...' with backslash escapes and doubled quotes.
    if rest.len() >= 2 && (rest[0] == b'e' || rest[0] == b'E') && rest[1] == b'\'' {
        let mut i = 2;
        loop {
            match rest.get(i) {
                None => break,
                Some(b'\'') => {
                    if rest.get(i + 1) == Some(&b'\'') {
                        i += 2;
                    } else {
                        offer(Token::Text, i + 1);
                        break;
                    }
                }
                Some(b'\\') => {
                    if i + 1 < rest.len() {
                        i += 2;
                    } else {
                        break;
                    }
                }
                Some(_) => i += 1,
            }
        }
    }
    // "..." and '...' with doubled quotes.
    for quote in *b"\"'" {
        if rest[0] == quote {
            let mut i = 1;
            loop {
                match rest.get(i) {
                    None => break,
                    Some(&c) if c == quote => {
                        if rest.get(i + 1) == Some(&quote) {
                            i += 2;
                        } else {
                            offer(Token::Text, i + 1);
                            break;
                        }
                    }
                    Some(_) => i += 1,
                }
            }
        }
    }
    // $tag$ or $$.
    if rest[0] == b'$' {
        let mut i = 1;
        if rest.get(1).is_some_and(|c| is_dolq_start(*c)) {
            i = 2;
            while rest.get(i).is_some_and(|c| is_dolq_start(*c) || c.is_ascii_digit()) {
                i += 1;
            }
        }
        if rest.get(i) == Some(&b'$') {
            offer(Token::CustomQuote, i + 1);
        }
    }
    // :{2,}
    if rest[0] == b':' {
        let n = rest.iter().take_while(|c| **c == b':').count();
        if n >= 2 {
            offer(Token::Text, n);
        }
    }
    // ??
    if rest.len() >= 2 && rest[0] == b'?' && rest[1] == b'?' {
        offer(Token::EscapedQuestion, 2);
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
    // Comments: /* ... */ and -- to the end of the line.
    if rest.starts_with(b"/*") {
        if let Some(close) = find_comment_end(rest) {
            offer(Token::Text, close);
        }
    } else if rest.starts_with(b"--") {
        let n = rest.iter().position(|c| *c == b'\n').unwrap_or(rest.len());
        offer(Token::Text, n);
    }
    // A run of anything else.
    let n = rest.iter().take_while(|c| !is_special(**c)).count();
    if n > 0 {
        offer(Token::Text, n);
    }
    if best.1 == 0 {
        // Unreachable in practice: every byte starts some token.
        best = (Token::Text, 1);
    }
    best
}

/// The end (exclusive) of the C comment opening `s`, matching
/// `"/*"([^*]+|[*]+[^/*])*[*]*"*/"`: the first `*/` after the opener.
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

pub use crate::adapter::sqlstate::describe;

/// The rows of a result as `fetchAll(PDO::FETCH_ASSOC)` returns them with
/// `ATTR_STRINGIFY_FETCHES`.
pub fn rows(outcome: &Outcome) -> Vec<Array> {
    let mut out = Vec::with_capacity(outcome.rows.len());
    for row in &outcome.rows {
        let mut a = Array::with_capacity(row.len());
        for (column, value) in outcome.columns.iter().zip(row) {
            let key = ArrayKey::normalize(Str::from(column.name.to_vec()));
            a.set(key, fetch(column.oid, value.as_ref()));
        }
        out.push(a);
    }
    out
}

/// One fetched value (`pgsql_stmt_get_col`, then PDO's stringify).
pub fn fetch(oid: u32, value: Option<&Bytes>) -> Value {
    let Some(v) = value else { return Value::Null };
    match oid {
        // bool
        16 => Value::Str(Str::from(if v.first() == Some(&b't') { "1" } else { "0" })),
        // int2, int4, int8, oid: strtol/strtoul, printed back.
        21 | 23 | 20 => Value::Str(Str::from(php_std::format::str_to_int(v).to_string())),
        26 => Value::Str(Str::from(parse_oid(v).to_string())),
        // float4, float8
        700 | 701 => {
            let f = if v.starts_with(b"Infinity") && v.len() <= 8 {
                f64::INFINITY
            } else if v.starts_with(b"-Infinity") && v.len() <= 9 {
                f64::NEG_INFINITY
            } else if v.starts_with(b"NaN") && v.len() <= 3 {
                f64::NAN
            } else {
                php_std::format::str_to_float(v)
            };
            Value::Str(Str::from(number::to_string(f)))
        }
        // bytea
        17 => Value::Str(Str::from(unescape_bytea(v))),
        _ => Value::Str(Str::from(v.to_vec())),
    }
}

fn parse_oid(v: &[u8]) -> u32 {
    let mut n: u64 = 0;
    for c in v.iter().take_while(|c| c.is_ascii_digit()) {
        n = n.saturating_mul(10).saturating_add(u64::from(c - b'0'));
    }
    n as u32
}

fn unescape_bytea(v: &[u8]) -> Vec<u8> {
    if let Some(hex) = v.strip_prefix(b"\\x") {
        return php_std::encoding::hex2bin(hex).unwrap_or_default();
    }
    let mut out = Vec::with_capacity(v.len());
    let mut i = 0;
    while i < v.len() {
        if v[i] == b'\\' {
            if v.get(i + 1) == Some(&b'\\') {
                out.push(b'\\');
                i += 2;
                continue;
            }
            if i + 3 < v.len() && v[i + 1..i + 4].iter().all(|c| (b'0'..=b'7').contains(c)) {
                out.push((v[i + 1] - b'0') * 64 + (v[i + 2] - b'0') * 8 + (v[i + 3] - b'0'));
                i += 4;
                continue;
            }
        }
        out.push(v[i]);
        i += 1;
    }
    out
}

/// The libpq message of an error result (`PQresultErrorMessage`), as
/// `pqBuildErrorMessage3` writes it with the default verbosity, for the
/// query `query` it answered.
pub fn libpq_message(outcome: &Outcome, query: &[u8]) -> String {
    if outcome.error.is_empty() {
        return outcome.client_message.clone();
    }
    let text = |c: u8| outcome.field(c).map(|v| String::from_utf8_lossy(v).into_owned());
    let mut msg = String::new();
    if let Some(s) = text(b'S') {
        msg.push_str(&s);
        msg.push_str(":  ");
    }
    if let Some(m) = text(b'M') {
        msg.push_str(&m);
    }
    let mut querytext: Option<Vec<u8>> = None;
    let mut querypos = 0i64;
    if let Some(p) = text(b'P') {
        querytext = Some(query.iter().take_while(|b| **b != 0).copied().collect());
        querypos = atoi(&p);
    } else if let Some(p) = text(b'p') {
        match outcome.field(b'q') {
            Some(q) => {
                querytext = Some(q.to_vec());
                querypos = atoi(&p);
            }
            None => {
                msg.push_str(" at character ");
                msg.push_str(&p);
            }
        }
    }
    msg.push('\n');
    let mut bytes = msg.into_bytes();
    if let Some(q) = querytext
        && querypos > 0
    {
        report_error_position(&mut bytes, &q, querypos);
    }
    let mut msg = String::from_utf8_lossy(&bytes).into_owned();
    for (code, label) in [(b'D', "DETAIL"), (b'H', "HINT"), (b'q', "QUERY"), (b'W', "CONTEXT")] {
        if let Some(v) = text(code) {
            msg.push_str(label);
            msg.push_str(":  ");
            msg.push_str(&v);
            msg.push('\n');
        }
    }
    msg
}

fn atoi(s: &str) -> i64 {
    let s = s.trim_start();
    let (neg, digits) = match s.as_bytes().first() {
        Some(b'-') => (true, &s[1..]),
        Some(b'+') => (false, &s[1..]),
        _ => (false, s),
    };
    let mut n: i64 = 0;
    for c in digits.bytes().take_while(u8::is_ascii_digit) {
        n = n.wrapping_mul(10).wrapping_add(i64::from(c - b'0'));
    }
    if neg { -n } else { n }
}

/// libpq's `reportErrorPosition` for UTF-8: the line of the query holding
/// the error, cut to 60 screen columns, and a caret under the error.
fn report_error_position(msg: &mut Vec<u8>, query: &[u8], loc: i64) {
    const DISPLAY_SIZE: i64 = 60;
    const MIN_RIGHT_CUT: i64 = 10;
    let loc = loc - 1;
    if loc < 0 {
        return;
    }
    let mut wquery = query.to_vec();
    let mut qidx: Vec<usize> = Vec::new();
    let mut scridx: Vec<i64> = Vec::new();
    let mut qoffset = 0usize;
    let mut scroffset = 0i64;
    let mut loc_line = 1;
    let mut ibeg = 0usize;
    let mut iend: Option<usize> = None;
    let mut cno = 0usize;
    while qoffset < wquery.len() && wquery[qoffset] != 0 {
        let ch = wquery[qoffset];
        qidx.push(qoffset);
        scridx.push(scroffset);
        if ch == b'\t' {
            wquery[qoffset] = b' ';
        } else if ch == b'\r' || ch == b'\n' {
            if (cno as i64) < loc {
                if ch == b'\r' || cno == 0 || wquery[qidx[cno - 1]] != b'\r' {
                    loc_line += 1;
                }
                ibeg = cno + 1;
            } else {
                iend = Some(cno);
                break;
            }
        }
        let mut w = dsplen(&wquery[qoffset..]);
        if w <= 0 {
            w = 1;
        }
        scroffset += w;
        qoffset += mblen_bounded(&wquery[qoffset..]);
        cno += 1;
    }
    let mut iend = match iend {
        Some(e) => e,
        None => {
            qidx.push(qoffset);
            scridx.push(scroffset);
            cno
        }
    };
    if loc as usize > cno {
        return;
    }
    let loc = loc as usize;
    let mut beg_trunc = false;
    let mut end_trunc = false;
    if scridx[iend] - scridx[ibeg] > DISPLAY_SIZE {
        if scridx[ibeg] + DISPLAY_SIZE >= scridx[loc] + MIN_RIGHT_CUT {
            while scridx[iend] - scridx[ibeg] > DISPLAY_SIZE {
                iend -= 1;
            }
            end_trunc = true;
        } else {
            while scridx[loc] + MIN_RIGHT_CUT < scridx[iend] {
                iend -= 1;
                end_trunc = true;
            }
            while scridx[iend] - scridx[ibeg] > DISPLAY_SIZE {
                ibeg += 1;
                beg_trunc = true;
            }
        }
    }
    let line_text = &wquery[qidx[ibeg]..qidx[iend]];
    let line_text = match line_text.iter().position(|b| *b == 0) {
        Some(i) => &line_text[..i],
        None => line_text,
    };
    let start = msg.len();
    msg.extend_from_slice(format!("LINE {loc_line}: ").as_bytes());
    if beg_trunc {
        msg.extend_from_slice(b"...");
    }
    let mut prefix = 0i64;
    let mut i = start;
    while i < msg.len() {
        let mut w = dsplen(&msg[i..]);
        if w <= 0 {
            w = 1;
        }
        prefix += w;
        i += mblen_bounded(&msg[i..]);
    }
    msg.extend_from_slice(line_text);
    if end_trunc {
        msg.extend_from_slice(b"...");
    }
    msg.push(b'\n');
    let caret = prefix + scridx[loc] - scridx[ibeg];
    for _ in 0..caret {
        msg.push(b' ');
    }
    msg.extend_from_slice(b"^\n");
}

/// `pg_utf_mblen`.
fn mblen(c: u8) -> usize {
    if c & 0x80 == 0 {
        1
    } else if c & 0xe0 == 0xc0 {
        2
    } else if c & 0xf0 == 0xe0 {
        3
    } else if c & 0xf8 == 0xf0 {
        4
    } else {
        1
    }
}

/// `PQmblenBounded`: the character length, not past a NUL.
fn mblen_bounded(s: &[u8]) -> usize {
    let l = mblen(s[0]);
    s.iter().take(l).take_while(|b| **b != 0).count().max(1)
}

/// `utf8_to_unicode`.
fn utf8_to_unicode(s: &[u8]) -> u32 {
    let b = |i: usize| u32::from(s.get(i).copied().unwrap_or(0));
    let c = b(0);
    if c & 0x80 == 0 {
        c
    } else if c & 0xe0 == 0xc0 {
        ((c & 0x1f) << 6) | (b(1) & 0x3f)
    } else if c & 0xf0 == 0xe0 {
        ((c & 0x0f) << 12) | ((b(1) & 0x3f) << 6) | (b(2) & 0x3f)
    } else if c & 0xf8 == 0xf0 {
        ((c & 0x07) << 18) | ((b(1) & 0x3f) << 12) | ((b(2) & 0x3f) << 6) | (b(3) & 0x3f)
    } else {
        0xffff_ffff
    }
}

/// `pg_utf_dsplen` (`ucs_wcwidth`).
fn dsplen(s: &[u8]) -> i64 {
    let ucs = utf8_to_unicode(s);
    if ucs == 0 {
        return 0;
    }
    if ucs < 0x20 || (0x7f..0xa0).contains(&ucs) || ucs > 0x0010_ffff {
        return -1;
    }
    if in_table(ucs, super::widths::NONSPACING) {
        return 0;
    }
    if in_table(ucs, super::widths::EAST_ASIAN_FW) {
        return 2;
    }
    1
}

fn in_table(ucs: u32, table: &[(u32, u32)]) -> bool {
    table
        .binary_search_by(|(first, last)| {
            if ucs > *last {
                std::cmp::Ordering::Less
            } else if ucs < *first {
                std::cmp::Ordering::Greater
            } else {
                std::cmp::Ordering::Equal
            }
        })
        .is_ok()
}

/// libpq's message trimmed as `_pdo_pgsql_trim_message` does.
fn trim_message(message: &str) -> &str {
    let b = message.as_bytes();
    if b.is_empty() {
        return message;
    }
    let mut i = b.len() - 1;
    if i > 1 && (b[i - 1] == b'\r' || b[i - 1] == b'\n') && b[i] == b'.' {
        i -= 1;
    }
    while i > 0 && (b[i] == b'\r' || b[i] == b'\n') {
        i -= 1;
    }
    &message[..i + 1]
}

/// The `PDOException` of a failed result (`pdo_pgsql_error` then
/// `pdo_handle_error`).
pub fn error_of(outcome: &Outcome, query: &[u8]) -> PdoError {
    let sqlstate = match outcome.field(b'C') {
        Some(c) if c.len() < 6 => String::from_utf8_lossy(c).into_owned(),
        _ => "HY000".to_owned(),
    };
    let native = outcome.status.code();
    let supplement = trim_message(&libpq_message(outcome, query)).to_owned();
    let description = describe(&sqlstate);
    let message = if native != 0 {
        format!("SQLSTATE[{sqlstate}]: {description}: {native} {supplement}")
    } else {
        format!("SQLSTATE[{sqlstate}]: {description}: {supplement}")
    };
    PdoError { sqlstate, native: Some(native), message }
}

/// A statement's outcome as `execute()` returns it.
fn executed(outcome: Outcome, query: &[u8]) -> Result<Executed, PdoFailure> {
    match outcome.status {
        Status::Command | Status::Tuples => {
            let count =
                if outcome.status == Status::Command { outcome.cmd_tuples() } else { outcome.rows.len() as i64 };
            Ok(Executed { ok: true, rows: rows(&outcome), count })
        }
        _ => Err(PdoFailure::Pdo(error_of(&outcome, query))),
    }
}

/// A statement's result: what `execute()` returned, the rows and the row count.
#[derive(Debug, Default)]
pub struct Executed {
    /// `execute()`'s return value (`false` when a value could not be quoted).
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

/// A `statement_timeout` value: the server's default, a number of
/// milliseconds, or not known.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Setting {
    Default,
    Ms(i64),
    Unknown,
}

/// The `statement_timeout` in effect on a connection, kept so that the
/// adapter's `execute()` (`SET`, the statement, `RESET`) sends a `SET` only
/// when the value a statement must run with differs from the one in effect.
/// Every statement still runs with the value PHP's sequence gives it.
#[derive(Clone, Debug)]
struct Timeouts {
    /// The session value, as left by statements outside a transaction.
    session: Setting,
    /// A `SET LOCAL` in effect in the current transaction.
    local: Option<Setting>,
    /// The `SET LOCAL` PHP would have in effect in the current transaction:
    /// the timeout of its last timed statement.
    timed: Option<Setting>,
    /// `(local, timed)` at each savepoint, by depth.
    savepoints: Vec<(i64, Option<Setting>, Option<Setting>)>,
}

impl Timeouts {
    const FRESH: Timeouts = Timeouts { session: Setting::Default, local: None, timed: None, savepoints: Vec::new() };

    fn effective(&self) -> Setting {
        self.local.unwrap_or(self.session)
    }

    /// A transaction ended: its `SET LOCAL`s are gone.
    fn idle(&mut self) {
        self.local = None;
        self.timed = None;
        self.savepoints.clear();
    }
}

/// `SET [LOCAL] statement_timeout` to `setting` (never [`Setting::Unknown`]).
fn set_timeout(local: bool, setting: Setting) -> String {
    let scope = if local { "SET LOCAL" } else { "SET" };
    match setting {
        Setting::Ms(ms) => format!("{scope} statement_timeout = '{ms}ms'"),
        _ => format!("{scope} statement_timeout TO DEFAULT"),
    }
}

/// The PDO handle: one connection.
pub struct Pdo {
    config: Config,
    wire: Option<Wire>,
    timeouts: Timeouts,
}

impl Pdo {
    /// `new PDO($dsn, ...)`.
    pub async fn connect(config: Config) -> Result<Pdo> {
        let wire = Wire::connect(&config).await.map_err(|e| connection_error(&config, &e))?;
        Ok(Pdo { config, wire: Some(wire), timeouts: Timeouts::FRESH })
    }

    /// The connection settings.
    pub fn config(&self) -> &Config {
        &self.config
    }

    /// Replaces the connection with a new one (`Utopia\Database\PDO::reconnect`).
    pub async fn reconnect(&mut self) -> Result<()> {
        if let Some(old) = self.wire.take() {
            old.close().await;
        }
        self.timeouts = Timeouts::FRESH;
        let wire = Wire::connect(&self.config).await.map_err(|e| connection_error(&self.config, &e))?;
        self.wire = Some(wire);
        Ok(())
    }

    fn wire(&mut self) -> &mut Wire {
        self.wire.as_mut().unwrap_or_else(|| unreachable!("a PDO handle always holds a connection"))
    }

    /// The backend process id.
    pub fn pid(&mut self) -> i32 {
        self.wire().pid()
    }

    /// `inTransaction()` (`PQtransactionStatus() > PQTRANS_IDLE`).
    pub fn in_transaction(&mut self) -> bool {
        self.wire().status() != TxStatus::Idle
    }

    /// Runs a query string, failing with the driver error. Transaction
    /// control and `LASTVAL()` go through here: they run with whatever
    /// timeout is in effect, which cannot change their outcome.
    async fn run(&mut self, query: &[u8]) -> Result<Outcome, PdoError> {
        let outcome = self.wire().exec(query).await;
        self.settle();
        match outcome.status {
            Status::Command | Status::Tuples => Ok(outcome),
            _ => Err(error_of(&outcome, query)),
        }
    }

    fn idle(&mut self) -> bool {
        self.wire().status() == TxStatus::Idle
    }

    /// Notes a transaction's end (the connection reports itself idle).
    fn settle(&mut self) {
        if self.idle() {
            self.timeouts.idle();
        }
    }

    /// Runs `query`, after `change` (a timeout change) when given, in one
    /// write, and records the change when it succeeds.
    async fn run_with(&mut self, change: Option<(bool, Setting)>, query: &[u8]) -> Outcome {
        let outcome = match change {
            None => self.wire().exec(query).await,
            Some((local, setting)) => {
                let set = set_timeout(local, setting);
                let mut outcomes = self.wire().exec_many(&[set.as_bytes(), query]).await.into_iter();
                let changed = outcomes.next().is_some_and(|o| matches!(o.status, Status::Command | Status::Tuples));
                let recorded = if changed { setting } else { Setting::Unknown };
                if local {
                    self.timeouts.local = Some(recorded);
                } else {
                    self.timeouts.session = recorded;
                }
                outcomes.next().unwrap_or_else(|| Outcome::client_error(""))
            }
        };
        self.settle();
        outcome
    }

    /// The timeout change an untimed statement needs to run with the value
    /// PHP gives it: the default outside a transaction (PHP resets after
    /// every timed statement), inside one the `SET LOCAL` of its last timed
    /// statement, if any.
    fn untimed_change(&mut self) -> Option<(bool, Setting)> {
        if self.idle() {
            return (self.timeouts.session != Setting::Default).then_some((false, Setting::Default));
        }
        let want = self.timeouts.timed.unwrap_or(Setting::Default);
        (self.timeouts.effective() != want).then_some((true, want))
    }

    /// `$pdo->exec($sql)`: the affected row count.
    pub async fn exec(&mut self, sql: &str) -> Result<i64, PdoError> {
        let change = self.untimed_change();
        let outcome = self.run_with(change, sql.as_bytes()).await;
        match outcome.status {
            Status::Command => Ok(outcome.cmd_tuples()),
            Status::Tuples => Ok(0),
            _ => Err(error_of(&outcome, sql.as_bytes())),
        }
    }

    /// `$statement->execute()` then `fetchAll()` and `rowCount()`.
    pub async fn execute(&mut self, statement: &Statement) -> Result<Executed, PdoFailure> {
        let query = match statement.render() {
            Ok(Some(q)) => q,
            Ok(None) => return Ok(Executed::default()),
            Err(Rendering::Pdo(e)) => return Err(PdoFailure::Pdo(e)),
            Err(Rendering::Engine(e)) => return Err(PdoFailure::Other(e)),
        };
        let change = self.untimed_change();
        let outcome = self.run_with(change, &query).await;
        executed(outcome, &query)
    }

    /// The adapter's `execute()`: `$statement->execute()` with a statement
    /// timeout of `timeout` ms, `SET LOCAL` in a transaction (`depth` > 0),
    /// otherwise `SET` and a `RESET` afterwards. The statement runs with the
    /// timeout PHP's sequence gives it, but the `SET` goes out only when the
    /// value in effect differs, and the `RESET` is left to the next untimed
    /// statement that needs it.
    pub async fn execute_timed(
        &mut self,
        timeout: i64,
        depth: i64,
        statement: &Statement,
    ) -> Result<Executed, PdoFailure> {
        let want = Setting::Ms(timeout);
        let idle = self.idle();
        let rendered = match statement.render() {
            Ok(Some(q)) if (depth == 0) == idle => q,
            // A statement that never reaches the server, or a transaction
            // the adapter and the connection disagree on: PHP's sequence as
            // it is, after which the value in effect is not tracked.
            _ => {
                let set = set_timeout(depth != 0, want);
                let reset = (depth == 0).then_some("RESET statement_timeout");
                let result = self.execute_between(&set, statement, reset).await;
                self.timeouts.session = Setting::Unknown;
                if depth != 0 {
                    self.timeouts.timed = Some(want);
                }
                if !self.idle() {
                    self.timeouts.local = Some(Setting::Unknown);
                }
                self.settle();
                return result;
            }
        };
        let change = if depth == 0 {
            (self.timeouts.session != want).then_some((false, want))
        } else {
            self.timeouts.timed = Some(want);
            (self.timeouts.effective() != want).then_some((true, want))
        };
        let outcome = self.run_with(change, &rendered).await;
        executed(outcome, &rendered)
    }

    /// `$pdo->exec($set)`, `$statement->execute()`, then (when `reset` is
    /// given) `$pdo->exec($reset)` in a `finally`: the adapter's `execute()`
    /// with its statement timeout. The three go out in one write; the
    /// result is the one PHP's sequence of calls produces (a failed `SET`
    /// stops there, a failed reset replaces the statement's outcome).
    async fn execute_between(
        &mut self,
        set: &str,
        statement: &Statement,
        reset: Option<&str>,
    ) -> Result<Executed, PdoFailure> {
        let rendered = statement.render();
        let mut queries: Vec<&[u8]> = vec![set.as_bytes()];
        if let Ok(Some(q)) = &rendered {
            queries.push(q);
        }
        if let Some(r) = reset {
            queries.push(r.as_bytes());
        }
        let mut outcomes = self.wire().exec_many(&queries).await.into_iter();
        let set_outcome = outcomes.next().unwrap_or_else(|| Outcome::client_error(""));
        if !matches!(set_outcome.status, Status::Command | Status::Tuples) {
            return Err(PdoFailure::Pdo(error_of(&set_outcome, set.as_bytes())));
        }
        let result = match rendered {
            Ok(Some(q)) => {
                let outcome = outcomes.next().unwrap_or_else(|| Outcome::client_error(""));
                match outcome.status {
                    Status::Command | Status::Tuples => {
                        let count = if outcome.status == Status::Command {
                            outcome.cmd_tuples()
                        } else {
                            outcome.rows.len() as i64
                        };
                        Ok(Executed { ok: true, rows: rows(&outcome), count })
                    }
                    _ => Err(PdoFailure::Pdo(error_of(&outcome, &q))),
                }
            }
            Ok(None) => Ok(Executed::default()),
            Err(Rendering::Pdo(e)) => Err(PdoFailure::Pdo(e)),
            Err(Rendering::Engine(e)) => Err(PdoFailure::Other(e)),
        };
        if let Some(r) = reset {
            let outcome = outcomes.next().unwrap_or_else(|| Outcome::client_error(""));
            if !matches!(outcome.status, Status::Command | Status::Tuples) {
                return Err(PdoFailure::Pdo(error_of(&outcome, r.as_bytes())));
            }
        }
        result
    }

    /// `beginTransaction()`.
    pub async fn begin(&mut self) -> Result<(), PdoError> {
        if self.in_transaction() {
            return Err(PdoError::plain("There is already an active transaction"));
        }
        self.run(b"BEGIN").await.map(|_| ())
    }

    /// `$pdo->exec('SAVEPOINT transaction{depth}')`.
    pub async fn savepoint(&mut self, depth: i64) -> Result<(), PdoError> {
        let (local, timed) = (self.timeouts.local, self.timeouts.timed);
        self.run(format!("SAVEPOINT transaction{depth}").as_bytes()).await?;
        self.timeouts.savepoints.retain(|(d, _, _)| *d < depth);
        self.timeouts.savepoints.push((depth, local, timed));
        Ok(())
    }

    /// `$pdo->exec('ROLLBACK TO transaction{depth}')`: also undoes the
    /// `SET LOCAL`s made since the savepoint.
    pub async fn rollback_to(&mut self, depth: i64) -> Result<(), PdoError> {
        let result = self.run(format!("ROLLBACK TO transaction{depth}").as_bytes()).await;
        let saved = self.timeouts.savepoints.iter().find(|(d, _, _)| *d == depth).copied();
        match saved {
            Some((_, local, timed)) if result.is_ok() => {
                self.timeouts.local = local;
                self.timeouts.timed = timed;
                self.timeouts.savepoints.retain(|(d, _, _)| *d <= depth);
            }
            _ if self.idle() => {}
            _ => {
                self.timeouts.local = Some(Setting::Unknown);
            }
        }
        result.map(|_| ())
    }

    /// `commit()`.
    pub async fn commit(&mut self) -> Result<(), PdoError> {
        if !self.in_transaction() {
            return Err(PdoError::plain("There is no active transaction"));
        }
        self.run(b"COMMIT").await.map(|_| ())
    }

    /// `rollBack()`.
    pub async fn rollback(&mut self) -> Result<(), PdoError> {
        if !self.in_transaction() {
            return Err(PdoError::plain("There is no active transaction"));
        }
        self.run(b"ROLLBACK").await.map(|_| ())
    }

    /// `lastInsertId()` (`SELECT LASTVAL()`).
    pub async fn last_insert_id(&mut self) -> Result<Value, PdoError> {
        let outcome = self.run(b"SELECT LASTVAL()").await?;
        Ok(outcome
            .rows
            .first()
            .and_then(|r| r.first().cloned().flatten())
            .map_or(Value::Str(Str::EMPTY), |v| Value::Str(Str::from(v.to_vec()))))
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

fn connection_error(config: &Config, e: &std::io::Error) -> Error {
    Error::new(
        Kind::Pdo,
        format!("SQLSTATE[08006] [7] connection to server at \"{}\", port {} failed: {}", config.host, config.port, e),
    )
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
            Ok(Some(q)) => String::from_utf8(q).unwrap(),
            Ok(None) => "<false>".into(),
            Err(Rendering::Pdo(e)) => e.message,
            Err(Rendering::Engine(e)) => e.message().to_owned(),
        }
    }

    #[test]
    fn substitutes_named_placeholders() {
        let s = |v: &str| Value::Str(Str::from(v));
        assert_eq!(
            render("SELECT :a::text AS x, ':b' AS y, \":c\" AS z -- :d\n, :a AS w", &[(":a", s("A"), ParamType::Str)]),
            "SELECT 'A'::text AS x, ':b' AS y, \":c\" AS z -- :d\n, 'A' AS w"
        );
        assert_eq!(render("x=:a", &[("a", s("it's"), ParamType::Str)]), "x='it''s'");
        assert_eq!(render("x=:a", &[("a", Value::Bool(true), ParamType::Bool)]), "x='t'");
        assert_eq!(render("x=:a", &[("a", Value::Null, ParamType::Bool)]), "x='f'");
        assert_eq!(render("x=:a", &[("a", Value::Null, ParamType::Int)]), "x=NULL");
        assert_eq!(render("x=:a", &[("a", s("a\0b"), ParamType::Str)]), "x='a'");
        assert_eq!(render("x=:a", &[("a", Value::Str(Str::from(vec![0xffu8])), ParamType::Str)]), "<false>");
        assert!(render("ab:a", &[("a", s("1"), ParamType::Str)]).contains("HY093"));
        assert!(render("SELECT 1", &[("zz", Value::Int(1), ParamType::Int)]).contains("HY093"));
    }

    #[test]
    fn trims_messages() {
        assert_eq!(trim_message("ERROR:  x\n"), "ERROR:  x");
        assert_eq!(trim_message("a\n\n"), "a");
    }
}
