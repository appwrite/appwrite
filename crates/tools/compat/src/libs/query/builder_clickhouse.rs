//! The ClickHouse builder of the query adapter: `Builder\ClickHouse`, its
//! `Format` and `AsofOperator` enums and `FormattedInsertStatement`.

use utopia_query::builder::{
    AsofOperator, ClickHouse, ClickHouseDialect, DeleteMode, Format, FormattedInsertStatement, feature,
};
use utopia_query::{Array, Value};

use super::builder::{
    base_call, bitwise, conditional_aggregates, cross_joins, cube, full_outer_joins, hints, raw_sql, rollup,
    string_aggregates, table_sampling, totals,
};
use super::core::executor;
use super::engine::{Args, Failure, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// `ClickHouse::CONSTANT`.
pub(crate) fn constant(class: &str, name: &str) -> Option<Ret> {
    match (class, name) {
        ("Builder\\ClickHouse", "DELETE_MODE_LIGHTWEIGHT") => Some(Ret::str(DeleteMode::Lightweight.as_str())),
        ("Builder\\ClickHouse", "DELETE_MODE_MUTATION") => Some(Ret::str(DeleteMode::Mutation.as_str())),
        _ => None,
    }
}

/// `Format::Case->method(...)`, `AsofOperator::Case->value`.
pub(crate) fn enum_call(class: &str, case: &str, method: &str, a: &mut Args) -> Option<Outcome> {
    match class {
        "Builder\\ClickHouse\\AsofOperator" => {
            let v = AsofOperator::from_name(case)?;
            (method == "->value").then(|| Ok(Ret::str(v.as_str())))
        }
        "Builder\\ClickHouse\\Format" => {
            let v = Format::from_name(case)?;
            match method {
                "->value" => Some(Ok(Ret::str(v.as_str()))),
                "serialize" => Some((|| {
                    let rows = rows_arg(a, 0, "rows")?;
                    let columns = a.opt_strings(1, "columns")?;
                    Ok(Ret::str(v.serialize(&rows, columns.as_deref())?))
                })()),
                _ => None,
            }
        }
        _ => None,
    }
}

/// An iterable of rows (an array or a generator), each an array.
fn rows_arg(a: &Args, index: usize, name: &str) -> Result<Vec<Array>, Failure> {
    row_values(a, index, name)?
        .into_iter()
        .map(|row| match row {
            Value::Array(row) => Ok(row),
            other => Err(fault(format!("row is not an array: {}", other.debug_type()))),
        })
        .collect()
}

fn row_values(a: &Args, index: usize, name: &str) -> Result<Vec<Value>, Failure> {
    match a.value(index, name)? {
        Value::Array(rows) => Ok(rows.values().cloned().collect()),
        other => Err(fault(format!("rows are not iterable: {}", other.debug_type()))),
    }
}

fn string_pairs(a: &Args, index: usize, name: &str) -> Result<Vec<(String, String)>, Failure> {
    a.entries(index, name)?
        .into_iter()
        .map(|(key, raw)| {
            let key = match key {
                php_std::ArrayKey::Int(i) => i.to_string(),
                php_std::ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
            };
            let value = a.php(raw)?;
            let value =
                value.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default();
            Ok((key, value))
        })
        .collect()
}

fn opt_value(a: &Args, index: usize, name: &str) -> Result<Option<Value>, Failure> {
    if a.has(index, name) { a.value(index, name).map(Some) } else { Ok(None) }
}

/// ClickHouse's own methods (and its overrides of base and feature methods).
fn clickhouse_call(b: &mut ClickHouse, method: &str, a: &mut Args) -> Option<Outcome> {
    let alias = |a: &Args, i: usize| a.str_or(i, "alias", "");
    Some((|| -> Outcome {
        Ok(match method {
            "prewhere" => {
                b.prewhere(a.queries(0, "queries")?);
                Ret::This
            }
            "final" => {
                b.final_();
                Ret::This
            }
            "sample" => {
                b.sample(a.float(0, "fraction")?)?;
                Ret::This
            }
            "settings" => {
                b.settings(string_pairs(a, 0, "settings")?)?;
                Ret::This
            }
            "bulkInsert" => {
                let format = match a.enum_case(0, "format")? {
                    Some((_, case)) => Format::from_name(&case).ok_or_else(|| fault(format!("no Format::{case}")))?,
                    None => return Err(fault("missing format")),
                };
                let rows = row_values(a, 1, "rows")?;
                Ret::obj(b.bulk_insert(format, rows, a.strings_or_empty(2, "columns")?)?)
            }
            "insertFormat" => {
                b.insert_format(&a.str(0, "format")?, a.strings_or_empty(1, "columns")?)?;
                Ret::This
            }
            "insert" => match b.formatted_insert()? {
                Some(formatted) => Ret::obj(formatted),
                None => Ret::obj(b.insert()?),
            },
            "useNamedBindings" => {
                b.use_named_bindings(a.bool_or(0, "enabled", true)?);
                Ret::This
            }
            "withParamType" => {
                b.with_param_type(a.str(0, "column")?, &a.str(1, "type")?)?;
                Ret::This
            }
            "withParamTypes" => {
                b.with_param_types(string_pairs(a, 0, "types")?)?;
                Ret::This
            }
            "deleteMode" => {
                b.delete_mode(DeleteMode::parse(&a.str(0, "mode")?)?);
                Ret::This
            }
            "stddev" => {
                b.stddev(&a.str(0, "attribute")?, &alias(a, 1)?)?;
                Ret::This
            }
            "variance" => {
                b.variance(&a.str(0, "attribute")?, &alias(a, 1)?)?;
                Ret::This
            }
            "stddevPop" => {
                b.stddev_pop(a.str(0, "attribute")?, alias(a, 1)?);
                Ret::This
            }
            "stddevSamp" => {
                b.stddev_samp(a.str(0, "attribute")?, alias(a, 1)?);
                Ret::This
            }
            "varPop" => {
                b.var_pop(a.str(0, "attribute")?, alias(a, 1)?);
                Ret::This
            }
            "varSamp" => {
                b.var_samp(a.str(0, "attribute")?, alias(a, 1)?);
                Ret::This
            }
            "quantile" => {
                b.quantile(a.float(0, "level")?, &a.str(1, "column")?, &alias(a, 2)?)?;
                Ret::This
            }
            "quantileExact" => {
                b.quantile_exact(a.float(0, "level")?, &a.str(1, "column")?, &alias(a, 2)?)?;
                Ret::This
            }
            "quantiles" => {
                let levels: Vec<f64> = a.array(0, "levels")?.values().map(Value::to_double).collect();
                b.quantiles(&levels, &a.str(1, "column")?, &alias(a, 2)?)?;
                Ret::This
            }
            "median"
            | "uniq"
            | "uniqExact"
            | "uniqCombined"
            | "anyValue"
            | "anyLastValue"
            | "groupUniqArray"
            | "groupArrayMovingAvg"
            | "groupArrayMovingSum" => {
                let column = a.str(0, "column")?;
                let alias = alias(a, 1)?;
                match method {
                    "median" => b.median(&column, &alias),
                    "uniq" => b.uniq(&column, &alias),
                    "uniqExact" => b.uniq_exact(&column, &alias),
                    "uniqCombined" => b.uniq_combined(&column, &alias),
                    "anyValue" => b.any_value(&column, &alias),
                    "anyLastValue" => b.any_last_value(&column, &alias),
                    "groupUniqArray" => b.group_uniq_array(&column, &alias),
                    "groupArrayMovingAvg" => b.group_array_moving_avg(&column, &alias),
                    _ => b.group_array_moving_sum(&column, &alias),
                }?;
                Ret::This
            }
            "argMin" | "argMax" => {
                let (value, arg, alias) = (a.str(0, "valueColumn")?, a.str(1, "argColumn")?, alias(a, 2)?);
                if method == "argMin" { b.arg_min(&value, &arg, &alias) } else { b.arg_max(&value, &arg, &alias) }?;
                Ret::This
            }
            "topK" => {
                b.top_k(a.int(0, "k")?, &a.str(1, "column")?, &alias(a, 2)?)?;
                Ret::This
            }
            "topKWeighted" => {
                b.top_k_weighted(a.int(0, "k")?, &a.str(1, "column")?, &a.str(2, "weightColumn")?, &alias(a, 3)?)?;
                Ret::This
            }
            "arrayJoin" => {
                b.array_join(a.str(0, "column")?, alias(a, 1)?);
                Ret::This
            }
            "leftArrayJoin" => {
                b.left_array_join(a.str(0, "column")?, alias(a, 1)?);
                Ret::This
            }
            "asofJoin" | "asofLeftJoin" => {
                let table = a.str(0, "table")?;
                let pairs = string_pairs(a, 1, "equiPairs")?;
                let left = a.str(2, "leftInequality")?;
                let operator = match a.enum_case(3, "operator")? {
                    Some((_, case)) => {
                        AsofOperator::from_name(&case).ok_or_else(|| fault(format!("no AsofOperator::{case}")))?
                    }
                    None => return Err(fault("missing operator")),
                };
                let right = a.str(4, "rightInequality")?;
                let alias = alias(a, 5)?;
                if method == "asofJoin" {
                    b.asof_join(&table, pairs, &left, operator, &right, &alias)?;
                } else {
                    b.asof_left_join(&table, pairs, &left, operator, &right, &alias)?;
                }
                Ret::This
            }
            "limitBy" => {
                b.limit_by(a.int(0, "count")?, a.strings(1, "columns")?);
                Ret::This
            }
            "orderWithFill" => {
                b.order_with_fill(
                    &a.str(0, "column")?,
                    &a.str_or(1, "direction", "ASC")?,
                    opt_value(a, 2, "from")?,
                    opt_value(a, 3, "to")?,
                    opt_value(a, 4, "step")?,
                )?;
                Ret::This
            }
            _ => return Err(fault("not a ClickHouse method")),
        })
    })())
    .filter(|r| !matches!(r, Err(Failure::Fault(f)) if f == "not a ClickHouse method"))
}

/// `class_implements(new ClickHouse())`.
const CLICKHOUSE_INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\BitwiseAggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\ClickHouse\\ApproximateAggregates",
    "Builder\\Feature\\ClickHouse\\ArrayJoins",
    "Builder\\Feature\\ClickHouse\\AsofJoins",
    "Builder\\Feature\\ClickHouse\\LimitBy",
    "Builder\\Feature\\ClickHouse\\WithFill",
    "Builder\\Feature\\ConditionalAggregates",
    "Builder\\Feature\\CrossJoins",
    "Builder\\Feature\\Cube",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\FullOuterJoins",
    "Builder\\Feature\\Hints",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\RawSql",
    "Builder\\Feature\\Rollup",
    "Builder\\Feature\\Selects",
    "Builder\\Feature\\StatisticalAggregates",
    "Builder\\Feature\\StringAggregates",
    "Builder\\Feature\\TableSampling",
    "Builder\\Feature\\Totals",
    "Builder\\Feature\\Unions",
    "Builder\\Feature\\Updates",
    "Builder\\Feature\\Windows",
    "Compiler",
];

