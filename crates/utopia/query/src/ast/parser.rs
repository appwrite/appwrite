//! [`Parser`]: tokens to a [`Select`].

use std::borrow::Cow;

use php_std::Str;
use php_std::format::{str_to_float, str_to_int};

use super::node::{
    Aliased, Between, Binary, CaseWhen, Cast, Column, Conditional, Cte, Exists, Expression, Func, In, InList,
    JoinClause, Literal, OrderByItem, Placeholder, Select, Star, Subquery, SubquerySource, Table, TableSource, Unary,
    Window, WindowDefinition, WindowSpecification,
};
use crate::enums::{NullsPosition, OrderDirection};
use crate::error::{Error, Result};
use crate::tokenizer::{Token, TokenType, Tokenizer};
use crate::value::Value;

/// How deep expressions may nest before parsing fails.
const MAX_DEPTH: usize = 256;

/// How many prefix operators (`NOT`, `-`, `+`) may chain. PHP has no limit
/// short of its stack; this one keeps trees shallow enough to drop safely.
const MAX_PREFIX: usize = 1024;

/// Nesting levels parsed on one stack before parsing moves to a new thread.
const STACK_LEVELS: usize = 32;

/// The stack of each such thread.
const STACK_SIZE: usize = 4 << 20;

/// A recursive-descent parser of `SELECT` statements.
///
/// Reads filtered tokens (no whitespace or comments, ending with
/// [`TokenType::Eof`], as [`Tokenizer::filter`] returns them) and builds a
/// [`Select`] that a [`Walker`](super::Walker) can rewrite and a
/// [`Serializer`](super::Serializer) write back. Not to be confused with the
/// [`classifier`](crate::classifier), which only reads a statement's leading
/// keyword.
///
/// PHP: `Utopia\Query\AST\Parser`.
///
/// ```
/// use utopia_query::ast::{Expression, Parser};
/// use utopia_query::tokenizer::Tokenizer;
///
/// let tokens = Tokenizer::filter(Tokenizer::new().tokenize("SELECT name FROM users WHERE age > 18")?);
/// let select = Parser::new().parse(&tokens)?;
/// assert!(matches!(select.where_clause, Some(Expression::Binary(ref b)) if b.operator == ">"));
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\AST\Parser;
/// use Utopia\Query\Tokenizer\Tokenizer;
///
/// $tokens = Tokenizer::filter((new Tokenizer())->tokenize('SELECT name FROM users WHERE age > 18'));
/// $select = (new Parser())->parse($tokens);
/// echo $select->where->operator, "\n"; // >
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct Parser;

impl Parser {
    /// A parser.
    ///
    /// PHP: `new Parser()`.
    pub const fn new() -> Self {
        Parser
    }

    /// The statement `tokens` spell: filtered tokens ending with
    /// [`TokenType::Eof`]. Fails with [`Error::Query`] on a syntax error
    /// (naming the token and its position) and [`Error::Validation`] when
    /// expressions nest more than 256 deep.
    ///
    /// PHP: `Parser::parse()`.
    pub fn parse(&self, tokens: &[Token<'_>]) -> Result<Select> {
        State { tokens, pos: 0, depth: 0, in_column_list: false }.select()
    }

    /// The statement `sql` spells, tokenized with the standard
    /// [`Tokenizer`] (as builders read their CTE queries).
    ///
    /// PHP: `$parser->parse(Tokenizer::filter((new Tokenizer())->tokenize($sql)))`.
    pub fn parse_sql<S: AsRef<[u8]> + ?Sized>(&self, sql: &S) -> Result<Select> {
        let tokens = Tokenizer::filter(Tokenizer::new().tokenize(sql)?);
        self.parse(&tokens)
    }
}

static EOF: Token<'static> = Token { kind: TokenType::Eof, value: Cow::Borrowed(b""), position: 0 };

struct State<'t, 'a> {
    tokens: &'t [Token<'a>],
    pos: usize,
    depth: usize,
    in_column_list: bool,
}

fn error(message: String) -> Error {
    Error::query(message)
}

/// An error quoting a token's value: `before`, the value's bytes, `after`
/// (the message is not UTF-8 when the value is part of a character).
fn quoting(before: String, value: &[u8], after: &str) -> Error {
    let mut message = before.into_bytes();
    message.extend_from_slice(value);
    message.extend_from_slice(after.as_bytes());
    Error::query_bytes(message)
}

