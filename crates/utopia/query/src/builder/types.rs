//! The values builders produce and keep: statements, conditions, join and
//! window definitions.

use std::fmt;
use std::sync::Arc;

use crate::error::{Error, Result};
use crate::method::backed_enum;
use crate::value::{Array, Value};

/// What runs a statement: given it, returns its rows (an array) or an
/// affected-row count (an integer).
///
/// PHP: the `Closure(Statement): (array|int)` of `setExecutor()` / `withExecutor()`.
pub type Executor = Arc<dyn Fn(&Statement) -> Result<Value> + Send + Sync>;

/// A compiled statement: the SQL (or MongoDB command JSON) with `?`
/// placeholders and the values bound to them, in order.
///
/// PHP: `Utopia\Query\Builder\Statement`.
#[derive(Clone)]
pub struct Statement {
    /// The statement text.
    pub query: String,
    /// The values of the placeholders, in order.
    pub bindings: Vec<Value>,
    /// Whether the statement only reads.
    pub read_only: bool,
    /// Named parameters (ClickHouse's `{name:Type}` placeholders), if any.
    pub named_bindings: Option<Array>,
    executor: Option<Executor>,
}

impl fmt::Debug for Statement {
    /// The statement without its executor.
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("Statement")
            .field("query", &self.query)
            .field("bindings", &self.bindings)
            .field("read_only", &self.read_only)
            .field("named_bindings", &self.named_bindings)
            .field("executor", &self.executor.is_some())
            .finish()
    }
}

impl PartialEq for Statement {
    /// Same text, bindings and flags (executors are not compared).
    fn eq(&self, other: &Self) -> bool {
        self.query == other.query
            && self.bindings == other.bindings
            && self.read_only == other.read_only
            && self.named_bindings == other.named_bindings
    }
}

impl Statement {
    /// A statement that is not read-only and has no executor.
    ///
    /// PHP: `new Statement($query, $bindings)`.
    pub fn new(query: impl Into<String>, bindings: Vec<Value>) -> Self {
        Statement { query: query.into(), bindings, read_only: false, named_bindings: None, executor: None }
    }

    /// Every part of a statement.
    ///
    /// PHP: `new Statement($query, $bindings, $readOnly, $executor, $namedBindings)`.
    pub fn with_parts(
        query: impl Into<String>,
        bindings: Vec<Value>,
        read_only: bool,
        executor: Option<Executor>,
        named_bindings: Option<Array>,
    ) -> Self {
        Statement { query: query.into(), bindings, read_only, named_bindings, executor }
    }

    /// Runs the statement with its executor.
    ///
    /// PHP: `execute()`, which throws `BadMethodCallException` ([`Error::NoExecutor`]) without one.
    pub fn execute(&self) -> Result<Value> {
        match &self.executor {
            Some(executor) => executor(self),
            None => Err(Error::NoExecutor("No executor configured on this plan".into())),
        }
    }

    /// The same statement run by `executor`.
    ///
    /// PHP: `withExecutor()`.
    pub fn with_executor(&self, executor: Executor) -> Statement {
        Statement { executor: Some(executor), ..self.clone() }
    }

    /// The executor, if any.
    pub fn executor(&self) -> Option<&Executor> {
        self.executor.as_ref()
    }
}

/// A binding captured while compiling: the value and the column it was
/// compared to, when known (ClickHouse types named parameters by column).
///
/// PHP: `Utopia\Query\Builder\Binding`.
#[derive(Debug, Clone, PartialEq)]
pub struct Binding {
    /// The value.
    pub value: Value,
    /// The raw column name, if the value was bound against one.
    pub column: Option<String>,
}

impl Binding {
    /// A binding of `value` against `column`.
    pub fn new(value: Value, column: Option<String>) -> Self {
        Binding { value, column }
    }
}

/// A SQL fragment with its bindings.
///
/// PHP: `Utopia\Query\Builder\Condition`.
#[derive(Debug, Clone, PartialEq)]
pub struct Condition {
    /// The SQL, with `?` placeholders.
    pub expression: String,
    /// The values of the placeholders.
    pub bindings: Vec<Value>,
}

impl Condition {
    /// A fragment with its bindings.
    pub fn new(expression: impl Into<String>, bindings: Vec<Value>) -> Self {
        Condition { expression: expression.into(), bindings }
    }
}

/// `left operator right`, both columns.
///
/// PHP: `Utopia\Query\Builder\ColumnPredicate`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ColumnPredicate {
    /// The left column.
    pub left: String,
    /// The operator.
    pub operator: String,
    /// The right column.
    pub right: String,
}

impl ColumnPredicate {
    /// A column comparison.
    pub fn new(left: impl Into<String>, operator: impl Into<String>, right: impl Into<String>) -> Self {
        ColumnPredicate { left: left.into(), operator: operator.into(), right: right.into() }
    }
}

