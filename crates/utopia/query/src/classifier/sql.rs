//! The SQL classification shared by the SQL wire protocols.

use super::Classifier;
use crate::Type;

/// Classifies SQL text by its leading keyword: the logic shared by the SQL
/// wire protocol classifiers ([`super::MySql`], [`super::PostgreSql`]),
/// which implement [`Classifier::classify`] for their packet format and
/// hand the SQL text to [`Sql::classify_sql`].
///
/// Handles leading whitespace, `--` and `/* */` comments, quoted literals and
/// identifiers (`'..'`, `".."`, `` `..` ``, `$tag$..$tag$`), mixed case,
/// `COPY ... TO` (read) against `COPY ... FROM` (write), and CTEs
/// (`WITH ... SELECT` reads, `WITH ... INSERT` writes).
///
/// PHP: `Utopia\Query\Classifier\SQL`.
pub trait Sql: Classifier {
    /// The classification of the SQL text `query`.
    ///
    /// PHP: `SQL::classifySQL()`.
    fn classify_sql(&self, query: &[u8]) -> Type {
        classify_sql(query)
    }

    /// The first keyword of `query`, uppercased (ASCII), after whitespace,
    /// comments and quoted literals; empty when there is none.
    ///
    /// PHP: `SQL::extractKeyword()`.
    fn extract_keyword(&self, query: &[u8]) -> Vec<u8> {
        query[keyword_span(query)].to_ascii_uppercase()
    }
}

/// What a leading keyword means.
enum Keyword {
    Type(Type),
    Copy,
    With,
    Other,
}

/// The longest keyword looked up (`SAVEPOINT`).
const LONGEST: usize = 9;

/// Looks `word` up case-insensitively (PHP uppercases it, then looks it up).
fn keyword(word: &[u8]) -> Keyword {
    if word.len() > LONGEST {
        return Keyword::Other;
    }
    let mut buf = [0u8; LONGEST];
    let upper = &mut buf[..word.len()];
    upper.copy_from_slice(word);
    upper.make_ascii_uppercase();
    match &*upper {
        b"SELECT" | b"SHOW" | b"DESCRIBE" | b"DESC" | b"EXPLAIN" | b"TABLE" | b"VALUES" => Keyword::Type(Type::Read),
        b"INSERT" | b"UPDATE" | b"DELETE" | b"CREATE" | b"DROP" | b"ALTER" | b"TRUNCATE" | b"GRANT" | b"REVOKE"
        | b"LOCK" | b"CALL" | b"DO" => Keyword::Type(Type::Write),
        b"BEGIN" | b"START" => Keyword::Type(Type::TransactionBegin),
        b"COMMIT" | b"ROLLBACK" => Keyword::Type(Type::TransactionEnd),
        b"SAVEPOINT" | b"RELEASE" | b"SET" => Keyword::Type(Type::Transaction),
        b"COPY" => Keyword::Copy,
        b"WITH" => Keyword::With,
        _ => Keyword::Other,
    }
}

fn classify_sql(query: &[u8]) -> Type {
    let span = keyword_span(query);
    if span.is_empty() {
        return Type::Unknown;
    }
    match keyword(&query[span]) {
        Keyword::Type(t) => t,
        Keyword::Copy => classify_copy(query),
        Keyword::With => classify_cte(query),
        Keyword::Other => Type::Unknown,
    }
}

/// The byte range of the first keyword (empty when there is none).
fn keyword_span(query: &[u8]) -> std::ops::Range<usize> {
    let len = query.len();
    let start = skip_insignificant(query, 0, len);
    let mut pos = start;
    while pos < len && !matches!(query[pos], b' ' | b'\t' | b'\n' | b'\r' | b'(' | b';') {
        pos += 1;
    }
    start..pos
}

/// Advances past whitespace, comments and quoted literals or identifiers.
fn skip_insignificant(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len {
        match query[pos] {
            b' ' | b'\t' | b'\n' | b'\r' | b'\x0c' => pos += 1,
            b'$' => match skip_dollar_quoted(query, pos, len) {
                Some(next) => pos = next,
                None => break,
            },
            _ => {
                let next = skip_literal_or_comment(query, pos, len);
                if next == pos {
                    break;
                }
                pos = next;
            }
        }
    }
    pos
}

fn skip_line_comment(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len && query[pos] != b'\n' {
        pos += 1;
    }
    if pos < len { pos + 1 } else { pos }
}