fn eq_upper(value: &[u8], upper: &str) -> bool {
    value.eq_ignore_ascii_case(upper.as_bytes())
}

/// `substr($raw, 1, -1)`.
fn inner(raw: &[u8]) -> &[u8] {
    raw.get(1..raw.len().saturating_sub(1).max(1)).unwrap_or(b"")
}

/// `str_replace($from, $to, $subject)` on bytes.
fn replace(subject: &[u8], from: &[u8], to: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(subject.len());
    let mut i = 0;
    while i < subject.len() {
        if subject[i..].starts_with(from) {
            out.extend_from_slice(to);
            i += from.len();
        } else {
            out.push(subject[i]);
            i += 1;
        }
    }
    out
}

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

fn unquote(raw: &[u8]) -> String {
    let inner = inner(raw);
    text(&match raw.first() {
        Some(b'`') => replace(inner, b"``", b"`"),
        Some(b'"') => replace(inner, b"\"\"", b"\""),
        Some(b'[') => replace(inner, b"]]", b"]"),
        _ => inner.to_vec(),
    })
}

impl<'t, 'a> State<'t, 'a> {
    fn current(&self) -> &'t Token<'a> {
        match self.tokens.get(self.pos) {
            Some(t) => t,
            None => &EOF,
        }
    }

    fn peek(&self, offset: usize) -> &'t Token<'a> {
        match self.tokens.get(self.pos + offset).or_else(|| self.tokens.last()) {
            Some(t) => t,
            None => &EOF,
        }
    }

    fn advance(&mut self) {
        if self.pos + 1 < self.tokens.len() {
            self.pos += 1;
        }
    }

    fn is(&self, kind: TokenType) -> bool {
        self.current().kind == kind
    }

    fn match_keyword(&self, keyword: &str) -> bool {
        let t = self.current();
        t.kind == TokenType::Keyword && eq_upper(&t.value, keyword)
    }

    fn peek_keyword(&self, offset: usize, keyword: &str) -> bool {
        let t = self.peek(offset);
        t.kind == TokenType::Keyword && eq_upper(&t.value, keyword)
    }

    fn consume_keyword(&mut self, keyword: &str) -> Result<()> {
        if !self.match_keyword(keyword) {
            let t = self.current();
            return Err(quoting(
                format!("Expected keyword '{keyword}' at position {}, got '", t.position),
                &t.value,
                "'",
            ));
        }
        self.advance();
        Ok(())
    }

    fn expect(&mut self, kind: TokenType) -> Result<()> {
        let t = self.current();
        if t.kind != kind {
            return Err(quoting(format!("Expected {} at position {}, got '", kind.name(), t.position), &t.value, "'"));
        }
        self.advance();
        Ok(())
    }

    fn match_and_consume(&mut self, kind: TokenType) -> bool {
        if self.is(kind) {
            self.advance();
            true
        } else {
            false
        }
    }

    fn expect_null(&mut self) -> Result<()> {
        let t = self.current();
        if t.kind != TokenType::Null {
            return Err(quoting(format!("Expected NULL at position {}, got '", t.position), &t.value, "'"));
        }
        self.advance();
        Ok(())
    }

    fn expect_identifier_value(&mut self, value: &str) -> Result<()> {
        let t = self.current();
        if matches!(t.kind, TokenType::Identifier | TokenType::Keyword) && eq_upper(&t.value, value) {
            self.advance();
            return Ok(());
        }
        Err(quoting(format!("Expected '{value}' at position {}, got '", t.position), &t.value, "'"))
    }

    fn extract_identifier(&self, t: &Token<'_>) -> Result<String> {
        match t.kind {
            TokenType::Identifier | TokenType::Keyword => Ok(text(&t.value)),
            TokenType::QuotedIdentifier => Ok(unquote(&t.value)),
            _ => Err(quoting(
                format!("Expected identifier at position {}, got '", t.position),
                &t.value,
                &format!("' ({})", t.kind.name()),
            )),
        }
    }

    fn expect_identifier(&mut self) -> Result<String> {
        let name = self.extract_identifier(self.current())?;
        self.advance();
        Ok(name)
    }

