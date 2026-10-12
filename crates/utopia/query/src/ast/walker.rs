//! [`Walker`] and [`Visitor`]: rewriting a tree, and the visitors the
//! library ships.

use std::collections::HashMap;

use super::node::{
    Binary, Column, Expression, InList, JoinClause, OrderByItem, Select, Star, Table, TableSource, WindowSpecification,
};
use crate::error::{Error, Result};

/// Inspects or rewrites the nodes a [`Walker`] visits: each method gets a
/// node (its children already walked) and returns it, or its replacement.
/// The defaults keep every node.
///
/// PHP: the `Utopia\Query\AST\Visitor` interface.
pub trait Visitor {
    /// Visits an expression.
    ///
    /// PHP: `Visitor::visitExpression()`.
    fn visit_expression(&mut self, expression: Expression) -> Result<Expression> {
        Ok(expression)
    }

    /// Visits a table reference (of a `FROM` or a join).
    ///
    /// PHP: `Visitor::visitTableReference()`.
    fn visit_table_reference(&mut self, reference: Table) -> Result<Table> {
        Ok(reference)
    }

    /// Visits a statement, after everything in it (subqueries included).
    ///
    /// PHP: `Visitor::visitSelect()`.
    fn visit_select(&mut self, select: Select) -> Result<Select> {
        Ok(select)
    }
}

impl<V: Visitor + ?Sized> Visitor for &mut V {
    fn visit_expression(&mut self, expression: Expression) -> Result<Expression> {
        (**self).visit_expression(expression)
    }

    fn visit_table_reference(&mut self, reference: Table) -> Result<Table> {
        (**self).visit_table_reference(reference)
    }

    fn visit_select(&mut self, select: Select) -> Result<Select> {
        (**self).visit_select(select)
    }
}

/// Applies a [`Visitor`] to every node of a statement, bottom-up: the
/// children of a node first, then the node. Statements nested in
/// subqueries, `IN`, `EXISTS`, derived tables and CTEs are walked the same
/// way, and each gets [`Visitor::visit_select`].
///
/// Order within a statement: columns, `FROM`, joins (table, then
/// condition), `WHERE`, `GROUP BY`, `HAVING`, `ORDER BY`, `LIMIT`,
/// `OFFSET`, CTEs, `WINDOW` definitions.
///
/// PHP: `Utopia\Query\AST\Walker`.
///
/// ```
/// use std::collections::HashMap;
/// use utopia_query::ast::{Parser, Serializer, TableRenamer, Walker};
///
/// let select = Parser::new().parse_sql("SELECT u.id FROM users u WHERE u.active = 1")?;
/// let mut renamer = TableRenamer::new(HashMap::from([("users".to_owned(), "accounts".to_owned())]));
/// let select = Walker::new().walk(select, &mut renamer)?;
/// assert_eq!(Serializer::new().serialize(&select)?, "SELECT `u`.`id` FROM `accounts` AS `u` WHERE `u`.`active` = 1");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\AST\Parser;
/// use Utopia\Query\AST\Serializer;
/// use Utopia\Query\AST\Visitor\TableRenamer;
/// use Utopia\Query\AST\Walker;
/// use Utopia\Query\Tokenizer\Tokenizer;
///
/// $select = (new Parser())->parse(Tokenizer::filter((new Tokenizer())->tokenize('SELECT u.id FROM users u WHERE u.active = 1')));
/// $select = (new Walker())->walk($select, new TableRenamer(['users' => 'accounts']));
/// echo (new Serializer())->serialize($select), "\n"; // SELECT `u`.`id` FROM `accounts` AS `u` WHERE `u`.`active` = 1
/// ```
#[derive(Debug, Clone, Copy, Default)]
pub struct Walker;

impl Walker {
    /// A walker.
    ///
    /// PHP: `new Walker()`.
    pub const fn new() -> Self {
        Walker
    }

    /// `statement` with `visitor` applied to every node; fails with the
    /// first error a visitor returns.
    ///
    /// PHP: `Walker::walk()`.
    pub fn walk<V: Visitor + ?Sized>(&self, statement: Select, visitor: &mut V) -> Result<Select> {
        let statement = self.statement(statement, visitor)?;
        visitor.visit_select(statement)
    }

