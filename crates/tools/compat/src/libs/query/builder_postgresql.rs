//! `Builder\PostgreSQL` on [`PostgreSql`]: the base methods, its features,
//! and its own (`MERGE`, `UPDATE ... FROM`, `DELETE ... USING`, `DISTINCT ON`,
//! vector ordering, ordered-set and `FILTER` aggregates, `FOR ... OF`).

use utopia_query::builder::{ExplainOptions, PostgreSql, VectorMetric};

use super::builder::{
    base_call, bitwise, builder_arg, conditional_aggregates, cross_joins, cube, full_outer_joins, full_text_search,
    insert_or_ignore, json, lateral_joins, locking, negated_full_text_search, raw_sql, rest_values, returning, rollup,
    sequences, spatial, statistical, string_aggregates, table_sampling, transactions, upsert, upsert_select,
};
use super::engine::{Args, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// `class_implements(new PostgreSQL())`.
const INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\BitwiseAggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\ConditionalAggregates",
    "Builder\\Feature\\CrossJoins",
    "Builder\\Feature\\Cube",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\FullOuterJoins",
    "Builder\\Feature\\FullTextSearch",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\InsertOrIgnore",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\Json",
    "Builder\\Feature\\LateralJoins",
    "Builder\\Feature\\Locking",
    "Builder\\Feature\\NegatedFullTextSearch",
    "Builder\\Feature\\PostgreSQL\\AggregateFilter",
    "Builder\\Feature\\PostgreSQL\\DistinctOn",
    "Builder\\Feature\\PostgreSQL\\LockingOf",
    "Builder\\Feature\\PostgreSQL\\Merge",
    "Builder\\Feature\\PostgreSQL\\OrderedSetAggregates",
    "Builder\\Feature\\PostgreSQL\\Returning",
    "Builder\\Feature\\PostgreSQL\\VectorSearch",
    "Builder\\Feature\\RawSql",
    "Builder\\Feature\\Rollup",
    "Builder\\Feature\\Selects",
    "Builder\\Feature\\Sequences",
    "Builder\\Feature\\Spatial",
    "Builder\\Feature\\StatisticalAggregates",
    "Builder\\Feature\\StringAggregates",
    "Builder\\Feature\\TableSampling",
    "Builder\\Feature\\Transactions",
    "Builder\\Feature\\Unions",
    "Builder\\Feature\\Updates",
    "Builder\\Feature\\Upsert",
    "Builder\\Feature\\UpsertSelect",
    "Builder\\Feature\\Windows",
    "Compiler",
];

