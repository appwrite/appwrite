//! The nodes of the syntax tree.

use crate::enums::{NullsPosition, OrderDirection};
use crate::value::Value;

/// An expression: a column, a literal, an operation, a function call, a
/// subquery.
///
/// PHP: the `Utopia\Query\AST\Expression` interface; each variant is one of
/// its implementations.
#[derive(Debug, Clone, PartialEq)]
pub enum Expression {
    /// A function call.
    Func(Func),
    /// An expression with an alias.
    Aliased(Aliased),
    /// `x [NOT] BETWEEN low AND high`.
    Between(Between),
    /// A binary operation.
    Binary(Binary),
    /// One `WHEN ... THEN ...` of a `CASE`, on its own (no serializer
    /// writes it outside a [`Conditional`]).
    CaseWhen(CaseWhen),
    /// `CAST(x AS type)`.
    Cast(Cast),
    /// `CASE ... END`.
    Conditional(Conditional),
    /// `[NOT] EXISTS (subquery)`.
    Exists(Exists),
    /// `x [NOT] IN (...)`.
    In(In),
    /// A scalar subquery.
    Subquery(Subquery),
    /// A unary operation (prefix, or postfix like `IS NULL`).
    Unary(Unary),
    /// A window function call (`... OVER (...)`).
    Window(Window),
    /// A literal value.
    Literal(Literal),
    /// A bound parameter placeholder.
    Placeholder(Placeholder),
    /// SQL written as is.
    Raw(Raw),
    /// A column reference.
    Column(Column),
    /// `*` or `table.*`.
    Star(Star),
}

impl Expression {
    /// The PHP class of the node, relative to `Utopia\Query\AST\`.
    pub const fn php_class(&self) -> &'static str {
        match self {
            Expression::Func(_) => "Call\\Func",
            Expression::Aliased(_) => "Expression\\Aliased",
            Expression::Between(_) => "Expression\\Between",
            Expression::Binary(_) => "Expression\\Binary",
            Expression::CaseWhen(_) => "Expression\\CaseWhen",
            Expression::Cast(_) => "Expression\\Cast",
            Expression::Conditional(_) => "Expression\\Conditional",
            Expression::Exists(_) => "Expression\\Exists",
            Expression::In(_) => "Expression\\In",
            Expression::Subquery(_) => "Expression\\Subquery",
            Expression::Unary(_) => "Expression\\Unary",
            Expression::Window(_) => "Expression\\Window",
            Expression::Literal(_) => "Literal",
            Expression::Placeholder(_) => "Placeholder",
            Expression::Raw(_) => "Raw",
            Expression::Column(_) => "Reference\\Column",
            Expression::Star(_) => "Star",
        }
    }
}

macro_rules! into_expression {
    ($($ty:ident),*) => {$(
        impl From<$ty> for Expression {
            /// The node as an expression.
            fn from(node: $ty) -> Self {
                Expression::$ty(node)
            }
        }
    )*};
}

into_expression!(
    Func,
    Aliased,
    Between,
    Binary,
    CaseWhen,
    Cast,
    Conditional,
    Exists,
    In,
    Subquery,
    Unary,
    Window,
    Literal,
    Placeholder,
    Raw,
    Column,
    Star
);

fn boxed(expression: impl Into<Expression>) -> Box<Expression> {
    Box::new(expression.into())
}

/// A function call: `NAME([DISTINCT] args) [FILTER (WHERE ...)]`.
///
/// PHP: `Utopia\Query\AST\Call\Func` (`name`, `arguments`, `distinct`, `filter`).
#[derive(Debug, Clone, PartialEq)]
pub struct Func {
    /// The function name (the parser upper-cases it).
    pub name: String,
    /// The arguments.
    pub arguments: Vec<Expression>,
    /// Whether the arguments are `DISTINCT`.
    pub distinct: bool,
    /// The `FILTER (WHERE ...)` condition.
    pub filter: Option<Box<Expression>>,
}

impl Func {
    /// A call of `name` with `arguments`.
    ///
    /// PHP: `new Func($name, $arguments)`.
    pub fn new(name: impl Into<String>, arguments: Vec<Expression>) -> Self {
        Func { name: name.into(), arguments, distinct: false, filter: None }
    }
}

