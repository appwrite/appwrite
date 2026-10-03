//! Builder supporting types.

use crate::error::QueryError;
use crate::value::QueryValue;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum DialectKind {
    Mysql,
    Mariadb,
    Postgres,
    Sqlite,
    Clickhouse,
    Mongodb,
}

impl DialectKind {
    pub fn wrap_char(self) -> char {
        match self {
            Self::Postgres | Self::Sqlite => '"',
            Self::Mongodb => '\0',
            _ => '`',
        }
    }

    pub fn class_name(self) -> &'static str {
        match self {
            Self::Mysql => "Utopia\\Query\\Builder\\MySQL",
            Self::Mariadb => "Utopia\\Query\\Builder\\MariaDB",
            Self::Postgres => "Utopia\\Query\\Builder\\PostgreSQL",
            Self::Sqlite => "Utopia\\Query\\Builder\\SQLite",
            Self::Clickhouse => "Utopia\\Query\\Builder\\ClickHouse",
            Self::Mongodb => "Utopia\\Query\\Builder\\MongoDB",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum JoinType {
    Inner,
    Left,
    Right,
    Cross,
    FullOuter,
    Natural,
}

impl JoinType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Inner => "JOIN",
            Self::Left => "LEFT JOIN",
            Self::Right => "RIGHT JOIN",
            Self::Cross => "CROSS JOIN",
            Self::FullOuter => "FULL OUTER JOIN",
            Self::Natural => "NATURAL JOIN",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum LockMode {
    ForUpdate,
    ForShare,
    ForUpdateSkipLocked,
    ForUpdateNoWait,
    ForShareSkipLocked,
    ForShareNoWait,
}

impl LockMode {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::ForUpdate => "FOR UPDATE",
            Self::ForShare => "FOR SHARE",
            Self::ForUpdateSkipLocked => "FOR UPDATE SKIP LOCKED",
            Self::ForUpdateNoWait => "FOR UPDATE NOWAIT",
            Self::ForShareSkipLocked => "FOR SHARE SKIP LOCKED",
            Self::ForShareNoWait => "FOR SHARE NOWAIT",
        }
    }

    pub fn to_sql(self) -> &'static str {
        self.as_str()
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum UnionType {
    Union,
    UnionAll,
    Intersect,
    IntersectAll,
    Except,
    ExceptAll,
}

impl UnionType {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Union => "UNION",
            Self::UnionAll => "UNION ALL",
            Self::Intersect => "INTERSECT",
            Self::IntersectAll => "INTERSECT ALL",
            Self::Except => "EXCEPT",
            Self::ExceptAll => "EXCEPT ALL",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum VectorMetric {
    Cosine,
    Euclidean,
    Dot,
}

impl VectorMetric {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Cosine => "cosine",
            Self::Euclidean => "euclidean",
            Self::Dot => "dot",
        }
    }

