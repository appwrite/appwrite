//! PDO's pgsql driver with emulated prepares.
//!
//! PDO substitutes the placeholders client side with quoted literals and
//! sends the statement as plain text with `PQexec`; with
//! `ATTR_STRINGIFY_FETCHES` every fetched value is a string:
//!
//! - placeholders are found by the pgsql driver's scanner (quoted strings,
//!   identifiers, comments, dollar quotes and `::` casts are not
//!   placeholders; a `:name` right after a letter or digit is not one; `??`
//!   is an escaped `?`);
//! - `PARAM_BOOL` values become `'t'`/`'f'` when bound, `PARAM_INT` an
//!   integer literal, `null` `NULL`, anything else its PHP string, quoted
//!   like `PQescapeStringConn` (doubled `'`, cut at the first NUL byte); a
//!   string that is not valid UTF-8 makes the statement fail silently
//!   (`execute()` returns `false` without running it);
//! - fetched booleans are `"1"`/`"0"`, integers are reprinted, floats are
//!   printed as PHP prints them (precision 14), `bytea` is unescaped, `NULL`
//!   is `null`, everything else is the text the server sent;
//! - errors are `PDOException`s with PDO's message:
//!   `SQLSTATE[23505]: Unique violation: 7 ERROR:  ...`, the server's error
//!   rebuilt as libpq formats it (`DETAIL:`, `HINT:`, `LINE n:` with its
//!   caret);
//! - a connection that failed reports a transaction (`PQTRANS_UNKNOWN`), as
//!   `pdo_pgsql`'s `inTransaction()` does.
//!
//! PHP: `\PDO` / `\PDOStatement` with the pgsql driver (`ext/pdo_pgsql`)
//! and libpq.

mod widths;
mod wire;

use std::time::Duration;

use bytes::Bytes;
use php_std::number;

use super::emulate::{self, Found, Placeholder};
use super::{Binding, Control, PdoError, Results, Rowset, dsn};
use crate::error::Result;
use crate::value::{Str, Value};
use wire::{Config, Outcome, Status, TxStatus, Wire};

/// One connection.
pub(crate) struct Conn {
    wire: Wire,
}

impl Conn {
    /// `new PDO('pgsql:...')`: the DSN's settings, the user and password
    /// given (which override the DSN's, as `pdo_pgsql` appends them).
    pub(crate) async fn connect(
        params: &dsn::Postgres,
        username: Option<&str>,
        password: Option<&str>,
        timeout: Duration,
    ) -> Result<Conn> {
        let user = username
            .map(str::to_owned)
            .or_else(|| params.user.clone())
            .or_else(|| std::env::var("USER").ok())
            .unwrap_or_else(|| "postgres".to_owned());
        let password = password.map(str::to_owned).or_else(|| params.password.clone()).unwrap_or_default();
        let host = params.host.clone().filter(|h| !h.is_empty()).unwrap_or_else(|| "localhost".to_owned());
        let port = match params.port.as_deref().filter(|p| !p.is_empty()) {
            None => 5432,
            Some(p) => p.trim().parse::<u16>().map_err(|_| {
                PdoError::connect("08006", 7, &format!("invalid integer value \"{p}\" for connection option \"port\""))
                    .into_error()
            })?,
        };
        let dbname = params.dbname.clone().filter(|d| !d.is_empty()).unwrap_or_else(|| user.clone());
        let config = Config { host, port, user, password, dbname };
        let wire = Wire::connect(&config, timeout).await.map_err(|m| PdoError::connect("08006", 7, &m).into_error())?;
        Ok(Conn { wire })
    }

    /// `inTransaction()` (`PQtransactionStatus() > PQTRANS_IDLE`).
    pub(crate) fn in_transaction(&self) -> bool {
        self.wire.status() != TxStatus::Idle
    }

    /// Runs a query string, failing with the driver error.
    async fn run(&mut self, query: &[u8]) -> Result<Outcome> {
        let outcome = self.wire.exec(query).await;
        match outcome.status {
            Status::Command | Status::Tuples => Ok(outcome),
            _ => Err(error_of(&outcome, query).into_error()),
        }
    }

    /// `$pdo->exec($sql)`: the affected row count (`0` for a query
    /// returning rows).
    pub(crate) async fn exec(&mut self, sql: &str) -> Result<i64> {
        let outcome = self.run(sql.as_bytes()).await?;
        Ok(if outcome.status == Status::Command { outcome.cmd_tuples() } else { 0 })
    }

    /// `$statement->execute()`: `None` when a value cannot be quoted.
    pub(crate) async fn execute(&mut self, sql: &str, bindings: &[Binding]) -> Result<Option<Results>> {
        let found = scan(sql.as_bytes());
        let quote = |value: &Value, _| Ok(quote(&emulate::php_string(value)?));
        let Some(query) = emulate::render(sql.as_bytes(), &found, bindings, quote)? else {
            return Ok(None);
        };
        let outcome = self.run(&query).await?;
        let count = if outcome.status == Status::Command { outcome.cmd_tuples() } else { outcome.rows.len() as i64 };
        let columns = outcome.columns.iter().map(|c| Str::from(c.name.to_vec())).collect();
        let rows = outcome
            .rows
            .iter()
            .map(|row| outcome.columns.iter().zip(row).map(|(c, v)| fetch(c.oid, v.as_ref())).collect())
            .collect();
        Ok(Some(Results { sets: vec![Rowset { columns, rows, count, insert_id: 0 }], failure: None }))
    }

    /// `beginTransaction()`, `commit()`, `rollBack()`.
    pub(crate) async fn control(&mut self, op: Control) -> Result<()> {
        self.run(op.sql().as_bytes()).await.map(|_| ())
    }

    /// `lastInsertId()` (`SELECT LASTVAL()`).
    pub(crate) async fn last_insert_id(&mut self) -> Result<Str> {
        let outcome = self.run(b"SELECT LASTVAL()").await?;
        Ok(outcome
            .rows
            .first()
            .and_then(|r| r.first().cloned().flatten())
            .map_or(Str::from(""), |v| Str::from(v.to_vec())))
    }

    /// Closes the connection.
    pub(crate) async fn close(self) {
        self.wire.close().await;
    }
}

/// `$pdo->quote($string)` (`PQescapeStringConn` in quotes): `None` when the
/// string, up to its first NUL, is not valid UTF-8.
pub(crate) fn quote(s: &[u8]) -> Option<Vec<u8>> {
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
fn scan(sql: &[u8]) -> Vec<Found> {
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
    out
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
        if let Some(close) = emulate::comment_end(rest) {
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

/// One fetched value (`pgsql_stmt_get_col`, then PDO's stringify).
fn fetch(oid: u32, value: Option<&Bytes>) -> Value {
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
fn libpq_message(outcome: &Outcome, query: &[u8]) -> String {
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
    if in_table(ucs, widths::NONSPACING) {
        return 0;
    }
    if in_table(ucs, widths::EAST_ASIAN_FW) {
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
fn error_of(outcome: &Outcome, query: &[u8]) -> PdoError {
    let sqlstate = match outcome.field(b'C') {
        Some(c) if c.len() < 6 => String::from_utf8_lossy(c).into_owned(),
        _ => "HY000".to_owned(),
    };
    let native = outcome.status.code();
    let supplement = trim_message(&libpq_message(outcome, query)).to_owned();
    let mut error = PdoError::driver(&sqlstate, native, &supplement);
    if native == 0 {
        error.message = format!("SQLSTATE[{sqlstate}]: {}: {supplement}", super::sqlstate::describe(&sqlstate));
    }
    error
}
