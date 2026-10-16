//! PHP `Utopia\Query\AST`.

use crate::enums::{NullsPosition, OrderDirection};
use crate::error::QueryError;
use crate::tokenizer::{Token, TokenType};

#[derive(Debug, Clone, PartialEq)]
pub enum Expression {
    Literal(Literal),
    Star(Star),
    Column(Column),
    Table(Table),
    Binary(Box<Binary>),
    Unary(Box<Unary>),
    Between(Box<Between>),
    In(Box<In>),
    Exists(Box<Exists>),
    Cast(Box<Cast>),
    Func(Box<Func>),
    Aliased(Box<Aliased>),
    CaseWhen(Box<CaseWhen>),
    Conditional(Box<Conditional>),
    Subquery(Box<Subquery>),
    Window(Box<WindowExpr>),
    Placeholder(Placeholder),
    Raw(Raw),
}

#[derive(Debug, Clone, PartialEq)]
pub struct Literal {
    pub value: LiteralValue,
}

#[derive(Debug, Clone, PartialEq)]
pub enum LiteralValue {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    String(String),
}

impl Literal {
    pub fn new(value: impl Into<LiteralValue>) -> Self {
        Self {
            value: value.into(),
        }
    }
}

impl From<()> for LiteralValue {
    fn from((): ()) -> Self {
        Self::Null
    }
}
impl From<bool> for LiteralValue {
    fn from(v: bool) -> Self {
        Self::Bool(v)
    }
}
impl From<i64> for LiteralValue {
    fn from(v: i64) -> Self {
        Self::Int(v)
    }
}
impl From<i32> for LiteralValue {
    fn from(v: i32) -> Self {
        Self::Int(i64::from(v))
    }
}
impl From<f64> for LiteralValue {
    fn from(v: f64) -> Self {
        Self::Float(v)
    }
}
impl From<String> for LiteralValue {
    fn from(v: String) -> Self {
        Self::String(v)
    }
}
impl From<&str> for LiteralValue {
    fn from(v: &str) -> Self {
        Self::String(v.to_owned())
    }
}

#[derive(Debug, Clone, PartialEq, Default)]
pub struct Star {
    pub table: Option<String>,
    pub schema: Option<String>,
}

