//! PHP `Utopia\Query\Tokenizer`.

use crate::error::QueryError;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum TokenType {
    Keyword,
    Identifier,
    QuotedIdentifier,
    Integer,
    Float,
    String,
    Boolean,
    Null,
    Operator,
    LeftParen,
    RightParen,
    Comma,
    Semicolon,
    Dot,
    Star,
    Placeholder,
    NamedPlaceholder,
    NumberedPlaceholder,
    LineComment,
    BlockComment,
    Whitespace,
    Eof,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Token {
    pub token_type: TokenType,
    pub value: String,
    pub position: usize,
}

impl Token {
    pub fn new(token_type: TokenType, value: impl Into<String>, position: usize) -> Self {
        Self {
            token_type,
            value: value.into(),
            position,
        }
    }
}

const KEYWORDS: &[&str] = &[
    "SELECT",
    "FROM",
    "WHERE",
    "AND",
    "OR",
    "NOT",
    "JOIN",
    "LEFT",
    "RIGHT",
    "INNER",
    "OUTER",
    "FULL",
    "CROSS",
    "NATURAL",
    "ON",
    "AS",
    "ORDER",
    "BY",
    "GROUP",
    "HAVING",
    "LIMIT",
    "OFFSET",
    "ASC",
    "DESC",
    "IN",
    "BETWEEN",
    "LIKE",
    "ILIKE",
    "IS",
    "CASE",
    "WHEN",
    "THEN",
    "ELSE",
    "END",
    "EXISTS",
    "DISTINCT",
    "ALL",
    "UNION",
    "INTERSECT",
    "EXCEPT",
    "WITH",
    "RECURSIVE",
    "SET",
    "INSERT",
    "INTO",
    "VALUES",
    "UPDATE",
    "DELETE",
    "CREATE",
    "ALTER",
    "DROP",
    "TABLE",
    "INDEX",
    "VIEW",
    "OVER",
    "PARTITION",
    "WINDOW",
    "ROWS",
    "RANGE",
    "UNBOUNDED",
    "PRECEDING",
    "FOLLOWING",
    "CURRENT",
    "ROW",
    "FETCH",
    "NEXT",
    "FIRST",
    "LAST",
    "NULLS",
    "CAST",
    "FILTER",
    "WITHIN",
];

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TokenizerKind {
    Generic,
    Mysql,
    Mariadb,
    Postgres,
    Sqlite,
    Clickhouse,
}

#[derive(Debug, Clone)]
pub struct Tokenizer {
    pub kind: TokenizerKind,
    sql: String,
    length: usize,
    pos: usize,
}

impl Tokenizer {
    pub fn new() -> Self {
        Self {
            kind: TokenizerKind::Generic,
            sql: String::new(),
            length: 0,
            pos: 0,
        }
    }

    pub fn mysql() -> Self {
        Self {
            kind: TokenizerKind::Mysql,
            ..Self::new()
        }
    }

    pub fn mariadb() -> Self {
        Self {
            kind: TokenizerKind::Mariadb,
            ..Self::new()
        }
    }

    pub fn postgres() -> Self {
        Self {
            kind: TokenizerKind::Postgres,
            ..Self::new()
        }
    }

    pub fn sqlite() -> Self {
        Self {
            kind: TokenizerKind::Sqlite,
            ..Self::new()
        }
    }

    pub fn clickhouse() -> Self {
        Self {
            kind: TokenizerKind::Clickhouse,
            ..Self::new()
        }
    }

    fn identifier_quote_char(&self) -> char {
        match self.kind {
            TokenizerKind::Postgres | TokenizerKind::Sqlite => '"',
            _ => '`',
        }
    }

    pub fn tokenize(&mut self, sql: &str) -> Result<Vec<Token>, QueryError> {
        let prepared = match self.kind {
            TokenizerKind::Mysql | TokenizerKind::Mariadb => replace_hash_comments(sql),
            _ => sql.to_owned(),
        };
        self.sql = prepared;
        self.length = self.sql.len();
        self.pos = 0;
        let mut tokens = Vec::new();
        let quote_char = self.identifier_quote_char();
        while self.pos < self.length {
            let start = self.pos;
            let char = self.byte_at(self.pos);
            let token = match char {
                b' ' | b'\t' | b'\n' | b'\r' => self.read_whitespace(start),
                b'-' => self.read_dash_prefix(start)?,
                b'/' => self.read_slash_prefix(start)?,
                b'\'' => self.read_string(start)?,
                q if q == quote_char as u8 => self.read_quoted_identifier(start, quote_char)?,
                b'"' => self.read_quoted_identifier(start, '"')?,
                b'0'..=b'9' => self.read_number(start),
                b'a'..=b'z' | b'A'..=b'Z' | b'_' => self.read_identifier_or_keyword(start),
                b'(' => self.consume_single(TokenType::LeftParen, "(", start),
                b')' => self.consume_single(TokenType::RightParen, ")", start),
                b',' => self.consume_single(TokenType::Comma, ",", start),
                b';' => self.consume_single(TokenType::Semicolon, ";", start),
                b'.' => self.read_dot(start),
                b'*' => self.consume_single(TokenType::Star, "*", start),
                b'?' => self.read_placeholder(start),
                b':' => self.read_named_placeholder(start),
                b'$' if self.kind == TokenizerKind::Postgres => {
                    self.read_numbered_placeholder(start)
                }
                _ => self.read_operator(start)?,
            };
            tokens.push(token);
        }
        tokens.push(Token::new(TokenType::Eof, "", self.pos));
        Ok(tokens)
    }

    pub fn filter(tokens: Vec<Token>) -> Vec<Token> {
        tokens
            .into_iter()
            .filter(|t| {
                !matches!(
                    t.token_type,
                    TokenType::Whitespace | TokenType::LineComment | TokenType::BlockComment
                )
            })
            .collect()
    }

    fn byte_at(&self, i: usize) -> u8 {
        self.sql.as_bytes().get(i).copied().unwrap_or(0)
    }

    fn consume_single(&mut self, ty: TokenType, value: &str, start: usize) -> Token {
        self.pos += 1;
        Token::new(ty, value, start)
    }

    fn read_whitespace(&mut self, start: usize) -> Token {
        while self.pos < self.length {
            match self.byte_at(self.pos) {
                b' ' | b'\t' | b'\n' | b'\r' => self.pos += 1,
                _ => break,
            }
        }
        Token::new(TokenType::Whitespace, &self.sql[start..self.pos], start)
    }

    fn read_dash_prefix(&mut self, start: usize) -> Result<Token, QueryError> {
        if self.pos + 1 < self.length && self.byte_at(self.pos + 1) == b'-' {
            self.pos += 2;
            while self.pos < self.length && self.byte_at(self.pos) != b'\n' {
                self.pos += 1;
            }
            return Ok(Token::new(
                TokenType::LineComment,
                &self.sql[start..self.pos],
                start,
            ));
        }
        self.read_operator(start)
    }

    fn read_slash_prefix(&mut self, start: usize) -> Result<Token, QueryError> {
        if self.pos + 1 < self.length && self.byte_at(self.pos + 1) == b'*' {
            self.pos += 2;
            while self.pos + 1 < self.length
                && !(self.byte_at(self.pos) == b'*' && self.byte_at(self.pos + 1) == b'/')
            {
                self.pos += 1;
            }
            if self.pos + 1 < self.length {
                self.pos += 2;
            }
            return Ok(Token::new(
                TokenType::BlockComment,
                &self.sql[start..self.pos],
                start,
            ));
        }
        self.read_operator(start)
    }

    fn read_string(&mut self, start: usize) -> Result<Token, QueryError> {
        self.pos += 1;
        while self.pos < self.length {
            let c = self.byte_at(self.pos);
            if c == b'\\' {
                self.pos += 2;
                continue;
            }
            if c == b'\'' {
                self.pos += 1;
                if self.pos < self.length && self.byte_at(self.pos) == b'\'' {
                    self.pos += 1;
                    continue;
                }
                let raw = &self.sql[start + 1..self.pos - 1];
                let value = raw.replace("''", "'").replace("\\'", "'");
                return Ok(Token::new(TokenType::String, value, start));
            }
            self.pos += 1;
        }
        Err(QueryError::validation("Unterminated string literal"))
    }

    fn read_quoted_identifier(&mut self, start: usize, quote: char) -> Result<Token, QueryError> {
        let q = quote as u8;
        self.pos += 1;
        while self.pos < self.length {
            let c = self.byte_at(self.pos);
            if c == q {
                self.pos += 1;
                if self.pos < self.length && self.byte_at(self.pos) == q {
                    self.pos += 1;
                    continue;
                }
                let raw = &self.sql[start + 1..self.pos - 1];
                let doubled = format!("{quote}{quote}");
                let value = raw.replace(&doubled, &quote.to_string());
                return Ok(Token::new(TokenType::QuotedIdentifier, value, start));
            }
            self.pos += 1;
        }
        Err(QueryError::validation("Unterminated quoted identifier"))
    }

    fn read_number(&mut self, start: usize) -> Token {
        let mut is_float = false;
        while self.pos < self.length {
            match self.byte_at(self.pos) {
                b'0'..=b'9' => self.pos += 1,
                b'.' if !is_float => {
                    is_float = true;
                    self.pos += 1;
                }
                b'e' | b'E' => {
                    is_float = true;
                    self.pos += 1;
                    if self.pos < self.length
                        && (self.byte_at(self.pos) == b'+' || self.byte_at(self.pos) == b'-')
                    {
                        self.pos += 1;
                    }
                }
                _ => break,
            }
        }
        Token::new(
            if is_float {
                TokenType::Float
            } else {
                TokenType::Integer
            },
            &self.sql[start..self.pos],
            start,
        )
    }

    fn read_identifier_or_keyword(&mut self, start: usize) -> Token {
        while self.pos < self.length {
            match self.byte_at(self.pos) {
                b'a'..=b'z' | b'A'..=b'Z' | b'0'..=b'9' | b'_' => self.pos += 1,
                _ => break,
            }
        }
        let value = self.sql[start..self.pos].to_owned();
        let upper = value.to_ascii_uppercase();
        if upper == "TRUE" || upper == "FALSE" {
            return Token::new(TokenType::Boolean, value, start);
        }
        if upper == "NULL" {
            return Token::new(TokenType::Null, value, start);
        }
        if KEYWORDS.contains(&upper.as_str()) {
            Token::new(TokenType::Keyword, upper, start)
        } else {
            Token::new(TokenType::Identifier, value, start)
        }
    }

    fn read_dot(&mut self, start: usize) -> Token {
        if self.pos + 1 < self.length && self.byte_at(self.pos + 1).is_ascii_digit() {
            return self.read_number(start);
        }
        self.consume_single(TokenType::Dot, ".", start)
    }

    fn read_placeholder(&mut self, start: usize) -> Token {
        self.pos += 1;
        if self.pos < self.length && self.byte_at(self.pos).is_ascii_digit() {
            while self.pos < self.length && self.byte_at(self.pos).is_ascii_digit() {
                self.pos += 1;
            }
            return Token::new(
                TokenType::NumberedPlaceholder,
                &self.sql[start..self.pos],
                start,
            );
        }
        Token::new(TokenType::Placeholder, "?", start)
    }

    fn read_named_placeholder(&mut self, start: usize) -> Token {
        self.pos += 1;
        while self.pos < self.length {
            match self.byte_at(self.pos) {
                b'a'..=b'z' | b'A'..=b'Z' | b'0'..=b'9' | b'_' => self.pos += 1,
                _ => break,
            }
        }
        Token::new(
            TokenType::NamedPlaceholder,
            &self.sql[start..self.pos],
            start,
        )
    }

    fn read_numbered_placeholder(&mut self, start: usize) -> Token {
        self.pos += 1;
        while self.pos < self.length && self.byte_at(self.pos).is_ascii_digit() {
            self.pos += 1;
        }
        Token::new(
            TokenType::NumberedPlaceholder,
            &self.sql[start..self.pos],
            start,
        )
    }

    fn read_operator(&mut self, start: usize) -> Result<Token, QueryError> {
        let rest = &self.sql[self.pos..];
        let two = if rest.len() >= 2 { &rest[..2] } else { "" };
        let three = if rest.len() >= 3 { &rest[..3] } else { "" };
        let (op, len) = if matches!(three, "<=>" | "<<>" | "<>>") {
            (three, 3)
        } else if matches!(
            two,
            "<=" | ">=" | "<>" | "!=" | "||" | "&&" | "->" | ">>" | "<<" | ":=" | "::"
        ) {
            (two, 2)
        } else if rest.starts_with("->>") {
            ("->>", 3)
        } else if !rest.is_empty()
            && matches!(
                rest.as_bytes()[0],
                b'=' | b'<' | b'>' | b'+' | b'-' | b'/' | b'%' | b'!' | b'|' | b'&' | b'~' | b'^'
            )
        {
            (&rest[..1], 1)
        } else {
            return Err(QueryError::validation(format!(
                "Unexpected character at position {start}"
            )));
        };
        self.pos += len;
        Ok(Token::new(TokenType::Operator, op, start))
    }
}

impl Default for Tokenizer {
    fn default() -> Self {
        Self::new()
    }
}

fn replace_hash_comments(sql: &str) -> String {
    let bytes = sql.as_bytes();
    let mut result = String::new();
    let mut i = 0;
    while i < bytes.len() {
        let char = bytes[i];
        if char == b'\'' {
            result.push('\'');
            i += 1;
            while i < bytes.len() {
                let c = bytes[i];
                if c == b'\\' {
                    result.push('\\');
                    i += 1;
                    if i < bytes.len() {
                        result.push(bytes[i] as char);
                        i += 1;
                    }
                    continue;
                }
                if c == b'\'' {
                    result.push('\'');
                    i += 1;
                    if i < bytes.len() && bytes[i] == b'\'' {
                        result.push('\'');
                        i += 1;
                        continue;
                    }
                    break;
                }
                result.push(c as char);
                i += 1;
            }
            continue;
        }
        if char == b'`' || char == b'"' {
            let q = char as char;
            result.push(q);
            i += 1;
            while i < bytes.len() {
                let c = bytes[i];
                if c == char {
                    result.push(q);
                    i += 1;
                    if i < bytes.len() && bytes[i] == char {
                        result.push(q);
                        i += 1;
                        continue;
                    }
                    break;
                }
                result.push(c as char);
                i += 1;
            }
            continue;
        }
        if char == b'#' {
            result.push_str("--");
            i += 1;
            continue;
        }
        result.push(char as char);
        i += 1;
    }
    result
}

pub type MySQL = Tokenizer;
pub type MariaDB = Tokenizer;
pub type PostgreSQL = Tokenizer;
pub type SQLite = Tokenizer;
pub type ClickHouse = Tokenizer;