fn skip_block_comment(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos + 1 < len {
        if query[pos] == b'*' && query[pos + 1] == b'/' {
            return pos + 2;
        }
        pos += 1;
    }
    len
}

fn skip_single_quoted(query: &[u8], mut pos: usize, len: usize) -> usize {
    while pos < len {
        match query[pos] {
            b'\\' if pos + 1 < len => pos += 2,
            b'\'' if pos + 1 < len && query[pos + 1] == b'\'' => pos += 2,
            b'\'' => return pos + 1,
            _ => pos += 1,
        }
    }
    len
}

/// Skips an identifier quoted by `quote`, where a doubled quote escapes it.
fn skip_quoted(query: &[u8], mut pos: usize, len: usize, quote: u8) -> usize {
    while pos < len {
        if query[pos] == quote {
            if pos + 1 < len && query[pos + 1] == quote {
                pos += 2;
                continue;
            }
            return pos + 1;
        }
        pos += 1;
    }
    len
}

/// Skips a dollar-quoted string (`$tag$...$tag$`) starting at `pos`; `None`
/// when the `$` does not open one.
fn skip_dollar_quoted(query: &[u8], pos: usize, len: usize) -> Option<usize> {
    let mut tag_end = pos + 1;
    while tag_end < len {
        let c = query[tag_end];
        if c == b'$' {
            break;
        }
        if !(c.is_ascii_alphanumeric() || c == b'_') {
            return None;
        }
        tag_end += 1;
    }
    if tag_end >= len {
        return None;
    }
    let tag = &query[pos..=tag_end];
    let body = &query[tag_end + 1..];
    Some(match body.windows(tag.len()).position(|w| w == tag) {
        Some(at) => tag_end + 1 + at + tag.len(),
        None => len,
    })
}

/// Skips the literal or comment at `pos`; `pos` when there is none.
fn skip_literal_or_comment(query: &[u8], pos: usize, len: usize) -> usize {
    if pos >= len {
        return pos;
    }
    match query[pos] {
        b'-' if pos + 1 < len && query[pos + 1] == b'-' => skip_line_comment(query, pos + 2, len),
        b'/' if pos + 1 < len && query[pos + 1] == b'*' => skip_block_comment(query, pos + 2, len),
        b'\'' => skip_single_quoted(query, pos + 1, len),
        b'"' => skip_quoted(query, pos + 1, len, b'"'),
        b'`' => skip_quoted(query, pos + 1, len, b'`'),
        b'$' => skip_dollar_quoted(query, pos, len).unwrap_or(pos),
        _ => pos,
    }
}

/// The first offset of `needle` in `haystack`, ASCII case-insensitively
/// (PHP's `stripos`).
fn find_ignore_case(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w.eq_ignore_ascii_case(needle))
}

/// `COPY ... TO` exports (a read), `COPY ... FROM` imports (a write); a
/// write when unsure.
fn classify_copy(query: &[u8]) -> Type {
    let to = find_ignore_case(query, b" TO ");
    let from = find_ignore_case(query, b" FROM ");
    match (to, from) {
        (Some(_), None) => Type::Read,
        (Some(to), Some(from)) if to < from => Type::Read,
        _ => Type::Write,
    }
}

/// A CTE is classified by the first read or write keyword at parenthesis
/// depth 0 after the first CTE block; a read when there is none.
fn classify_cte(query: &[u8]) -> Type {
    let len = query.len();
    let mut pos = 0;
    let mut depth: i64 = 0;
    let mut seen_paren = false;
    while pos < len {
        let skipped = skip_literal_or_comment(query, pos, len);
        if skipped != pos {
            pos = skipped;
            continue;
        }
        let c = query[pos];
        if c == b'(' {
            depth += 1;
            seen_paren = true;
            pos += 1;
            continue;
        }
        if c == b')' {
            depth -= 1;
            pos += 1;
            continue;
        }
        if depth == 0 && seen_paren && c.is_ascii_alphabetic() {
            let start = pos;
            while pos < len && (query[pos].is_ascii_alphanumeric() || query[pos] == b'_') {
                pos += 1;
            }
            if let Keyword::Type(t @ (Type::Read | Type::Write)) = keyword(&query[start..pos]) {
                return t;
            }
            continue;
        }
        pos += 1;
    }
    Type::Read
}