    pub fn to_operator(self) -> &'static str {
        match self {
            Self::Cosine => "<=>",
            Self::Euclidean => "<->",
            Self::Dot => "<#>",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum CaseKind {
    Comparison,
    Null,
    NotNull,
    In,
    Raw,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum CaseOperator {
    Equal,
    NotEqual,
    LessThan,
    LessThanEqual,
    GreaterThan,
    GreaterThanEqual,
}

impl CaseOperator {
    pub fn sql_operator(self) -> &'static str {
        match self {
            Self::Equal => "=",
            Self::NotEqual => "!=",
            Self::LessThan => "<",
            Self::LessThanEqual => "<=",
            Self::GreaterThan => ">",
            Self::GreaterThanEqual => ">=",
        }
    }

    pub fn as_str(self) -> &'static str {
        match self {
            Self::Equal => "equal",
            Self::NotEqual => "notEqual",
            Self::LessThan => "lessThan",
            Self::LessThanEqual => "lessThanEqual",
            Self::GreaterThan => "greaterThan",
            Self::GreaterThanEqual => "greaterThanEqual",
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Binding {
    pub value: QueryValue,
    pub column: Option<String>,
}

impl Binding {
    pub fn new(value: impl Into<QueryValue>, column: Option<String>) -> Self {
        Self {
            value: value.into(),
            column,
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct Condition {
    pub expression: String,
    pub bindings: Vec<QueryValue>,
}

impl Condition {
    pub fn new(expression: impl Into<String>, bindings: Vec<QueryValue>) -> Self {
        Self {
            expression: expression.into(),
            bindings,
        }
    }

    pub fn expr(expression: impl Into<String>) -> Self {
        Self::new(expression, Vec::new())
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct JoinOn {
    pub left: String,
    pub operator: String,
    pub right: String,
}

#[derive(Debug, Clone, PartialEq, Default)]
pub struct JoinBuilder {
    pub ons: Vec<JoinOn>,
    pub wheres: Vec<Condition>,
}

impl JoinBuilder {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn on(
        &mut self,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
    ) -> Result<&mut Self, QueryError> {
        let left = left.into();
        let right = right.into();
        let operator = operator.into();
        validate_ident(&left)?;
        validate_ident(&right)?;
        validate_join_op(&operator)?;
        self.ons.push(JoinOn {
            left,
            operator,
            right,
        });
        Ok(self)
    }

    pub fn on_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.wheres.push(Condition::new(expression, bindings));
        self
    }

    pub fn where_col(
        &mut self,
        column: impl Into<String>,
        operator: impl Into<String>,
        value: impl Into<QueryValue>,
    ) -> Result<&mut Self, QueryError> {
        let column = column.into();
        let operator = operator.into();
        validate_ident(&column)?;
        validate_join_op(&operator)?;
        self.wheres.push(Condition::new(
            format!("{column} {operator} ?"),
            vec![value.into()],
        ));
        Ok(self)
    }

    pub fn where_raw(
        &mut self,
        expression: impl Into<String>,
        bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.wheres.push(Condition::new(expression, bindings));
        self
    }
}

fn validate_ident(name: &str) -> Result<(), QueryError> {
    let ok = name.starts_with(|c: char| c.is_ascii_alphabetic() || c == '_')
        && name
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '.');
    if ok {
        Ok(())
    } else {
        Err(QueryError::validation(format!(
            "Invalid column name: {name}"
        )))
    }
}

fn validate_join_op(op: &str) -> Result<(), QueryError> {
    if matches!(op, "=" | "!=" | "<" | ">" | "<=" | ">=" | "<>") {
        Ok(())
    } else {
        Err(QueryError::validation(format!(
            "Invalid join operator: {op}"
        )))
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct WindowFrame {
    pub frame_type: String,
    pub start: String,
    pub end: Option<String>,
}

impl WindowFrame {
    pub fn new(
        frame_type: impl Into<String>,
        start: impl Into<String>,
        end: Option<String>,
    ) -> Self {
        Self {
            frame_type: frame_type.into(),
            start: start.into(),
            end,
        }
    }

    pub fn to_sql(&self) -> String {
        match &self.end {
            None => format!("{} {}", self.frame_type, self.start),
            Some(end) => format!("{} BETWEEN {} AND {}", self.frame_type, self.start, end),
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct WindowSelect {
    pub function: String,
    pub alias: String,
    pub window_name: Option<String>,
    pub partition_by: Option<Vec<String>>,
    pub order_by: Option<Vec<String>>,
    pub frame: Option<WindowFrame>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct WindowDefinition {
    pub name: String,
    pub partition_by: Option<Vec<String>>,
    pub order_by: Option<Vec<String>>,
    pub frame: Option<WindowFrame>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct CteClause {
    pub name: String,
    pub query: String,
    pub bindings: Vec<QueryValue>,
    pub recursive: bool,
    pub columns: Vec<String>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct UnionClause {
    pub union_type: UnionType,
    pub query: String,
    pub bindings: Vec<QueryValue>,
}

#[derive(Debug, Clone, PartialEq)]
pub struct ColumnPredicate {
    pub left: String,
    pub operator: String,
    pub right: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum MongoOperation {
    InsertMany,
    UpdateMany,
    DeleteMany,
    UpdateOne,
    Find,
    Aggregate,
}

impl MongoOperation {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::InsertMany => "insertMany",
            Self::UpdateMany => "updateMany",
            Self::DeleteMany => "deleteMany",
            Self::UpdateOne => "updateOne",
            Self::Find => "find",
            Self::Aggregate => "aggregate",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum UpdateOperator {
    Set,
    Unset,
    Inc,
    Mul,
    Min,
    Max,
    Rename,
    SetOnInsert,
    CurrentDate,
    Push,
    Pull,
    AddToSet,
    Pop,
    PullAll,
}

impl UpdateOperator {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Set => "$set",
            Self::Unset => "$unset",
            Self::Inc => "$inc",
            Self::Mul => "$mul",
            Self::Min => "$min",
            Self::Max => "$max",
            Self::Rename => "$rename",
            Self::SetOnInsert => "$setOnInsert",
            Self::CurrentDate => "$currentDate",
            Self::Push => "$push",
            Self::Pull => "$pull",
            Self::AddToSet => "$addToSet",
            Self::Pop => "$pop",
            Self::PullAll => "$pullAll",
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct SpatialDistanceFilter {
    pub geometry: QueryValue,
    pub distance: QueryValue,
    pub meters: bool,
}

impl SpatialDistanceFilter {
    pub fn from_tuple(tuple: &QueryValue) -> Result<Self, QueryError> {
        let items = tuple
            .as_array()
            .ok_or_else(|| QueryError::validation("Invalid spatial distance tuple"))?;
        Ok(Self {
            geometry: items.first().cloned().unwrap_or(QueryValue::Null),
            distance: items.get(1).cloned().unwrap_or(QueryValue::Null),
            meters: items.get(2).and_then(QueryValue::as_bool).unwrap_or(false),
        })
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct WhenClause {
    pub kind: CaseKind,
    pub column: Option<String>,
    pub operator: Option<CaseOperator>,
    pub value: QueryValue,
    pub then: QueryValue,
    pub values: Vec<QueryValue>,
    pub raw_condition: Option<String>,
    pub raw_bindings: Vec<QueryValue>,
}

#[derive(Debug, Clone, PartialEq, Default)]
pub struct CaseExpression {
    pub whens: Vec<WhenClause>,
    pub has_else: bool,
    pub else_value: QueryValue,
    pub alias: String,
}

impl CaseExpression {
    pub fn new() -> Self {
        Self::default()
    }

    pub fn when(
        &mut self,
        column: impl Into<String>,
        operator: CaseOperator,
        value: impl Into<QueryValue>,
        then: impl Into<QueryValue>,
    ) -> &mut Self {
        self.whens.push(WhenClause {
            kind: CaseKind::Comparison,
            column: Some(column.into()),
            operator: Some(operator),
            value: value.into(),
            then: then.into(),
            values: Vec::new(),
            raw_condition: None,
            raw_bindings: Vec::new(),
        });
        self
    }

    pub fn when_null(
        &mut self,
        column: impl Into<String>,
        then: impl Into<QueryValue>,
    ) -> &mut Self {
        self.whens.push(WhenClause {
            kind: CaseKind::Null,
            column: Some(column.into()),
            operator: None,
            value: QueryValue::Null,
            then: then.into(),
            values: Vec::new(),
            raw_condition: None,
            raw_bindings: Vec::new(),
        });
        self
    }

    pub fn when_not_null(
        &mut self,
        column: impl Into<String>,
        then: impl Into<QueryValue>,
    ) -> &mut Self {
        self.whens.push(WhenClause {
            kind: CaseKind::NotNull,
            column: Some(column.into()),
            operator: None,
            value: QueryValue::Null,
            then: then.into(),
            values: Vec::new(),
            raw_condition: None,
            raw_bindings: Vec::new(),
        });
        self
    }

    pub fn when_in(
        &mut self,
        column: impl Into<String>,
        values: Vec<QueryValue>,
        then: impl Into<QueryValue>,
    ) -> Result<&mut Self, QueryError> {
        if values.is_empty() {
            return Err(QueryError::validation(
                "whenIn() requires at least one value.",
            ));
        }
        self.whens.push(WhenClause {
            kind: CaseKind::In,
            column: Some(column.into()),
            operator: None,
            value: QueryValue::Null,
            then: then.into(),
            values,
            raw_condition: None,
            raw_bindings: Vec::new(),
        });
        Ok(self)
    }

    pub fn when_raw(
        &mut self,
        condition: impl Into<String>,
        then: impl Into<QueryValue>,
        condition_bindings: Vec<QueryValue>,
    ) -> &mut Self {
        self.whens.push(WhenClause {
            kind: CaseKind::Raw,
            column: None,
            operator: None,
            value: QueryValue::Null,
            then: then.into(),
            values: Vec::new(),
            raw_condition: Some(condition.into()),
            raw_bindings: condition_bindings,
        });
        self
    }

    pub fn else_value(&mut self, value: impl Into<QueryValue>) -> &mut Self {
        self.has_else = true;
        self.else_value = value.into();
        self
    }

    pub fn alias(&mut self, alias: impl Into<String>) -> &mut Self {
        self.alias = alias.into();
        self
    }

    pub fn get_whens(&self) -> &[WhenClause] {
        &self.whens
    }

    pub fn has_else(&self) -> bool {
        self.has_else
    }

    pub fn get_else(&self) -> &QueryValue {
        &self.else_value
    }

    pub fn get_alias(&self) -> &str {
        &self.alias
    }
}
