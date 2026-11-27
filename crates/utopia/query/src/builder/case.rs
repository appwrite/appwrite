//! [`CaseExpression`]: `CASE WHEN ... THEN ... ELSE ... END`, built
//! clause by clause and compiled with bindings by a builder.

use crate::error::{Error, Result};
use crate::method::backed_enum;
use crate::value::{IntoValue, IntoValues, Value};

backed_enum! {
    /// What a `WHEN` clause tests.
    ///
    /// PHP: `Utopia\Query\Builder\Case\Kind`.
    pub enum Kind: "Utopia\\Query\\Builder\\Case\\Kind" {
        /// `column operator ?`.
        Comparison = "comparison",
        /// `column IS NULL`.
        Null = "null",
        /// `column IS NOT NULL`.
        NotNull = "notNull",
        /// `column IN (?, ...)`.
        In = "in",
        /// Raw SQL with bindings.
        Raw = "raw",
    }
}

backed_enum! {
    /// A comparison of a `WHEN` clause.
    ///
    /// PHP: `Utopia\Query\Builder\Case\Operator`.
    pub enum Operator: "Utopia\\Query\\Builder\\Case\\Operator" {
        /// `=`.
        Equal = "equal",
        /// `!=`.
        NotEqual = "notEqual",
        /// `<`.
        LessThan = "lessThan",
        /// `<=`.
        LessThanEqual = "lessThanEqual",
        /// `>`.
        GreaterThan = "greaterThan",
        /// `>=`.
        GreaterThanEqual = "greaterThanEqual",
    }
}

impl Operator {
    /// The SQL operator.
    ///
    /// PHP: `sqlOperator()`.
    pub fn sql_operator(self) -> &'static str {
        match self {
            Operator::Equal => "=",
            Operator::NotEqual => "!=",
            Operator::LessThan => "<",
            Operator::LessThanEqual => "<=",
            Operator::GreaterThan => ">",
            Operator::GreaterThanEqual => ">=",
        }
    }
}

/// One `WHEN ... THEN ...` clause.
///
/// PHP: `Utopia\Query\Builder\Case\WhenClause`.
#[derive(Debug, Clone, PartialEq)]
pub struct WhenClause {
    /// What the clause tests.
    pub kind: Kind,
    /// The column tested (not for raw clauses).
    pub column: Option<String>,
    /// The comparison (comparison clauses).
    pub operator: Option<Operator>,
    /// The value compared to (comparison clauses).
    pub value: Value,
    /// The result when the clause matches.
    pub then: Value,
    /// The values of an `IN` clause.
    pub values: Vec<Value>,
    /// The SQL of a raw clause.
    pub raw_condition: Option<String>,
    /// The bindings of a raw clause.
    pub raw_bindings: Vec<Value>,
}

impl WhenClause {
    /// A clause from its parts.
    ///
    /// PHP: `new WhenClause(...)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        kind: Kind,
        column: Option<String>,
        operator: Option<Operator>,
        value: Value,
        then: Value,
        values: Vec<Value>,
        raw_condition: Option<String>,
        raw_bindings: Vec<Value>,
    ) -> Self {
        WhenClause { kind, column, operator, value, then, values, raw_condition, raw_bindings }
    }
}

/// A `CASE` expression: `WHEN` clauses in order, an optional `ELSE` and an alias.
///
/// PHP: `Utopia\Query\Builder\Case\Expression`.
///
/// ```
/// use utopia_query::builder::{CaseExpression, MySql, Operator};
///
/// let mut case = CaseExpression::new();
/// case.when("status", Operator::Equal, "active", 1).else_(0).alias("is_active");
/// let statement = MySql::new().from("users", "").select_case(case).build()?;
/// assert_eq!(statement.query, "SELECT CASE WHEN `status` = ? THEN ? ELSE ? END AS `is_active` FROM `users`");
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\Case\Expression;
/// use Utopia\Query\Builder\Case\Operator;
/// use Utopia\Query\Builder\MySQL;
///
/// $case = (new Expression())->when('status', Operator::Equal, 'active', 1)->else(0)->alias('is_active');
/// echo (new MySQL())->from('users')->selectCase($case)->build()->query, "\n";
/// ```
#[derive(Debug, Clone, PartialEq, Default)]
pub struct CaseExpression {
    whens: Vec<WhenClause>,
    has_else: bool,
    else_value: Value,
    alias: String,
}