    fn select(&mut self) -> Result<Select> {
        let mut ctes = Vec::new();
        if self.match_keyword("WITH") {
            self.advance();
            let mut recursive = false;
            if self.match_keyword("RECURSIVE") {
                recursive = true;
                self.advance();
            }
            loop {
                ctes.push(self.cte(recursive)?);
                if !self.match_and_consume(TokenType::Comma) {
                    break;
                }
            }
        }

        self.consume_keyword("SELECT")?;

        let mut distinct = false;
        if self.match_keyword("DISTINCT") {
            distinct = true;
            self.advance();
        }

        let columns = self.column_list()?;
        let mut select = Select { columns, distinct, ctes, ..Select::default() };

        if self.match_keyword("FROM") {
            self.advance();
            select.from = Some(self.table_source()?);
            select.joins = self.joins()?;
        }
        if self.match_keyword("WHERE") {
            self.advance();
            select.where_clause = Some(self.expression()?);
        }
        if self.match_keyword("GROUP") {
            self.advance();
            self.consume_keyword("BY")?;
            select.group_by = self.expression_list()?;
        }
        if self.match_keyword("HAVING") {
            self.advance();
            select.having = Some(self.expression()?);
        }
        if self.match_keyword("WINDOW") {
            self.advance();
            select.windows = self.window_definitions()?;
        }
        if self.match_keyword("ORDER") {
            self.advance();
            self.consume_keyword("BY")?;
            select.order_by = self.order_by_list()?;
        }
        if self.match_keyword("LIMIT") {
            self.advance();
            select.limit = Some(self.expression()?);
        }
        if self.match_keyword("OFFSET") {
            self.advance();
            select.offset = Some(self.expression()?);
        }
        if self.match_keyword("FETCH") {
            self.advance();
            if !(self.match_keyword("FIRST") || self.match_keyword("NEXT")) {
                return Err(error(format!(
                    "Expected FIRST or NEXT after FETCH at position {}",
                    self.current().position
                )));
            }
            self.advance();
            select.limit = Some(self.expression()?);
            if !(self.match_keyword("ROW") || self.match_keyword("ROWS")) {
                return Err(error(format!("Expected ROW or ROWS at position {}", self.current().position)));
            }
            self.advance();
            self.expect_identifier_value("ONLY")?;
        }

        Ok(select)
    }

    fn cte(&mut self, recursive: bool) -> Result<Cte> {
        let name = self.expect_identifier()?;
        let mut columns = Vec::new();

        if self.is(TokenType::LeftParen) && self.peek_is_column_list() {
            self.expect(TokenType::LeftParen)?;
            columns.push(self.expect_identifier()?);
            while self.match_and_consume(TokenType::Comma) {
                columns.push(self.expect_identifier()?);
            }
            self.expect(TokenType::RightParen)?;
        }

        self.consume_keyword("AS")?;
        self.expect(TokenType::LeftParen)?;
        let query = self.select()?;
        self.expect(TokenType::RightParen)?;

        Ok(Cte { name, query, columns, recursive })
    }

    fn peek_is_column_list(&self) -> bool {
        let mut depth = 0i64;
        for (i, t) in self.tokens.iter().enumerate().skip(self.pos) {
            match t.kind {
                TokenType::LeftParen => depth += 1,
                TokenType::RightParen => {
                    depth -= 1;
                    if depth == 0 {
                        return self
                            .tokens
                            .get(i + 1)
                            .is_some_and(|n| n.kind == TokenType::Keyword && eq_upper(&n.value, "AS"));
                    }
                }
                _ => {}
            }
        }
        false
    }

    fn column_list(&mut self) -> Result<Vec<Expression>> {
        self.in_column_list = true;
        let mut columns = vec![self.select_column()?];
        while self.match_and_consume(TokenType::Comma) {
            columns.push(self.select_column()?);
        }
        self.in_column_list = false;
        Ok(columns)
    }

    fn select_column(&mut self) -> Result<Expression> {
        let expression = self.expression()?;

        if self.match_keyword("AS") {
            self.advance();
            let alias = self.expect_identifier()?;
            return Ok(Aliased::new(expression, alias).into());
        }

        if self.in_column_list && matches!(self.current().kind, TokenType::Identifier | TokenType::QuotedIdentifier) {
            let alias = self.expect_identifier()?;
            return Ok(Aliased::new(expression, alias).into());
        }

        Ok(expression)
    }