/// An expression with an alias: `expression AS alias`.
///
/// PHP: `Utopia\Query\AST\Expression\Aliased` (`expression`, `alias`).
#[derive(Debug, Clone, PartialEq)]
pub struct Aliased {
    /// The expression.
    pub expression: Box<Expression>,
    /// The alias.
    pub alias: String,
}

impl Aliased {
    /// `expression AS alias`.
    ///
    /// PHP: `new Aliased($expression, $alias)`.
    pub fn new(expression: impl Into<Expression>, alias: impl Into<String>) -> Self {
        Aliased { expression: boxed(expression), alias: alias.into() }
    }
}

/// `expression [NOT] BETWEEN low AND high`.
///
/// PHP: `Utopia\Query\AST\Expression\Between` (`expression`, `low`, `high`, `negated`).
#[derive(Debug, Clone, PartialEq)]
pub struct Between {
    /// The tested expression.
    pub expression: Box<Expression>,
    /// The lower bound.
    pub low: Box<Expression>,
    /// The upper bound.
    pub high: Box<Expression>,
    /// `NOT BETWEEN`.
    pub negated: bool,
}

impl Between {
    /// `expression BETWEEN low AND high`, or `NOT BETWEEN` when `negated`.
    ///
    /// PHP: `new Between($expression, $low, $high, $negated)`.
    pub fn new(
        expression: impl Into<Expression>,
        low: impl Into<Expression>,
        high: impl Into<Expression>,
        negated: bool,
    ) -> Self {
        Between { expression: boxed(expression), low: boxed(low), high: boxed(high), negated }
    }
}

/// A binary operation: `left operator right`.
///
/// PHP: `Utopia\Query\AST\Expression\Binary` (`left`, `operator`, `right`).
#[derive(Debug, Clone, PartialEq)]
pub struct Binary {
    /// The left operand.
    pub left: Box<Expression>,
    /// The operator (`=`, `AND`, `NOT LIKE`, `+`).
    pub operator: String,
    /// The right operand.
    pub right: Box<Expression>,
}

impl Binary {
    /// `left operator right`.
    ///
    /// PHP: `new Binary($left, $operator, $right)`.
    pub fn new(left: impl Into<Expression>, operator: impl Into<String>, right: impl Into<Expression>) -> Self {
        Binary { left: boxed(left), operator: operator.into(), right: boxed(right) }
    }
}

/// One `WHEN condition THEN result` of a `CASE`.
///
/// PHP: `Utopia\Query\AST\Expression\CaseWhen` (`condition`, `result`).
#[derive(Debug, Clone, PartialEq)]
pub struct CaseWhen {
    /// The `WHEN` condition (or value, for a `CASE` with an operand).
    pub condition: Box<Expression>,
    /// The `THEN` result.
    pub result: Box<Expression>,
}

impl CaseWhen {
    /// `WHEN condition THEN result`.
    ///
    /// PHP: `new CaseWhen($condition, $result)`.
    pub fn new(condition: impl Into<Expression>, result: impl Into<Expression>) -> Self {
        CaseWhen { condition: boxed(condition), result: boxed(result) }
    }
}

/// `CAST(expression AS type)` (or PostgreSQL's `expression::type`).
///
/// PHP: `Utopia\Query\AST\Expression\Cast` (`expression`, `type`).
#[derive(Debug, Clone, PartialEq)]
pub struct Cast {
    /// The expression.
    pub expression: Box<Expression>,
    /// The target type.
    ///
    /// PHP: `$type`.
    pub data_type: String,
}

impl Cast {
    /// `CAST(expression AS data_type)`.
    ///
    /// PHP: `new Cast($expression, $type)`.
    pub fn new(expression: impl Into<Expression>, data_type: impl Into<String>) -> Self {
        Cast { expression: boxed(expression), data_type: data_type.into() }
    }
}

/// `CASE [operand] WHEN ... THEN ... [ELSE ...] END`.
///
/// PHP: `Utopia\Query\AST\Expression\Conditional` (`operand`, `whens`, `else`).
#[derive(Debug, Clone, PartialEq)]
pub struct Conditional {
    /// The operand of a simple `CASE`.
    pub operand: Option<Box<Expression>>,
    /// The `WHEN` branches.
    pub whens: Vec<CaseWhen>,
    /// The `ELSE` result.
    ///
    /// PHP: `$else`.
    pub else_result: Option<Box<Expression>>,
}

