//! SQL tokenizers: SQL text to [`Token`]s, per dialect.
//!
//! A [`Tokenizer`] splits SQL into keywords, identifiers, literals,
//! operators, punctuation, placeholders, comments and whitespace, each with
//! its byte position. [`Tokenizer::filter`] drops whitespace and comments,
//! which is what the [`Parser`](crate::ast::Parser) reads. Token values
//! borrow the SQL text where they can, so tokenizing allocates only the
//! token list.
//!
//! PHP: `Utopia\Query\Tokenizer\*`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Tokenizer\Tokenizer` | [`Tokenizer::new`] |
//! | `Tokenizer\MySQL` | [`Tokenizer::mysql`] |
//! | `Tokenizer\MariaDB` | [`Tokenizer::mariadb`] |
//! | `Tokenizer\PostgreSQL` | [`Tokenizer::postgresql`] |
//! | `Tokenizer\SQLite` | [`Tokenizer::sqlite`] |
//! | `Tokenizer\ClickHouse` | [`Tokenizer::clickhouse`] |
//! | `Tokenizer::tokenize()` | [`Tokenizer::tokenize`] |
//! | `Tokenizer::filter()` | [`Tokenizer::filter`] |
//! | `Tokenizer\Token` | [`Token`] |
//! | `Tokenizer\TokenType` | [`TokenType`] |
//!
//! ```
//! use utopia_query::tokenizer::{TokenType, Tokenizer};
//!
//! let tokens = Tokenizer::filter(Tokenizer::new().tokenize("SELECT * FROM users")?);
//! let kinds: Vec<TokenType> = tokens.iter().map(|t| t.kind).collect();
//! assert_eq!(kinds, [TokenType::Keyword, TokenType::Star, TokenType::Keyword, TokenType::Identifier, TokenType::Eof]);
//! assert_eq!(tokens[3].text(), "users");
//! # Ok::<(), utopia_query::Error>(())
//! ```
//!
//! ```php
//! use Utopia\Query\Tokenizer\Tokenizer;
//!
//! $tokens = Tokenizer::filter((new Tokenizer())->tokenize('SELECT * FROM users'));
//! echo implode(' ', array_map(fn ($t) => $t->type->name, $tokens)), "\n"; // Keyword Star Keyword Identifier Eof
//! echo $tokens[3]->value, "\n"; // users
//! ```

use std::borrow::Cow;

use crate::error::{Error, Result};

/// What a [`Token`] is.
///
/// PHP: `Utopia\Query\Tokenizer\TokenType`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum TokenType {
    /// A reserved word (`SELECT`, `FROM`), upper-cased.
    Keyword,
    /// A bare identifier, as written.
    Identifier,
    /// A quoted identifier, quotes included (`` `name` ``, `"name"`).
    QuotedIdentifier,
    /// An integer literal.
    Integer,
    /// A decimal or exponent literal (`1.5`, `.5`, `1e3`).
    Float,
    /// A string literal, quotes and escapes included.
    String,
    /// `TRUE` or `FALSE`, upper-cased.
    Boolean,
    /// `NULL`, upper-cased.
    Null,
    /// An operator, or any other character.
    Operator,
    /// `(`.
    LeftParen,
    /// `)`.
    RightParen,
    /// `,`.
    Comma,
    /// `;`.
    Semicolon,
    /// `.`.
    Dot,
    /// `*`.
    Star,
    /// `?`.
    Placeholder,
    /// `:name`.
    NamedPlaceholder,
    /// `$1`.
    NumberedPlaceholder,
    /// `-- comment` (and MySQL's `# comment`, rewritten to `-- `).
    LineComment,
    /// `/* comment */`.
    BlockComment,
    /// Spaces, tabs and line breaks.
    Whitespace,
    /// The end of the input.
    Eof,
}