/// A common table expression, compiled.
///
/// PHP: `Utopia\Query\Builder\CteClause`.
#[derive(Debug, Clone, PartialEq)]
pub struct CteClause {
    /// The name.
    pub name: String,
    /// The SQL.
    pub query: String,
    /// The bindings of the SQL.
    pub bindings: Vec<Value>,
    /// Whether it is recursive.
    pub recursive: bool,
    /// The column names, if given.
    pub columns: Vec<String>,
}

impl CteClause {
    /// A compiled CTE.
    pub fn new(
        name: impl Into<String>,
        query: impl Into<String>,
        bindings: Vec<Value>,
        recursive: bool,
        columns: Vec<String>,
    ) -> Self {
        CteClause { name: name.into(), query: query.into(), bindings, recursive, columns }
    }
}

/// A column-to-column condition of a join built with a [`JoinBuilder`](super::JoinBuilder).
///
/// PHP: `Utopia\Query\Builder\JoinOn`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct JoinOn {
    /// The left column.
    pub left: String,
    /// The operator.
    pub operator: String,
    /// The right column.
    pub right: String,
}

impl JoinOn {
    /// A join condition.
    pub fn new(left: impl Into<String>, operator: impl Into<String>, right: impl Into<String>) -> Self {
        JoinOn { left: left.into(), operator: operator.into(), right: right.into() }
    }
}

backed_enum! {
    /// A kind of join, as its SQL keyword.
    ///
    /// PHP: `Utopia\Query\Builder\JoinType`.
    pub enum JoinType: "Utopia\\Query\\Builder\\JoinType" {
        /// `JOIN`.
        Inner = "JOIN",
        /// `LEFT JOIN`.
        Left = "LEFT JOIN",
        /// `RIGHT JOIN`.
        Right = "RIGHT JOIN",
        /// `CROSS JOIN`.
        Cross = "CROSS JOIN",
        /// `FULL OUTER JOIN`.
        FullOuter = "FULL OUTER JOIN",
        /// `NATURAL JOIN`.
        Natural = "NATURAL JOIN",
    }
}

backed_enum! {
    /// A row lock, as its SQL.
    ///
    /// PHP: `Utopia\Query\Builder\LockMode`.
    pub enum LockMode: "Utopia\\Query\\Builder\\LockMode" {
        /// `FOR UPDATE`.
        ForUpdate = "FOR UPDATE",
        /// `FOR SHARE`.
        ForShare = "FOR SHARE",
        /// `FOR UPDATE SKIP LOCKED`.
        ForUpdateSkipLocked = "FOR UPDATE SKIP LOCKED",
        /// `FOR UPDATE NOWAIT`.
        ForUpdateNoWait = "FOR UPDATE NOWAIT",
        /// `FOR SHARE SKIP LOCKED`.
        ForShareSkipLocked = "FOR SHARE SKIP LOCKED",
        /// `FOR SHARE NOWAIT`.
        ForShareNoWait = "FOR SHARE NOWAIT",
    }
}

impl LockMode {
    /// The SQL.
    ///
    /// PHP: `toSql()`.
    pub fn to_sql(self) -> &'static str {
        self.as_str()
    }
}

backed_enum! {
    /// How a compound select combines its members.
    ///
    /// PHP: `Utopia\Query\Builder\UnionType`.
    pub enum UnionType: "Utopia\\Query\\Builder\\UnionType" {
        /// `UNION`.
        Union = "UNION",
        /// `UNION ALL`.
        UnionAll = "UNION ALL",
        /// `INTERSECT`.
        Intersect = "INTERSECT",
        /// `INTERSECT ALL`.
        IntersectAll = "INTERSECT ALL",
        /// `EXCEPT`.
        Except = "EXCEPT",
        /// `EXCEPT ALL`.
        ExceptAll = "EXCEPT ALL",
    }
}

/// A compiled member of a compound select.
///
/// PHP: `Utopia\Query\Builder\UnionClause`.
#[derive(Debug, Clone, PartialEq)]
pub struct UnionClause {
    /// How it combines.
    pub union_type: UnionType,
    /// The SQL.
    pub query: String,
    /// Its bindings.
    pub bindings: Vec<Value>,
}

impl UnionClause {
    /// A compiled member.
    pub fn new(union_type: UnionType, query: impl Into<String>, bindings: Vec<Value>) -> Self {
        UnionClause { union_type, query: query.into(), bindings }
    }
}

backed_enum! {
    /// A vector distance metric (pgvector).
    ///
    /// PHP: `Utopia\Query\Builder\VectorMetric`.
    pub enum VectorMetric: "Utopia\\Query\\Builder\\VectorMetric" {
        /// Cosine distance.
        Cosine = "cosine",
        /// Euclidean distance.
        Euclidean = "euclidean",
        /// Negative inner product.
        Dot = "dot",
    }
}

impl VectorMetric {
    /// The pgvector operator: `<=>`, `<->` or `<#>`.
    ///
    /// PHP: `toOperator()`.
    pub fn to_operator(self) -> &'static str {
        match self {
            VectorMetric::Cosine => "<=>",
            VectorMetric::Euclidean => "<->",
            VectorMetric::Dot => "<#>",
        }
    }
}

