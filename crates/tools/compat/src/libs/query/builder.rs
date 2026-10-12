//! The builder area of the query adapter: every dialect's builder, the
//! join builder, `CASE` expressions and the small value types.
//!
//! `base_call` maps the methods every builder has; each dialect adds its
//! features (`feature_*` functions) in its `Object::call`.

use std::sync::Arc;

use serde_json::Value as Json;
use utopia_query::builder::feature;
use utopia_query::builder::{
    AnyBuilder, Builder, CaseExpression, Dialect, ExplainOptions, JoinBuilder, JoinType, MySql, MySqlDialect, MySqlFamily,
    Operator, SpatialDistanceFilter, Statement, WindowFrame,
};
use utopia_query::{Array, Compiler, Query, Value};

use super::core::{executor_arg, hook_arg};
use super::engine::{Args, Callback, Failure, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// `new Class(...)`.
pub(crate) fn construct(class: &str, a: &mut Args) -> Option<Outcome> {
    Some(match class {
        "Builder\\MySQL" => Ok(Ret::obj(MySql::new())),
        "Builder\\ClickHouse" => Ok(Ret::obj(utopia_query::builder::ClickHouse::new())),
        "Builder\\PostgreSQL" => Ok(Ret::obj(utopia_query::builder::PostgreSql::new())),
        "Builder\\SQLite" => Ok(Ret::obj(utopia_query::builder::Sqlite::new())),
        "Builder\\MariaDB" => Ok(Ret::obj(utopia_query::builder::MariaDb::new())),
        "Builder\\MongoDB" | "Builder\\MongoDB\\UpdateOperation" => return super::builder_mongodb::construct(class, a),
        "Builder\\Case\\Expression" => Ok(Ret::obj(CaseExpression::new())),
        "Builder\\JoinBuilder" => Ok(Ret::obj(JoinBuilder::new())),
        "Builder\\WindowFrame" => (|| {
            Ok(Ret::obj(WindowFrame::new(a.str(0, "type")?, a.str(1, "start")?, a.opt_str(2, "end")?)))
        })(),
        "Builder\\SpatialDistanceFilter" => (|| {
            Ok(Ret::obj(SpatialDistanceFilter::new(a.value(0, "geometry")?, a.float(1, "distance")?, a.bool(2, "meters")?)))
        })(),
        _ => return None,
    })
}

/// `Class::method(...)`.
pub(crate) fn call_static(class: &str, method: &str, a: &mut Args) -> Option<Outcome> {
    match (class, method) {
        (_, "fromAst") => from_ast_for(class, a),
        ("Builder\\SpatialDistanceFilter", "fromTuple") => {
            Some((|| Ok(Ret::obj(SpatialDistanceFilter::from_tuple(&a.array(0, "tuple")?))))())
        }
        _ => None,
    }
}

/// `Builder\X::fromAst($ast)` for the dialect builder `class` names.
fn from_ast_for(class: &str, a: &Args) -> Option<Outcome> {
    use utopia_query::builder::{
        ClickHouseDialect, MariaDbDialect, MongoDbDialect, PostgreSqlDialect, SqliteDialect,
    };
    fn make<D: Dialect>(a: &Args) -> Outcome
    where
        Builder<D>: Object,
    {
        let select = a.object::<utopia_query::ast::Select>(0, "ast")?;
        Ok(Ret::obj(Builder::<D>::from_ast(&select)?))
    }
    Some(match class {
        "Builder\\MySQL" => make::<MySqlDialect>(a),
        "Builder\\MariaDB" => make::<MariaDbDialect>(a),
        "Builder\\PostgreSQL" => make::<PostgreSqlDialect>(a),
        "Builder\\SQLite" => make::<SqliteDialect>(a),
        "Builder\\ClickHouse" => make::<ClickHouseDialect>(a),
        "Builder\\MongoDB" => make::<MongoDbDialect>(a),
        _ => return None,
    })
}

/// `Class::CONSTANT`.
pub(crate) fn constant(class: &str, name: &str) -> Option<Ret> {
    super::builder_clickhouse::constant(class, name)
}

/// `Class::Case->method(...)`.
pub(crate) fn enum_call(class: &str, case: &str, method: &str, _a: &mut Args) -> Option<Outcome> {
    if let Some(outcome) = super::builder_clickhouse::enum_call(class, case, method, _a) {
        return Some(outcome);
    }
    if let Some(outcome) = super::builder_mongodb::enum_call(class, case, method, _a) {
        return Some(outcome);
    }
    use utopia_query::builder::{Kind, LockMode, UnionType, VectorMetric};
    macro_rules! value_of {
        ($t:ty) => {{
            let v = <$t>::from_name(case)?;
            if method == "->value" {
                return Some(Ok(Ret::str(v.as_str())));
            }
            v
        }};
    }
    match class {
        "Builder\\JoinType" => {
            value_of!(JoinType);
            None
        }
        "Builder\\UnionType" => {
            value_of!(UnionType);
            None
        }
        "Builder\\Case\\Kind" => {
            value_of!(Kind);
            None
        }
        "Builder\\LockMode" => {
            let v = value_of!(LockMode);
            (method == "toSql").then(|| Ok(Ret::str(v.to_sql())))
        }
        "Builder\\VectorMetric" => {
            let v = value_of!(VectorMetric);
            (method == "toOperator").then(|| Ok(Ret::str(v.to_operator())))
        }
        "Builder\\Case\\Operator" => {
            let v = value_of!(Operator);
            (method == "sqlOperator").then(|| Ok(Ret::str(v.sql_operator())))
        }
        _ => None,
    }
}

/// `$query->compile($builder)`: compiles `query` with the builder `object`.
pub(crate) fn compile_with(object: &mut dyn Object, query: &Query) -> Outcome {
    let compiled = with_compiler(object, |c| query.compile(c));
    match compiled {
        Some(result) => Ok(Ret::str(result?)),
        None => Err(fault(format!("{} is not a compiler", object.class()))),
    }
}

/// Runs `f` with `object` as a [`Compiler`], if it is a builder.
pub(crate) fn with_compiler<R>(object: &mut dyn Object, f: impl FnOnce(&mut dyn Compiler) -> R) -> Option<R> {
    let any = object.as_any_mut();
    if let Some(b) = any.downcast_mut::<utopia_query::builder::MongoDb>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::ClickHouse>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::PostgreSql>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::Sqlite>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::MariaDb>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<MySql>() {
        return Some(f(b));
    }
    None
}

/// A copy of a builder object, as a subquery.
pub(crate) fn any_builder(object: &dyn Object) -> Option<Box<dyn AnyBuilder>> {
    let any = object.as_any();
    if let Some(b) = any.downcast_ref::<utopia_query::builder::MongoDb>() {
        return Some(b.clone_box());
    }
    if let Some(b) = any.downcast_ref::<utopia_query::builder::ClickHouse>() {
        return Some(b.clone_box());
    }
    if let Some(b) = any.downcast_ref::<utopia_query::builder::PostgreSql>() {
        return Some(b.clone_box());
    }
    if let Some(b) = any.downcast_ref::<utopia_query::builder::Sqlite>() {
        return Some(b.clone_box());
    }
    if let Some(b) = any.downcast_ref::<utopia_query::builder::MariaDb>() {
        return Some(b.clone_box());
    }
    if let Some(b) = any.downcast_ref::<MySql>() {
        return Some(b.clone_box());
    }
    None
}

/// Runs `f` with `object` as a builder.
pub(crate) fn with_any_builder<R>(object: &mut dyn Object, f: impl FnOnce(&mut dyn AnyBuilder) -> R) -> Option<R> {
    let any = object.as_any_mut();
    if let Some(b) = any.downcast_mut::<utopia_query::builder::MongoDb>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::ClickHouse>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::PostgreSql>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::Sqlite>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<utopia_query::builder::MariaDb>() {
        return Some(f(b));
    }
    if let Some(b) = any.downcast_mut::<MySql>() {
        return Some(f(b));
    }
    None
}

/// The builder argument `index` / `name`, copied.
pub(crate) fn builder_arg(a: &Args, index: usize, name: &str) -> Result<Box<dyn AnyBuilder>, Failure> {
    a.with(index, name, |o| any_builder(o).ok_or_else(|| fault(format!("{} is not a builder", o.class()))))?
}

/// Runs `f` with the builder argument `index` / `name` (built in place).
pub(crate) fn with_builder_arg<R>(a: &Args, index: usize, name: &str, f: impl FnOnce(&mut dyn AnyBuilder) -> R) -> Result<R, Failure> {
    a.with(index, name, |o| {
        let class = o.class();
        with_any_builder(o, f).ok_or_else(|| fault(format!("{class} is not a builder")))
    })?
}

fn nulls(a: &Args, index: usize, name: &str) -> Result<Option<utopia_query::NullsPosition>, Failure> {
    Ok(a.enum_case(index, name)?.and_then(|(_, case)| utopia_query::NullsPosition::from_name(&case)))
}

fn join_type(a: &Args, index: usize, name: &str) -> Result<JoinType, Failure> {
    Ok(match a.enum_case(index, name)? {
        Some((_, case)) => JoinType::from_name(&case).ok_or_else(|| fault(format!("no JoinType::{case}")))?,
        None => JoinType::Inner,
    })
}

fn frame(a: &Args, index: usize, name: &str) -> Result<Option<WindowFrame>, Failure> {
    a.opt_object::<WindowFrame>(index, name)
}

fn values(array: Array) -> Vec<Value> {
    array.values().cloned().collect()
}

/// A test closure taking the builder (`when`, `beforeBuild`).
fn lend<D: Dialect>(callback: Callback) -> impl Fn(&mut Builder<D>) -> utopia_query::Result<()> + Send + Sync + 'static
where
    Builder<D>: Object,
{
    move |builder: &mut Builder<D>| {
        callback.invoke_lent(vec![Ret::null()], 0, builder).map_err(to_error)?;
        Ok(())
    }
}

/// A callback failure as a library error.
pub(crate) fn to_error(f: Failure) -> utopia_query::Error {
    match f {
        Failure::Lib(e) => e,
        Failure::Engine(_, m) | Failure::Fault(m) => utopia_query::Error::new(m, 0),
    }
}

/// The methods every builder has.
pub(crate) fn base_call<D: Dialect>(b: &mut Builder<D>, method: &str, a: &mut Args) -> Option<Outcome>
where
    Builder<D>: Object,
{
    let result = (|| -> Outcome {
        Ok(match method {
            "from" => {
                b.from(a.str_or(0, "table", "")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "fromNone" => {
                b.from_none();
                Ret::This
            }
            "fromSub" => {
                let sub = builder_arg(a, 0, "subquery")?;
                b.from_sub_boxed(sub, a.str(1, "alias")?);
                Ret::This
            }
            "selectSub" => {
                let sub = builder_arg(a, 0, "subquery")?;
                b.select_sub(sub, a.str(1, "alias")?);
                Ret::This
            }
            "filterWhereIn" => {
                let sub = builder_arg(a, 1, "subquery")?;
                b.filter_where_in(a.str(0, "column")?, sub);
                Ret::This
            }
            "filterWhereNotIn" => {
                let sub = builder_arg(a, 1, "subquery")?;
                b.filter_where_not_in(a.str(0, "column")?, sub);
                Ret::This
            }
            "filterExists" => {
                let sub = builder_arg(a, 0, "subquery")?;
                b.filter_exists(sub);
                Ret::This
            }
            "filterNotExists" => {
                let sub = builder_arg(a, 0, "subquery")?;
                b.filter_not_exists(sub);
                Ret::This
            }
            "select" => {
                match a.raw(0, "columns") {
                    Some(Json::String(_)) => {
                        b.select_raw(a.str(0, "columns")?, a.array_or_empty(1, "bindings")?);
                    }
                    _ => {
                        let columns = a.strings(0, "columns")?;
                        b.select(columns);
                    }
                }
                Ret::This
            }
            "distinct" => {
                b.distinct();
                Ret::This
            }
            "filter" => {
                b.filter(a.queries(0, "queries")?);
                Ret::This
            }
            "queries" => {
                b.queries(a.queries(0, "queries")?);
                Ret::This
            }
            "sortAsc" => {
                b.sort_asc(a.str(0, "attribute")?, nulls(a, 1, "nulls")?);
                Ret::This
            }
            "sortDesc" => {
                b.sort_desc(a.str(0, "attribute")?, nulls(a, 1, "nulls")?);
                Ret::This
            }
            "sortRandom" => {
                b.sort_random();
                Ret::This
            }
            "limit" => {
                b.limit(a.int(0, "value")?);
                Ret::This
            }
            "offset" => {
                b.offset(a.int(0, "value")?);
                Ret::This
            }
            "fetch" => {
                b.fetch(a.int(0, "count")?, a.bool_or(1, "withTies", false)?);
                Ret::This
            }
            "page" => {
                b.page(a.int(0, "page")?, a.int_or(1, "perPage", 25)?)?;
                Ret::This
            }
            "cursorAfter" => {
                b.cursor_after(a.value(0, "value")?);
                Ret::This
            }
            "cursorBefore" => {
                b.cursor_before(a.value(0, "value")?);
                Ret::This
            }
            "when" => {
                let condition = a.bool(0, "condition")?;
                let callback = a.closure(1, "callback")?;
                b.when(condition, lend::<D>(callback))?;
                Ret::This
            }
            "beforeBuild" => {
                b.before_build(lend::<D>(a.closure(0, "callback")?));
                Ret::This
            }
            "afterBuild" => {
                let callback = a.closure(0, "callback")?;
                b.after_build(move |statement: Statement| {
                    let ret = callback.invoke(vec![Ret::obj(statement)]).map_err(to_error)?;
                    callback.cx().decoded(&ret).object::<Statement>(0, "ret").map_err(to_error)
                });
                Ret::This
            }
            "setExecutor" => {
                if let Some(executor) = executor_arg(a, 0, "executor")? {
                    b.set_executor(executor);
                }
                Ret::This
            }
            "explain" => Ret::obj(b.explain_with(
                a.bool_or(0, "analyze", false)?,
                &ExplainOptions {
                    verbose: a.bool_or(1, "verbose", false).unwrap_or(false),
                    buffers: a.bool_or(2, "buffers", false).unwrap_or(false),
                    format: explain_format(a)?,
                },
            )?),
            "execute" => Ret::Value(b.execute()?),
            "toRawSql" => Ret::str(b.to_raw_sql()?),
            "getBindings" => Ret::values(&b.bindings()),
            "reset" => {
                b.reset();
                Ret::This
            }
            "build" => Ret::obj(b.build()?),
            "toAst" => Ret::obj(b.to_ast()?),
            "clone" => Ret::obj(b.clone()),
            "count" => {
                b.count(a.str_or(0, "attribute", "*")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "countDistinct" => {
                b.count_distinct(a.str(0, "attribute")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "sum" => {
                b.sum(a.str(0, "attribute")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "avg" => {
                b.avg(a.str(0, "attribute")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "min" => {
                b.min(a.str(0, "attribute")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "max" => {
                b.max(a.str(0, "attribute")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "groupBy" => {
                b.group_by(a.strings(0, "columns")?);
                Ret::This
            }
            "groupByTimeBucket" => {
                b.group_by_time_bucket(a.str(0, "attribute")?, &a.str(1, "interval")?)?;
                Ret::This
            }
            "having" => {
                b.having(a.queries(0, "queries")?);
                Ret::This
            }
            "join" | "leftJoin" | "rightJoin" => {
                let table = a.str(0, "table")?;
                let left = a.str(1, "left")?;
                let right = a.str(2, "right")?;
                let operator = a.str_or(3, "operator", "=")?;
                let alias = a.str_or(4, "alias", "")?;
                match method {
                    "join" => b.join(table, &left, &right, &operator, alias),
                    "leftJoin" => b.left_join(table, &left, &right, &operator, alias),
                    _ => b.right_join(table, &left, &right, &operator, alias),
                };
                Ret::This
            }
            "joinWhere" => {
                let table = a.str(0, "table")?;
                let callback = a.closure(1, "callback")?;
                let join_type = join_type(a, 2, "type")?;
                let alias = a.str_or(3, "alias", "")?;
                b.join_where(
                    table,
                    |join: &mut JoinBuilder| {
                        callback.invoke_lent(vec![Ret::null()], 0, join).map_err(to_error)?;
                        Ok(())
                    },
                    join_type,
                    alias,
                )?;
                Ret::This
            }
            "union" | "unionAll" | "intersect" | "intersectAll" | "except" | "exceptAll" => {
                with_builder_arg(a, 0, "other", |other| -> utopia_query::Result<()> {
                    match method {
                        "union" => b.union(other),
                        "unionAll" => b.union_all(other),
                        "intersect" => b.intersect(other),
                        "intersectAll" => b.intersect_all(other),
                        "except" => b.except(other),
                        _ => b.except_all(other),
                    }
                    .map(|_| ())
                })??;
                Ret::This
            }
            "with" | "withRecursive" => {
                let name = a.str(0, "name")?;
                let columns = a.strings_or_empty(2, "columns")?;
                with_builder_arg(a, 1, "query", |query| {
                    if method == "with" { b.with(name, query, columns) } else { b.with_recursive(name, query, columns) }.map(|_| ())
                })??;
                Ret::This
            }
            "withRecursiveSeedStep" => {
                let name = a.str(0, "name")?;
                let columns = a.strings_or_empty(3, "columns")?;
                let mut seed = builder_arg(a, 1, "seed")?;
                let mut step = builder_arg(a, 2, "step")?;
                b.with_recursive_seed_step(name, seed.as_mut(), step.as_mut(), columns)?;
                Ret::This
            }
            "into" => {
                b.into(a.str(0, "table")?);
                Ret::This
            }
            "insertAs" => {
                b.insert_as(a.str(0, "alias")?);
                Ret::This
            }
            "set" => {
                b.set(a.array(0, "row")?)?;
                Ret::This
            }
            "onConflict" => {
                b.on_conflict(a.strings(0, "keys")?, a.strings(1, "updateColumns")?);
                Ret::This
            }
            "fromSelect" => {
                let source = builder_arg(a, 1, "source")?;
                b.from_select(a.strings(0, "columns")?, source);
                Ret::This
            }
            "insert" => Ret::obj(b.insert()?),
            "insertDefaultValues" => Ret::obj(b.insert_default_values()?),
            "insertSelect" => Ret::obj(b.insert_select()?),
            "update" => Ret::obj(b.update()?),
            "delete" => Ret::obj(b.delete()?),
            "addHook" => {
                b.add_hook(hook_arg(a, 0, "hook")?)?;
                Ret::This
            }
            "selectWindow" => {
                b.select_window(
                    a.str(0, "function")?,
                    a.str(1, "alias")?,
                    a.opt_strings(2, "partitionBy")?,
                    a.opt_strings(3, "orderBy")?,
                    a.opt_str(4, "windowName")?,
                    frame(a, 5, "frame")?,
                )?;
                Ret::This
            }
            "window" => {
                b.window(a.str(0, "name")?, a.opt_strings(1, "partitionBy")?, a.opt_strings(2, "orderBy")?, frame(a, 3, "frame")?);
                Ret::This
            }
            "compileFilter" => Ret::str(Compiler::compile_filter(b, &a.query(0, "query")?)?),
            "compileOrder" => Ret::str(Compiler::compile_order(b, &a.query(0, "query")?)?),
            "compileLimit" => Ret::str(Compiler::compile_limit(b, &a.query(0, "query")?)?),
            "compileOffset" => Ret::str(Compiler::compile_offset(b, &a.query(0, "query")?)?),
            "compileSelect" => Ret::str(Compiler::compile_select(b, &a.query(0, "query")?)?),
            "compileCursor" => Ret::str(Compiler::compile_cursor(b, &a.query(0, "query")?)?),
            "compileAggregate" => Ret::str(Compiler::compile_aggregate(b, &a.query(0, "query")?)?),
            "compileGroupBy" => Ret::str(Compiler::compile_group_by(b, &a.query(0, "query")?)?),
            "compileJoin" => Ret::str(Compiler::compile_join(b, &a.query(0, "query")?)?),
            _ => return Err(fault("not a base method")),
        })
    })();
    match result {
        Err(Failure::Fault(f)) if f == "not a base method" => None,
        other => Some(other),
    }
}

fn explain_format(a: &Args) -> Result<String, Failure> {
    // MySQL's explain($analyze, $format); PostgreSQL's explain($analyze, $verbose, $buffers, $format).
    if let Some(Json::String(_)) = a.raw(1, "format") {
        return a.str(1, "format");
    }
    a.str_or(3, "format", "")
}

macro_rules! features {
    ($name:ident, $bound:path, |$b:ident, $a:ident| { $($m:literal => $body:expr),* $(,)? }) => {
        pub(crate) fn $name<D: $bound>($b: &mut Builder<D>, method: &str, $a: &mut Args) -> Option<Outcome>
        where
            Builder<D>: Object,
        {
            Some(match method {
                $($m => (|| -> Outcome { $body })(),)*
                _ => return None,
            })
        }
    };
}

features!(locking, feature::Locking, |b, _a| {
    "forUpdate" => { b.for_update(); Ok(Ret::This) },
    "forShare" => { b.for_share(); Ok(Ret::This) },
    "forUpdateSkipLocked" => { b.for_update_skip_locked(); Ok(Ret::This) },
    "forUpdateNoWait" => { b.for_update_no_wait(); Ok(Ret::This) },
    "forShareSkipLocked" => { b.for_share_skip_locked(); Ok(Ret::This) },
    "forShareNoWait" => { b.for_share_no_wait(); Ok(Ret::This) },
});

features!(transactions, feature::Transactions, |b, a| {
    "begin" => Ok(Ret::obj(b.begin())),
    "commit" => Ok(Ret::obj(b.commit())),
    "rollback" => Ok(Ret::obj(b.rollback())),
    "savepoint" => Ok(Ret::obj(b.savepoint(&a.str(0, "name")?)?)),
    "releaseSavepoint" => Ok(Ret::obj(b.release_savepoint(&a.str(0, "name")?)?)),
    "rollbackToSavepoint" => Ok(Ret::obj(b.rollback_to_savepoint(&a.str(0, "name")?)?)),
});

features!(statistical, feature::StatisticalAggregates, |b, a| {
    "stddev" => { b.stddev(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "stddevPop" => { b.stddev_pop(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "stddevSamp" => { b.stddev_samp(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "variance" => { b.variance(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "varPop" => { b.var_pop(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "varSamp" => { b.var_samp(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
});

features!(bitwise, feature::BitwiseAggregates, |b, a| {
    "bitAnd" => { b.bit_and(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "bitOr" => { b.bit_or(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "bitXor" => { b.bit_xor(a.str(0, "attribute")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
});

features!(raw_sql, feature::RawSql, |b, a| {
    "selectRaw" => { b.select_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?); Ok(Ret::This) },
    "selectCast" => { b.select_cast(&a.str(0, "column")?, &a.str(1, "type")?, &a.str_or(2, "alias", "")?)?; Ok(Ret::This) },
    "orderByRaw" => { b.order_by_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?); Ok(Ret::This) },
    "groupByRaw" => { b.group_by_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?); Ok(Ret::This) },
    "havingRaw" => { b.having_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?); Ok(Ret::This) },
    "whereRaw" => { b.where_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?); Ok(Ret::This) },
    "whereColumn" => { b.where_column(&a.str(0, "left")?, &a.str(1, "operator")?, &a.str(2, "right")?)?; Ok(Ret::This) },
    "selectCase" => { b.select_case(a.object::<CaseExpression>(0, "case")?); Ok(Ret::This) },
    "setCase" => { b.set_case(a.str(0, "column")?, a.object::<CaseExpression>(1, "case")?); Ok(Ret::This) },
    "setRaw" => { b.set_raw(a.str(0, "column")?, a.str(1, "expression")?, a.array_or_empty(2, "bindings")?); Ok(Ret::This) },
    "conflictSetRaw" => { b.conflict_set_raw(a.str(0, "column")?, a.str(1, "expression")?, a.array_or_empty(2, "bindings")?); Ok(Ret::This) },
    "insertColumnExpression" => { b.insert_column_expression(a.str(0, "column")?, a.str(1, "expression")?, a.array_or_empty(2, "extraBindings")?); Ok(Ret::This) },
});

features!(cross_joins, feature::CrossJoins, |b, a| {
    "crossJoin" => { b.cross_join(a.str(0, "table")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
    "naturalJoin" => { b.natural_join(a.str(0, "table")?, a.str_or(1, "alias", "")?); Ok(Ret::This) },
});

features!(full_outer_joins, feature::FullOuterJoins, |b, a| {
    "fullOuterJoin" => {
        b.full_outer_join(a.str(0, "table")?, &a.str(1, "left")?, &a.str(2, "right")?, &a.str_or(3, "operator", "=")?, a.str_or(4, "alias", "")?);
        Ok(Ret::This)
    },
});

features!(lateral_joins, feature::LateralJoins, |b, a| {
    "joinLateral" => {
        let sub = builder_arg(a, 0, "subquery")?;
        b.join_lateral(sub, a.str(1, "alias")?, join_type(a, 2, "type")?);
        Ok(Ret::This)
    },
    "leftJoinLateral" => {
        let sub = builder_arg(a, 0, "subquery")?;
        b.left_join_lateral(sub, a.str(1, "alias")?);
        Ok(Ret::This)
    },
});

features!(rollup, feature::Rollup, |b, _a| {
    "withRollup" => { b.with_rollup(); Ok(Ret::This) },
});

features!(cube, feature::Cube, |b, _a| {
    "withCube" => { b.with_cube(); Ok(Ret::This) },
});

features!(totals, feature::Totals, |b, _a| {
    "withTotals" => { b.with_totals(); Ok(Ret::This) },
});

features!(hints, feature::Hints, |b, a| {
    "hint" => { b.hint(&a.str(0, "hint")?)?; Ok(Ret::This) },
});

features!(conditional_aggregates, feature::ConditionalAggregates, |b, a| {
    "countWhen" => { let rest = rest_values(a, 2); b.count_when(&a.str(0, "condition")?, &a.str_or(1, "alias", "")?, rest?)?; Ok(Ret::This) },
    "sumWhen" => { let rest = rest_values(a, 3); b.sum_when(&a.str(0, "column")?, &a.str(1, "condition")?, &a.str_or(2, "alias", "")?, rest?)?; Ok(Ret::This) },
    "avgWhen" => { let rest = rest_values(a, 3); b.avg_when(&a.str(0, "column")?, &a.str(1, "condition")?, &a.str_or(2, "alias", "")?, rest?)?; Ok(Ret::This) },
    "minWhen" => { let rest = rest_values(a, 3); b.min_when(&a.str(0, "column")?, &a.str(1, "condition")?, &a.str_or(2, "alias", "")?, rest?)?; Ok(Ret::This) },
    "maxWhen" => { let rest = rest_values(a, 3); b.max_when(&a.str(0, "column")?, &a.str(1, "condition")?, &a.str_or(2, "alias", "")?, rest?)?; Ok(Ret::This) },
});

/// The values of a variadic parameter from position `index`.
pub(crate) fn rest_values(a: &Args, index: usize) -> Result<Vec<Value>, Failure> {
    a.rest(index).into_iter().map(|raw| a.php(raw)).collect()
}

features!(string_aggregates, feature::StringAggregates, |b, a| {
    "groupConcat" => {
        let order = a.opt_strings(3, "orderBy")?;
        b.group_concat(&a.str(0, "column")?, &a.str_or(1, "separator", ",")?, &a.str_or(2, "alias", "")?, order.as_deref())?;
        Ok(Ret::This)
    },
    "jsonArrayAgg" => { b.json_array_agg(&a.str(0, "column")?, &a.str_or(1, "alias", "")?)?; Ok(Ret::This) },
    "jsonObjectAgg" => { b.json_object_agg(&a.str(0, "keyColumn")?, &a.str(1, "valueColumn")?, &a.str_or(2, "alias", "")?)?; Ok(Ret::This) },
});

features!(json, feature::Json, |b, a| {
    "filterJsonContains" => { b.filter_json_contains(a.str(0, "attribute")?, a.value(1, "value")?); Ok(Ret::This) },
    "filterJsonNotContains" => { b.filter_json_not_contains(a.str(0, "attribute")?, a.value(1, "value")?); Ok(Ret::This) },
    "filterJsonOverlaps" => { b.filter_json_overlaps(a.str(0, "attribute")?, a.array(1, "values")?); Ok(Ret::This) },
    "filterJsonPath" => { b.filter_json_path(a.str(0, "attribute")?, &a.str(1, "path")?, &a.str(2, "operator")?, a.value(3, "value")?); Ok(Ret::This) },
    "setJsonAppend" => { b.set_json_append(&a.str(0, "column")?, a.array(1, "values")?)?; Ok(Ret::This) },
    "setJsonPrepend" => { b.set_json_prepend(&a.str(0, "column")?, a.array(1, "values")?)?; Ok(Ret::This) },
    "setJsonInsert" => { b.set_json_insert(&a.str(0, "column")?, a.int(1, "index")?, a.value(2, "value")?)?; Ok(Ret::This) },
    "setJsonRemove" => { b.set_json_remove(&a.str(0, "column")?, a.value(1, "value")?)?; Ok(Ret::This) },
    "setJsonIntersect" => { b.set_json_intersect(&a.str(0, "column")?, a.array(1, "values")?)?; Ok(Ret::This) },
    "setJsonDiff" => { b.set_json_diff(&a.str(0, "column")?, a.array(1, "values")?)?; Ok(Ret::This) },
    "setJsonUnique" => { b.set_json_unique(&a.str(0, "column")?)?; Ok(Ret::This) },
    "setJsonPath" => { b.set_json_path(&a.str(0, "column")?, &a.str(1, "path")?, a.value(2, "value")?)?; Ok(Ret::This) },
});

features!(spatial, feature::Spatial, |b, a| {
    "filterDistance" => {
        let point = a.array(1, "point")?;
        b.filter_distance(a.str(0, "attribute")?, &point, &a.str(2, "operator")?, a.float(3, "distance")?, a.bool_or(4, "meters", false)?);
        Ok(Ret::This)
    },
    "filterIntersects" => { b.filter_intersects(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterNotIntersects" => { b.filter_not_intersects(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterCrosses" => { b.filter_crosses(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterNotCrosses" => { b.filter_not_crosses(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterOverlaps" => { b.filter_overlaps(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterNotOverlaps" => { b.filter_not_overlaps(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterTouches" => { b.filter_touches(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterNotTouches" => { b.filter_not_touches(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterCovers" => { b.filter_covers(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterNotCovers" => { b.filter_not_covers(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterSpatialEquals" => { b.filter_spatial_equals(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
    "filterNotSpatialEquals" => { b.filter_not_spatial_equals(a.str(0, "attribute")?, a.array(1, "geometry")?); Ok(Ret::This) },
});

features!(full_text_search, feature::FullTextSearch, |b, a| {
    "filterSearch" => { b.filter_search(&a.str(0, "attribute")?, &a.str(1, "value")?)?; Ok(Ret::This) },
});

features!(negated_full_text_search, feature::NegatedFullTextSearch, |b, a| {
    "filterNotSearch" => { b.filter_not_search(&a.str(0, "attribute")?, &a.str(1, "value")?); Ok(Ret::This) },
});

features!(upsert, feature::Upsert, |b, _a| {
    "upsert" => Ok(Ret::obj(b.upsert()?)),
});

features!(upsert_select, feature::UpsertSelect, |b, _a| {
    "upsertSelect" => Ok(Ret::obj(b.upsert_select()?)),
});

features!(insert_or_ignore, feature::InsertOrIgnore, |b, _a| {
    "insertOrIgnore" => Ok(Ret::obj(b.insert_or_ignore()?)),
});

features!(table_sampling, feature::TableSampling, |b, a| {
    "tablesample" => { b.tablesample(a.float(0, "percent")?, &a.str_or(1, "method", "BERNOULLI")?)?; Ok(Ret::This) },
});

features!(sequences, feature::Sequences, |b, a| {
    "nextVal" => { b.next_val(&a.str(0, "sequence")?, &a.str_or(1, "alias", "")?)?; Ok(Ret::This) },
    "currVal" => { b.curr_val(&a.str(0, "sequence")?, &a.str_or(1, "alias", "")?)?; Ok(Ret::This) },
});

features!(returning, feature::Returning, |b, a| {
    "returning" => {
        let columns = if a.has(0, "columns") { a.strings(0, "columns")? } else { vec!["*".to_owned()] };
        b.returning(columns);
        Ok(Ret::This)
    },
});

features!(mysql_family, MySqlFamily, |b, a| {
    "updateJoin" => { b.update_join(a.str(0, "table")?, a.str(1, "left")?, a.str(2, "right")?, a.str_or(3, "alias", "")?); Ok(Ret::This) },
    "deleteJoin" => { b.delete_join(a.str(0, "alias")?, a.str(1, "table")?, a.str(2, "left")?, a.str(3, "right")?); Ok(Ret::This) },
    "maxExecutionTime" => { b.max_execution_time(a.int(0, "ms")?)?; Ok(Ret::This) },
});

/// The interfaces every builder implements (PHP's base `Builder`).
const BASE_INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\Selects",
    "Builder\\Feature\\Unions",
    "Builder\\Feature\\Updates",
    "Builder\\Feature\\Windows",
    "Compiler",
];

/// `class_implements(new MySQL())`.
const MYSQL_INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\BitwiseAggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\ConditionalAggregates",
    "Builder\\Feature\\CrossJoins",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\FullTextSearch",
    "Builder\\Feature\\Hints",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\InsertOrIgnore",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\Json",
    "Builder\\Feature\\LateralJoins",
    "Builder\\Feature\\Locking",
    "Builder\\Feature\\NegatedFullTextSearch",
    "Builder\\Feature\\RawSql",
    "Builder\\Feature\\Rollup",
    "Builder\\Feature\\Selects",
    "Builder\\Feature\\Spatial",
    "Builder\\Feature\\StatisticalAggregates",
    "Builder\\Feature\\StringAggregates",
    "Builder\\Feature\\Transactions",
    "Builder\\Feature\\Unions",
    "Builder\\Feature\\Updates",
    "Builder\\Feature\\Upsert",
    "Builder\\Feature\\UpsertSelect",
    "Builder\\Feature\\Windows",
    "Compiler",
];

#[allow(dead_code)]
fn base_interfaces() -> &'static [&'static str] {
    BASE_INTERFACES
}

/// Compile-time check that the Rust MySQL dialect has every feature PHP's class implements.
#[allow(dead_code)]
fn mysql_features<
    D: feature::BitwiseAggregates
        + feature::ConditionalAggregates
        + feature::CrossJoins
        + feature::FullTextSearch
        + feature::Hints
        + feature::InsertOrIgnore
        + feature::Json
        + feature::LateralJoins
        + feature::Locking
        + feature::NegatedFullTextSearch
        + feature::RawSql
        + feature::Rollup
        + feature::Spatial
        + feature::StatisticalAggregates
        + feature::StringAggregates
        + feature::Transactions
        + feature::Upsert
        + feature::UpsertSelect,
>() {
}

const _: fn() = mysql_features::<MySqlDialect>;

impl Object for MySql {
    fn class(&self) -> String {
        "Builder\\MySQL".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        base_call(self, method, a)
            .or_else(|| mysql_family(self, method, a))
            .or_else(|| locking(self, method, a))
            .or_else(|| transactions(self, method, a))
            .or_else(|| statistical(self, method, a))
            .or_else(|| bitwise(self, method, a))
            .or_else(|| raw_sql(self, method, a))
            .or_else(|| cross_joins(self, method, a))
            .or_else(|| lateral_joins(self, method, a))
            .or_else(|| rollup(self, method, a))
            .or_else(|| hints(self, method, a))
            .or_else(|| conditional_aggregates(self, method, a))
            .or_else(|| string_aggregates(self, method, a))
            .or_else(|| json(self, method, a))
            .or_else(|| spatial(self, method, a))
            .or_else(|| full_text_search(self, method, a))
            .or_else(|| negated_full_text_search(self, method, a))
            .or_else(|| upsert(self, method, a))
            .or_else(|| upsert_select(self, method, a))
            .or_else(|| insert_or_ignore(self, method, a))
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder\\SQL", "Builder"]
    }
    fn interfaces(&self) -> &'static [&'static str] {
        MYSQL_INTERFACES
    }
    query_object_boilerplate!();
}

impl Object for JoinBuilder {
    fn class(&self) -> String {
        "Builder\\JoinBuilder".into()
    }
    fn get(&self, property: &str) -> Option<Ret> {
        match property {
            "ons" => Some(Ret::List(self.ons().iter().cloned().map(Ret::obj).collect())),
            "wheres" => Some(Ret::List(self.wheres().iter().cloned().map(Ret::obj).collect())),
            _ => None,
        }
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            match method {
                "on" => {
                    self.on(&a.str(0, "left")?, &a.str(1, "right")?, &a.str_or(2, "operator", "=")?)?;
                }
                "onRaw" => {
                    self.on_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?);
                }
                "where" => {
                    self.where_(&a.str(0, "column")?, &a.str(1, "operator")?, a.value(2, "value")?)?;
                }
                "whereRaw" => {
                    self.where_raw(a.str(0, "expression")?, a.array_or_empty(1, "bindings")?);
                }
                _ => return Err(fault(format!("JoinBuilder has no method {method}"))),
            }
            Ok(Ret::This)
        })())
    }
    query_object_boilerplate!();
}

impl Object for utopia_query::builder::JoinOn {
    fn class(&self) -> String {
        "Builder\\JoinOn".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("left", Ret::str(&self.left)), ("operator", Ret::str(&self.operator)), ("right", Ret::str(&self.right))]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for CaseExpression {
    fn class(&self) -> String {
        "Builder\\Case\\Expression".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            match method {
                "when" => {
                    let operator = match a.enum_case(1, "operator")? {
                        Some((_, case)) => Operator::from_name(&case).ok_or_else(|| fault("bad operator"))?,
                        None => return Err(fault("missing operator")),
                    };
                    self.when(a.str(0, "column")?, operator, a.value(2, "value")?, a.value(3, "then")?);
                }
                "whenNull" => {
                    self.when_null(a.str(0, "column")?, a.value(1, "then")?);
                }
                "whenNotNull" => {
                    self.when_not_null(a.str(0, "column")?, a.value(1, "then")?);
                }
                "whenIn" => {
                    self.when_in(a.str(0, "column")?, a.array(1, "values")?, a.value(2, "then")?)?;
                }
                "whenRaw" => {
                    self.when_raw(a.str(0, "condition")?, a.value(1, "then")?, a.array_or_empty(2, "conditionBindings")?);
                }
                "else" => {
                    self.else_(a.value(0, "value")?);
                }
                "alias" => {
                    self.alias(a.str(0, "alias")?);
                }
                "getWhens" => return Ok(Ret::List(self.whens().iter().cloned().map(Ret::obj).collect())),
                "hasElse" => return Ok(Ret::bool(self.has_else())),
                "getElse" => return Ok(Ret::Value(self.else_value().clone())),
                "getAlias" => return Ok(Ret::str(self.get_alias())),
                _ => return Err(fault(format!("Case\\Expression has no method {method}"))),
            }
            Ok(Ret::This)
        })())
    }
    query_object_boilerplate!();
}

impl Object for utopia_query::builder::WhenClause {
    fn class(&self) -> String {
        "Builder\\Case\\WhenClause".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("kind", Ret::Enum("Builder\\Case\\Kind".into(), self.kind.name())),
            ("column", Ret::opt_str(self.column.as_ref())),
            ("operator", self.operator.map(|o| Ret::Enum("Builder\\Case\\Operator".into(), o.name())).unwrap_or_else(Ret::null)),
            ("value", Ret::Value(self.value.clone())),
            ("then", Ret::Value(self.then.clone())),
            ("values", Ret::values(&self.values)),
            ("rawCondition", Ret::opt_str(self.raw_condition.as_ref())),
            ("rawBindings", Ret::values(&self.raw_bindings)),
        ]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

impl Object for WindowFrame {
    fn class(&self) -> String {
        "Builder\\WindowFrame".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![("type", Ret::str(&self.frame_type)), ("start", Ret::str(&self.start)), ("end", Ret::opt_str(self.end.as_ref()))]
    }
    fn call(&mut self, method: &str, _a: &mut Args) -> Option<Outcome> {
        (method == "toSql").then(|| Ok(Ret::str(self.to_sql())))
    }
    query_object_boilerplate!();
}

impl Object for SpatialDistanceFilter {
    fn class(&self) -> String {
        "Builder\\SpatialDistanceFilter".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("geometry", Ret::Value(self.geometry.clone())),
            ("distance", Ret::Value(Value::Float(self.distance))),
            ("meters", Ret::bool(self.meters)),
        ]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}

#[allow(dead_code)]
fn unused(_: Arc<()>, _: Statement, _: &Query) {}