    fn expression(&mut self) -> Result<Expression> {
        if self.depth >= MAX_DEPTH {
            return Err(Error::Validation("Expression nesting too deep".into()));
        }
        self.depth += 1;
        let result = if self.depth.is_multiple_of(STACK_LEVELS) { self.or_on_new_stack() } else { self.or() };
        self.depth -= 1;
        result
    }

    /// [`State::or`] on a thread of its own: every [`STACK_LEVELS`] nesting
    /// levels, parsing continues on a fresh stack, so the deepest nesting
    /// allowed fits in any caller's stack (debug builds use kilobytes per
    /// level).
    #[cold]
    fn or_on_new_stack(&mut self) -> Result<Expression> {
        std::thread::scope(|scope| {
            match std::thread::Builder::new().stack_size(STACK_SIZE).spawn_scoped(scope, || self.or()) {
                Ok(handle) => handle.join().unwrap_or_else(|panic| std::panic::resume_unwind(panic)),
                Err(_) => Err(Error::Validation("Expression nesting too deep".into())),
            }
        })
    }

    fn or(&mut self) -> Result<Expression> {
        let mut left = self.and()?;
        while self.match_keyword("OR") {
            self.advance();
            let right = self.and()?;
            left = Binary::new(left, "OR", right).into();
        }
        Ok(left)
    }

    fn and(&mut self) -> Result<Expression> {
        let mut left = self.not()?;
        while self.match_keyword("AND") {
            self.advance();
            let right = self.not()?;
            left = Binary::new(left, "AND", right).into();
        }
        Ok(left)
    }

    /// `NOT` chains, read in a loop (PHP recurses; the result is the same).
    fn not(&mut self) -> Result<Expression> {
        let mut nots = 0usize;
        let mut operand = loop {
            if !self.match_keyword("NOT") {
                let left = self.addition()?;
                break self.postfix(left)?;
            }
            if self.peek_keyword(1, "EXISTS") {
                break self.not_exists()?;
            }
            self.advance();
            nots += 1;
            if nots > MAX_PREFIX {
                return Err(Error::Validation("Expression nesting too deep".into()));
            }
        };
        for _ in 0..nots {
            operand = Unary::new("NOT", operand, true).into();
        }
        Ok(operand)
    }

    fn not_exists(&mut self) -> Result<Expression> {
        self.advance();
        self.advance();
        self.expect(TokenType::LeftParen)?;
        let subquery = self.select()?;
        self.expect(TokenType::RightParen)?;
        Ok(Exists::new(subquery, true).into())
    }

    fn postfix(&mut self, left: Expression) -> Result<Expression> {
        if self.match_keyword("IS") {
            self.advance();
            if self.match_keyword("NOT") {
                self.advance();
                self.expect_null()?;
                return Ok(Unary::new("IS NOT NULL", left, false).into());
            }
            self.expect_null()?;
            return Ok(Unary::new("IS NULL", left, false).into());
        }

        if self.match_keyword("NOT") {
            if self.peek_keyword(1, "IN") {
                self.advance();
                self.advance();
                return self.in_list(left, true);
            }
            if self.peek_keyword(1, "BETWEEN") {
                self.advance();
                self.advance();
                return self.between(left, true);
            }
            for (keyword, operator) in [("LIKE", "NOT LIKE"), ("ILIKE", "NOT ILIKE")] {
                if self.peek_keyword(1, keyword) {
                    self.advance();
                    self.advance();
                    let right = self.addition()?;
                    return Ok(Binary::new(left, operator, right).into());
                }
            }
        }

        if self.match_keyword("IN") {
            self.advance();
            return self.in_list(left, false);
        }
        if self.match_keyword("BETWEEN") {
            self.advance();
            return self.between(left, false);
        }
        for keyword in ["LIKE", "ILIKE"] {
            if self.match_keyword(keyword) {
                self.advance();
                let right = self.addition()?;
                return Ok(Binary::new(left, keyword, right).into());
            }
        }

        let t = self.current();
        if t.kind == TokenType::Operator && matches!(&*t.value, b"=" | b"!=" | b"<>" | b"<" | b">" | b"<=" | b">=") {
            let operator = text(&t.value);
            self.advance();
            let right = self.addition()?;
            return self.postfix(Binary::new(left, operator, right).into());
        }

        Ok(left)
    }