impl TokenType {
    /// Every case, in declaration order.
    ///
    /// PHP: `TokenType::cases()`.
    pub const CASES: &'static [TokenType] = &[
        TokenType::Keyword,
        TokenType::Identifier,
        TokenType::QuotedIdentifier,
        TokenType::Integer,
        TokenType::Float,
        TokenType::String,
        TokenType::Boolean,
        TokenType::Null,
        TokenType::Operator,
        TokenType::LeftParen,
        TokenType::RightParen,
        TokenType::Comma,
        TokenType::Semicolon,
        TokenType::Dot,
        TokenType::Star,
        TokenType::Placeholder,
        TokenType::NamedPlaceholder,
        TokenType::NumberedPlaceholder,
        TokenType::LineComment,
        TokenType::BlockComment,
        TokenType::Whitespace,
        TokenType::Eof,
    ];

    /// The case name.
    ///
    /// PHP: `->name`.
    pub const fn name(self) -> &'static str {
        match self {
            TokenType::Keyword => "Keyword",
            TokenType::Identifier => "Identifier",
            TokenType::QuotedIdentifier => "QuotedIdentifier",
            TokenType::Integer => "Integer",
            TokenType::Float => "Float",
            TokenType::String => "String",
            TokenType::Boolean => "Boolean",
            TokenType::Null => "Null",
            TokenType::Operator => "Operator",
            TokenType::LeftParen => "LeftParen",
            TokenType::RightParen => "RightParen",
            TokenType::Comma => "Comma",
            TokenType::Semicolon => "Semicolon",
            TokenType::Dot => "Dot",
            TokenType::Star => "Star",
            TokenType::Placeholder => "Placeholder",
            TokenType::NamedPlaceholder => "NamedPlaceholder",
            TokenType::NumberedPlaceholder => "NumberedPlaceholder",
            TokenType::LineComment => "LineComment",
            TokenType::BlockComment => "BlockComment",
            TokenType::Whitespace => "Whitespace",
            TokenType::Eof => "Eof",
        }
    }

    /// The case named `name`, if any.
    pub fn from_name(name: &str) -> Option<Self> {
        Self::CASES.iter().copied().find(|c| c.name() == name)
    }

    /// Whether [`Tokenizer::filter`] drops tokens of this type (whitespace and comments).
    pub const fn is_trivia(self) -> bool {
        matches!(self, TokenType::Whitespace | TokenType::LineComment | TokenType::BlockComment)
    }
}

/// One token: its type, its text and its byte position in the SQL.
///
/// The value is bytes, as PHP's strings are: a character the tokenizer does
/// not know outside quotes is one operator token per byte, so a multi-byte
/// UTF-8 character becomes several tokens that are not UTF-8 on their own
/// ([`Token::text`] reads a value as text). It borrows the SQL text, except
/// for keywords, `NULL` and booleans (upper-cased) and tokens of MySQL SQL
/// whose `#` comments were rewritten.
///
/// PHP: `Utopia\Query\Tokenizer\Token` (`type`, `value`, `position`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Token<'a> {
    /// What the token is.
    ///
    /// PHP: `$type`.
    pub kind: TokenType,
    /// Its text.
    ///
    /// PHP: `$value`.
    pub value: Cow<'a, [u8]>,
    /// Its byte offset in the SQL.
    ///
    /// PHP: `$position`.
    pub position: usize,
}

impl<'a> Token<'a> {
    /// A token.
    ///
    /// PHP: `new Token($type, $value, $position)`.
    pub fn new(kind: TokenType, value: impl Into<Cow<'a, [u8]>>, position: usize) -> Self {
        Token { kind, value: value.into(), position }
    }

    /// The value as text (lossy where it is not UTF-8).
    pub fn text(&self) -> Cow<'_, str> {
        String::from_utf8_lossy(&self.value)
    }

    /// The token, owning its value.
    pub fn into_owned(self) -> Token<'static> {
        Token { kind: self.kind, value: Cow::Owned(self.value.into_owned()), position: self.position }
    }
}