/// A window frame: `ROWS UNBOUNDED PRECEDING`, `RANGE BETWEEN ... AND ...`.
///
/// PHP: `Utopia\Query\Builder\WindowFrame`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct WindowFrame {
    /// `ROWS`, `RANGE` or `GROUPS`.
    pub frame_type: String,
    /// The start bound.
    pub start: String,
    /// The end bound, if any.
    pub end: Option<String>,
}

impl WindowFrame {
    /// A frame.
    pub fn new(frame_type: impl Into<String>, start: impl Into<String>, end: Option<String>) -> Self {
        WindowFrame { frame_type: frame_type.into(), start: start.into(), end }
    }

    /// The SQL.
    ///
    /// PHP: `toSql()`.
    pub fn to_sql(&self) -> String {
        match &self.end {
            None => format!("{} {}", self.frame_type, self.start),
            Some(end) => format!("{} BETWEEN {} AND {}", self.frame_type, self.start, end),
        }
    }
}

/// A named window: `WINDOW name AS (...)`.
///
/// PHP: `Utopia\Query\Builder\WindowDefinition`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct WindowDefinition {
    /// The name.
    pub name: String,
    /// The partition columns, if any.
    pub partition_by: Option<Vec<String>>,
    /// The order columns (`-col` descending), if any.
    pub order_by: Option<Vec<String>>,
    /// The frame, if any.
    pub frame: Option<WindowFrame>,
}

impl WindowDefinition {
    /// A named window.
    pub fn new(
        name: impl Into<String>,
        partition_by: Option<Vec<String>>,
        order_by: Option<Vec<String>>,
        frame: Option<WindowFrame>,
    ) -> Self {
        WindowDefinition { name: name.into(), partition_by, order_by, frame }
    }
}

/// A window function in the select list.
///
/// PHP: `Utopia\Query\Builder\WindowSelect`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct WindowSelect {
    /// The function call (`ROW_NUMBER()`).
    pub function: String,
    /// The alias.
    pub alias: String,
    /// The partition columns, if any.
    pub partition_by: Option<Vec<String>>,
    /// The order columns (`-col` descending), if any.
    pub order_by: Option<Vec<String>>,
    /// A named window used instead of an inline one.
    pub window_name: Option<String>,
    /// The frame, if any.
    pub frame: Option<WindowFrame>,
}

impl WindowSelect {
    /// A window function.
    pub fn new(
        function: impl Into<String>,
        alias: impl Into<String>,
        partition_by: Option<Vec<String>>,
        order_by: Option<Vec<String>>,
        window_name: Option<String>,
        frame: Option<WindowFrame>,
    ) -> Self {
        WindowSelect { function: function.into(), alias: alias.into(), partition_by, order_by, window_name, frame }
    }
}

/// A `MERGE` action and its bindings.
///
/// PHP: `Utopia\Query\Builder\MergeClause`.
#[derive(Debug, Clone, PartialEq)]
pub struct MergeClause {
    /// The action SQL.
    pub action: String,
    /// Whether it applies to matched rows.
    pub matched: bool,
    /// Its bindings.
    pub bindings: Vec<Value>,
}

impl MergeClause {
    /// A merge action.
    pub fn new(action: impl Into<String>, matched: bool, bindings: Vec<Value>) -> Self {
        MergeClause { action: action.into(), matched, bindings }
    }
}

/// The geometry, distance and unit of a `distance*` query.
///
/// PHP: `Utopia\Query\Builder\SpatialDistanceFilter`.
#[derive(Debug, Clone, PartialEq)]
pub struct SpatialDistanceFilter {
    /// WKT, or a coordinate array.
    pub geometry: Value,
    /// The distance threshold.
    pub distance: f64,
    /// Whether the distance is in meters (sphere / geography).
    pub meters: bool,
}

impl SpatialDistanceFilter {
    /// A distance filter.
    pub fn new(geometry: Value, distance: f64, meters: bool) -> Self {
        SpatialDistanceFilter { geometry, distance, meters }
    }

    /// The filter from the `[geometry, distance, meters]` value of a `distance*` query.
    ///
    /// PHP: `SpatialDistanceFilter::fromTuple()`.
    /// Fails with PHP's `TypeError` when the geometry is not a string or an
    /// array (other scalars become strings), or `meters` is not a scalar.
    pub fn from_tuple(tuple: &Array) -> crate::Result<Self> {
        const NEW: &str = "Utopia\\Query\\Builder\\SpatialDistanceFilter::__construct";
        const FILE: &str = "Builder/SpatialDistanceFilter.php";
        let at = |i: i64| tuple.get(&php_std::ArrayKey::Int(i)).unwrap_or(&Value::Null);
        let distance = at(1).to_double();
        let geometry = crate::value::Param::method(NEW, 1, "geometry", FILE, 26).array_or_string(at(0))?;
        let meters = crate::value::Param::method(NEW, 3, "meters", FILE, 26).bool(at(2))?;
        Ok(SpatialDistanceFilter { geometry, distance, meters })
    }
}