    fn in_list(&mut self, left: Expression, negated: bool) -> Result<Expression> {
        self.expect(TokenType::LeftParen)?;

        if self.match_keyword("SELECT") || self.match_keyword("WITH") {
            let subquery = self.select()?;
            self.expect(TokenType::RightParen)?;
            return Ok(In::new(left, InList::Subquery(Box::new(subquery)), negated).into());
        }

        let mut list = vec![self.expression()?];
        while self.match_and_consume(TokenType::Comma) {
            list.push(self.expression()?);
        }
        self.expect(TokenType::RightParen)?;

        Ok(In::new(left, InList::Values(list), negated).into())
    }

    fn between(&mut self, left: Expression, negated: bool) -> Result<Expression> {
        let low = self.addition()?;
        self.consume_keyword("AND")?;
        let high = self.addition()?;
        Ok(Between::new(left, low, high, negated).into())
    }

    fn addition(&mut self) -> Result<Expression> {
        let mut left = self.multiplication()?;
        loop {
            let t = self.current();
            if t.kind == TokenType::Operator && matches!(&*t.value, b"+" | b"-" | b"||") {
                let operator = text(&t.value);
                self.advance();
                let right = self.multiplication()?;
                left = Binary::new(left, operator, right).into();
            } else {
                return Ok(left);
            }
        }
    }

    fn multiplication(&mut self) -> Result<Expression> {
        let mut left = self.unary()?;
        loop {
            let t = self.current();
            let operator = match t.kind {
                TokenType::Star => "*",
                TokenType::Operator if *t.value == *b"/" => "/",
                TokenType::Operator if *t.value == *b"%" => "%",
                _ => return Ok(left),
            };
            self.advance();
            let right = self.unary()?;
            left = Binary::new(left, operator, right).into();
        }
    }

    /// Prefix `-` / `+` chains, read in a loop (PHP recurses; the result is the same).
    fn unary(&mut self) -> Result<Expression> {
        let mut operators = Vec::new();
        loop {
            let t = self.current();
            if t.kind == TokenType::Operator && (*t.value == *b"-" || *t.value == *b"+") {
                operators.push(if *t.value == *b"-" { "-" } else { "+" });
                if operators.len() > MAX_PREFIX {
                    return Err(Error::Validation("Expression nesting too deep".into()));
                }
                self.advance();
            } else {
                break;
            }
        }

        let mut expression = self.primary()?;
        while self.current().kind == TokenType::Operator && *self.current().value == *b"::" {
            self.advance();
            let data_type = self.expect_identifier()?;
            expression = Cast::new(expression, data_type).into();
        }
        for operator in operators.into_iter().rev() {
            expression = Unary::new(operator, expression, true).into();
        }
        Ok(expression)
    }

    // `primary`, `expression` and the precedence levels between them recurse
    // once per nesting level: they keep small frames, and the rest of a
    // primary expression is read by `atom`.
    fn primary(&mut self) -> Result<Expression> {
        if !self.is(TokenType::LeftParen) {
            return self.atom();
        }
        self.advance();
        if self.match_keyword("SELECT") || self.match_keyword("WITH") {
            return self.scalar_subquery();
        }
        let expression = self.expression()?;
        self.expect(TokenType::RightParen)?;
        Ok(expression)
    }

    fn scalar_subquery(&mut self) -> Result<Expression> {
        let subquery = self.select()?;
        self.expect(TokenType::RightParen)?;
        Ok(Subquery::new(subquery).into())
    }