/// Splits SQL into [`Token`]s, the way one dialect writes it.
///
/// Dialects differ in the identifier quote (a backtick, or a double quote
/// for PostgreSQL and SQLite), in MySQL's `#` line comments and in
/// PostgreSQL's multi-character operators (`@>`, `<@`, `<=>`, `<->`, `<#>`,
/// `?|`, `?&`).
///
/// PHP: `Utopia\Query\Tokenizer\Tokenizer` and its dialect subclasses.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Tokenizer {
    quote: u8,
    hash_comments: bool,
    postgres_operators: bool,
}

impl Default for Tokenizer {
    /// [`Tokenizer::new`].
    fn default() -> Self {
        Tokenizer::new()
    }
}

/// Single-character operators.
const fn single_operator(c: u8) -> Option<&'static str> {
    match c {
        b'=' => Some("="),
        b'<' => Some("<"),
        b'>' => Some(">"),
        b'+' => Some("+"),
        b'-' => Some("-"),
        b'/' => Some("/"),
        b'%' => Some("%"),
        _ => None,
    }
}

/// The keyword (or `NULL` / boolean) an upper-cased word is, with its type.
fn keyword(upper: &[u8]) -> Option<(TokenType, &'static str)> {
    use TokenType::{Boolean, Keyword, Null};
    Some(match upper {
        b"NULL" => (Null, "NULL"),
        b"TRUE" => (Boolean, "TRUE"),
        b"FALSE" => (Boolean, "FALSE"),
        b"SELECT" => (Keyword, "SELECT"),
        b"FROM" => (Keyword, "FROM"),
        b"WHERE" => (Keyword, "WHERE"),
        b"AND" => (Keyword, "AND"),
        b"OR" => (Keyword, "OR"),
        b"NOT" => (Keyword, "NOT"),
        b"JOIN" => (Keyword, "JOIN"),
        b"LEFT" => (Keyword, "LEFT"),
        b"RIGHT" => (Keyword, "RIGHT"),
        b"INNER" => (Keyword, "INNER"),
        b"OUTER" => (Keyword, "OUTER"),
        b"FULL" => (Keyword, "FULL"),
        b"CROSS" => (Keyword, "CROSS"),
        b"NATURAL" => (Keyword, "NATURAL"),
        b"ON" => (Keyword, "ON"),
        b"AS" => (Keyword, "AS"),
        b"ORDER" => (Keyword, "ORDER"),
        b"BY" => (Keyword, "BY"),
        b"GROUP" => (Keyword, "GROUP"),
        b"HAVING" => (Keyword, "HAVING"),
        b"LIMIT" => (Keyword, "LIMIT"),
        b"OFFSET" => (Keyword, "OFFSET"),
        b"ASC" => (Keyword, "ASC"),
        b"DESC" => (Keyword, "DESC"),
        b"IN" => (Keyword, "IN"),
        b"BETWEEN" => (Keyword, "BETWEEN"),
        b"LIKE" => (Keyword, "LIKE"),
        b"ILIKE" => (Keyword, "ILIKE"),
        b"IS" => (Keyword, "IS"),
        b"CASE" => (Keyword, "CASE"),
        b"WHEN" => (Keyword, "WHEN"),
        b"THEN" => (Keyword, "THEN"),
        b"ELSE" => (Keyword, "ELSE"),
        b"END" => (Keyword, "END"),
        b"EXISTS" => (Keyword, "EXISTS"),
        b"DISTINCT" => (Keyword, "DISTINCT"),
        b"ALL" => (Keyword, "ALL"),
        b"UNION" => (Keyword, "UNION"),
        b"INTERSECT" => (Keyword, "INTERSECT"),
        b"EXCEPT" => (Keyword, "EXCEPT"),
        b"WITH" => (Keyword, "WITH"),
        b"RECURSIVE" => (Keyword, "RECURSIVE"),
        b"SET" => (Keyword, "SET"),
        b"INSERT" => (Keyword, "INSERT"),
        b"INTO" => (Keyword, "INTO"),
        b"VALUES" => (Keyword, "VALUES"),
        b"UPDATE" => (Keyword, "UPDATE"),
        b"DELETE" => (Keyword, "DELETE"),
        b"CREATE" => (Keyword, "CREATE"),
        b"ALTER" => (Keyword, "ALTER"),
        b"DROP" => (Keyword, "DROP"),
        b"TABLE" => (Keyword, "TABLE"),
        b"INDEX" => (Keyword, "INDEX"),
        b"VIEW" => (Keyword, "VIEW"),
        b"OVER" => (Keyword, "OVER"),
        b"PARTITION" => (Keyword, "PARTITION"),
        b"WINDOW" => (Keyword, "WINDOW"),
        b"ROWS" => (Keyword, "ROWS"),
        b"RANGE" => (Keyword, "RANGE"),
        b"UNBOUNDED" => (Keyword, "UNBOUNDED"),
        b"PRECEDING" => (Keyword, "PRECEDING"),
        b"FOLLOWING" => (Keyword, "FOLLOWING"),
        b"CURRENT" => (Keyword, "CURRENT"),
        b"ROW" => (Keyword, "ROW"),
        b"FETCH" => (Keyword, "FETCH"),
        b"NEXT" => (Keyword, "NEXT"),
        b"FIRST" => (Keyword, "FIRST"),
        b"LAST" => (Keyword, "LAST"),
        b"NULLS" => (Keyword, "NULLS"),
        b"CAST" => (Keyword, "CAST"),
        b"FILTER" => (Keyword, "FILTER"),
        b"WITHIN" => (Keyword, "WITHIN"),
        _ => return None,
    })
}

