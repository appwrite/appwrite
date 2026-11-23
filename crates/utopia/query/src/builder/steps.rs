//! The steps of compilation a subclass of a PHP builder may override.
//!
//! PHP subclasses a builder and overrides its public and protected methods
//! (`Utopia\Database\Builder\MySQL` does). Rust builders are not subclassed,
//! so a builder given [`Overrides`] asks them before it runs a [`Step`], and
//! runs its own when they decline. [`Builder::base`] runs a step without
//! asking, which is what `parent::step()` is.

use std::sync::Arc;

use crate::builder::case::CaseExpression;
use crate::builder::core::{Builder, Dialect};
use crate::builder::join::JoinBuilder;
use crate::builder::types::{Condition, Statement};
use crate::error::{Error, Result};
use crate::hook::Placement;
use crate::method::Method;
use crate::query::ParsedQuery;
use crate::query::Query;
use crate::value::{Array, Value};

/// A method of PHP's builders that a subclass can override.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Step {
    /// `compileFilter(Query $query): string`.
    CompileFilter,
    /// `compileOrder(Query $query): string`.
    CompileOrder,
    /// `compileLimit(Query $query): string`.
    CompileLimit,
    /// `compileOffset(Query $query): string`.
    CompileOffset,
    /// `compileSelect(Query $query): string`.
    CompileSelect,
    /// `compileCursor(Query $query): string`.
    CompileCursor,
    /// `compileAggregate(Query $query): string`.
    CompileAggregate,
    /// `compileGroupBy(Query $query): string`.
    CompileGroupBy,
    /// `compileJoin(Query $query): string`.
    CompileJoin,
    /// `compileIn(string $attribute, array $values, ?string $column = null): string`.
    CompileIn,
    /// `compileNotIn(string $attribute, array $values, ?string $column = null): string`.
    CompileNotIn,
    /// `compileRegex(string $attribute, array $values, ?string $column = null): string`.
    CompileRegex,
    /// `compileContains(string $attribute, array $values, ?string $column = null): string`.
    CompileContains,
    /// `compileContainsAll(string $attribute, array $values, ?string $column = null): string`.
    CompileContainsAll,
    /// `compileNotContains(string $attribute, array $values, ?string $column = null): string`.
    CompileNotContains,
    /// `compileSearchExpression(string $attribute, array $values, bool $not): string` (SQL dialects).
    CompileSearchExpression,
    /// `compileSpatialFilter(Method $method, string $attribute, Query $query): string` (SQL dialects).
    CompileSpatialFilter,
    /// `compileArrayFilter(Method $method, string $attribute, Query $query): string` (SQL dialects).
    CompileArrayFilter,
    /// `compileJsonContainsExpression(string $attribute, array $values, bool $not): string` (SQL dialects).
    CompileJsonContainsExpression,
    /// `compileJsonOverlapsExpression(string $attribute, array $values): string` (SQL dialects).
    CompileJsonOverlapsExpression,
    /// `compileJoinWithBuilder(Query $query, JoinBuilder $joinBuilder): string`.
    CompileJoinWithBuilder,
    /// `resolveAttribute(string $attribute): string`.
    ResolveAttribute,
    /// `resolveAndWrap(string $attribute): string`.
    ResolveAndWrap,
    /// `compileLike(string $attribute, array $values, string $prefix, string $suffix, bool $not, ?string $column = null): string`.
    CompileLike,
    /// `addBinding(mixed $value, ?string $column = null): void`.
    AddBinding,
    /// `build(): Statement`.
    Build,
    /// `compileRandom()`.
    CompileRandom,
    /// `buildTableClause()`.
    BuildTableClause,
    /// `buildAfterGroupByClause()`.
    BuildAfterGroupByClause,
    /// `buildAfterOrderByClause()`.
    BuildAfterOrderByClause,
    /// `buildSettingsClause()`.
    BuildSettingsClause,
    /// `wrapUnionMember()`.
    WrapUnionMember,
    /// `shouldEmitOffset()`.
    ShouldEmitOffset,
    /// `compileHavingCondition()`.
    CompileHavingCondition,
    /// `compileGroupByTimeBucket()`.
    CompileGroupByTimeBucket,
    /// `compileLikePredicate()`.
    CompileLikePredicate,
    /// `likeEscape()`.
    LikeEscape,
    /// `compileComparison()`.
    CompileComparison,
    /// `enclose()`.
    Enclose,
    /// `escapeLikeValue()`.
    EscapeLikeValue,
    /// `compileSpatialDistance()`.
    CompileSpatialDistance,
    /// `compileSpatialPredicate()`.
    CompileSpatialPredicate,
    /// `compileSpatialCoversPredicate()`.
    CompileSpatialCoversPredicate,
    /// `compileJsonPathExpression()`.
    CompileJsonPathExpression,
    /// `compileJsonFilter()`.
    CompileJsonFilter,
    /// `compileConflictHeader()`.
    CompileConflictHeader,
    /// `compileConflictAssignment()`.
    CompileConflictAssignment,
    /// `compileConflictClause()`.
    CompileConflictClause,
    /// `groupConcatExpr()`.
    GroupConcatExpr,
    /// `jsonArrayAggExpr()`.
    JsonArrayAggExpr,
    /// `jsonObjectAggExpr()`.
    JsonObjectAggExpr,
    /// `geomFromText()`.
    GeomFromText,
    /// `compileObjectFilter()`.
    CompileObjectFilter,
    /// `compileJsonbContainment()`.
    CompileJsonbContainment,
    /// `buildJsonbPath()`.
    BuildJsonbPath,
    /// `validateTable()`.
    ValidateTable,
    /// `validateRows()`.
    ValidateRows,
    /// `validateAndGetColumns()`.
    ValidateAndGetColumns,
    /// `addBindings()`.
    AddBindings,
    /// `compileCase()`.
    CompileCase,
    /// `compileInsertBody()`.
    CompileInsertBody,
    /// `compileAssignments()`.
    CompileAssignments,
    /// `compileWhereClauses()`.
    CompileWhereClauses,
    /// `compileOrderAndLimit()`.
    CompileOrderAndLimit,
    /// `buildAfterJoinsClause()`.
    BuildAfterJoinsClause,
    /// `compileVectorOrderExpression()`.
    CompileVectorOrderExpression,
    /// `resolveJoinFilterPlacement()`.
    ResolveJoinFilterPlacement,
    /// `appendReturning()`.
    AppendReturning,
    /// `resetReturning()`.
    ResetReturning,
    /// `resetGroupByModifier()`.
    ResetGroupByModifier,
    /// `applyNamedTypedBindings()`.
    ApplyNamedTypedBindings,
    /// `createTokenizer()`: asked to read a CTE's SQL with the tokenizer it returns.
    CreateTokenizer,
    /// `createAstSerializer()`: not run as a step; `fromAst()` is handed what it returns.
    CreateAstSerializer,
}