impl Star {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn with_table(table: impl Into<String>) -> Self {
        Self {
            table: Some(table.into()),
            schema: None,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Column {
    pub name: String,
    pub table: Option<String>,
    pub schema: Option<String>,
}

impl Column {
    pub fn new(name: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            table: None,
            schema: None,
        }
    }

    pub fn qualified(name: impl Into<String>, table: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            table: Some(table.into()),
            schema: None,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Table {
    pub name: String,
    pub alias: Option<String>,
    pub schema: Option<String>,
}

impl Table {
    pub fn new(name: impl Into<String>, alias: Option<String>) -> Self {
        Self {
            name: name.into(),
            alias,
            schema: None,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Binary {
    pub left: Expression,
    pub operator: String,
    pub right: Expression,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Unary {
    pub operator: String,
    pub operand: Expression,
    pub prefix: bool,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Between {
    pub expression: Expression,
    pub low: Expression,
    pub high: Expression,
    pub negated: bool,
}

#[derive(Debug, Clone, PartialEq)]
pub struct In {
    pub expression: Expression,
    pub list: Vec<Expression>,
    pub negated: bool,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Exists {
    pub query: Box<Select>,
    pub negated: bool,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Cast {
    pub expression: Expression,
    pub type_name: String,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Func {
    pub name: String,
    pub arguments: Vec<Expression>,
    pub distinct: bool,
}

impl Func {
    pub fn new(name: impl Into<String>, arguments: Vec<Expression>, distinct: bool) -> Self {
        Self {
            name: name.into(),
            arguments,
            distinct,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Aliased {
    pub expression: Expression,
    pub alias: String,
}

#[derive(Debug, Clone, PartialEq)]
pub struct CaseWhen {
    pub operand: Option<Expression>,
    pub whens: Vec<(Expression, Expression)>,
    pub else_expr: Option<Expression>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Conditional {
    pub condition: Expression,
    pub then_expr: Expression,
    pub else_expr: Expression,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Subquery {
    pub query: Box<Select>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct SubquerySource {
    pub query: Box<Select>,
    pub alias: Option<String>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct WindowExpr {
    pub function: Expression,
    pub specification: WindowSpecification,
}

#[derive(Debug, Clone, PartialEq)]
pub struct WindowSpecification {
    pub partition_by: Vec<Expression>,
    pub order_by: Vec<OrderByItem>,
    pub frame: Option<String>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Placeholder {
    pub name: String,
}

#[derive(Debug, Clone, PartialEq)]
pub struct Raw {
    pub sql: String,
}

impl Raw {
    pub fn new(sql: impl Into<String>) -> Self {
        Self { sql: sql.into() }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct JoinClause {
    pub join_type: String,
    pub table: FromSource,
    pub condition: Option<Expression>,
}

#[derive(Debug, Clone, PartialEq)]
pub enum FromSource {
    Table(Table),
    Subquery(SubquerySource),
}

#[derive(Debug, Clone, PartialEq)]
pub struct OrderByItem {
    pub expression: Expression,
    pub direction: OrderDirection,
    pub nulls: Option<NullsPosition>,
}

impl OrderByItem {
    pub fn new(expression: Expression, direction: OrderDirection) -> Self {
        Self {
            expression,
            direction,
            nulls: None,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Cte {
    pub name: String,
    pub query: Box<Select>,
    pub columns: Vec<String>,
    pub recursive: bool,
}

#[derive(Debug, Clone, PartialEq)]
pub struct WindowDef {
    pub name: String,
    pub specification: WindowSpecification,
}

#[derive(Debug, Clone, PartialEq, Default)]
pub struct Select {
    pub columns: Vec<Expression>,
    pub from: Option<FromSource>,
    pub joins: Vec<JoinClause>,
    pub where_clause: Option<Expression>,
    pub group_by: Vec<Expression>,
    pub having: Option<Expression>,
    pub order_by: Vec<OrderByItem>,
    pub limit: Option<Expression>,
    pub offset: Option<Expression>,
    pub distinct: bool,
    pub ctes: Vec<Cte>,
    pub windows: Vec<WindowDef>,
}

impl Select {
    pub fn with_columns(mut self, columns: Vec<Expression>) -> Self {
        self.columns = columns;
        self
    }
}

pub trait Visitor {
    fn visit_expression(&mut self, expression: Expression) -> Expression {
        expression
    }
    fn visit_table_reference(&mut self, reference: Table) -> Table {
        reference
    }
    fn visit_select(&mut self, stmt: Select) -> Select {
        stmt
    }
}

#[derive(Debug, Default)]
pub struct Walker;

impl Walker {
    pub fn new() -> Self {
        Self
    }

    pub fn walk(&self, stmt: Select, visitor: &mut dyn Visitor) -> Select {
        let stmt = self.walk_statement(stmt, visitor);
        visitor.visit_select(stmt)
    }

    fn walk_statement(&self, mut stmt: Select, visitor: &mut dyn Visitor) -> Select {
        stmt.columns = self.walk_expr_vec(stmt.columns, visitor);
        stmt.from = match stmt.from {
            Some(FromSource::Table(t)) => Some(FromSource::Table(visitor.visit_table_reference(t))),
            other => other,
        };
        stmt.where_clause = stmt.where_clause.map(|e| self.walk_expr(e, visitor));
        stmt.group_by = self.walk_expr_vec(stmt.group_by, visitor);
        stmt.having = stmt.having.map(|e| self.walk_expr(e, visitor));
        stmt
    }

    fn walk_expr_vec(&self, exprs: Vec<Expression>, visitor: &mut dyn Visitor) -> Vec<Expression> {
        exprs
            .into_iter()
            .map(|e| self.walk_expr(e, visitor))
            .collect()
    }

    fn walk_expr(&self, expr: Expression, visitor: &mut dyn Visitor) -> Expression {
        visitor.visit_expression(expr)
    }
}

#[derive(Debug)]
pub struct ColumnValidator {
    pub allowed: Vec<String>,
    pub errors: Vec<String>,
}

impl ColumnValidator {
    pub fn new(allowed: Vec<String>) -> Self {
        Self {
            allowed,
            errors: Vec::new(),
        }
    }
}

impl Visitor for ColumnValidator {
    fn visit_expression(&mut self, expression: Expression) -> Expression {
        if let Expression::Column(col) = &expression {
            if !self.allowed.iter().any(|a| a == &col.name) {
                self.errors.push(format!("Unknown column: {}", col.name));
            }
        }
        expression
    }
}

#[derive(Debug)]
pub struct TableRenamer {
    pub from: String,
    pub to: String,
}

impl Visitor for TableRenamer {
    fn visit_table_reference(&mut self, mut reference: Table) -> Table {
        if reference.name == self.from {
            self.to.clone_into(&mut reference.name);
        }
        reference
    }
}

#[derive(Debug)]
pub struct FilterInjector {
    pub expression: Expression,
}

impl Visitor for FilterInjector {
    fn visit_select(&mut self, mut stmt: Select) -> Select {
        stmt.where_clause = Some(match stmt.where_clause.take() {
            Some(existing) => Expression::Binary(Box::new(Binary {
                left: existing,
                operator: "AND".to_owned(),
                right: self.expression.clone(),
            })),
            None => self.expression.clone(),
        });
        stmt
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SerializerDialect {
    Generic,
    Mysql,
    Mariadb,
    Postgres,
    Sqlite,
    Clickhouse,
}

#[derive(Debug, Clone)]
pub struct Serializer {
    pub dialect: SerializerDialect,
    wrap: char,
}

impl Serializer {
    pub fn new() -> Self {
        Self {
            dialect: SerializerDialect::Generic,
            wrap: '`',
        }
    }

    pub fn mysql() -> Self {
        Self {
            dialect: SerializerDialect::Mysql,
            wrap: '`',
        }
    }

    pub fn postgres() -> Self {
        Self {
            dialect: SerializerDialect::Postgres,
            wrap: '"',
        }
    }

    pub fn sqlite() -> Self {
        Self {
            dialect: SerializerDialect::Sqlite,
            wrap: '"',
        }
    }

    pub fn clickhouse() -> Self {
        Self {
            dialect: SerializerDialect::Clickhouse,
            wrap: '`',
        }
    }

    pub fn mariadb() -> Self {
        Self::mysql()
    }

    pub fn serialize(&self, stmt: &Select) -> String {
        let mut sql = String::new();
        if !stmt.ctes.is_empty() {
            let rec = stmt.ctes.iter().any(|c| c.recursive);
            sql.push_str(if rec { "WITH RECURSIVE " } else { "WITH " });
            let parts: Vec<String> = stmt
                .ctes
                .iter()
                .map(|c| format!("{} AS ({})", self.quote(&c.name), self.serialize(&c.query)))
                .collect();
            sql.push_str(&parts.join(", "));
            sql.push(' ');
        }
        sql.push_str(if stmt.distinct {
            "SELECT DISTINCT "
        } else {
            "SELECT "
        });
        if stmt.columns.is_empty() {
            sql.push('*');
        } else {
            let cols: Vec<String> = stmt
                .columns
                .iter()
                .map(|c| self.serialize_expression(c))
                .collect();
            sql.push_str(&cols.join(", "));
        }
        if let Some(from) = &stmt.from {
            sql.push_str(" FROM ");
            sql.push_str(&self.serialize_from(from));
        }
        for join in &stmt.joins {
            sql.push(' ');
            sql.push_str(&join.join_type);
            sql.push(' ');
            sql.push_str(&self.serialize_from(&join.table));
            if let Some(cond) = &join.condition {
                sql.push_str(" ON ");
                sql.push_str(&self.serialize_expression(cond));
            }
        }
        if let Some(w) = &stmt.where_clause {
            sql.push_str(" WHERE ");
            sql.push_str(&self.serialize_expression(w));
        }
        if !stmt.group_by.is_empty() {
            sql.push_str(" GROUP BY ");
            let g: Vec<String> = stmt
                .group_by
                .iter()
                .map(|c| self.serialize_expression(c))
                .collect();
            sql.push_str(&g.join(", "));
        }
        if let Some(h) = &stmt.having {
            sql.push_str(" HAVING ");
            sql.push_str(&self.serialize_expression(h));
        }
        if !stmt.order_by.is_empty() {
            sql.push_str(" ORDER BY ");
            let o: Vec<String> = stmt
                .order_by
                .iter()
                .map(|i| {
                    format!(
                        "{} {}",
                        self.serialize_expression(&i.expression),
                        i.direction.as_str()
                    )
                })
                .collect();
            sql.push_str(&o.join(", "));
        }
        if let Some(l) = &stmt.limit {
            sql.push_str(" LIMIT ");
            sql.push_str(&self.serialize_expression(l));
        }
        if let Some(o) = &stmt.offset {
            sql.push_str(" OFFSET ");
            sql.push_str(&self.serialize_expression(o));
        }
        sql
    }

    fn serialize_from(&self, from: &FromSource) -> String {
        match from {
            FromSource::Table(t) => {
                let mut s = self.quote(&t.name);
                if let Some(a) = &t.alias {
                    s.push_str(" AS ");
                    s.push_str(&self.quote(a));
                }
                s
            }
            FromSource::Subquery(s) => {
                let mut out = format!("({})", self.serialize(&s.query));
                if let Some(a) = &s.alias {
                    out.push_str(" AS ");
                    out.push_str(&self.quote(a));
                }
                out
            }
        }
    }

    pub fn serialize_expression(&self, expr: &Expression) -> String {
        match expr {
            Expression::Literal(l) => match &l.value {
                LiteralValue::Null => "NULL".to_owned(),
                LiteralValue::Bool(true) => "TRUE".to_owned(),
                LiteralValue::Bool(false) => "FALSE".to_owned(),
                LiteralValue::Int(n) => n.to_string(),
                LiteralValue::Float(n) => n.to_string(),
                LiteralValue::String(s) => format!("'{}'", s.replace('\'', "''")),
            },
            Expression::Star(s) => match (&s.schema, &s.table) {
                (Some(sch), Some(t)) => format!("{}.{t}.*", self.quote(sch)),
                (None, Some(t)) => format!("{}.*", self.quote(t)),
                _ => "*".to_owned(),
            },
            Expression::Column(c) => {
                let mut parts = Vec::new();
                if let Some(s) = &c.schema {
                    parts.push(self.quote(s));
                }
                if let Some(t) = &c.table {
                    parts.push(self.quote(t));
                }
                parts.push(self.quote(&c.name));
                parts.join(".")
            }
            Expression::Table(t) => self.quote(&t.name),
            Expression::Binary(b) => format!(
                "({} {} {})",
                self.serialize_expression(&b.left),
                b.operator,
                self.serialize_expression(&b.right)
            ),
            Expression::Unary(u) => {
                if u.prefix {
                    format!("{} {}", u.operator, self.serialize_expression(&u.operand))
                } else {
                    format!("{} {}", self.serialize_expression(&u.operand), u.operator)
                }
            }
            Expression::Between(b) => {
                let n = if b.negated { "NOT BETWEEN" } else { "BETWEEN" };
                format!(
                    "{} {n} {} AND {}",
                    self.serialize_expression(&b.expression),
                    self.serialize_expression(&b.low),
                    self.serialize_expression(&b.high)
                )
            }
            Expression::In(i) => {
                let n = if i.negated { "NOT IN" } else { "IN" };
                let list: Vec<String> = i
                    .list
                    .iter()
                    .map(|e| self.serialize_expression(e))
                    .collect();
                format!(
                    "{} {n} ({})",
                    self.serialize_expression(&i.expression),
                    list.join(", ")
                )
            }
            Expression::Func(f) => {
                let args: Vec<String> = f
                    .arguments
                    .iter()
                    .map(|e| self.serialize_expression(e))
                    .collect();
                let distinct = if f.distinct { "DISTINCT " } else { "" };
                format!("{}({distinct}{})", f.name, args.join(", "))
            }
            Expression::Aliased(a) => format!(
                "{} AS {}",
                self.serialize_expression(&a.expression),
                self.quote(&a.alias)
            ),
            Expression::Raw(r) => r.sql.clone(),
            Expression::Placeholder(p) => {
                if p.name.is_empty() {
                    "?".to_owned()
                } else {
                    format!(":{}", p.name)
                }
            }
            Expression::Cast(c) => format!(
                "CAST({} AS {})",
                self.serialize_expression(&c.expression),
                c.type_name
            ),
            Expression::Subquery(s) => format!("({})", self.serialize(&s.query)),
            Expression::Exists(e) => {
                let n = if e.negated { "NOT EXISTS" } else { "EXISTS" };
                format!("{n} ({})", self.serialize(&e.query))
            }
            Expression::CaseWhen(c) => {
                let mut sql = String::from("CASE");
                if let Some(op) = &c.operand {
                    sql.push(' ');
                    sql.push_str(&self.serialize_expression(op));
                }
                for (when, then) in &c.whens {
                    sql.push_str(" WHEN ");
                    sql.push_str(&self.serialize_expression(when));
                    sql.push_str(" THEN ");
                    sql.push_str(&self.serialize_expression(then));
                }
                if let Some(e) = &c.else_expr {
                    sql.push_str(" ELSE ");
                    sql.push_str(&self.serialize_expression(e));
                }
                sql.push_str(" END");
                sql
            }
            Expression::Conditional(c) => format!(
                "CASE WHEN {} THEN {} ELSE {} END",
                self.serialize_expression(&c.condition),
                self.serialize_expression(&c.then_expr),
                self.serialize_expression(&c.else_expr)
            ),
            Expression::Window(w) => self.serialize_expression(&w.function),
        }
    }

    fn quote(&self, ident: &str) -> String {
        if ident == "*" {
            return "*".to_owned();
        }
        let w = self.wrap.to_string();
        format!("{w}{}{w}", ident.replace(&w, &format!("{w}{w}")))
    }
}

impl Default for Serializer {
    fn default() -> Self {
        Self::new()
    }
}

#[derive(Debug)]
pub struct Parser {
    tokens: Vec<Token>,
    pos: usize,
}

impl Parser {
    pub fn new() -> Self {
        Self {
            tokens: Vec::new(),
            pos: 0,
        }
    }

    pub fn parse(&mut self, tokens: Vec<Token>) -> Result<Select, QueryError> {
        self.tokens = tokens;
        self.pos = 0;
        self.parse_select()
    }

    fn current(&self) -> Option<&Token> {
        self.tokens.get(self.pos)
    }

    fn advance(&mut self) -> Option<Token> {
        let t = self.tokens.get(self.pos).cloned();
        if t.is_some() {
            self.pos += 1;
        }
        t
    }

    fn match_keyword(&self, kw: &str) -> bool {
        matches!(self.current(), Some(t) if t.token_type == TokenType::Keyword && t.value.eq_ignore_ascii_case(kw))
    }

    fn consume_keyword(&mut self, kw: &str) -> Result<(), QueryError> {
        if self.match_keyword(kw) {
            self.advance();
            Ok(())
        } else {
            Err(QueryError::validation(format!("Expected keyword {kw}")))
        }
    }

    fn parse_select(&mut self) -> Result<Select, QueryError> {
        let mut stmt = Select::default();
        if self.match_keyword("WITH") {
            self.advance();
            let mut recursive = false;
            if self.match_keyword("RECURSIVE") {
                recursive = true;
                self.advance();
            }
            stmt.ctes = self.parse_ctes(recursive)?;
        }
        self.consume_keyword("SELECT")?;
        if self.match_keyword("DISTINCT") {
            stmt.distinct = true;
            self.advance();
        }
        stmt.columns = self.parse_select_list()?;
        if self.match_keyword("FROM") {
            self.advance();
            stmt.from = Some(self.parse_from()?);
        }
        while self.is_join_start() {
            stmt.joins.push(self.parse_join()?);
        }
        if self.match_keyword("WHERE") {
            self.advance();
            stmt.where_clause = Some(self.parse_or()?);
        }
        if self.match_keyword("GROUP") {
            self.advance();
            self.consume_keyword("BY")?;
            stmt.group_by = self.parse_expr_list()?;
        }
        if self.match_keyword("HAVING") {
            self.advance();
            stmt.having = Some(self.parse_or()?);
        }
        if self.match_keyword("ORDER") {
            self.advance();
            self.consume_keyword("BY")?;
            stmt.order_by = self.parse_order_by()?;
        }
        if self.match_keyword("LIMIT") {
            self.advance();
            stmt.limit = Some(self.parse_primary()?);
        }
        if self.match_keyword("OFFSET") {
            self.advance();
            stmt.offset = Some(self.parse_primary()?);
        }
        Ok(stmt)
    }

    fn parse_ctes(&mut self, recursive: bool) -> Result<Vec<Cte>, QueryError> {
        let mut ctes = Vec::new();
        loop {
            let name = self.advance().map(|t| t.value).unwrap_or_default();
            self.expect_punct(TokenType::Keyword, "AS")?;
            self.expect_type(TokenType::LeftParen)?;
            let query = self.parse_select()?;
            self.expect_type(TokenType::RightParen)?;
            ctes.push(Cte {
                name,
                query: Box::new(query),
                columns: Vec::new(),
                recursive,
            });
            if matches!(self.current(), Some(t) if t.token_type == TokenType::Comma) {
                self.advance();
                continue;
            }
            break;
        }
        Ok(ctes)
    }

    fn parse_select_list(&mut self) -> Result<Vec<Expression>, QueryError> {
        let mut cols = Vec::new();
        loop {
            cols.push(self.parse_select_item()?);
            if matches!(self.current(), Some(t) if t.token_type == TokenType::Comma) {
                self.advance();
                continue;
            }
            break;
        }
        Ok(cols)
    }

    fn parse_select_item(&mut self) -> Result<Expression, QueryError> {
        let expr = self.parse_or()?;
        if self.match_keyword("AS") {
            self.advance();
            let alias = self.advance().map(|t| t.value).unwrap_or_default();
            return Ok(Expression::Aliased(Box::new(Aliased {
                expression: expr,
                alias,
            })));
        }
        Ok(expr)
    }

    fn parse_from(&mut self) -> Result<FromSource, QueryError> {
        if matches!(self.current(), Some(t) if t.token_type == TokenType::LeftParen) {
            self.advance();
            let query = self.parse_select()?;
            self.expect_type(TokenType::RightParen)?;
            let mut alias = None;
            if self.match_keyword("AS") {
                self.advance();
                alias = self.advance().map(|t| t.value);
            }
            return Ok(FromSource::Subquery(SubquerySource {
                query: Box::new(query),
                alias,
            }));
        }
        let name = self.advance().map(|t| t.value).unwrap_or_default();
        let mut alias = None;
        if self.match_keyword("AS") {
            self.advance();
            alias = self.advance().map(|t| t.value);
        } else if matches!(
            self.current(),
            Some(t) if t.token_type == TokenType::Identifier
        ) {
            alias = self.advance().map(|t| t.value);
        }
        Ok(FromSource::Table(Table::new(name, alias)))
    }

    fn is_join_start(&self) -> bool {
        self.match_keyword("JOIN")
            || self.match_keyword("LEFT")
            || self.match_keyword("RIGHT")
            || self.match_keyword("INNER")
            || self.match_keyword("FULL")
            || self.match_keyword("CROSS")
            || self.match_keyword("NATURAL")
    }

    fn parse_join(&mut self) -> Result<JoinClause, QueryError> {
        let mut join_type = String::new();
        while self.is_join_start() && !self.match_keyword("JOIN") {
            if !join_type.is_empty() {
                join_type.push(' ');
            }
            join_type.push_str(&self.advance().unwrap().value.to_uppercase());
        }
        if self.match_keyword("JOIN") {
            if !join_type.is_empty() {
                join_type.push(' ');
            }
            join_type.push_str("JOIN");
            self.advance();
        }
        let table = self.parse_from()?;
        let mut condition = None;
        if self.match_keyword("ON") {
            self.advance();
            condition = Some(self.parse_or()?);
        }
        Ok(JoinClause {
            join_type,
            table,
            condition,
        })
    }

    fn parse_order_by(&mut self) -> Result<Vec<OrderByItem>, QueryError> {
        let mut items = Vec::new();
        loop {
            let expression = self.parse_or()?;
            let mut direction = OrderDirection::Asc;
            if self.match_keyword("ASC") {
                self.advance();
            } else if self.match_keyword("DESC") {
                direction = OrderDirection::Desc;
                self.advance();
            }
            items.push(OrderByItem::new(expression, direction));
            if matches!(self.current(), Some(t) if t.token_type == TokenType::Comma) {
                self.advance();
                continue;
            }
            break;
        }
        Ok(items)
    }

    fn parse_expr_list(&mut self) -> Result<Vec<Expression>, QueryError> {
        let mut items = Vec::new();
        loop {
            items.push(self.parse_or()?);
            if matches!(self.current(), Some(t) if t.token_type == TokenType::Comma) {
                self.advance();
                continue;
            }
            break;
        }
        Ok(items)
    }

    fn parse_or(&mut self) -> Result<Expression, QueryError> {
        let mut left = self.parse_and()?;
        while self.match_keyword("OR") {
            self.advance();
            let right = self.parse_and()?;
            left = Expression::Binary(Box::new(Binary {
                left,
                operator: "OR".to_owned(),
                right,
            }));
        }
        Ok(left)
    }

    fn parse_and(&mut self) -> Result<Expression, QueryError> {
        let mut left = self.parse_not()?;
        while self.match_keyword("AND") {
            self.advance();
            let right = self.parse_not()?;
            left = Expression::Binary(Box::new(Binary {
                left,
                operator: "AND".to_owned(),
                right,
            }));
        }
        Ok(left)
    }

    fn parse_not(&mut self) -> Result<Expression, QueryError> {
        if self.match_keyword("NOT") {
            self.advance();
            let operand = self.parse_not()?;
            return Ok(Expression::Unary(Box::new(Unary {
                operator: "NOT".to_owned(),
                operand,
                prefix: true,
            })));
        }
        self.parse_comparison()
    }

    fn parse_comparison(&mut self) -> Result<Expression, QueryError> {
        let left = self.parse_concat()?;
        if let Some(tok) = self.current() {
            if tok.token_type == TokenType::Operator {
                let op = tok.value.clone();
                self.advance();
                let right = self.parse_concat()?;
                return Ok(Expression::Binary(Box::new(Binary {
                    left,
                    operator: op,
                    right,
                })));
            }
        }
        if self.match_keyword("LIKE") {
            self.advance();
            let right = self.parse_concat()?;
            return Ok(Expression::Binary(Box::new(Binary {
                left,
                operator: "LIKE".to_owned(),
                right,
            })));
        }
        if self.match_keyword("IN") {
            self.advance();
            self.expect_type(TokenType::LeftParen)?;
            let list = self.parse_expr_list()?;
            self.expect_type(TokenType::RightParen)?;
            return Ok(Expression::In(Box::new(In {
                expression: left,
                list,
                negated: false,
            })));
        }
        if self.match_keyword("BETWEEN") {
            self.advance();
            let low = self.parse_concat()?;
            self.consume_keyword("AND")?;
            let high = self.parse_concat()?;
            return Ok(Expression::Between(Box::new(Between {
                expression: left,
                low,
                high,
                negated: false,
            })));
        }
        if self.match_keyword("IS") {
            self.advance();
            let mut op = "IS".to_owned();
            if self.match_keyword("NOT") {
                "IS NOT".clone_into(&mut op);
                self.advance();
            }
            if self.match_keyword("NULL") {
                self.advance();
                return Ok(Expression::Unary(Box::new(Unary {
                    operator: format!("{op} NULL"),
                    operand: left,
                    prefix: false,
                })));
            }
        }
        Ok(left)
    }

    fn parse_concat(&mut self) -> Result<Expression, QueryError> {
        self.parse_add()
    }

    fn parse_add(&mut self) -> Result<Expression, QueryError> {
        let mut left = self.parse_mul()?;
        while matches!(
            self.current(),
            Some(t) if t.token_type == TokenType::Operator && (t.value == "+" || t.value == "-")
        ) {
            let op = self.advance().unwrap().value;
            let right = self.parse_mul()?;
            left = Expression::Binary(Box::new(Binary {
                left,
                operator: op,
                right,
            }));
        }
        Ok(left)
    }

    fn parse_mul(&mut self) -> Result<Expression, QueryError> {
        let mut left = self.parse_primary()?;
        while matches!(
            self.current(),
            Some(t) if t.token_type == TokenType::Operator && (t.value == "*" || t.value == "/" || t.value == "%")
        ) {
            let op = self.advance().unwrap().value;
            let right = self.parse_primary()?;
            left = Expression::Binary(Box::new(Binary {
                left,
                operator: op,
                right,
            }));
        }
        Ok(left)
    }

    fn parse_primary(&mut self) -> Result<Expression, QueryError> {
        let Some(tok) = self.advance() else {
            return Err(QueryError::validation("Unexpected end of SQL"));
        };
        match tok.token_type {
            TokenType::Star => Ok(Expression::Star(Star::new())),
            TokenType::Integer => Ok(Expression::Literal(Literal::new(
                tok.value.parse::<i64>().unwrap_or(0),
            ))),
            TokenType::Float => Ok(Expression::Literal(Literal::new(
                tok.value.parse::<f64>().unwrap_or(0.0),
            ))),
            TokenType::String => Ok(Expression::Literal(Literal::new(tok.value))),
            TokenType::Null => Ok(Expression::Literal(Literal {
                value: LiteralValue::Null,
            })),
            TokenType::Boolean => Ok(Expression::Literal(Literal::new(
                tok.value.eq_ignore_ascii_case("true"),
            ))),
            TokenType::Placeholder => Ok(Expression::Placeholder(Placeholder {
                name: String::new(),
            })),
            TokenType::NamedPlaceholder => Ok(Expression::Placeholder(Placeholder {
                name: tok.value.trim_start_matches(':').to_owned(),
            })),
            TokenType::LeftParen => {
                if self.match_keyword("SELECT") {
                    let q = self.parse_select()?;
                    self.expect_type(TokenType::RightParen)?;
                    Ok(Expression::Subquery(Box::new(Subquery {
                        query: Box::new(q),
                    })))
                } else {
                    let e = self.parse_or()?;
                    self.expect_type(TokenType::RightParen)?;
                    Ok(e)
                }
            }
            TokenType::Identifier | TokenType::QuotedIdentifier | TokenType::Keyword => {
                if matches!(self.current(), Some(t) if t.token_type == TokenType::LeftParen) {
                    self.advance();
                    let mut args = Vec::new();
                    if !matches!(self.current(), Some(t) if t.token_type == TokenType::RightParen) {
                        args = self.parse_expr_list()?;
                    }
                    self.expect_type(TokenType::RightParen)?;
                    return Ok(Expression::Func(Box::new(Func::new(
                        tok.value, args, false,
                    ))));
                }
                let mut table = None;
                let mut name = tok.value;
                if matches!(self.current(), Some(t) if t.token_type == TokenType::Dot) {
                    self.advance();
                    table = Some(name);
                    name = self.advance().map_or_else(|| "*".to_owned(), |t| t.value);
                    if name == "*" {
                        return Ok(Expression::Star(Star::with_table(table.unwrap())));
                    }
                }
                Ok(Expression::Column(Column {
                    name,
                    table,
                    schema: None,
                }))
            }
            _ => Err(QueryError::validation(format!(
                "Unexpected token: {}",
                tok.value
            ))),
        }
    }

    fn expect_type(&mut self, ty: TokenType) -> Result<(), QueryError> {
        match self.current() {
            Some(t) if t.token_type == ty => {
                self.advance();
                Ok(())
            }
            _ => Err(QueryError::validation(format!("Expected {ty:?}"))),
        }
    }

    fn expect_punct(&mut self, _ty: TokenType, kw: &str) -> Result<(), QueryError> {
        self.consume_keyword(kw)
    }
}

impl Default for Parser {
    fn default() -> Self {
        Self::new()
    }
}