/// The longest keyword.
const KEYWORD_MAX: usize = 9;

const fn is_ident_start(c: u8) -> bool {
    c.is_ascii_alphabetic() || c == b'_'
}

const fn is_ident_char(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_'
}

impl Tokenizer {
    /// The standard tokenizer: identifiers quoted with backticks (or double quotes).
    ///
    /// PHP: `new Tokenizer\Tokenizer()`.
    pub const fn new() -> Self {
        Tokenizer { quote: b'`', hash_comments: false, postgres_operators: false }
    }

    /// MySQL: also reads `#` line comments.
    ///
    /// PHP: `new Tokenizer\MySQL()`.
    pub const fn mysql() -> Self {
        Tokenizer { quote: b'`', hash_comments: true, postgres_operators: false }
    }

    /// MariaDB: as MySQL.
    ///
    /// PHP: `new Tokenizer\MariaDB()`.
    pub const fn mariadb() -> Self {
        Self::mysql()
    }

    /// PostgreSQL: identifiers quoted with double quotes, and its
    /// multi-character operators as one token.
    ///
    /// PHP: `new Tokenizer\PostgreSQL()`.
    pub const fn postgresql() -> Self {
        Tokenizer { quote: b'"', hash_comments: false, postgres_operators: true }
    }

    /// SQLite: identifiers quoted with double quotes.
    ///
    /// PHP: `new Tokenizer\SQLite()`.
    pub const fn sqlite() -> Self {
        Tokenizer { quote: b'"', hash_comments: false, postgres_operators: false }
    }

    /// ClickHouse: as the standard tokenizer.
    ///
    /// PHP: `new Tokenizer\ClickHouse()`.
    pub const fn clickhouse() -> Self {
        Self::new()
    }

