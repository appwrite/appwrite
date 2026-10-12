//! [`Serializer`]: a [`Select`] back to SQL.

use std::fmt::Write as _;

use super::node::{
    Binary, Column, Conditional, Cte, Expression, Func, InList, JoinClause, Literal, OrderByItem, Select, Star, Table,
    TableSource, Window, WindowSpecification,
};
use crate::error::{Error, Result};
use crate::value::Value;

/// Writes a syntax tree as SQL, quoting identifiers the way one dialect
/// does: backticks (MySQL, MariaDB, ClickHouse, and the base serializer) or
/// double quotes (PostgreSQL, SQLite), with the quote doubled inside.
///
/// PHP: `Utopia\Query\AST\Serializer` and its dialect subclasses.
///
/// ```
/// use utopia_query::ast::{Parser, Serializer};
///
/// let select = Parser::new().parse_sql("select id, name from users where id = 1")?;
/// assert_eq!(Serializer::new().serialize(&select)?, "SELECT `id`, `name` FROM `users` WHERE `id` = 1");
/// assert_eq!(Serializer::postgresql().serialize(&select)?, r#"SELECT "id", "name" FROM "users" WHERE "id" = 1"#);
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\AST\Parser;
/// use Utopia\Query\AST\Serializer;
/// use Utopia\Query\Tokenizer\Tokenizer;
///
/// $select = (new Parser())->parse(Tokenizer::filter((new Tokenizer())->tokenize('select id, name from users where id = 1')));
/// echo (new Serializer())->serialize($select), "\n"; // SELECT `id`, `name` FROM `users` WHERE `id` = 1
/// ```
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Serializer {
    quote: char,
}

impl Default for Serializer {
    /// [`Serializer::new`].
    fn default() -> Self {
        Serializer::new()
    }
}

/// The binding strength of a binary operator (0 for unknown operators).
fn precedence(operator: &str) -> u8 {
    const LEVELS: &[(&str, u8)] = &[
        ("OR", 1),
        ("AND", 2),
        ("=", 3),
        ("!=", 3),
        ("<>", 3),
        ("<", 3),
        (">", 3),
        ("<=", 3),
        (">=", 3),
        ("LIKE", 3),
        ("ILIKE", 3),
        ("NOT LIKE", 3),
        ("NOT ILIKE", 3),
        ("+", 4),
        ("-", 4),
        ("||", 4),
        ("*", 5),
        ("/", 5),
        ("%", 5),
    ];
    LEVELS.iter().find(|(op, _)| op.eq_ignore_ascii_case(operator)).map_or(0, |(_, level)| *level)
}

impl Serializer {
    /// The base serializer: identifiers in backticks.
    ///
    /// PHP: `new Serializer()`.
    pub const fn new() -> Self {
        Serializer { quote: '`' }
    }

    /// MySQL: identifiers in backticks.
    ///
    /// PHP: `new Serializer\MySQL()`.
    pub const fn mysql() -> Self {
        Self::new()
    }

    /// MariaDB: identifiers in backticks.
    ///
    /// PHP: `new Serializer\MariaDB()`.
    pub const fn mariadb() -> Self {
        Self::new()
    }

    /// ClickHouse: identifiers in backticks.
    ///
    /// PHP: `new Serializer\ClickHouse()`.
    pub const fn clickhouse() -> Self {
        Self::new()
    }

    /// PostgreSQL: identifiers in double quotes.
    ///
    /// PHP: `new Serializer\PostgreSQL()`.
    pub const fn postgresql() -> Self {
        Serializer { quote: '"' }
    }

    /// SQLite: identifiers in double quotes.
    ///
    /// PHP: `new Serializer\SQLite()`.
    pub const fn sqlite() -> Self {
        Self::postgresql()
    }

    /// A serializer quoting identifiers with `quote` (doubled inside them).
    ///
    /// PHP: a subclass overriding `quoteIdentifier()`.
    pub const fn with_quote(quote: char) -> Self {
        Serializer { quote }
    }

    /// `statement` as SQL. Fails with [`Error::Query`] on a node no SQL
    /// position takes (a [`CaseWhen`](super::CaseWhen) outside a `CASE`).
    ///
    /// PHP: `Serializer::serialize()`.
    pub fn serialize(&self, statement: &Select) -> Result<String> {
        let mut out = String::with_capacity(64);
        self.select(&mut out, statement)?;
        Ok(out)
    }