impl Step {
    /// Every step, in the order of the enum.
    pub const ALL: [Step; 73] = [
        Step::CompileFilter,
        Step::CompileOrder,
        Step::CompileLimit,
        Step::CompileOffset,
        Step::CompileSelect,
        Step::CompileCursor,
        Step::CompileAggregate,
        Step::CompileGroupBy,
        Step::CompileJoin,
        Step::CompileIn,
        Step::CompileNotIn,
        Step::CompileRegex,
        Step::CompileContains,
        Step::CompileContainsAll,
        Step::CompileNotContains,
        Step::CompileSearchExpression,
        Step::CompileSpatialFilter,
        Step::CompileArrayFilter,
        Step::CompileJsonContainsExpression,
        Step::CompileJsonOverlapsExpression,
        Step::CompileJoinWithBuilder,
        Step::ResolveAttribute,
        Step::ResolveAndWrap,
        Step::CompileLike,
        Step::AddBinding,
        Step::Build,
        Step::CompileRandom,
        Step::BuildTableClause,
        Step::BuildAfterGroupByClause,
        Step::BuildAfterOrderByClause,
        Step::BuildSettingsClause,
        Step::WrapUnionMember,
        Step::ShouldEmitOffset,
        Step::CompileHavingCondition,
        Step::CompileGroupByTimeBucket,
        Step::CompileLikePredicate,
        Step::LikeEscape,
        Step::CompileComparison,
        Step::Enclose,
        Step::EscapeLikeValue,
        Step::CompileSpatialDistance,
        Step::CompileSpatialPredicate,
        Step::CompileSpatialCoversPredicate,
        Step::CompileJsonPathExpression,
        Step::CompileJsonFilter,
        Step::CompileConflictHeader,
        Step::CompileConflictAssignment,
        Step::CompileConflictClause,
        Step::GroupConcatExpr,
        Step::JsonArrayAggExpr,
        Step::JsonObjectAggExpr,
        Step::GeomFromText,
        Step::CompileObjectFilter,
        Step::CompileJsonbContainment,
        Step::BuildJsonbPath,
        Step::ValidateTable,
        Step::ValidateRows,
        Step::ValidateAndGetColumns,
        Step::AddBindings,
        Step::CompileCase,
        Step::CompileInsertBody,
        Step::CompileAssignments,
        Step::CompileWhereClauses,
        Step::CompileOrderAndLimit,
        Step::BuildAfterJoinsClause,
        Step::CompileVectorOrderExpression,
        Step::ResolveJoinFilterPlacement,
        Step::AppendReturning,
        Step::ResetReturning,
        Step::ResetGroupByModifier,
        Step::ApplyNamedTypedBindings,
        Step::CreateTokenizer,
        Step::CreateAstSerializer,
    ];