/// PostgreSQL's own methods.
fn own(b: &mut PostgreSql, method: &str, a: &mut Args) -> Option<Outcome> {
    Some((|| -> Outcome {
        Ok(match method {
            "explain" => Ret::obj(b.explain_with(
                a.bool_or(0, "analyze", false)?,
                &ExplainOptions {
                    verbose: a.bool_or(1, "verbose", false)?,
                    buffers: a.bool_or(2, "buffers", false)?,
                    format: a.str_or(3, "format", "")?,
                },
            )?),
            "updateFrom" => {
                b.update_from(a.str(0, "table")?, a.str_or(1, "alias", "")?);
                Ret::This
            }
            "updateFromWhere" => {
                let bindings = rest_values(a, 1)?;
                b.update_from_where(a.str(0, "condition")?, bindings);
                Ret::This
            }
            "deleteUsing" => {
                let bindings = rest_values(a, 2)?;
                b.delete_using(a.str(0, "table")?, a.str(1, "condition")?, bindings);
                Ret::This
            }
            "distinctOn" => {
                b.distinct_on(a.strings(0, "columns")?);
                Ret::This
            }
            "forUpdateOf" => {
                b.for_update_of(a.str(0, "table")?);
                Ret::This
            }
            "forShareOf" => {
                b.for_share_of(a.str(0, "table")?);
                Ret::This
            }
            "orderByVectorDistance" => {
                let metric = match a.enum_case(2, "metric")? {
                    Some((_, case)) => VectorMetric::from_name(&case).ok_or_else(|| fault(format!("no VectorMetric::{case}")))?,
                    None => VectorMetric::Cosine,
                };
                b.order_by_vector_distance(a.str(0, "attribute")?, a.array(1, "vector")?, metric);
                Ret::This
            }
            "selectAggregateFilter" => {
                b.select_aggregate_filter(
                    &a.str(0, "aggregateExpr")?,
                    &a.str(1, "filterCondition")?,
                    &a.str_or(2, "alias", "")?,
                    a.array_or_empty(3, "bindings")?,
                )?;
                Ret::This
            }
            "arrayAgg" | "boolAnd" | "boolOr" | "every" | "mode" => {
                let column = a.str(0, "column")?;
                let alias = a.str_or(1, "alias", "")?;
                match method {
                    "arrayAgg" => b.array_agg(&column, &alias)?,
                    "boolAnd" => b.bool_and(&column, &alias)?,
                    "boolOr" => b.bool_or(&column, &alias)?,
                    "every" => b.every(&column, &alias)?,
                    _ => b.mode(&column, &alias)?,
                };
                Ret::This
            }
            "percentileCont" | "percentileDisc" => {
                let fraction = a.float(0, "fraction")?;
                let column = a.str(1, "orderColumn")?;
                let alias = a.str_or(2, "alias", "")?;
                if method == "percentileCont" {
                    b.percentile_cont(fraction, &column, &alias)?;
                } else {
                    b.percentile_disc(fraction, &column, &alias)?;
                }
                Ret::This
            }
            "mergeInto" => {
                b.merge_into(a.str(0, "target")?);
                Ret::This
            }
            "using" => {
                let source = builder_arg(a, 0, "source")?;
                b.using(source, a.str(1, "alias")?);
                Ret::This
            }
            "on" => {
                let bindings = rest_values(a, 1)?;
                b.on(a.str(0, "condition")?, bindings);
                Ret::This
            }
            "whenMatched" => {
                let bindings = rest_values(a, 1)?;
                b.when_matched(a.str(0, "action")?, bindings);
                Ret::This
            }
            "whenNotMatched" => {
                let bindings = rest_values(a, 1)?;
                b.when_not_matched(a.str(0, "action")?, bindings);
                Ret::This
            }
            "executeMerge" => Ret::obj(b.execute_merge()?),
            _ => return Err(fault("not a PostgreSQL method")),
        })
    })())
    .filter(|r| !matches!(r, Err(super::engine::Failure::Fault(f)) if f == "not a PostgreSQL method"))
}

impl Object for PostgreSql {
    fn class(&self) -> String {
        "Builder\\PostgreSQL".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        own(self, method, a)
            .or_else(|| base_call(self, method, a))
            .or_else(|| locking(self, method, a))
            .or_else(|| transactions(self, method, a))
            .or_else(|| statistical(self, method, a))
            .or_else(|| bitwise(self, method, a))
            .or_else(|| raw_sql(self, method, a))
            .or_else(|| cross_joins(self, method, a))
            .or_else(|| full_outer_joins(self, method, a))
            .or_else(|| lateral_joins(self, method, a))
            .or_else(|| rollup(self, method, a))
            .or_else(|| cube(self, method, a))
            .or_else(|| conditional_aggregates(self, method, a))
            .or_else(|| string_aggregates(self, method, a))
            .or_else(|| json(self, method, a))
            .or_else(|| spatial(self, method, a))
            .or_else(|| full_text_search(self, method, a))
            .or_else(|| negated_full_text_search(self, method, a))
            .or_else(|| upsert(self, method, a))
            .or_else(|| upsert_select(self, method, a))
            .or_else(|| insert_or_ignore(self, method, a))
            .or_else(|| table_sampling(self, method, a))
            .or_else(|| sequences(self, method, a))
            .or_else(|| returning(self, method, a))
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder\\SQL", "Builder"]
    }
    fn interfaces(&self) -> &'static [&'static str] {
        INTERFACES
    }
    query_object_boilerplate!();
}