    /// The tokens of `sql`, whitespace and comments included, ending with an
    /// [`TokenType::Eof`] token. Fails with [`Error::Validation`] on an
    /// unterminated string literal or quoted identifier.
    ///
    /// PHP: `Tokenizer::tokenize()`.
    ///
    /// ```
    /// use utopia_query::tokenizer::{TokenType, Tokenizer};
    ///
    /// let tokens = Tokenizer::mysql().tokenize("SELECT 1 #note")?;
    /// assert_eq!(tokens[4].kind, TokenType::LineComment);
    /// assert_eq!(tokens[4].text(), "-- note");
    /// assert!(Tokenizer::new().tokenize("SELECT 'open").is_err());
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Tokenizer\MySQL;
    ///
    /// $tokens = (new MySQL())->tokenize('SELECT 1 #note');
    /// echo $tokens[4]->type->name, ' ', $tokens[4]->value, "\n"; // LineComment -- note
    /// ```
    pub fn tokenize<'a, S: AsRef<[u8]> + ?Sized>(&self, sql: &'a S) -> Result<Vec<Token<'a>>> {
        let sql: &'a [u8] = sql.as_ref();
        if self.hash_comments
            && let Some(rewritten) = replace_hash_comments(sql)
        {
            let tokens = self.scan(&rewritten)?;
            return Ok(tokens.into_iter().map(Token::into_owned).collect());
        }
        let mut tokens = self.scan(sql)?;
        if self.postgres_operators {
            merge_postgres_operators(sql, &mut tokens);
        }
        Ok(tokens)
    }

    /// `tokens` without whitespace and comments.
    ///
    /// PHP: `Tokenizer::filter()`.
    pub fn filter(mut tokens: Vec<Token<'_>>) -> Vec<Token<'_>> {
        tokens.retain(|t| !t.kind.is_trivia());
        tokens
    }

    fn scan<'a>(&self, sql: &'a [u8]) -> Result<Vec<Token<'a>>> {
        let mut scanner = Scanner { bytes: sql, pos: 0 };
        let mut tokens = Vec::with_capacity(sql.len() / 3 + 1);
        while scanner.pos < scanner.bytes.len() {
            tokens.push(scanner.next(self.quote)?);
        }
        tokens.push(Token { kind: TokenType::Eof, value: Cow::Borrowed(b""), position: scanner.pos });
        Ok(tokens)
    }
}

struct Scanner<'a> {
    bytes: &'a [u8],
    pos: usize,
}

impl<'a> Scanner<'a> {
    fn peek(&self, offset: usize) -> Option<u8> {
        self.bytes.get(self.pos + offset).copied()
    }

    fn at(&self, i: usize) -> Option<u8> {
        self.bytes.get(i).copied()
    }