    /// The PHP method.
    pub const fn method(self) -> &'static str {
        match self {
            Step::CompileFilter => "compileFilter",
            Step::CompileOrder => "compileOrder",
            Step::CompileLimit => "compileLimit",
            Step::CompileOffset => "compileOffset",
            Step::CompileSelect => "compileSelect",
            Step::CompileCursor => "compileCursor",
            Step::CompileAggregate => "compileAggregate",
            Step::CompileGroupBy => "compileGroupBy",
            Step::CompileJoin => "compileJoin",
            Step::CompileIn => "compileIn",
            Step::CompileNotIn => "compileNotIn",
            Step::CompileRegex => "compileRegex",
            Step::CompileContains => "compileContains",
            Step::CompileContainsAll => "compileContainsAll",
            Step::CompileNotContains => "compileNotContains",
            Step::CompileSearchExpression => "compileSearchExpression",
            Step::CompileSpatialFilter => "compileSpatialFilter",
            Step::CompileArrayFilter => "compileArrayFilter",
            Step::CompileJsonContainsExpression => "compileJsonContainsExpression",
            Step::CompileJsonOverlapsExpression => "compileJsonOverlapsExpression",
            Step::CompileJoinWithBuilder => "compileJoinWithBuilder",
            Step::ResolveAttribute => "resolveAttribute",
            Step::ResolveAndWrap => "resolveAndWrap",
            Step::CompileLike => "compileLike",
            Step::AddBinding => "addBinding",
            Step::Build => "build",
            Step::CompileRandom => "compileRandom",
            Step::BuildTableClause => "buildTableClause",
            Step::BuildAfterGroupByClause => "buildAfterGroupByClause",
            Step::BuildAfterOrderByClause => "buildAfterOrderByClause",
            Step::BuildSettingsClause => "buildSettingsClause",
            Step::WrapUnionMember => "wrapUnionMember",
            Step::ShouldEmitOffset => "shouldEmitOffset",
            Step::CompileHavingCondition => "compileHavingCondition",
            Step::CompileGroupByTimeBucket => "compileGroupByTimeBucket",
            Step::CompileLikePredicate => "compileLikePredicate",
            Step::LikeEscape => "likeEscape",
            Step::CompileComparison => "compileComparison",
            Step::Enclose => "enclose",
            Step::EscapeLikeValue => "escapeLikeValue",
            Step::CompileSpatialDistance => "compileSpatialDistance",
            Step::CompileSpatialPredicate => "compileSpatialPredicate",
            Step::CompileSpatialCoversPredicate => "compileSpatialCoversPredicate",
            Step::CompileJsonPathExpression => "compileJsonPathExpression",
            Step::CompileJsonFilter => "compileJsonFilter",
            Step::CompileConflictHeader => "compileConflictHeader",
            Step::CompileConflictAssignment => "compileConflictAssignment",
            Step::CompileConflictClause => "compileConflictClause",
            Step::GroupConcatExpr => "groupConcatExpr",
            Step::JsonArrayAggExpr => "jsonArrayAggExpr",
            Step::JsonObjectAggExpr => "jsonObjectAggExpr",
            Step::GeomFromText => "geomFromText",
            Step::CompileObjectFilter => "compileObjectFilter",
            Step::CompileJsonbContainment => "compileJsonbContainment",
            Step::BuildJsonbPath => "buildJsonbPath",
            Step::ValidateTable => "validateTable",
            Step::ValidateRows => "validateRows",
            Step::ValidateAndGetColumns => "validateAndGetColumns",
            Step::AddBindings => "addBindings",
            Step::CompileCase => "compileCase",
            Step::CompileInsertBody => "compileInsertBody",
            Step::CompileAssignments => "compileAssignments",
            Step::CompileWhereClauses => "compileWhereClauses",
            Step::CompileOrderAndLimit => "compileOrderAndLimit",
            Step::BuildAfterJoinsClause => "buildAfterJoinsClause",
            Step::CompileVectorOrderExpression => "compileVectorOrderExpression",
            Step::ResolveJoinFilterPlacement => "resolveJoinFilterPlacement",
            Step::AppendReturning => "appendReturning",
            Step::ResetReturning => "resetReturning",
            Step::ResetGroupByModifier => "resetGroupByModifier",
            Step::ApplyNamedTypedBindings => "applyNamedTypedBindings",
            Step::CreateTokenizer => "createTokenizer",
            Step::CreateAstSerializer => "createAstSerializer",
        }
    }
}