/// `[NOT] EXISTS (subquery)`.
///
/// PHP: `Utopia\Query\AST\Expression\Exists` (`subquery`, `negated`).
#[derive(Debug, Clone, PartialEq)]
pub struct Exists {
    /// The subquery.
    pub subquery: Box<Select>,
    /// `NOT EXISTS`.
    pub negated: bool,
}

impl Exists {
    /// `EXISTS (subquery)`, or `NOT EXISTS` when `negated`.
    ///
    /// PHP: `new Exists($subquery, $negated)`.
    pub fn new(subquery: Select, negated: bool) -> Self {
        Exists { subquery: Box::new(subquery), negated }
    }
}

/// What an `IN` tests against.
///
/// PHP: `In::$list`, `Expression[]|Select`.
#[derive(Debug, Clone, PartialEq)]
pub enum InList {
    /// A list of expressions.
    Values(Vec<Expression>),
    /// A subquery.
    Subquery(Box<Select>),
}

/// `expression [NOT] IN (list)`.
///
/// PHP: `Utopia\Query\AST\Expression\In` (`expression`, `list`, `negated`).
#[derive(Debug, Clone, PartialEq)]
pub struct In {
    /// The tested expression.
    pub expression: Box<Expression>,
    /// The values or the subquery.
    pub list: InList,
    /// `NOT IN`.
    pub negated: bool,
}

impl In {
    /// `expression IN (list)`, or `NOT IN` when `negated`.
    ///
    /// PHP: `new In($expression, $list, $negated)`.
    pub fn new(expression: impl Into<Expression>, list: InList, negated: bool) -> Self {
        In { expression: boxed(expression), list, negated }
    }
}

/// A scalar subquery: `(SELECT ...)`.
///
/// PHP: `Utopia\Query\AST\Expression\Subquery` (`query`).
#[derive(Debug, Clone, PartialEq)]
pub struct Subquery {
    /// The query.
    pub query: Box<Select>,
}

impl Subquery {
    /// `(query)`.
    ///
    /// PHP: `new Subquery($query)`.
    pub fn new(query: Select) -> Self {
        Subquery { query: Box::new(query) }
    }
}

/// A unary operation: `operator (operand)` when `prefix`, else
/// `operand operator` (`IS NULL`, `IS NOT NULL`).
///
/// PHP: `Utopia\Query\AST\Expression\Unary` (`operator`, `operand`, `prefix`).
#[derive(Debug, Clone, PartialEq)]
pub struct Unary {
    /// The operator.
    pub operator: String,
    /// The operand.
    pub operand: Box<Expression>,
    /// Whether the operator comes first.
    pub prefix: bool,
}

impl Unary {
    /// A prefix operation (`prefix` true) or a postfix one.
    ///
    /// PHP: `new Unary($operator, $operand, $prefix)`.
    pub fn new(operator: impl Into<String>, operand: impl Into<Expression>, prefix: bool) -> Self {
        Unary { operator: operator.into(), operand: boxed(operand), prefix }
    }
}

/// A window function call: `function OVER name` or `function OVER (specification)`.
///
/// PHP: `Utopia\Query\AST\Expression\Window` (`function`, `windowName`, `specification`).
#[derive(Debug, Clone, PartialEq)]
pub struct Window {
    /// The function.
    pub function: Box<Expression>,
    /// A named window (`OVER w`).
    pub window_name: Option<String>,
    /// An inline window (`OVER (...)`), boxed to keep [`Expression`] small.
    pub specification: Option<Box<WindowSpecification>>,
}

/// A literal: a string, an integer, a float, a boolean or `NULL`.
///
/// PHP: `Utopia\Query\AST\Literal` (`value`, `string|int|float|bool|null`).
#[derive(Debug, Clone, PartialEq)]
pub struct Literal {
    /// The value: a scalar or [`Value::Null`].
    pub value: Value,
}

impl Literal {
    /// A literal of `value` (a scalar).
    ///
    /// PHP: `new Literal($value)`.
    pub fn new(value: impl Into<Value>) -> Self {
        Literal { value: value.into() }
    }
}

/// A bound parameter placeholder, written as is (`?`, `:name`, `$1`).
///
/// PHP: `Utopia\Query\AST\Placeholder` (`value`).
#[derive(Debug, Clone, PartialEq)]
pub struct Placeholder {
    /// The placeholder text.
    pub value: String,
}