    fn token(&self, kind: TokenType, start: usize) -> Token<'a> {
        Token { kind, value: Cow::Borrowed(&self.bytes[start..self.pos]), position: start }
    }

    fn fixed(&mut self, kind: TokenType, value: &'static str, start: usize) -> Token<'a> {
        self.pos += value.len();
        Token { kind, value: Cow::Borrowed(value.as_bytes()), position: start }
    }

    fn next(&mut self, quote: u8) -> Result<Token<'a>> {
        let start = self.pos;
        let c = self.bytes[start];
        Ok(match c {
            b' ' | b'\t' | b'\n' | b'\r' => {
                while matches!(self.peek(0), Some(b' ' | b'\t' | b'\n' | b'\r')) {
                    self.pos += 1;
                }
                self.token(TokenType::Whitespace, start)
            }
            b'-' if self.peek(1) == Some(b'-') => {
                self.pos += 2;
                while self.peek(0).is_some_and(|c| c != b'\n') {
                    self.pos += 1;
                }
                self.token(TokenType::LineComment, start)
            }
            b'/' if self.peek(1) == Some(b'*') => self.block_comment(start),
            b'\'' => self.string(start)?,
            _ if c == quote => self.quoted_identifier(start, quote)?,
            b'"' => self.quoted_identifier(start, b'"')?,
            b'0'..=b'9' => self.number(start),
            _ if is_ident_start(c) => self.word(start),
            b'(' => self.fixed(TokenType::LeftParen, "(", start),
            b')' => self.fixed(TokenType::RightParen, ")", start),
            b',' => self.fixed(TokenType::Comma, ",", start),
            b';' => self.fixed(TokenType::Semicolon, ";", start),
            b'.' => {
                if self.peek(1).is_some_and(|c| c.is_ascii_digit()) {
                    self.number(start)
                } else {
                    self.fixed(TokenType::Dot, ".", start)
                }
            }
            b'*' => self.fixed(TokenType::Star, "*", start),
            b'?' => self.fixed(TokenType::Placeholder, "?", start),
            b':' => match self.peek(1) {
                Some(b':') => self.fixed(TokenType::Operator, "::", start),
                Some(n) if is_ident_start(n) => {
                    self.pos += 1;
                    while self.peek(0).is_some_and(is_ident_char) {
                        self.pos += 1;
                    }
                    self.token(TokenType::NamedPlaceholder, start)
                }
                _ => self.fixed(TokenType::Operator, ":", start),
            },
            b'$' => {
                if self.peek(1).is_some_and(|c| c.is_ascii_digit()) {
                    self.pos += 1;
                    while self.peek(0).is_some_and(|c| c.is_ascii_digit()) {
                        self.pos += 1;
                    }
                    self.token(TokenType::NumberedPlaceholder, start)
                } else {
                    self.fixed(TokenType::Operator, "$", start)
                }
            }
            _ => self.operator(start, c),
        })
    }

    fn block_comment(&mut self, start: usize) -> Token<'a> {
        self.pos += 2;
        let len = self.bytes.len();
        let mut terminated = false;
        while self.pos + 1 < len {
            if self.bytes[self.pos] == b'*' && self.bytes[self.pos + 1] == b'/' {
                self.pos += 2;
                terminated = true;
                break;
            }
            self.pos += 1;
        }
        if !terminated {
            self.pos = len;
        }
        self.token(TokenType::BlockComment, start)
    }

    fn string(&mut self, start: usize) -> Result<Token<'a>> {
        self.pos += 1;
        let len = self.bytes.len();
        while self.pos < len {
            match self.bytes[self.pos] {
                b'\\' => {
                    if self.pos + 1 >= len {
                        break;
                    }
                    self.pos += 2;
                }
                b'\'' => {
                    self.pos += 1;
                    if self.peek(0) == Some(b'\'') {
                        self.pos += 1;
                        continue;
                    }
                    return Ok(self.token(TokenType::String, start));
                }
                _ => self.pos += 1,
            }
        }
        Err(Error::Validation("Unterminated string literal".into()))
    }

    fn quoted_identifier(&mut self, start: usize, quote: u8) -> Result<Token<'a>> {
        self.pos += 1;
        while let Some(c) = self.peek(0) {
            self.pos += 1;
            if c == quote {
                if self.peek(0) == Some(quote) {
                    self.pos += 1;
                    continue;
                }
                return Ok(self.token(TokenType::QuotedIdentifier, start));
            }
        }
        Err(Error::Validation("Unterminated quoted identifier".into()))
    }

    fn digits(&mut self) {
        while self.peek(0).is_some_and(|c| c.is_ascii_digit()) {
            self.pos += 1;
        }
    }

    fn number(&mut self, start: usize) -> Token<'a> {
        let mut float = false;
        if self.peek(0) == Some(b'.') {
            float = true;
            self.pos += 1;
        }
        self.digits();
        if !float && self.peek(0) == Some(b'.') && self.peek(1).is_some_and(|c| c.is_ascii_digit()) {
            float = true;
            self.pos += 1;
            self.digits();
        }
        if matches!(self.peek(0), Some(b'e' | b'E')) {
            let mut next = self.pos + 1;
            if matches!(self.at(next), Some(b'+' | b'-')) {
                next += 1;
            }
            if self.at(next).is_some_and(|c| c.is_ascii_digit()) {
                float = true;
                self.pos = next;
                self.digits();
            }
        }
        self.token(if float { TokenType::Float } else { TokenType::Integer }, start)
    }

    fn word(&mut self, start: usize) -> Token<'a> {
        while self.peek(0).is_some_and(is_ident_char) {
            self.pos += 1;
        }
        let word = &self.bytes[start..self.pos];
        if word.len() <= KEYWORD_MAX {
            let mut upper = [0u8; KEYWORD_MAX];
            for (u, c) in upper.iter_mut().zip(word) {
                *u = c.to_ascii_uppercase();
            }
            if let Some((kind, value)) = keyword(&upper[..word.len()]) {
                return Token { kind, value: Cow::Borrowed(value.as_bytes()), position: start };
            }
        }
        self.token(TokenType::Identifier, start)
    }

    fn operator(&mut self, start: usize, c: u8) -> Token<'a> {
        let two = match (c, self.peek(1)) {
            (b'<', Some(b'=')) => Some("<="),
            (b'>', Some(b'=')) => Some(">="),
            (b'!', Some(b'=')) => Some("!="),
            (b'<', Some(b'>')) => Some("<>"),
            (b'|', Some(b'|')) => Some("||"),
            _ => None,
        };
        if let Some(op) = two {
            return self.fixed(TokenType::Operator, op, start);
        }
        if let Some(op) = single_operator(c) {
            return self.fixed(TokenType::Operator, op, start);
        }
        // Any other byte is a one-byte operator (a multi-byte UTF-8
        // character is one token per byte, as in PHP).
        self.pos += 1;
        self.token(TokenType::Operator, start)
    }
}