/// An argument of a [`Step`].
#[derive(Debug, Clone, Copy)]
pub enum Arg<'a> {
    /// A `string`.
    Str(&'a str),
    /// A `?string`.
    MaybeStr(Option<&'a str>),
    /// A `bool`.
    Bool(bool),
    /// An `array` of values.
    Values(&'a Array),
    /// A `mixed` value.
    Value(&'a Value),
    /// A `Query`.
    Query(&'a Query),
    /// A `Method`.
    Method(Method),
    /// A `JoinBuilder`.
    Join(&'a JoinBuilder),
    /// An `int`, or a `?int` that is null.
    Int(Option<i64>),
    /// A `Case\Expression`.
    Case(&'a CaseExpression),
    /// A `Statement`.
    Statement(&'a Statement),
    /// A `Hook\Join\Placement`.
    Placement(Placement),
    /// A list of strings, which a method that takes it by reference changes.
    Strings(&'a [String]),
    /// A `?ParsedQuery`: the builder's queries, grouped.
    Parsed(Option<&'a ParsedQuery>),
}

/// What a [`Step`] returns.
#[derive(Debug, Clone)]
pub enum Ret {
    /// `void`.
    None,
    /// A `string`.
    Str(String),
    /// A `Statement`.
    Statement(Statement),
    /// A `bool`.
    Bool(bool),
    /// A list of strings.
    Strings(Vec<String>),
    /// `compileInsertBody()`'s SQL and bindings.
    InsertBody(String, Vec<Value>),
    /// A `?Condition`.
    Condition(Option<Condition>),
    /// A `Hook\Join\Placement`.
    Placement(Placement),
    /// Column names.
    Columns(Vec<php_std::ArrayKey>),
    /// A parsed statement.
    Select(Box<crate::ast::Select>),
}

impl Ret {
    fn string(self) -> Result<String> {
        match self {
            Ret::Str(text) => Ok(text),
            _ => Err(Error::Validation("A builder step did not return a string".into())),
        }
    }
}

/// The overrides of a builder's steps.
pub trait Overrides: Send + Sync {
    /// Runs the override of `step` on `builder` (a `*mut Builder<D>` lent for
    /// the call, for an override that calls back into the builder), or
    /// `None` when the step is not overridden.
    fn call(&self, builder: *mut (), step: Step, arguments: &[Arg<'_>]) -> Option<Result<Ret>>;
}

fn wrong(step: Step) -> Error {
    Error::Validation(format!("{}() was given arguments it does not take", step.method()))
}

impl<D: Dialect> Builder<D> {
    /// Asks `overrides` before each [`Step`].
    pub fn set_overrides(&mut self, overrides: Arc<dyn Overrides>) -> &mut Self {
        self.core.overrides = Some(overrides);
        self
    }

    /// Says which steps the overrides take, a bit for each by its place in [`Step::ALL`]:
    /// the others are not asked about.
    pub fn set_overridden(&mut self, steps: u128) -> &mut Self {
        self.core.steps = steps;
        self
    }

    /// Says whose builder this is, for the overrides and `after_build_owned` callbacks to read
    /// back: state kept beside the builder would cross when two builds interleave on a thread.
    pub fn set_owner(&mut self, owner: usize) -> &mut Self {
        self.core.owner = owner;
        self
    }

    /// The owner [`Self::set_owner`] set, or zero: a clone has none.
    pub fn owner(&self) -> usize {
        self.core.owner
    }

    /// Whether a subclass overrides `step`.
    pub(crate) fn overrides(&self, step: Step) -> bool {
        self.core.steps & (1 << step as u32) != 0
    }

    /// The override of `step`, when there is one.
    fn overridden(&mut self, step: Step, arguments: &[Arg<'_>]) -> Option<Result<Ret>> {
        if self.core.steps & (1 << step as u32) == 0 {
            return None;
        }
        let overrides = self.core.overrides.clone()?;
        overrides.call(std::ptr::from_mut(self).cast(), step, arguments)
    }

    /// Runs `step` as the builder itself does, whatever is overridden.
    ///
    /// PHP: `parent::step(...)` in a subclass of the dialect's builder.
    pub fn base(&mut self, step: Step, arguments: &[Arg<'_>]) -> Result<Ret> {
        match (step, arguments) {
            (Step::CompileFilter, [Arg::Query(query)]) => D::compile_filter(self, query).map(Ret::Str),
            (Step::CompileOrder, [Arg::Query(query)]) => self.compile_order_query(query).map(Ret::Str),
            (Step::CompileLimit, [Arg::Query(query)]) => Ok(Ret::Str(self.compile_limit_query(query))),
            (Step::CompileOffset, [Arg::Query(query)]) => Ok(Ret::Str(self.compile_offset_query(query))),
            (Step::CompileSelect, [Arg::Query(query)]) => self.compile_select_query(query).map(Ret::Str),
            (Step::CompileCursor, [Arg::Query(query)]) => self.compile_cursor_query(query).map(Ret::Str),
            (Step::CompileAggregate, [Arg::Query(query)]) => self.compile_aggregate_query(query).map(Ret::Str),
            (Step::CompileGroupBy, [Arg::Query(query)]) => self.compile_group_by_query(query).map(Ret::Str),
            (Step::CompileJoin, [Arg::Query(query)]) => self.compile_join_query(query).map(Ret::Str),
            (Step::CompileIn, [Arg::Str(attribute), Arg::Values(values), Arg::MaybeStr(column)]) => {
                self.base_compile_in(attribute, values, *column).map(Ret::Str)
            }
            (
                Step::CompileLike,
                [
                    Arg::Str(attribute),
                    Arg::Values(values),
                    Arg::Str(prefix),
                    Arg::Str(suffix),
                    Arg::Bool(not),
                    Arg::MaybeStr(column),
                ],
            ) => D::compile_like(self, attribute, values, prefix, suffix, *not, *column).map(Ret::Str),
            (Step::CompileNotIn, [Arg::Str(attribute), Arg::Values(values), Arg::MaybeStr(column)]) => {
                self.base_compile_not_in(attribute, values, *column).map(Ret::Str)
            }
            (Step::CompileRegex, [Arg::Str(attribute), Arg::Values(values), Arg::MaybeStr(column)]) => {
                D::compile_regex(self, attribute, values, *column).map(Ret::Str)
            }
            (Step::CompileContains, [Arg::Str(attribute), Arg::Values(values), Arg::MaybeStr(column)]) => {
                D::compile_contains(self, attribute, values, *column).map(Ret::Str)
            }
            (Step::CompileContainsAll, [Arg::Str(attribute), Arg::Values(values), Arg::MaybeStr(column)]) => {
                D::compile_contains_all(self, attribute, values, *column).map(Ret::Str)
            }
            (Step::CompileNotContains, [Arg::Str(attribute), Arg::Values(values), Arg::MaybeStr(column)]) => {
                D::compile_not_contains(self, attribute, values, *column).map(Ret::Str)
            }
            (Step::CompileJoinWithBuilder, [Arg::Query(query), Arg::Join(join)]) => {
                D::compile_join_with_builder(self, query, join).map(Ret::Str)
            }
            (Step::ResolveAttribute, [Arg::Str(attribute)]) => self.base_resolve_attribute(attribute).map(Ret::Str),
            (Step::ResolveAndWrap, [Arg::Str(attribute)]) => self.base_resolve_and_wrap(attribute).map(Ret::Str),
            (Step::AddBinding, [Arg::Value(value), Arg::MaybeStr(column)]) => {
                self.base_add_binding((*value).clone(), *column);
                Ok(Ret::None)
            }
            (Step::Build, []) => self.build().map(Ret::Statement),
            (Step::CompileRandom, []) => Ok(Ret::Str(D::compile_random(self))),
            (Step::BuildTableClause, []) => D::build_table_clause(self).map(Ret::Str),
            (Step::BuildAfterGroupByClause, []) => D::build_after_group_by_clause(self).map(Ret::Str),
            (Step::BuildAfterOrderByClause, []) => D::build_after_order_by_clause(self).map(Ret::Str),
            (Step::BuildSettingsClause, []) => D::build_settings_clause(self).map(Ret::Str),
            (Step::WrapUnionMember, [Arg::Str(sql)]) => Ok(Ret::Str(D::wrap_union_member(self, sql))),
            (Step::ShouldEmitOffset, [Arg::Int(offset), Arg::Int(limit)]) => {
                D::should_emit_offset(self, *offset, *limit).map(Ret::Bool)
            }
            (Step::CompileHavingCondition, [Arg::Query(query), Arg::Str(expression)]) => {
                self.base_compile_having_condition(query, expression).map(Ret::Str)
            }
            (Step::CompileGroupByTimeBucket, [Arg::Str(attribute), Arg::Str(interval)]) => {
                D::compile_group_by_time_bucket(self, attribute, interval).map(Ret::Str)
            }
            (Step::CompileLikePredicate, [Arg::Str(attribute), Arg::Bool(not)]) => {
                self.base_like_predicate(attribute, *not).map(Ret::Str)
            }
            (Step::LikeEscape, []) => self.base_like_escape().map(Ret::Str),
            (
                Step::CompileComparison,
                [Arg::Str(attribute), Arg::Str(operator), Arg::Values(values), Arg::MaybeStr(column)],
            ) => Ok(Ret::Str(self.base_compile_comparison(attribute, operator, values, *column))),
            (Step::Enclose, [Arg::Str(condition)]) => Ok(Ret::Str(crate::builder::core::enclose(condition))),
            (Step::ValidateTable, []) => self.base_validate_table().map(|()| Ret::None),
            (Step::ValidateRows, [Arg::Str(operation)]) => self.base_validate_rows(operation).map(|()| Ret::None),
            (Step::ValidateAndGetColumns, []) => self.base_validate_and_get_columns().map(Ret::Columns),
            (Step::AddBindings, [Arg::Values(values)]) => {
                self.base_add_bindings(values.values().cloned());
                Ok(Ret::None)
            }
            (Step::CompileCase, [Arg::Case(case)]) => self.base_compile_case(case).map(Ret::Str),
            (Step::CompileInsertBody, []) => {
                self.base_compile_insert_body().map(|(sql, bindings)| Ret::InsertBody(sql, bindings))
            }
            (Step::CompileAssignments, []) => self.base_compile_assignments().map(Ret::Strings),
            (Step::CompileWhereClauses, [Arg::Strings(parts), Arg::Parsed(grouped)]) => {
                let mut parts = parts.to_vec();
                self.base_compile_where_clauses(&mut parts, *grouped).map(|()| Ret::Strings(parts))
            }
            (Step::CompileOrderAndLimit, [Arg::Strings(parts), Arg::Parsed(grouped)]) => {
                let mut parts = parts.to_vec();
                self.base_compile_order_and_limit(&mut parts, *grouped).map(|()| Ret::Strings(parts))
            }
            (Step::BuildAfterJoinsClause, [Arg::Parsed(Some(grouped))]) => {
                D::build_after_joins_clause(self, grouped).map(Ret::Str)
            }
            (Step::CompileVectorOrderExpression, []) => D::compile_vector_order_expression(self).map(Ret::Condition),
            (Step::ResolveJoinFilterPlacement, [Arg::Placement(requested), Arg::Bool(cross)]) => {
                Ok(Ret::Placement(D::resolve_join_filter_placement(self, *requested, *cross)))
            }
            (Step::AppendReturning, [Arg::Statement(statement)]) => {
                self.base_append_returning((*statement).clone()).map(Ret::Statement)
            }
            (Step::ResetReturning, []) => {
                self.core.returning.clear();
                Ok(Ret::None)
            }
            (Step::ResetGroupByModifier, []) => {
                self.core.group_by_modifier = None;
                Ok(Ret::None)
            }
            (Step::CreateTokenizer, [Arg::Str(sql)]) => crate::ast::Parser::new()
                .with_literal(D::LITERAL)
                .parse_sql(sql)
                .map(|read| Ret::Select(Box::new(read))),
            (Step::EscapeLikeValue, [Arg::Value(value)]) => {
                let escaped = crate::builder::core::escape_like_value(value, D::LIKE_ESCAPE);
                Ok(Ret::Str(escaped.iter().map(|&byte| char::from(byte)).collect()))
            }
            // A step only some dialects have.
            _ => D::base_step(self, step, arguments).unwrap_or_else(|| Err(wrong(step))),
        }
    }

    /// `step`: its override, or the builder's own.
    pub(crate) fn step(&mut self, step: Step, arguments: &[Arg<'_>]) -> Result<Ret> {
        match self.overridden(step, arguments) {
            Some(result) => result,
            None => self.base(step, arguments),
        }
    }

    /// `$this->build()`: the subclass's own when it overrides it. `explain()` and
    /// `toRawSql()` build through this.
    pub(crate) fn built(&mut self) -> Result<Statement> {
        match self.step(Step::Build, &[])? {
            Ret::Statement(statement) => Ok(statement),
            _ => Err(Error::Validation("build() did not return a statement".into())),
        }
    }

    /// `validateTable()`: a subclass's own when it has one.
    pub fn validate_table(&mut self) -> Result<()> {
        if !self.overrides(Step::ValidateTable) {
            return self.base_validate_table();
        }
        self.step(Step::ValidateTable, &[]).map(drop)
    }

    /// `validateRows()`.
    pub fn validate_rows(&mut self, operation: &str) -> Result<()> {
        if !self.overrides(Step::ValidateRows) {
            return self.base_validate_rows(operation);
        }
        self.step(Step::ValidateRows, &[Arg::Str(operation)]).map(drop)
    }

    /// `validateAndGetColumns()`.
    pub fn validate_and_get_columns(&mut self) -> Result<Vec<php_std::ArrayKey>> {
        if !self.overrides(Step::ValidateAndGetColumns) {
            return self.base_validate_and_get_columns();
        }
        match self.step(Step::ValidateAndGetColumns, &[])? {
            Ret::Columns(columns) => Ok(columns),
            _ => Err(wrong(Step::ValidateAndGetColumns)),
        }
    }

    /// `addBindings()`.
    pub fn add_bindings(&mut self, values: impl IntoIterator<Item = Value>) {
        if !self.overrides(Step::AddBindings) {
            return self.base_add_bindings(values);
        }
        let values = crate::value::list(values.into_iter().collect::<Vec<_>>());
        // An override that throws leaves its exception for the method that is running.
        let _ = self.step(Step::AddBindings, &[Arg::Values(&values)]);
    }

    /// `compileCase()`.
    pub fn compile_case(&mut self, case: &CaseExpression) -> Result<String> {
        if !self.overrides(Step::CompileCase) {
            return self.base_compile_case(case);
        }
        self.step_string(Step::CompileCase, &[Arg::Case(case)])
    }

    /// `compileInsertBody()`.
    pub fn compile_insert_body(&mut self) -> Result<(String, Vec<Value>)> {
        if !self.overrides(Step::CompileInsertBody) {
            return self.base_compile_insert_body();
        }
        match self.step(Step::CompileInsertBody, &[])? {
            Ret::InsertBody(sql, bindings) => Ok((sql, bindings)),
            _ => Err(wrong(Step::CompileInsertBody)),
        }
    }

    /// `compileAssignments()`.
    pub fn compile_assignments(&mut self) -> Result<Vec<String>> {
        if !self.overrides(Step::CompileAssignments) {
            return self.base_compile_assignments();
        }
        self.step_strings(Step::CompileAssignments, &[])
    }

    /// `compileWhereClauses()`.
    pub fn compile_where_clauses(&mut self, parts: &mut Vec<String>, grouped: Option<&ParsedQuery>) -> Result<()> {
        if !self.overrides(Step::CompileWhereClauses) {
            return self.base_compile_where_clauses(parts, grouped);
        }
        *parts = self.step_strings(Step::CompileWhereClauses, &[Arg::Strings(parts), Arg::Parsed(grouped)])?;
        Ok(())
    }

    /// `compileOrderAndLimit()`.
    pub fn compile_order_and_limit(&mut self, parts: &mut Vec<String>, grouped: Option<&ParsedQuery>) -> Result<()> {
        if !self.overrides(Step::CompileOrderAndLimit) {
            return self.base_compile_order_and_limit(parts, grouped);
        }
        *parts = self.step_strings(Step::CompileOrderAndLimit, &[Arg::Strings(parts), Arg::Parsed(grouped)])?;
        Ok(())
    }

    /// `appendReturning()`.
    pub fn append_returning(&mut self, result: Statement) -> Result<Statement> {
        if !self.overrides(Step::AppendReturning) {
            return self.base_append_returning(result);
        }
        match self.step(Step::AppendReturning, &[Arg::Statement(&result)])? {
            Ret::Statement(statement) => Ok(statement),
            _ => Err(wrong(Step::AppendReturning)),
        }
    }

    /// `resetReturning()`.
    pub fn reset_returning(&mut self) {
        // As for `addBindings()`: `reset()` has no error to return.
        let _ = self.step(Step::ResetReturning, &[]);
    }

    /// `resetGroupByModifier()`.
    pub fn reset_group_by_modifier(&mut self) {
        let _ = self.step(Step::ResetGroupByModifier, &[]);
    }

    /// `step`, which returns a list of strings.
    fn step_strings(&mut self, step: Step, arguments: &[Arg<'_>]) -> Result<Vec<String>> {
        match self.step(step, arguments)? {
            Ret::Strings(strings) => Ok(strings),
            _ => Err(wrong(step)),
        }
    }

    /// `step`, which returns a bool.
    pub(crate) fn step_bool(&mut self, step: Step, arguments: &[Arg<'_>]) -> Result<bool> {
        match self.step(step, arguments)? {
            Ret::Bool(flag) => Ok(flag),
            _ => Err(Error::Validation(format!("{}() did not return a bool", step.method()))),
        }
    }

    /// `step`, which returns a string.
    pub(crate) fn step_string(&mut self, step: Step, arguments: &[Arg<'_>]) -> Result<String> {
        self.step(step, arguments).and_then(Ret::string)
    }
}