impl Placeholder {
    /// A placeholder.
    ///
    /// PHP: `new Placeholder($value)`.
    pub fn new(value: impl Into<String>) -> Self {
        Placeholder { value: value.into() }
    }
}

/// SQL written as is.
///
/// PHP: `Utopia\Query\AST\Raw` (`sql`).
#[derive(Debug, Clone, PartialEq)]
pub struct Raw {
    /// The SQL.
    pub sql: String,
}

impl Raw {
    /// Raw SQL.
    ///
    /// PHP: `new Raw($sql)`.
    pub fn new(sql: impl Into<String>) -> Self {
        Raw { sql: sql.into() }
    }
}

/// A column reference: `[schema.][table.]name`.
///
/// PHP: `Utopia\Query\AST\Reference\Column` (`name`, `table`, `schema`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Column {
    /// The column name.
    pub name: String,
    /// The table (or alias) qualifier.
    pub table: Option<String>,
    /// The schema qualifier.
    pub schema: Option<String>,
}

impl Column {
    /// An unqualified column.
    ///
    /// PHP: `new Column($name)`.
    pub fn new(name: impl Into<String>) -> Self {
        Column { name: name.into(), table: None, schema: None }
    }

    /// A column of `table`.
    ///
    /// PHP: `new Column($name, $table)`.
    pub fn of(table: impl Into<String>, name: impl Into<String>) -> Self {
        Column { name: name.into(), table: Some(table.into()), schema: None }
    }
}

/// `*`, `table.*` or `schema.table.*`.
///
/// PHP: `Utopia\Query\AST\Star` (`table`, `schema`).
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct Star {
    /// The table qualifier.
    pub table: Option<String>,
    /// The schema qualifier.
    pub schema: Option<String>,
}

/// A table reference: `[schema.]name [AS alias]`.
///
/// PHP: `Utopia\Query\AST\Reference\Table` (`name`, `alias`, `schema`).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Table {
    /// The table name.
    pub name: String,
    /// The alias.
    pub alias: Option<String>,
    /// The schema qualifier.
    pub schema: Option<String>,
}

impl Table {
    /// A table without alias or schema.
    ///
    /// PHP: `new Table($name)`.
    pub fn new(name: impl Into<String>) -> Self {
        Table { name: name.into(), alias: None, schema: None }
    }
}

/// A subquery used as a table: `(SELECT ...) AS alias`.
///
/// PHP: `Utopia\Query\AST\SubquerySource` (`query`, `alias`).
#[derive(Debug, Clone, PartialEq)]
pub struct SubquerySource {
    /// The query.
    pub query: Box<Select>,
    /// The alias.
    pub alias: String,
}

impl SubquerySource {
    /// `(query) AS alias`.
    ///
    /// PHP: `new SubquerySource($query, $alias)`.
    pub fn new(query: Select, alias: impl Into<String>) -> Self {
        SubquerySource { query: Box::new(query), alias: alias.into() }
    }
}

/// What a `FROM` or a join reads: a table or a subquery.
///
/// PHP: `Table|SubquerySource`.
#[derive(Debug, Clone, PartialEq)]
pub enum TableSource {
    /// A table.
    Table(Table),
    /// A subquery.
    Subquery(SubquerySource),
}

impl From<Table> for TableSource {
    /// The table as a source.
    fn from(table: Table) -> Self {
        TableSource::Table(table)
    }
}

impl From<SubquerySource> for TableSource {
    /// The subquery as a source.
    fn from(source: SubquerySource) -> Self {
        TableSource::Subquery(source)
    }
}

/// A join: `TYPE source [ON condition]`.
///
/// PHP: `Utopia\Query\AST\JoinClause` (`type`, `table`, `condition`).
#[derive(Debug, Clone, PartialEq)]
pub struct JoinClause {
    /// The join keywords (`JOIN`, `LEFT JOIN`, `CROSS JOIN`).
    ///
    /// PHP: `$type`.
    pub kind: String,
    /// What is joined.
    pub table: TableSource,
    /// The `ON` condition.
    pub condition: Option<Expression>,
}

impl JoinClause {
    /// A join.
    ///
    /// PHP: `new JoinClause($type, $table, $condition)`.
    pub fn new(kind: impl Into<String>, table: impl Into<TableSource>, condition: Option<Expression>) -> Self {
        JoinClause { kind: kind.into(), table: table.into(), condition }
    }
}

