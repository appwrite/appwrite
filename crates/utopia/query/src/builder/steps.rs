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
}

impl Step {
    /// Every step, in the order of the enum.
    pub const ALL: [Step; 25] = [
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
}

/// What a [`Step`] returns.
#[derive(Debug, Clone, PartialEq)]
pub enum Ret {
    /// `void`.
    None,
    /// A `string`.
    Str(String),
}

impl Ret {
    fn string(self) -> Result<String> {
        match self {
            Ret::Str(text) => Ok(text),
            Ret::None => Err(Error::Validation("A builder step returned nothing where it returns a string".into())),
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
    pub fn set_overridden(&mut self, steps: u64) -> &mut Self {
        self.core.steps = steps;
        self
    }

    /// The override of `step`, when there is one.
    fn overridden(&mut self, step: Step, arguments: &[Arg<'_>]) -> Option<Result<Ret>> {
        if self.core.steps & (1 << step as u64) == 0 {
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

    /// `step`, which returns a string.
    pub(crate) fn step_string(&mut self, step: Step, arguments: &[Arg<'_>]) -> Result<String> {
        self.step(step, arguments).and_then(Ret::string)
    }
}