    /// `expression` as SQL.
    ///
    /// PHP: `Serializer::serializeExpression()`.
    pub fn serialize_expression(&self, expression: &Expression) -> Result<String> {
        let mut out = String::with_capacity(32);
        self.expression(&mut out, expression)?;
        Ok(out)
    }

    /// `name` quoted as an identifier.
    ///
    /// PHP: `Serializer::quoteIdentifier()` (protected).
    pub fn quote_identifier(&self, name: &str) -> String {
        let mut out = String::with_capacity(name.len() + 2);
        self.quote(&mut out, name);
        out
    }

    fn quote(&self, out: &mut String, name: &str) {
        out.push(self.quote);
        for c in name.chars() {
            if c == self.quote {
                out.push(c);
            }
            out.push(c);
        }
        out.push(self.quote);
    }

    fn list<T>(
        &self,
        out: &mut String,
        items: &[T],
        mut each: impl FnMut(&Self, &mut String, &T) -> Result<()>,
    ) -> Result<()> {
        for (i, item) in items.iter().enumerate() {
            if i > 0 {
                out.push_str(", ");
            }
            each(self, out, item)?;
        }
        Ok(())
    }

    fn expressions(&self, out: &mut String, items: &[Expression]) -> Result<()> {
        self.list(out, items, |s, out, e| s.expression(out, e))
    }

    fn order_by_items(&self, out: &mut String, items: &[OrderByItem]) -> Result<()> {
        self.list(out, items, |s, out, item| s.order_by_item(out, item))
    }

    fn select(&self, out: &mut String, statement: &Select) -> Result<()> {
        if !statement.ctes.is_empty() {
            self.ctes(out, &statement.ctes)?;
            out.push(' ');
        }

        out.push_str("SELECT");
        if statement.distinct {
            out.push_str(" DISTINCT");
        }
        out.push(' ');
        self.expressions(out, &statement.columns)?;

        if let Some(from) = &statement.from {
            out.push_str(" FROM ");
            self.table_source(out, from)?;
        }
        for join in &statement.joins {
            out.push(' ');
            self.join(out, join)?;
        }
        if let Some(condition) = &statement.where_clause {
            out.push_str(" WHERE ");
            self.expression(out, condition)?;
        }
        if !statement.group_by.is_empty() {
            out.push_str(" GROUP BY ");
            self.expressions(out, &statement.group_by)?;
        }
        if let Some(having) = &statement.having {
            out.push_str(" HAVING ");
            self.expression(out, having)?;
        }
        if !statement.windows.is_empty() {
            out.push_str(" WINDOW ");
            self.list(out, &statement.windows, |s, out, window| {
                s.quote(out, &window.name);
                out.push_str(" AS (");
                s.window_specification(out, &window.specification)?;
                out.push(')');
                Ok(())
            })?;
        }
        if !statement.order_by.is_empty() {
            out.push_str(" ORDER BY ");
            self.order_by_items(out, &statement.order_by)?;
        }
        if let Some(limit) = &statement.limit {
            out.push_str(" LIMIT ");
            self.expression(out, limit)?;
        }
        if let Some(offset) = &statement.offset {
            out.push_str(" OFFSET ");
            self.expression(out, offset)?;
        }
        Ok(())
    }