/// One `ORDER BY` item: `expression ASC|DESC [NULLS FIRST|LAST]`.
///
/// PHP: `Utopia\Query\AST\OrderByItem` (`expression`, `direction`, `nulls`).
#[derive(Debug, Clone, PartialEq)]
pub struct OrderByItem {
    /// What is sorted.
    pub expression: Expression,
    /// The direction (`ASC` by default).
    pub direction: OrderDirection,
    /// Where `NULL`s go.
    pub nulls: Option<NullsPosition>,
}

impl OrderByItem {
    /// `expression direction`.
    ///
    /// PHP: `new OrderByItem($expression, $direction)`.
    pub fn new(expression: impl Into<Expression>, direction: OrderDirection) -> Self {
        OrderByItem { expression: expression.into(), direction, nulls: None }
    }
}

/// A common table expression: `name [(columns)] AS (query)`.
///
/// PHP: `Utopia\Query\AST\Definition\Cte` (`name`, `query`, `columns`, `recursive`).
#[derive(Debug, Clone, PartialEq)]
pub struct Cte {
    /// The name.
    pub name: String,
    /// The query.
    pub query: Select,
    /// The column names.
    pub columns: Vec<String>,
    /// Whether it is recursive (any recursive CTE makes the `WITH` recursive).
    pub recursive: bool,
}

impl Cte {
    /// `name AS (query)`.
    ///
    /// PHP: `new Cte($name, $query)`.
    pub fn new(name: impl Into<String>, query: Select) -> Self {
        Cte { name: name.into(), query, columns: Vec::new(), recursive: false }
    }
}

/// A named window: `WINDOW name AS (specification)`.
///
/// PHP: `Utopia\Query\AST\Definition\Window` (`name`, `specification`).
#[derive(Debug, Clone, PartialEq)]
pub struct WindowDefinition {
    /// The name.
    pub name: String,
    /// The window.
    pub specification: WindowSpecification,
}

/// A window: `[PARTITION BY ...] [ORDER BY ...] [ROWS|RANGE frame]`.
///
/// PHP: `Utopia\Query\AST\Specification\Window` (`partitionBy`, `orderBy`,
/// `frameType`, `frameStart`, `frameEnd`).
#[derive(Debug, Clone, PartialEq, Default)]
pub struct WindowSpecification {
    /// `PARTITION BY` expressions.
    pub partition_by: Vec<Expression>,
    /// `ORDER BY` items.
    pub order_by: Vec<OrderByItem>,
    /// `ROWS` or `RANGE`.
    pub frame_type: Option<String>,
    /// The frame start (`UNBOUNDED PRECEDING`), or its only bound.
    pub frame_start: Option<String>,
    /// The frame end, for `BETWEEN start AND end`.
    pub frame_end: Option<String>,
}

/// A `SELECT` statement.
///
/// The PHP class is immutable and copies itself with `with()`; here the
/// fields are public, so a changed copy is struct update syntax
/// (`Select { where_clause: Some(condition), ..select }`).
///
/// PHP: `Utopia\Query\AST\Statement\Select` (`columns`, `from`, `joins`,
/// `where`, `groupBy`, `having`, `orderBy`, `limit`, `offset`, `distinct`,
/// `ctes`, `windows`), and `Select::with()`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Select {
    /// The selected expressions.
    pub columns: Vec<Expression>,
    /// The `FROM` source.
    pub from: Option<TableSource>,
    /// The joins.
    pub joins: Vec<JoinClause>,
    /// The `WHERE` condition.
    ///
    /// PHP: `$where`.
    pub where_clause: Option<Expression>,
    /// The `GROUP BY` expressions.
    pub group_by: Vec<Expression>,
    /// The `HAVING` condition.
    pub having: Option<Expression>,
    /// The `ORDER BY` items.
    pub order_by: Vec<OrderByItem>,
    /// The `LIMIT`.
    pub limit: Option<Expression>,
    /// The `OFFSET`.
    pub offset: Option<Expression>,
    /// `SELECT DISTINCT`.
    pub distinct: bool,
    /// The `WITH` clauses.
    pub ctes: Vec<Cte>,
    /// The `WINDOW` definitions.
    pub windows: Vec<WindowDefinition>,
}