/// MySQL's `#` comments rewritten to `-- ` outside strings and quoted
/// identifiers (`None` when the SQL has none).
fn replace_hash_comments(sql: &[u8]) -> Option<Vec<u8>> {
    let bytes = sql;
    let len = bytes.len();
    let mut hashes = Vec::new();
    let mut i = 0;
    while i < len {
        let c = bytes[i];
        match c {
            b'\'' | b'"' | b'`' => {
                i += 1;
                while i < len {
                    let d = bytes[i];
                    if d == b'\\' && c != b'`' {
                        i += 2;
                        continue;
                    }
                    i += 1;
                    if d == c {
                        if i < len && bytes[i] == c {
                            i += 1;
                            continue;
                        }
                        break;
                    }
                }
            }
            b'#' => {
                hashes.push(i);
                i += 1;
            }
            _ => i += 1,
        }
    }
    if hashes.is_empty() {
        return None;
    }
    let mut out = Vec::with_capacity(len + hashes.len() * 2);
    let mut from = 0;
    for h in hashes {
        out.extend_from_slice(&sql[from..h]);
        out.extend_from_slice(b"-- ");
        from = h + 1;
    }
    out.extend_from_slice(&sql[from.min(len)..]);
    Some(out)
}

fn is_op(t: &Token<'_>) -> bool {
    matches!(t.kind, TokenType::Operator | TokenType::Star)
}

/// PostgreSQL's multi-character operators, merged from adjacent tokens.
fn merge_postgres_operators<'a>(sql: &'a [u8], tokens: &mut Vec<Token<'a>>) {
    let count = tokens.len();
    let mut out = 0;
    let mut i = 0;
    // Adjacent tokens cover adjacent text: a merged operator is the span.
    let span = |tokens: &[Token<'a>], from: usize, to: usize| -> &'a [u8] {
        let start = tokens[from].position;
        let end = tokens[to].position + tokens[to].value.len();
        &sql[start..end]
    };
    while i < count {
        let mut merged = None;
        if i + 2 < count && is_op(&tokens[i]) && is_op(&tokens[i + 1]) && is_op(&tokens[i + 2]) {
            let three = span(tokens, i, i + 2);
            if three == b"<->" || three == b"<#>" {
                merged = Some((three, 3));
            }
        }
        if merged.is_none() && i + 1 < count && is_op(&tokens[i]) && is_op(&tokens[i + 1]) {
            let two = span(tokens, i, i + 1);
            if two == b"@>" || two == b"<@" || two == b"<=>" {
                merged = Some((two, 2));
            }
        }
        if merged.is_none()
            && i + 1 < count
            && tokens[i].kind == TokenType::Placeholder
            && tokens[i + 1].kind == TokenType::Operator
            && (*tokens[i + 1].value == *b"|" || *tokens[i + 1].value == *b"&")
        {
            merged = Some((span(tokens, i, i + 1), 2));
        }
        match merged {
            Some((value, n)) => {
                let position = tokens[i].position;
                tokens[out] = Token { kind: TokenType::Operator, value: Cow::Borrowed(value), position };
                i += n;
            }
            None => {
                if out != i {
                    tokens.swap(out, i);
                }
                i += 1;
            }
        }
        out += 1;
    }
    tokens.truncate(out);
}