    fn statement<V: Visitor + ?Sized>(&self, s: Select, v: &mut V) -> Result<Select> {
        let columns = self.expressions(s.columns, v)?;
        let from = match s.from {
            Some(source) => Some(self.source(source, v)?),
            None => None,
        };
        let joins = s
            .joins
            .into_iter()
            .map(|join| {
                let table = self.source(join.table, v)?;
                let condition = self.optional(join.condition, v)?;
                Ok(JoinClause { kind: join.kind, table, condition })
            })
            .collect::<Result<_>>()?;
        let where_clause = self.optional(s.where_clause, v)?;
        let group_by = self.expressions(s.group_by, v)?;
        let having = self.optional(s.having, v)?;
        let order_by = self.order_by(s.order_by, v)?;
        let limit = self.optional(s.limit, v)?;
        let offset = self.optional(s.offset, v)?;
        let mut ctes = s.ctes;
        for cte in &mut ctes {
            cte.query = self.walk(std::mem::take(&mut cte.query), v)?;
        }
        let mut windows = s.windows;
        for window in &mut windows {
            window.specification = self.specification(std::mem::take(&mut window.specification), v)?;
        }
        Ok(Select {
            columns,
            from,
            joins,
            where_clause,
            group_by,
            having,
            order_by,
            limit,
            offset,
            distinct: s.distinct,
            ctes,
            windows,
        })
    }

    fn source<V: Visitor + ?Sized>(&self, source: TableSource, v: &mut V) -> Result<TableSource> {
        Ok(match source {
            TableSource::Table(t) => TableSource::Table(v.visit_table_reference(t)?),
            TableSource::Subquery(mut s) => {
                *s.query = self.walk(std::mem::take(&mut *s.query), v)?;
                TableSource::Subquery(s)
            }
        })
    }

    fn optional<V: Visitor + ?Sized>(&self, e: Option<Expression>, v: &mut V) -> Result<Option<Expression>> {
        e.map(|e| self.expression(e, v)).transpose()
    }

    fn boxed<V: Visitor + ?Sized>(&self, e: &mut Box<Expression>, v: &mut V) -> Result<()> {
        let inner = std::mem::replace(&mut **e, Expression::Star(Star::default()));
        **e = self.expression(inner, v)?;
        Ok(())
    }

    fn expressions<V: Visitor + ?Sized>(&self, items: Vec<Expression>, v: &mut V) -> Result<Vec<Expression>> {
        items.into_iter().map(|e| self.expression(e, v)).collect()
    }

    fn order_by<V: Visitor + ?Sized>(&self, items: Vec<OrderByItem>, v: &mut V) -> Result<Vec<OrderByItem>> {
        items
            .into_iter()
            .map(|item| {
                Ok(OrderByItem {
                    expression: self.expression(item.expression, v)?,
                    direction: item.direction,
                    nulls: item.nulls,
                })
            })
            .collect()
    }

    fn specification<V: Visitor + ?Sized>(&self, s: WindowSpecification, v: &mut V) -> Result<WindowSpecification> {
        Ok(WindowSpecification {
            partition_by: self.expressions(s.partition_by, v)?,
            order_by: self.order_by(s.order_by, v)?,
            ..s
        })
    }

    fn expression<V: Visitor + ?Sized>(&self, mut expression: Expression, v: &mut V) -> Result<Expression> {
        match &mut expression {
            Expression::Binary(b) => {
                self.boxed(&mut b.left, v)?;
                self.boxed(&mut b.right, v)?;
            }
            Expression::Unary(u) => self.boxed(&mut u.operand, v)?,
            Expression::Func(f) => {
                f.arguments = self.expressions(std::mem::take(&mut f.arguments), v)?;
                if let Some(filter) = &mut f.filter {
                    self.boxed(filter, v)?;
                }
            }
            Expression::Aliased(a) => self.boxed(&mut a.expression, v)?,
            Expression::In(i) => {
                self.boxed(&mut i.expression, v)?;
                match &mut i.list {
                    InList::Subquery(query) => **query = self.walk(std::mem::take(&mut **query), v)?,
                    InList::Values(items) => *items = self.expressions(std::mem::take(items), v)?,
                }
            }
            Expression::Between(b) => {
                self.boxed(&mut b.expression, v)?;
                self.boxed(&mut b.low, v)?;
                self.boxed(&mut b.high, v)?;
            }
            Expression::Exists(e) => *e.subquery = self.walk(std::mem::take(&mut *e.subquery), v)?,
            Expression::Conditional(c) => {
                if let Some(operand) = &mut c.operand {
                    self.boxed(operand, v)?;
                }
                for when in &mut c.whens {
                    self.boxed(&mut when.condition, v)?;
                    self.boxed(&mut when.result, v)?;
                }
                if let Some(else_result) = &mut c.else_result {
                    self.boxed(else_result, v)?;
                }
            }
            Expression::Cast(c) => self.boxed(&mut c.expression, v)?,
            Expression::Subquery(s) => *s.query = self.walk(std::mem::take(&mut *s.query), v)?,
            Expression::Window(w) => {
                self.boxed(&mut w.function, v)?;
                if let Some(specification) = w.specification.take() {
                    w.specification = Some(Box::new(self.specification(*specification, v)?));
                }
            }
            Expression::CaseWhen(_)
            | Expression::Literal(_)
            | Expression::Placeholder(_)
            | Expression::Raw(_)
            | Expression::Column(_)
            | Expression::Star(_) => {}
        }
        v.visit_expression(expression)
    }
}