    fn atom(&mut self) -> Result<Expression> {
        let t = self.current();
        match t.kind {
            TokenType::Integer => {
                self.advance();
                return Ok(Literal { value: Value::Int(str_to_int(&t.value)) }.into());
            }
            TokenType::Float => {
                self.advance();
                return Ok(Literal { value: Value::Float(str_to_float(&t.value)) }.into());
            }
            TokenType::String => {
                self.advance();
                let unescaped = replace(&replace(inner(&t.value), b"''", b"'"), b"\\'", b"'");
                return Ok(Literal { value: Value::Str(Str::from(unescaped)) }.into());
            }
            TokenType::Boolean => {
                self.advance();
                return Ok(Literal { value: Value::Bool(eq_upper(&t.value, "TRUE")) }.into());
            }
            TokenType::Null => {
                self.advance();
                return Ok(Literal { value: Value::Null }.into());
            }
            TokenType::Placeholder | TokenType::NamedPlaceholder | TokenType::NumberedPlaceholder => {
                self.advance();
                return Ok(Placeholder::new(text(&t.value)).into());
            }
            TokenType::Star => {
                self.advance();
                return Ok(Star::default().into());
            }
            _ => {}
        }

        if self.match_keyword("CASE") {
            return self.case();
        }
        if self.match_keyword("CAST") {
            return self.cast();
        }
        if self.match_keyword("EXISTS") {
            self.advance();
            self.expect(TokenType::LeftParen)?;
            let subquery = self.select()?;
            self.expect(TokenType::RightParen)?;
            return Ok(Exists::new(subquery, false).into());
        }
        if matches!(t.kind, TokenType::Identifier | TokenType::QuotedIdentifier)
            || (t.kind == TokenType::Keyword && self.peek(1).kind == TokenType::LeftParen)
        {
            return self.identifier_expression();
        }

        Err(quoting(
            "Unexpected token '".into(),
            &t.value,
            &format!("' ({}) at position {}", t.kind.name(), t.position),
        ))
    }

    fn identifier_expression(&mut self) -> Result<Expression> {
        let name = self.extract_identifier(self.current())?;
        self.advance();

        match self.current().kind {
            TokenType::LeftParen => self.function_call(name),
            TokenType::Dot => {
                self.advance();
                if self.is(TokenType::Star) {
                    self.advance();
                    return Ok(Star { table: Some(name), schema: None }.into());
                }
                let second = self.extract_identifier(self.current())?;
                self.advance();
                if self.is(TokenType::Dot) {
                    self.advance();
                    if self.is(TokenType::Star) {
                        self.advance();
                        return Ok(Star { table: Some(second), schema: Some(name) }.into());
                    }
                    let third = self.extract_identifier(self.current())?;
                    self.advance();
                    return Ok(Column { name: third, table: Some(second), schema: Some(name) }.into());
                }
                Ok(Column { name: second, table: Some(name), schema: None }.into())
            }
            _ => Ok(Column::new(name).into()),
        }
    }

    fn function_call(&mut self, name: String) -> Result<Expression> {
        let name = name.to_ascii_uppercase();
        self.expect(TokenType::LeftParen)?;

        if self.is(TokenType::Star) {
            self.advance();
            self.expect(TokenType::RightParen)?;
            return self.function_postfix(Func::new(name, vec![Star::default().into()]));
        }
        if self.is(TokenType::RightParen) {
            self.advance();
            return self.function_postfix(Func::new(name, Vec::new()));
        }

        let mut distinct = false;
        if self.match_keyword("DISTINCT") {
            distinct = true;
            self.advance();
        }

        let mut arguments = vec![self.expression()?];
        while self.match_and_consume(TokenType::Comma) {
            arguments.push(self.expression()?);
        }
        self.expect(TokenType::RightParen)?;

        self.function_postfix(Func { name, arguments, distinct, filter: None })
    }

    fn function_postfix(&mut self, mut function: Func) -> Result<Expression> {
        if self.match_keyword("FILTER") {
            self.advance();
            self.expect(TokenType::LeftParen)?;
            self.consume_keyword("WHERE")?;
            function.filter = Some(Box::new(self.expression()?));
            self.expect(TokenType::RightParen)?;
        }

        if self.match_keyword("OVER") {
            self.advance();
            if self.is(TokenType::Identifier) {
                let window_name = self.extract_identifier(self.current())?;
                self.advance();
                return Ok(Window {
                    function: Box::new(function.into()),
                    window_name: Some(window_name),
                    specification: None,
                }
                .into());
            }
            self.expect(TokenType::LeftParen)?;
            let specification = self.window_specification()?;
            self.expect(TokenType::RightParen)?;
            return Ok(Window {
                function: Box::new(function.into()),
                window_name: None,
                specification: Some(Box::new(specification)),
            }
            .into());
        }

        Ok(function.into())
    }