    fn expression(&self, out: &mut String, expression: &Expression) -> Result<()> {
        match expression {
            Expression::Aliased(a) => {
                self.expression(out, &a.expression)?;
                out.push_str(" AS ");
                self.quote(out, &a.alias);
            }
            Expression::Window(w) => self.window(out, w)?,
            Expression::Binary(b) => self.binary(out, b, None)?,
            Expression::Unary(u) => {
                if u.prefix {
                    // Always parenthesise the operand so a negative literal or
                    // a nested unary cannot produce `--`, a MySQL comment.
                    out.push_str(&u.operator);
                    out.push_str(" (");
                    self.expression(out, &u.operand)?;
                    out.push(')');
                } else {
                    self.expression(out, &u.operand)?;
                    out.push(' ');
                    out.push_str(&u.operator);
                }
            }
            Expression::Column(c) => self.column(out, c),
            Expression::Literal(l) => literal(out, l),
            Expression::Star(s) => self.star(out, s),
            Expression::Placeholder(p) => out.push_str(&p.value),
            Expression::Raw(r) => out.push_str(&r.sql),
            Expression::Func(f) => self.func(out, f)?,
            Expression::In(i) => {
                self.expression(out, &i.expression)?;
                out.push_str(if i.negated { " NOT IN (" } else { " IN (" });
                match &i.list {
                    InList::Subquery(query) => self.select(out, query)?,
                    InList::Values(items) => self.expressions(out, items)?,
                }
                out.push(')');
            }
            Expression::Between(b) => {
                self.expression(out, &b.expression)?;
                out.push_str(if b.negated { " NOT BETWEEN " } else { " BETWEEN " });
                self.expression(out, &b.low)?;
                out.push_str(" AND ");
                self.expression(out, &b.high)?;
            }
            Expression::Exists(e) => {
                out.push_str(if e.negated { "NOT EXISTS (" } else { "EXISTS (" });
                self.select(out, &e.subquery)?;
                out.push(')');
            }
            Expression::Conditional(c) => self.conditional(out, c)?,
            Expression::Cast(c) => {
                out.push_str("CAST(");
                self.expression(out, &c.expression)?;
                out.push_str(" AS ");
                out.push_str(&c.data_type);
                out.push(')');
            }
            Expression::Subquery(s) => {
                out.push('(');
                self.select(out, &s.query)?;
                out.push(')');
            }
            Expression::CaseWhen(_) => {
                return Err(Error::query(format!(
                    "Unsupported expression type: Utopia\\Query\\AST\\{}",
                    expression.php_class()
                )));
            }
        }
        Ok(())
    }

    fn binary(&self, out: &mut String, binary: &Binary, parent: Option<u8>) -> Result<()> {
        let level = precedence(&binary.operator);
        // Non-commutative left-associative operators bind their right operand
        // tighter, so an equal-precedence right subtree keeps its grouping.
        let right_level = if matches!(binary.operator.as_str(), "-" | "/" | "%") { level + 1 } else { level };
        let parens = parent.is_some_and(|p| level < p);
        if parens {
            out.push('(');
        }
        self.binary_child(out, &binary.left, level)?;
        out.push(' ');
        out.push_str(&binary.operator);
        out.push(' ');
        self.binary_child(out, &binary.right, right_level)?;
        if parens {
            out.push(')');
        }
        Ok(())
    }

    fn binary_child(&self, out: &mut String, child: &Expression, parent: u8) -> Result<()> {
        match child {
            Expression::Binary(b) => self.binary(out, b, Some(parent)),
            other => self.expression(out, other),
        }
    }

    fn column(&self, out: &mut String, column: &Column) {
        if let Some(schema) = &column.schema {
            self.quote(out, schema);
            out.push('.');
        }
        if let Some(table) = &column.table {
            self.quote(out, table);
            out.push('.');
        }
        self.quote(out, &column.name);
    }

    fn star(&self, out: &mut String, star: &Star) {
        if let Some(table) = &star.table {
            if let Some(schema) = &star.schema {
                self.quote(out, schema);
                out.push('.');
            }
            self.quote(out, table);
            out.push('.');
        }
        out.push('*');
    }

    fn func(&self, out: &mut String, func: &Func) -> Result<()> {
        out.push_str(&func.name);
        match func.arguments.as_slice() {
            [Expression::Star(_)] => {
                out.push_str("(*)");
                return Ok(());
            }
            [] => {
                out.push_str("()");
                return Ok(());
            }
            arguments => {
                out.push_str(if func.distinct { "(DISTINCT " } else { "(" });
                self.expressions(out, arguments)?;
                out.push(')');
            }
        }
        if let Some(filter) = &func.filter {
            out.push_str(" FILTER (WHERE ");
            self.expression(out, filter)?;
            out.push(')');
        }
        Ok(())
    }

    fn conditional(&self, out: &mut String, conditional: &Conditional) -> Result<()> {
        out.push_str("CASE");
        if let Some(operand) = &conditional.operand {
            out.push(' ');
            self.expression(out, operand)?;
        }
        for when in &conditional.whens {
            out.push_str(" WHEN ");
            self.expression(out, &when.condition)?;
            out.push_str(" THEN ");
            self.expression(out, &when.result)?;
        }
        if let Some(else_result) = &conditional.else_result {
            out.push_str(" ELSE ");
            self.expression(out, else_result)?;
        }
        out.push_str(" END");
        Ok(())
    }

