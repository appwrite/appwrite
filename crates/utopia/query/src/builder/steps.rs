//! The steps of compilation a subclass of a PHP builder may override.
//!
//! PHP subclasses a builder and overrides its public and protected methods
//! (`Utopia\Database\Builder\MySQL` does). Rust builders are not subclassed,
//! so a builder given [`Overrides`] asks them before it runs a [`Step`], and
//! runs its own when they decline. [`Builder::base`] runs a step without
//! asking, which is what `parent::step()` is.

use std::sync::Arc;

use crate::builder::core::{Builder, Dialect};
use crate::builder::join::JoinBuilder;
use crate::builder::types::Statement;
use crate::error::{Error, Result};
use crate::method::Method;
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
}

impl Step {
    /// Every step, in the order of the enum.
    pub const ALL: [Step; 55] = [
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