    fn case(&mut self) -> Result<Expression> {
        self.consume_keyword("CASE")?;

        let mut operand = None;
        if !self.match_keyword("WHEN") {
            operand = Some(Box::new(self.expression()?));
        }

        let mut whens = Vec::new();
        while self.match_keyword("WHEN") {
            self.advance();
            let condition = self.expression()?;
            self.consume_keyword("THEN")?;
            let result = self.expression()?;
            whens.push(CaseWhen::new(condition, result));
        }

        let mut else_result = None;
        if self.match_keyword("ELSE") {
            self.advance();
            else_result = Some(Box::new(self.expression()?));
        }

        self.consume_keyword("END")?;

        Ok(Conditional { operand, whens, else_result }.into())
    }

    fn cast(&mut self) -> Result<Expression> {
        self.consume_keyword("CAST")?;
        self.expect(TokenType::LeftParen)?;
        let expression = self.expression()?;
        self.consume_keyword("AS")?;
        let data_type = self.expect_identifier()?;
        self.expect(TokenType::RightParen)?;
        Ok(Cast::new(expression, data_type).into())
    }

    fn table_source(&mut self) -> Result<TableSource> {
        if self.is(TokenType::LeftParen) {
            self.advance();
            let subquery = self.select()?;
            self.expect(TokenType::RightParen)?;
            if self.match_keyword("AS") {
                self.advance();
            }
            let alias = self.expect_identifier()?;
            return Ok(SubquerySource::new(subquery, alias).into());
        }
        Ok(self.table_reference()?.into())
    }

    fn table_reference(&mut self) -> Result<Table> {
        let mut name = self.expect_identifier()?;
        let mut schema = None;
        let mut alias = None;

        if self.is(TokenType::Dot) {
            self.advance();
            schema = Some(name);
            name = self.expect_identifier()?;
        }

        if self.match_keyword("AS") {
            self.advance();
            alias = Some(self.expect_identifier()?);
        } else if matches!(self.current().kind, TokenType::Identifier | TokenType::QuotedIdentifier) {
            alias = Some(self.expect_identifier()?);
        }

        Ok(Table { name, alias, schema })
    }

    fn joins(&mut self) -> Result<Vec<JoinClause>> {
        let mut joins = Vec::new();
        while let Some(kind) = self.join_type()? {
            let table = self.table_source()?;
            let mut condition = None;
            if kind != "CROSS JOIN" && kind != "NATURAL JOIN" && self.match_keyword("ON") {
                self.advance();
                condition = Some(self.expression()?);
            }
            joins.push(JoinClause { kind: kind.to_owned(), table, condition });
        }
        Ok(joins)
    }