impl CaseExpression {
    /// An empty `CASE`.
    pub fn new() -> Self {
        CaseExpression::default()
    }

    /// `WHEN column operator value THEN then`.
    ///
    /// PHP: `when()`.
    pub fn when(
        &mut self,
        column: impl Into<String>,
        operator: Operator,
        value: impl IntoValue,
        then: impl IntoValue,
    ) -> &mut Self {
        self.whens.push(WhenClause::new(
            Kind::Comparison,
            Some(column.into()),
            Some(operator),
            value.into_value(),
            then.into_value(),
            Vec::new(),
            None,
            Vec::new(),
        ));
        self
    }

    /// `WHEN column IS NULL THEN then`.
    ///
    /// PHP: `whenNull()`.
    pub fn when_null(&mut self, column: impl Into<String>, then: impl IntoValue) -> &mut Self {
        self.whens.push(WhenClause::new(
            Kind::Null,
            Some(column.into()),
            None,
            Value::Null,
            then.into_value(),
            Vec::new(),
            None,
            Vec::new(),
        ));
        self
    }

    /// `WHEN column IS NOT NULL THEN then`.
    ///
    /// PHP: `whenNotNull()`.
    pub fn when_not_null(&mut self, column: impl Into<String>, then: impl IntoValue) -> &mut Self {
        self.whens.push(WhenClause::new(
            Kind::NotNull,
            Some(column.into()),
            None,
            Value::Null,
            then.into_value(),
            Vec::new(),
            None,
            Vec::new(),
        ));
        self
    }

    /// `WHEN column IN (values) THEN then`; `values` must not be empty.
    ///
    /// PHP: `whenIn()`.
    pub fn when_in(
        &mut self,
        column: impl Into<String>,
        values: impl IntoValues,
        then: impl IntoValue,
    ) -> Result<&mut Self> {
        let values: Vec<Value> = values.into_values().values().cloned().collect();
        if values.is_empty() {
            return Err(Error::Validation("whenIn() requires at least one value.".into()));
        }
        self.whens.push(WhenClause::new(
            Kind::In,
            Some(column.into()),
            None,
            Value::Null,
            then.into_value(),
            values,
            None,
            Vec::new(),
        ));
        Ok(self)
    }

    /// `WHEN condition THEN then` with raw SQL and its bindings.
    ///
    /// PHP: `whenRaw()`.
    pub fn when_raw(
        &mut self,
        condition: impl Into<String>,
        then: impl IntoValue,
        bindings: impl IntoValues,
    ) -> &mut Self {
        self.whens.push(WhenClause::new(
            Kind::Raw,
            None,
            None,
            Value::Null,
            then.into_value(),
            Vec::new(),
            Some(condition.into()),
            bindings.into_values().values().cloned().collect(),
        ));
        self
    }

    /// `ELSE value`.
    ///
    /// PHP: `else()`.
    pub fn else_(&mut self, value: impl IntoValue) -> &mut Self {
        self.has_else = true;
        self.else_value = value.into_value();
        self
    }

    /// The alias (`AS alias`).
    ///
    /// PHP: `alias()`.
    pub fn alias(&mut self, alias: impl Into<String>) -> &mut Self {
        self.alias = alias.into();
        self
    }

    /// The `WHEN` clauses.
    ///
    /// PHP: `getWhens()`.
    pub fn whens(&self) -> &[WhenClause] {
        &self.whens
    }

    /// Whether an `ELSE` was set.
    ///
    /// PHP: `hasElse()`.
    pub fn has_else(&self) -> bool {
        self.has_else
    }

    /// The `ELSE` value (`null` when none).
    ///
    /// PHP: `getElse()`.
    pub fn else_value(&self) -> &Value {
        &self.else_value
    }

    /// The alias, or `""`.
    ///
    /// PHP: `getAlias()`.
    pub fn get_alias(&self) -> &str {
        &self.alias
    }
}