/// Compile-time check that the Rust ClickHouse dialect has the shared
/// features PHP's class implements (`StatisticalAggregates` is inherent: its
/// `stddev` and `variance` differ).
#[allow(dead_code)]
fn clickhouse_features<
    D: feature::BitwiseAggregates
        + feature::ConditionalAggregates
        + feature::CrossJoins
        + feature::Cube
        + feature::FullOuterJoins
        + feature::Hints
        + feature::RawSql
        + feature::Rollup
        + feature::StringAggregates
        + feature::TableSampling
        + feature::Totals,
>() {
}

const _: fn() = clickhouse_features::<ClickHouseDialect>;

impl Object for ClickHouse {
    fn class(&self) -> String {
        "Builder\\ClickHouse".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        clickhouse_call(self, method, a)
            .or_else(|| base_call(self, method, a))
            .or_else(|| bitwise(self, method, a))
            .or_else(|| raw_sql(self, method, a))
            .or_else(|| cross_joins(self, method, a))
            .or_else(|| full_outer_joins(self, method, a))
            .or_else(|| rollup(self, method, a))
            .or_else(|| cube(self, method, a))
            .or_else(|| totals(self, method, a))
            .or_else(|| hints(self, method, a))
            .or_else(|| conditional_aggregates(self, method, a))
            .or_else(|| string_aggregates(self, method, a))
            .or_else(|| table_sampling(self, method, a))
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder"]
    }
    fn interfaces(&self) -> &'static [&'static str] {
        CLICKHOUSE_INTERFACES
    }
    query_object_boilerplate!();
}

impl Object for FormattedInsertStatement {
    fn class(&self) -> String {
        "Builder\\ClickHouse\\FormattedInsertStatement".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("query", Ret::str(&self.query)),
            ("bindings", Ret::values(&self.bindings)),
            ("readOnly", Ret::bool(self.read_only)),
            (
                "namedBindings",
                match &self.named_bindings {
                    Some(a) => Ret::Value(Value::Array(a.clone())),
                    None => Ret::null(),
                },
            ),
            ("columns", Ret::strings(&self.columns)),
            ("format", Ret::str(&self.format)),
            ("body", Ret::opt_str(self.body.as_ref())),
        ]
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        Some((|| -> Outcome {
            Ok(match method {
                "execute" => Ret::Value(self.execute()?),
                "withExecutor" => Ret::obj(self.with_executor(executor(a.closure(0, "executor")?))),
                _ => return Err(fault(format!("FormattedInsertStatement has no method {method}"))),
            })
        })())
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder\\Statement"]
    }
    query_object_boilerplate!();
}