    fn join_type(&mut self) -> Result<Option<&'static str>> {
        if self.match_keyword("JOIN") {
            self.advance();
            return Ok(Some("JOIN"));
        }
        let (kind, outer) = if self.match_keyword("INNER") {
            ("INNER JOIN", false)
        } else if self.match_keyword("LEFT") {
            ("LEFT JOIN", true)
        } else if self.match_keyword("RIGHT") {
            ("RIGHT JOIN", true)
        } else if self.match_keyword("FULL") {
            ("FULL OUTER JOIN", true)
        } else if self.match_keyword("CROSS") {
            ("CROSS JOIN", false)
        } else if self.match_keyword("NATURAL") {
            ("NATURAL JOIN", false)
        } else {
            return Ok(None);
        };
        self.advance();
        if outer && self.match_keyword("OUTER") {
            self.advance();
        }
        self.consume_keyword("JOIN")?;
        Ok(Some(kind))
    }

    fn expression_list(&mut self) -> Result<Vec<Expression>> {
        let mut expressions = vec![self.expression()?];
        while self.match_and_consume(TokenType::Comma) {
            expressions.push(self.expression()?);
        }
        Ok(expressions)
    }

    fn order_by_list(&mut self) -> Result<Vec<OrderByItem>> {
        let mut items = vec![self.order_by_item()?];
        while self.match_and_consume(TokenType::Comma) {
            items.push(self.order_by_item()?);
        }
        Ok(items)
    }

    fn order_by_item(&mut self) -> Result<OrderByItem> {
        let expression = self.expression()?;

        let mut direction = OrderDirection::Asc;
        if self.match_keyword("ASC") {
            self.advance();
        } else if self.match_keyword("DESC") {
            self.advance();
            direction = OrderDirection::Desc;
        }

        let mut nulls = None;
        if self.match_keyword("NULLS") {
            self.advance();
            if self.match_keyword("FIRST") {
                self.advance();
                nulls = Some(NullsPosition::First);
            } else if self.match_keyword("LAST") {
                self.advance();
                nulls = Some(NullsPosition::Last);
            } else {
                let t = self.current();
                return Err(quoting(
                    format!("Expected FIRST or LAST after NULLS at position {}, got '", t.position),
                    &t.value,
                    "'",
                ));
            }
        }

        Ok(OrderByItem { expression, direction, nulls })
    }

    fn window_definitions(&mut self) -> Result<Vec<WindowDefinition>> {
        let mut definitions = Vec::new();
        loop {
            let name = self.expect_identifier()?;
            self.consume_keyword("AS")?;
            self.expect(TokenType::LeftParen)?;
            let specification = self.window_specification()?;
            self.expect(TokenType::RightParen)?;
            definitions.push(WindowDefinition { name, specification });
            if !self.match_and_consume(TokenType::Comma) {
                return Ok(definitions);
            }
        }
    }

    fn window_specification(&mut self) -> Result<WindowSpecification> {
        let mut specification = WindowSpecification::default();

        if self.match_keyword("PARTITION") {
            self.advance();
            self.consume_keyword("BY")?;
            specification.partition_by = self.expression_list()?;
        }
        if self.match_keyword("ORDER") {
            self.advance();
            self.consume_keyword("BY")?;
            specification.order_by = self.order_by_list()?;
        }
        if self.match_keyword("ROWS") || self.match_keyword("RANGE") {
            specification.frame_type = Some(text(&self.current().value.to_ascii_uppercase()));
            self.advance();
            if self.match_keyword("BETWEEN") {
                self.advance();
                specification.frame_start = Some(self.frame_bound()?);
                self.consume_keyword("AND")?;
                specification.frame_end = Some(self.frame_bound()?);
            } else {
                specification.frame_start = Some(self.frame_bound()?);
            }
        }

        Ok(specification)
    }

    fn frame_bound(&mut self) -> Result<String> {
        if self.match_keyword("UNBOUNDED") {
            self.advance();
            if self.match_keyword("PRECEDING") {
                self.advance();
                return Ok("UNBOUNDED PRECEDING".into());
            }
            if self.match_keyword("FOLLOWING") {
                self.advance();
                return Ok("UNBOUNDED FOLLOWING".into());
            }
            return Err(error(format!(
                "Expected PRECEDING or FOLLOWING after UNBOUNDED at position {}",
                self.current().position
            )));
        }

        if self.match_keyword("CURRENT") {
            self.advance();
            self.consume_keyword("ROW")?;
            return Ok("CURRENT ROW".into());
        }

        if self.is(TokenType::Integer) {
            let n = text(&self.current().value);
            self.advance();
            for keyword in ["PRECEDING", "FOLLOWING"] {
                if self.match_keyword(keyword) {
                    self.advance();
                    return Ok(format!("{n} {keyword}"));
                }
            }
            return Err(error(format!(
                "Expected PRECEDING or FOLLOWING after number at position {}",
                self.current().position
            )));
        }

        let t = self.current();
        Err(quoting("Unexpected frame bound token '".into(), &t.value, &format!("' at position {}", t.position)))
    }
}

#[cfg(test)]
mod tests {
    use super::Parser;

    /// Parses `depth` nested parentheses on a thread with a small stack.
    fn nested(depth: usize) -> Result<(), String> {
        let sql = format!("SELECT {}1{} FROM t", "(".repeat(depth), ")".repeat(depth));
        std::thread::Builder::new()
            .stack_size(1 << 20)
            .spawn(move || Parser::new().parse_sql(&sql).map(drop).map_err(|e| e.to_string()))
            .expect("thread")
            .join()
            .expect("no stack overflow")
    }

    #[test]
    fn nesting_up_to_the_limit_fits_in_a_small_stack() {
        assert!(nested(255).is_ok());
        assert_eq!(nested(500), Err("Expression nesting too deep".to_owned()));
    }
}