    fn window(&self, out: &mut String, window: &Window) -> Result<()> {
        self.expression(out, &window.function)?;
        if let Some(name) = &window.window_name {
            out.push_str(" OVER ");
            self.quote(out, name);
        } else if let Some(specification) = &window.specification {
            out.push_str(" OVER (");
            self.window_specification(out, specification)?;
            out.push(')');
        } else {
            out.push_str(" OVER ()");
        }
        Ok(())
    }

    fn window_specification(&self, out: &mut String, specification: &WindowSpecification) -> Result<()> {
        let mut first = true;
        let mut separate = |out: &mut String| {
            if !std::mem::take(&mut first) {
                out.push(' ');
            }
        };
        if !specification.partition_by.is_empty() {
            separate(out);
            out.push_str("PARTITION BY ");
            self.expressions(out, &specification.partition_by)?;
        }
        if !specification.order_by.is_empty() {
            separate(out);
            out.push_str("ORDER BY ");
            self.order_by_items(out, &specification.order_by)?;
        }
        if let Some(frame_type) = &specification.frame_type {
            separate(out);
            out.push_str(frame_type);
            let start = specification.frame_start.as_deref().unwrap_or("");
            match &specification.frame_end {
                Some(end) => {
                    let _ = write!(out, " BETWEEN {start} AND {end}");
                }
                None => {
                    out.push(' ');
                    out.push_str(start);
                }
            }
        }
        Ok(())
    }

    fn order_by_item(&self, out: &mut String, item: &OrderByItem) -> Result<()> {
        self.expression(out, &item.expression)?;
        out.push(' ');
        out.push_str(item.direction.as_str());
        if let Some(nulls) = item.nulls {
            out.push_str(" NULLS ");
            out.push_str(nulls.as_str());
        }
        Ok(())
    }

    fn table_source(&self, out: &mut String, source: &TableSource) -> Result<()> {
        match source {
            TableSource::Subquery(s) => {
                out.push('(');
                self.select(out, &s.query)?;
                out.push_str(") AS ");
                self.quote(out, &s.alias);
            }
            TableSource::Table(t) => self.table(out, t),
        }
        Ok(())
    }

    fn table(&self, out: &mut String, table: &Table) {
        if let Some(schema) = &table.schema {
            self.quote(out, schema);
            out.push('.');
        }
        self.quote(out, &table.name);
        if let Some(alias) = &table.alias {
            out.push_str(" AS ");
            self.quote(out, alias);
        }
    }

    fn join(&self, out: &mut String, join: &JoinClause) -> Result<()> {
        out.push_str(&join.kind);
        out.push(' ');
        self.table_source(out, &join.table)?;
        if let Some(condition) = &join.condition {
            out.push_str(" ON ");
            self.expression(out, condition)?;
        }
        Ok(())
    }

    fn ctes(&self, out: &mut String, ctes: &[Cte]) -> Result<()> {
        out.push_str(if ctes.iter().any(|c| c.recursive) { "WITH RECURSIVE " } else { "WITH " });
        self.list(out, ctes, |s, out, cte| {
            s.quote(out, &cte.name);
            if !cte.columns.is_empty() {
                out.push_str(" (");
                for (i, column) in cte.columns.iter().enumerate() {
                    if i > 0 {
                        out.push_str(", ");
                    }
                    s.quote(out, column);
                }
                out.push(')');
            }
            out.push_str(" AS (");
            s.select(out, &cte.query)?;
            out.push(')');
            Ok(())
        })
    }
}

/// A literal as SQL: `NULL`, `TRUE` / `FALSE`, a number as PHP prints it, or
/// a quoted string with `\` doubled and `'` doubled.
fn literal(out: &mut String, literal: &Literal) {
    match &literal.value {
        Value::Null => out.push_str("NULL"),
        Value::Bool(b) => out.push_str(if *b { "TRUE" } else { "FALSE" }),
        Value::Int(i) => {
            let _ = write!(out, "{i}");
        }
        Value::Float(f) => out.push_str(&php_std::number::to_string(*f)),
        other => {
            let text = match other {
                Value::Str(s) => String::from_utf8_lossy(s.as_bytes()),
                other => std::borrow::Cow::Owned(crate::value::to_php_string(other)),
            };
            out.push('\'');
            for c in text.chars() {
                match c {
                    '\\' => out.push_str("\\\\"),
                    '\'' => out.push_str("''"),
                    c => out.push(c),
                }
            }
            out.push('\'');
        }
    }
}