/// Rejects columns outside an allow-list, and `*` unless allowed.
///
/// PHP: `Utopia\Query\AST\Visitor\ColumnValidator`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ColumnValidator {
    allowed: Vec<String>,
    allow_star: bool,
}

impl ColumnValidator {
    /// A validator allowing `allowed` columns (by name), and `*` when `allow_star`.
    ///
    /// PHP: `new ColumnValidator($allowedColumns, $allowStar)`.
    pub fn new(allowed: Vec<String>, allow_star: bool) -> Self {
        ColumnValidator { allowed, allow_star }
    }
}

impl Visitor for ColumnValidator {
    /// Fails with [`Error::Query`] on a column not allowed, or on `*` (any
    /// `*`, qualified or not) unless allowed.
    ///
    /// PHP: `ColumnValidator::visitExpression()`.
    fn visit_expression(&mut self, expression: Expression) -> Result<Expression> {
        match &expression {
            Expression::Column(c) if !self.allowed.contains(&c.name) => {
                Err(Error::query(format!("Column '{}' is not in the allowed list", c.name)))
            }
            Expression::Star(_) if !self.allow_star => Err(Error::query(
                "Wildcard (*) selection is not allowed; list explicit columns or construct ColumnValidator with allowStar: true",
            )),
            _ => Ok(expression),
        }
    }
}

/// Adds a condition to the `WHERE` of statements (`AND`-ed with an existing one).
///
/// Walked, it applies to every statement, subqueries included; to change
/// only the outer one, call [`Visitor::visit_select`] on it directly.
///
/// PHP: `Utopia\Query\AST\Visitor\FilterInjector`.
#[derive(Debug, Clone, PartialEq)]
pub struct FilterInjector {
    condition: Expression,
}

impl FilterInjector {
    /// An injector of `condition`.
    ///
    /// PHP: `new FilterInjector($condition)`.
    pub fn new(condition: impl Into<Expression>) -> Self {
        FilterInjector { condition: condition.into() }
    }
}

impl Visitor for FilterInjector {
    /// `select` with the condition in its `WHERE`.
    ///
    /// PHP: `FilterInjector::visitSelect()`.
    fn visit_select(&mut self, mut select: Select) -> Result<Select> {
        let condition = self.condition.clone();
        select.where_clause = Some(match select.where_clause.take() {
            None => condition,
            Some(existing) => Binary::new(existing, "AND", condition).into(),
        });
        Ok(select)
    }
}

/// Renames tables: table references (name and alias) and the table
/// qualifier of columns and `table.*`.
///
/// PHP: `Utopia\Query\AST\Visitor\TableRenamer`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TableRenamer {
    renames: HashMap<String, String>,
}

impl TableRenamer {
    /// A renamer of each old name to its new name.
    ///
    /// PHP: `new TableRenamer($renames)`.
    pub fn new(renames: HashMap<String, String>) -> Self {
        TableRenamer { renames }
    }

    fn renamed(&self, name: &Option<String>) -> Option<&String> {
        name.as_ref().and_then(|n| self.renames.get(n))
    }
}

impl Visitor for TableRenamer {
    /// A column or `*` with its table qualifier renamed.
    ///
    /// PHP: `TableRenamer::visitExpression()`.
    fn visit_expression(&mut self, expression: Expression) -> Result<Expression> {
        Ok(match expression {
            Expression::Column(c) => match self.renamed(&c.table) {
                Some(table) => Column { name: c.name, table: Some(table.clone()), schema: c.schema }.into(),
                None => Expression::Column(c),
            },
            Expression::Star(s) => match self.renamed(&s.table) {
                Some(table) => Star { table: Some(table.clone()), schema: s.schema }.into(),
                None => Expression::Star(s),
            },
            other => other,
        })
    }

    /// The table with its name and alias renamed.
    ///
    /// PHP: `TableRenamer::visitTableReference()`.
    fn visit_table_reference(&mut self, reference: Table) -> Result<Table> {
        let name = self.renames.get(&reference.name).cloned();
        let alias = self.renamed(&reference.alias).cloned();
        if name.is_none() && alias.is_none() {
            return Ok(reference);
        }
        Ok(Table { name: name.unwrap_or(reference.name), alias: alias.or(reference.alias), schema: reference.schema })
    }
}
