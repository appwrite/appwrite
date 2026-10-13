//! Reads: query parsing and validation, rows, aggregates, counts, sums,
//! time series, the daily table, and purges.

use php_std::ArrayKey;
use php_std::datetime::DateTime;
use php_std::types::KeyRef;
use utopia_query::builder::ClickHouse as Builder;
use utopia_query::{Array, Method, Object, Query, Value};

use super::setup::{EVENT_PROJECTIONS, EVENT_TIME_BUCKET};
use super::{ClickHouse, Params, decode_rows, decode_total, escape, field, format_text_time, named, param_type, prefix_bindings};
use crate::adapter::{Point, Series, TimeSeries, Totals};
use crate::error::{Error, Result};
use crate::metric::{Kind, Metric, Number};
use crate::{php, query as usage_query};

/// Filter methods that need at least one value.
const VALUE_REQUIRED: &[Method] = &[
    Method::Equal,
    Method::NotEqual,
    Method::LessThan,
    Method::LessThanEqual,
    Method::GreaterThan,
    Method::GreaterThanEqual,
    Method::Between,
    Method::NotBetween,
    Method::Contains,
    Method::ContainsAny,
    Method::NotContains,
    Method::StartsWith,
    Method::EndsWith,
];

/// Columns of the daily table.
pub(super) const DAILY_COLUMNS: &[&str] =
    &["metric", "value", "time", "resourceType", "resourceId", "resourceInternalId", "teamId", "teamInternalId"];

const HOUR_MILLIS: i64 = 3_600_000;

/// Intervals the hourly bucket can serve.
const BUCKET_ROUTABLE_INTERVALS: &[&str] = &["1h", "1d", "1w", "1M"];

/// The parts of a query list the builder consumes.
#[derive(Debug, Clone, Default)]
pub(super) struct Parsed {
    pub filters: Vec<Query>,
    pub order: Vec<(String, &'static str)>,
    pub limit: Option<i64>,
    pub offset: Option<i64>,
    pub interval: Option<String>,
    pub group_by: Vec<String>,
    pub aggregate: Option<&'static str>,
    pub cursor: Option<Array>,
    pub before: bool,
}

/// Time filters re-expressed on the hourly bucket.
pub(super) struct Bucketed {
    pub filters: Vec<Query>,
    pub conditions: Vec<String>,
    pub bindings: Params,
}

struct Branch {
    time: String,
    conditions: Vec<String>,
    bindings: Params,
}

struct Split {
    filters: Vec<Query>,
    branches: Vec<Branch>,
}

pub(super) fn first(values: &Array) -> Option<&Value> {
    values.get(&ArrayKey::Int(0))
}

/// `$values[0] ?? null` as a time string: a string as is (a `DateTime` is
/// not a query value here).
pub(super) fn stringify_time(value: Option<&Value>) -> Option<String> {
    match value {
        Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
        _ => None,
    }
}

/// `str_replace(' ', 'T', $t) . '+00:00'` unless it has a `T`.
pub(super) fn iso(text: &str) -> String {
    if text.contains('T') { text.to_owned() } else { format!("{}+00:00", text.replace(' ', "T")) }
}

fn ucfirst(s: &str) -> String {
    let mut chars = s.chars();
    match chars.next() {
        Some(c) => c.to_ascii_uppercase().to_string() + chars.as_str(),
        None => String::new(),
    }
}

fn epoch_millis(value: Option<&Value>) -> Option<i64> {
    let text = stringify_time(value)?;
    let dt = php::datetime_utc(text.as_bytes()).ok()?;
    Some(dt.timestamp() * 1000 + php::format(&dt, "v").parse::<i64>().unwrap_or(0))
}

fn millis_to_wire(millis: i64) -> String {
    let seconds = millis / 1000;
    let dt = php::datetime(format!("@{seconds}").as_bytes()).map(|d| php::format(&d, "Y-m-d H:i:s")).unwrap_or_default();
    format!("{dt}.{:0>3}", millis % 1000)
}

impl<A: utopia_client::Adapter> ClickHouse<A> {
    /// Tenant scope and filters on `builder`.
    pub(super) fn apply_filters(&self, builder: &mut Builder, tenant: Option<&str>, filters: &[Query]) -> Result<()> {
        if self.config.shared_tables
            && let Some(tenant) = tenant
        {
            if tenant.is_empty() {
                return Err(Error::exception("Tenant cannot be empty in shared-tables mode"));
            }
            builder.filter([Query::equal("tenant", [tenant])]);
        }
        if !filters.is_empty() {
            builder.filter(filters.iter().cloned());
        }
        Ok(())
    }

    fn validate_group_by(&self, attribute: &str, kind: Kind) -> Result<()> {
        let mut allowed: Vec<&str> = kind.columns().to_vec();
        if self.config.shared_tables {
            allowed.push("tenant");
        }
        if allowed.contains(&attribute) {
            return Ok(());
        }
        Err(Error::exception(format!(
            "Invalid groupBy attribute '{attribute}' for {}. Allowed: {}",
            kind.as_str(),
            allowed.join(", ")
        )))
    }

    pub(super) fn validate_daily_attribute(&self, name: &str) -> Result<()> {
        if name == "id" || (name == "tenant" && self.config.shared_tables) || DAILY_COLUMNS.contains(&name) {
            return Ok(());
        }
        Err(Error::exception(format!(
            "Invalid attribute '{name}' for daily table. Allowed: {}{}.",
            DAILY_COLUMNS.join(", "),
            if self.config.shared_tables { ", tenant" } else { "" }
        )))
    }

    /// Validates `queries` and splits them for the builder.
    ///
    /// PHP: the private `parseQueries()`.
    pub(super) fn parse(&self, tenant: Option<&str>, queries: &[Query], kind: Kind) -> Result<Parsed> {
        if self.config.shared_tables && tenant == Some("") {
            return Err(Error::exception("Tenant cannot be empty in shared-tables mode"));
        }
        let mut parsed = Parsed::default();
        for query in queries {
            let method = query.method();
            let attribute = query.attribute();
            let values = query.values();
            if VALUE_REQUIRED.contains(&method) && values.is_empty() {
                return Err(Error::exception(format!(
                    "{} queries require at least one value.",
                    ucfirst(method.as_str())
                )));
            }
            match method {
                Method::Equal
                | Method::NotEqual
                | Method::LessThan
                | Method::LessThanEqual
                | Method::GreaterThan
                | Method::GreaterThanEqual
                | Method::Between
                | Method::NotBetween
                | Method::IsNull
                | Method::IsNotNull => {
                    self.validate_attribute(attribute, kind)?;
                    parsed.filters.push(query.clone());
                }
                Method::Contains | Method::ContainsAny | Method::NotContains => {
                    self.validate_attribute(attribute, kind)?;
                    if values.values().any(|v| !matches!(v, Value::Str(_))) {
                        return Err(Error::exception(format!(
                            "{} value must be a string for attribute '{attribute}'",
                            method.as_str()
                        )));
                    }
                    parsed.filters.push(query.clone());
                }
                Method::StartsWith | Method::EndsWith => {
                    self.validate_attribute(attribute, kind)?;
                    if !matches!(first(values), Some(Value::Str(_))) {
                        let word = if method == Method::StartsWith { "startsWith" } else { "endsWith" };
                        return Err(Error::exception(format!(
                            "{word} needle must be a string for attribute '{attribute}'"
                        )));
                    }
                    parsed.filters.push(query.clone());
                }
                Method::OrderDesc => {
                    self.validate_attribute(attribute, kind)?;
                    parsed.order.push((attribute.to_owned(), "DESC"));
                }
                Method::OrderAsc => {
                    self.validate_attribute(attribute, kind)?;
                    parsed.order.push((attribute.to_owned(), "ASC"));
                }
                Method::CursorAfter | Method::CursorBefore => {
                    if parsed.cursor.is_some() {
                        continue;
                    }
                    let raw = first(values).filter(|v| !matches!(v, Value::Null));
                    let Some(raw) = raw else { continue };
                    parsed.cursor = Some(cursor_row(raw)?);
                    parsed.before = method == Method::CursorBefore;
                }
                Method::Limit => match first(values) {
                    Some(Value::Int(i)) => parsed.limit = Some(*i),
                    _ => return Err(Error::exception("Invalid limit value. Expected int")),
                },
                Method::Offset => match first(values) {
                    Some(Value::Int(i)) => parsed.offset = Some(*i),
                    _ => return Err(Error::exception("Invalid offset value. Expected int")),
                },
                Method::GroupByTimeBucket => {
                    self.validate_attribute(attribute, kind)?;
                    let names =
                        usage_query::VALID_INTERVALS.iter().map(|(n, _)| *n).collect::<Vec<_>>().join(", ");
                    let interval = match first(values) {
                        None | Some(Value::Null) => "1h".to_owned(),
                        Some(Value::Str(s)) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                        Some(other) => {
                            return Err(Error::exception(format!(
                                "Invalid groupByInterval interval: expected string, got {}. Allowed: {names}",
                                other.debug_type()
                            )));
                        }
                    };
                    if usage_query::interval_sql(&interval).is_none() {
                        return Err(Error::exception(format!(
                            "Invalid groupByInterval interval '{interval}'. Allowed: {names}"
                        )));
                    }
                    parsed.interval = Some(interval);
                }
                Method::GroupBy => {
                    let mut dims: Vec<String> = Vec::new();
                    if !attribute.is_empty() {
                        dims.push(attribute.to_owned());
                    }
                    for value in values.values() {
                        if let Value::Str(s) = value
                            && !s.is_empty()
                        {
                            dims.push(String::from_utf8_lossy(s.as_bytes()).into_owned());
                        }
                    }
                    for dim in dims {
                        self.validate_group_by(&dim, kind)?;
                        if !parsed.group_by.contains(&dim) {
                            parsed.group_by.push(dim);
                        }
                    }
                }
                Method::Max => parsed.aggregate = usage_query::extract_aggregate(std::slice::from_ref(query)),
                other => {
                    return Err(Error::exception(format!(
                        "Unsupported query method for the ClickHouse adapter: {}",
                        other.as_str()
                    )));
                }
            }
        }
        parsed.filters = normalize_times(std::mem::take(&mut parsed.filters))?;
        Ok(parsed)
    }

    pub(super) async fn find_scoped(&self, tenant: Option<&str>, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        if let Some(kind) = kind {
            return self.find_from(tenant, queries, kind).await;
        }
        let mut limit = None;
        for query in queries {
            match query.method() {
                Method::CursorAfter | Method::CursorBefore => {
                    return Err(Error::exception("Cursor pagination requires an explicit $type (event or gauge)"));
                }
                Method::Limit => {
                    if let Some(v) = first(query.values())
                        && php::is_numeric(v)
                    {
                        limit = Some(match v {
                            Value::Int(i) => *i,
                            Value::Float(f) => php_std::format::dval_to_lval(*f),
                            Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
                            _ => 0,
                        });
                    }
                }
                _ => {}
            }
        }
        let mut merged = if self.queries_match(queries, Kind::Event) {
            self.find_from(tenant, queries, Kind::Event).await?
        } else {
            Vec::new()
        };
        if self.queries_match(queries, Kind::Gauge) {
            merged.extend(self.find_from(tenant, queries, Kind::Gauge).await?);
        }
        if let Some(limit) = limit
            && (merged.len() as i64) > limit
        {
            merged.truncate(limit.max(0) as usize);
        }
        Ok(merged)
    }

    async fn find_from(&self, tenant: Option<&str>, queries: &[Query], kind: Kind) -> Result<Vec<Metric>> {
        let table = self.table_for(kind);
        let parsed = self.parse(tenant, queries, kind)?;
        if parsed.cursor.is_some() && parsed.interval.is_some() {
            return Err(Error::exception("Cursor pagination cannot be combined with groupByInterval"));
        }
        if parsed.interval.is_some() || !parsed.group_by.is_empty() || parsed.aggregate.is_some() {
            return self.find_aggregated(tenant, &parsed, &table, kind).await;
        }

        let mut order = parsed.order.clone();
        let mut builder = self.builder(kind);
        builder.from(table.as_str(), "").select(self.select_columns(kind));
        self.apply_filters(&mut builder, tenant, &parsed.filters)?;

        let mut extra = Params::new();
        if let Some(cursor) = &parsed.cursor {
            order = resolve_cursor_order(order);
            extra = apply_cursor(&mut builder, &order, cursor, parsed.before)?;
        }
        apply_order(&mut builder, &order, parsed.before && parsed.cursor.is_some());
        if let Some(limit) = parsed.limit {
            builder.limit(limit);
        }
        if let Some(offset) = parsed.offset {
            builder.offset(offset);
        }
        let statement = builder.build()?;
        let sql = format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table]));
        let mut params = named(statement.named_bindings);
        params.extend(extra);
        let body = self.query(&sql, &params).await?;
        let mut rows = parse_results(&body, kind);
        if parsed.cursor.is_some() && parsed.before {
            rows.reverse();
        }
        Ok(rows)
    }

    fn select_columns(&self, kind: Kind) -> Vec<&'static str> {
        let mut columns = vec!["id"];
        columns.extend(kind.schema().iter().map(|a| a.id));
        if self.config.shared_tables {
            columns.push("tenant");
        }
        columns
    }

    async fn find_aggregated(&self, tenant: Option<&str>, parsed: &Parsed, table: &str, kind: Kind) -> Result<Vec<Metric>> {
        let has_interval = parsed.interval.is_some();
        let mut value_expr = if kind == Kind::Gauge { "argMax(`value`, `time`) AS `value`" } else { "SUM(`value`) AS `value`" };
        if parsed.aggregate == Some("max") {
            value_expr = "max(`value`) AS `value`";
        }

        let mut bucketed = None;
        let mut split = None;
        if kind == Kind::Event
            && parsed.interval.as_deref().is_none_or(|i| BUCKET_ROUTABLE_INTERVALS.contains(&i))
        {
            bucketed = bucket_aligned(&parsed.filters);
            if bucketed.is_none() && parsed.aggregate.is_none() && projection_covers(parsed) {
                split = split_window(&parsed.filters);
            }
        }

        if let Some(split) = split {
            let body = self.query_split(tenant, parsed, split, table, kind, value_expr).await?;
            return Ok(parse_aggregated(&body, kind));
        }

        let (filters, time_expr, conditions, bindings) = match bucketed {
            Some(b) => (b.filters, EVENT_TIME_BUCKET, b.conditions, b.bindings),
            None => (parsed.filters.clone(), "`time`", Vec::new(), Params::new()),
        };
        let mut builder = self.branch_builder(tenant, parsed, &filters, table, kind, value_expr, time_expr, &conditions)?;
        for clause in order_clauses(parsed, has_interval)? {
            builder.order_by_raw(clause, Array::default());
        }
        if let Some(limit) = parsed.limit {
            builder.limit(limit);
        }
        if let Some(offset) = parsed.offset {
            builder.offset(offset);
        }
        let statement = builder.build()?;
        let sql = format!("{} FORMAT JSON", self.qualify(&statement.query, &[table]));
        let mut params = named(statement.named_bindings);
        params.extend(bindings);
        let body = self.query(&sql, &params).await?;
        Ok(parse_aggregated(&body, kind))
    }

    #[allow(clippy::too_many_arguments)]
    fn branch_builder(
        &self,
        tenant: Option<&str>,
        parsed: &Parsed,
        filters: &[Query],
        table: &str,
        kind: Kind,
        value_expr: &str,
        time_expr: &str,
        conditions: &[String],
    ) -> Result<Builder> {
        let mut builder = self.builder(kind);
        builder.from(table, "").select(["metric"]).select_raw(value_expr, Array::default());
        if let Some(interval) = &parsed.interval {
            let sql = usage_query::interval_sql(interval).unwrap_or_default();
            builder.select_raw(format!("toStartOfInterval({time_expr}, {sql}) AS `bucket`"), Array::default());
        }
        for dim in &parsed.group_by {
            builder.select_raw(escape(dim), Array::default());
        }
        for condition in conditions {
            builder.where_raw(condition.as_str(), Array::default());
        }
        self.apply_filters(&mut builder, tenant, filters)?;
        builder.group_by_raw(group_parts(parsed).join(", "), Array::default());
        Ok(builder)
    }

    async fn query_split(
        &self,
        tenant: Option<&str>,
        parsed: &Parsed,
        split: Split,
        table: &str,
        kind: Kind,
        value_expr: &str,
    ) -> Result<String> {
        let mut branches = Vec::new();
        let mut bindings = Params::new();
        for (index, branch) in split.branches.into_iter().enumerate() {
            let statement = self
                .branch_builder(tenant, parsed, &split.filters, table, kind, value_expr, &branch.time, &branch.conditions)?
                .build()?;
            let (sql, renamed) = prefix_bindings(
                &self.qualify(&statement.query, &[table]),
                named(statement.named_bindings),
                &format!("b{index}_"),
            );
            branches.push(sql);
            merge(&mut bindings, renamed);
            merge(&mut bindings, branch.bindings);
        }
        let groups = group_parts(parsed);
        let mut select = vec![groups[0].clone(), value_expr.to_owned()];
        select.extend(groups[1..].iter().cloned());
        let mut sql = format!(
            "SELECT {} FROM ({}) GROUP BY {} ORDER BY {}",
            select.join(", "),
            branches.join(" UNION ALL "),
            groups.join(", "),
            order_clauses(parsed, parsed.interval.is_some())?.join(", ")
        );
        if let Some(limit) = parsed.limit {
            sql.push_str(&format!(" LIMIT {limit}"));
        }
        if let Some(offset) = parsed.offset {
            sql.push_str(&format!(" OFFSET {offset}"));
        }
        self.query(&format!("{sql} FORMAT JSON"), &bindings).await
    }

    pub(super) async fn count_from(&self, tenant: &str, queries: &[Query], kind: Kind, max: Option<i64>) -> Result<i64> {
        let table = self.table_for(kind);
        let parsed = self.parse(Some(tenant), queries, kind)?;
        let body = if let Some(max) = max {
            let mut inner = self.builder(kind);
            inner.from(table.as_str(), "").select_raw("1", Array::default()).limit(max);
            self.apply_filters(&mut inner, Some(tenant), &parsed.filters)?;
            let statement = inner.build()?;
            let inner_sql = self.qualify(&statement.query, &[&table]);
            self.query(
                &format!("SELECT COUNT(*) as total FROM ({inner_sql}) sub FORMAT JSON"),
                &named(statement.named_bindings),
            )
            .await?
        } else {
            let mut builder = self.builder(kind);
            builder.from(table.as_str(), "").count("*", "total");
            self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
            let statement = builder.build()?;
            self.query(
                &format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])),
                &named(statement.named_bindings),
            )
            .await?
        };
        Ok(decode_total(&body))
    }

    pub(super) async fn sum_from(&self, tenant: &str, queries: &[Query], attribute: &str, kind: Kind) -> Result<i64> {
        let table = self.table_for(kind);
        self.validate_attribute(attribute, kind)?;
        let parsed = self.parse(Some(tenant), queries, kind)?;
        let mut builder = self.builder(kind);
        builder.from(table.as_str(), "").sum(attribute, "total");
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        let statement = builder.build()?;
        let body = self
            .query(&format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])), &named(statement.named_bindings))
            .await?;
        Ok(decode_total(&body))
    }

    fn validate_daily_queries(&self, queries: &[Query]) -> Result<()> {
        for query in queries {
            let attribute = query.attribute();
            if !(attribute.is_empty() || attribute == "0") {
                self.validate_daily_attribute(attribute)?;
            }
        }
        Ok(())
    }

    pub(super) async fn daily_rows(&self, tenant: &str, queries: &[Query]) -> Result<Vec<Metric>> {
        self.set_context("findDaily()");
        let table = self.daily_table();
        self.validate_daily_queries(queries)?;
        let parsed = self.parse(Some(tenant), queries, Kind::Event)?;
        let mut columns: Vec<&str> = if self.config.shared_tables { vec!["tenant"] } else { Vec::new() };
        columns.extend(["metric", "time", "resourceType", "resourceId", "resourceInternalId", "teamId", "teamInternalId"]);
        let mut builder = self.builder(Kind::Event);
        builder
            .from(table.as_str(), "")
            .select(columns.iter().copied())
            .select_raw("sum(`value`) AS `value`", Array::default())
            .group_by_raw(columns.iter().map(|c| escape(c)).collect::<Vec<_>>().join(", "), Array::default());
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        apply_order(&mut builder, &parsed.order, false);
        if let Some(limit) = parsed.limit {
            builder.limit(limit);
        }
        if let Some(offset) = parsed.offset {
            builder.offset(offset);
        }
        let statement = builder.build()?;
        let body = self
            .query(&format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])), &named(statement.named_bindings))
            .await?;
        Ok(parse_results(&body, Kind::Event))
    }

    pub(super) async fn daily_total(&self, tenant: &str, queries: &[Query], attribute: &str) -> Result<i64> {
        let table = self.daily_table();
        self.validate_daily_attribute(attribute)?;
        self.validate_daily_queries(queries)?;
        let parsed = self.parse(Some(tenant), queries, Kind::Event)?;
        let mut builder = self.builder(Kind::Event);
        builder.from(table.as_str(), "").sum(attribute, "total");
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        let statement = builder.build()?;
        let body = self
            .query(&format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])), &named(statement.named_bindings))
            .await?;
        Ok(decode_total(&body))
    }

    pub(super) async fn daily_batch(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        if metrics.is_empty() {
            return Ok(Totals::new());
        }
        self.set_context("sumDailyBatch()");
        self.validate_daily_queries(queries)?;
        let mut totals: Totals = metrics.iter().map(|m| (m.clone(), 0)).collect();
        let table = self.daily_table();
        let parsed = self.parse(Some(tenant), queries, Kind::Event)?;
        let mut builder = self.builder(Kind::Event);
        builder
            .from(table.as_str(), "")
            .select(["metric"])
            .select_raw("SUM(`value`) AS `total`", Array::default())
            .filter([Query::equal("metric", metrics.iter().map(String::as_str).collect::<Vec<_>>())])
            .group_by_raw("`metric`", Array::default());
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        let statement = builder.build()?;
        let body = self
            .query(&format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])), &named(statement.named_bindings))
            .await?;
        for row in decode_rows(&body) {
            let name = String::from_utf8_lossy(php::to_str(field(&row, "metric")).as_bytes()).into_owned();
            if let Some(total) = totals.get_mut(&name) {
                *total = php::to_int(field(&row, "total"));
            }
        }
        Ok(totals)
    }

    pub(super) async fn total_batch_from(&self, tenant: &str, metrics: &[String], queries: &[Query], kind: Kind) -> Result<Totals> {
        let table = self.table_for(kind);
        let parsed = self.parse(Some(tenant), queries, kind)?;
        let value_expr = if kind == Kind::Event { "SUM(`value`) AS `agg_val`" } else { "argMax(`value`, `time`) AS `agg_val`" };
        let mut builder = self.builder(kind);
        builder
            .from(table.as_str(), "")
            .select(["metric"])
            .select_raw(value_expr, Array::default())
            .filter([Query::equal("metric", metrics.iter().map(String::as_str).collect::<Vec<_>>())])
            .group_by_raw("`metric`", Array::default());
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        let statement = builder.build()?;
        let body = self
            .query(&format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])), &named(statement.named_bindings))
            .await?;
        Ok(rows_by_metric(&body, "agg_val"))
    }

    #[allow(clippy::too_many_arguments)]
    pub(super) async fn series(
        &self,
        tenant: &str,
        metrics: &[String],
        interval: &str,
        start: &str,
        end: &str,
        queries: &[Query],
        zero_fill: bool,
        kind: Option<Kind>,
    ) -> Result<TimeSeries> {
        if metrics.is_empty() {
            return Ok(TimeSeries::new());
        }
        if interval != "1h" && interval != "1d" {
            return Err(Error::invalid_argument(format!("Invalid interval '{interval}'. Allowed: 1h, 1d")));
        }
        self.set_context("getTimeSeries()");
        let mut output: TimeSeries =
            metrics.iter().map(|m| (m.clone(), Series { total: Number::Int(0), data: Vec::new() })).collect();
        let mut types: Vec<(String, Kind)> = Vec::new();
        let kinds: Vec<Kind> = match kind {
            Some(k) => vec![k],
            None => vec![Kind::Event, Kind::Gauge],
        };
        for query_kind in kinds {
            if !self.queries_match(queries, query_kind) {
                continue;
            }
            let result = self.series_from(tenant, metrics, interval, start, end, queries, query_kind).await?;
            for (name, series) in result {
                let Some(out) = output.get_mut(&name) else { continue };
                if !series.data.is_empty() {
                    match types.iter_mut().find(|(n, _)| *n == name) {
                        Some(entry) => entry.1 = query_kind,
                        None => types.push((name.clone(), query_kind)),
                    }
                }
                out.total = add(out.total, series.total);
                out.data.extend(series.data);
            }
        }
        if zero_fill {
            for (name, series) in output.iter_mut() {
                let fill = types.iter().find(|(n, _)| n == name).map(|(_, k)| *k).or(kind).unwrap_or(Kind::Event);
                let data = std::mem::take(&mut series.data);
                series.data = if fill == Kind::Gauge {
                    locf_fill(data, interval, start, end)?
                } else {
                    zero_fill_series(data, interval, start, end)?
                };
            }
        }
        Ok(output)
    }

    #[allow(clippy::too_many_arguments)]
    async fn series_from(
        &self,
        tenant: &str,
        metrics: &[String],
        interval: &str,
        start: &str,
        end: &str,
        queries: &[Query],
        kind: Kind,
    ) -> Result<TimeSeries> {
        let function = if interval == "1h" { "toStartOfHour" } else { "toStartOfDay" };
        let table = self.table_for(kind);
        let mut parsed = self.parse(Some(tenant), queries, kind)?;
        let value_expr = if kind == Kind::Event { "SUM(`value`) AS `agg_value`" } else { "argMax(`value`, `time`) AS `agg_value`" };
        let window =
            Query::between("time", format_text_time(start.as_bytes())?, format_text_time(end.as_bytes())?);
        let bucketed = if kind == Kind::Event {
            let mut filters = parsed.filters.clone();
            filters.push(window.clone());
            bucket_aligned(&filters)
        } else {
            None
        };
        let time_expr = if bucketed.is_some() { EVENT_TIME_BUCKET } else { "`time`" };

        let mut builder = self.builder(kind);
        builder
            .from(table.as_str(), "")
            .select(["metric"])
            .select_raw(format!("{function}({time_expr}, 'UTC') AS `bucket`"), Array::default())
            .select_raw(value_expr, Array::default())
            .filter([Query::equal("metric", metrics.iter().map(String::as_str).collect::<Vec<_>>())])
            .group_by_raw("`metric`, `bucket`", Array::default())
            .order_by_raw("`bucket` ASC", Array::default());
        let mut bucket_bindings = Params::new();
        match bucketed {
            None => {
                builder.filter([window]);
            }
            Some(b) => {
                parsed.filters = b.filters;
                bucket_bindings = b.bindings;
                for condition in &b.conditions {
                    builder.where_raw(condition.as_str(), Array::default());
                }
            }
        }
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        let statement = builder.build()?;
        let mut params = named(statement.named_bindings);
        params.extend(bucket_bindings);
        let body = self.query(&format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])), &params).await?;

        let mut output: TimeSeries =
            metrics.iter().map(|m| (m.clone(), Series { total: Number::Float(0.0), data: Vec::new() })).collect();
        for row in decode_rows(&body) {
            let name = String::from_utf8_lossy(php::to_str(field(&row, "metric")).as_bytes()).into_owned();
            let bucket = String::from_utf8_lossy(php::to_str(field(&row, "bucket")).as_bytes()).into_owned();
            let value = php::to_float(field(&row, "agg_value"));
            let Some(series) = output.get_mut(&name) else { continue };
            series.total = add(series.total, Number::Float(value));
            series.data.push(Point { value, date: iso(&bucket) });
        }
        Ok(output)
    }

    pub(super) async fn delete(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<bool> {
        self.set_context("purge()");
        let kinds: Vec<Kind> = match kind {
            Some(k) => vec![k],
            None => vec![Kind::Event, Kind::Gauge],
        };
        for purge_kind in kinds {
            let table = self.table_for(purge_kind);
            let parsed = self.parse(Some(tenant), queries, purge_kind)?;
            let mut builder = self.builder(purge_kind);
            builder.from(table.as_str(), "");
            self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
            builder.where_raw("1 = 1", Array::default());
            let statement = builder.delete()?;
            self.query(&self.qualify(&statement.query, &[&table]), &named(statement.named_bindings)).await?;
            if purge_kind == Kind::Event {
                self.purge_daily(tenant, queries).await?;
            }
        }
        Ok(true)
    }

    async fn purge_daily(&self, tenant: &str, queries: &[Query]) -> Result<()> {
        let mut safe: Vec<&str> = vec!["time"];
        safe.extend(DAILY_COLUMNS.iter().copied().filter(|c| *c != "value"));
        if self.config.shared_tables {
            safe.push("tenant");
        }
        let compatible = queries.iter().all(|q| q.attribute().is_empty() || safe.contains(&q.attribute()));
        let daily_queries = if compatible {
            super::route::day_boundaries(queries)
        } else {
            let kept: Vec<Query> =
                queries.iter().filter(|q| q.attribute().is_empty() || safe.contains(&q.attribute())).cloned().collect();
            super::route::day_boundaries(&kept)
        };
        if !queries.is_empty() && daily_queries.is_empty() {
            return Ok(());
        }
        let table = self.daily_table();
        let parsed = self.parse(Some(tenant), &daily_queries, Kind::Event)?;
        let mut builder = self.builder(Kind::Event);
        builder.from(table.as_str(), "");
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        builder.where_raw("1 = 1", Array::default());
        let statement = builder.delete()?;
        self.query(&self.qualify(&statement.query, &[&table]), &named(statement.named_bindings)).await?;
        Ok(())
    }
}

/// `$a + $b` of two PHP numbers.
fn add(a: Number, b: Number) -> Number {
    match (a, b) {
        (Number::Int(x), Number::Int(y)) => match x.checked_add(y) {
            Some(s) => Number::Int(s),
            None => Number::Float(x as f64 + y as f64),
        },
        (x, y) => Number::Float(as_f64(x) + as_f64(y)),
    }
}

fn as_f64(n: Number) -> f64 {
    match n {
        Number::Int(i) => i as f64,
        Number::Float(f) => f,
    }
}

/// Appends `more` to `params`, a later key replacing an earlier one in place.
pub(super) fn merge(params: &mut Params, more: Params) {
    for (key, value) in more {
        match params.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = value,
            None => params.push((key, value)),
        }
    }
}

pub(super) fn rows_by_metric(body: &str, column: &str) -> Totals {
    let mut out = Totals::new();
    for row in decode_rows(body) {
        let name = String::from_utf8_lossy(php::to_str(field(&row, "metric")).as_bytes()).into_owned();
        out.insert(name, php::to_int(field(&row, column)));
    }
    out
}

/// `time` filter values in ClickHouse's wire format (`Y-m-d H:i:s.v`).
fn normalize_times(queries: Vec<Query>) -> Result<Vec<Query>> {
    let mut out = Vec::with_capacity(queries.len());
    for mut query in queries {
        if query.attribute() != "time" || !query.method().is_filter() {
            out.push(query);
            continue;
        }
        let mut values = Array::default();
        for value in query.values().values() {
            let rewritten = match value {
                Value::Null => Value::Null,
                Value::Str(s) => Value::from(format_text_time(s.as_bytes())?.as_str()),
                other => other.clone(),
            };
            let _ = values.push(rewritten);
        }
        query.set_values(values);
        out.push(query);
    }
    Ok(out)
}

/// A cursor value as a row keyed by column (`$id` as `id`).
fn cursor_row(raw: &Value) -> Result<Array> {
    let mut row = match raw {
        Value::Ext(Object::Row { fields, .. }) => fields.clone(),
        Value::Array(a) => a.clone(),
        other => {
            return Err(Error::exception(format!(
                "Invalid cursor value: expected ArrayObject (Metric) or associative array, got {}",
                other.debug_type()
            )));
        }
    };
    let id = ArrayKey::from("id");
    let dollar = ArrayKey::from("$id");
    if !row.contains_key(&id)
        && let Some(value) = row.remove(&dollar)
    {
        row.set(id, value);
    }
    Ok(row)
}

fn resolve_cursor_order(mut order: Vec<(String, &'static str)>) -> Vec<(String, &'static str)> {
    if order.iter().any(|(a, _)| a == "id") {
        return order;
    }
    let direction = order.last().map_or("ASC", |(_, d)| *d);
    order.push(("id".into(), direction));
    order
}

fn typed_value(chtype: &str, value: &Value) -> Result<Value> {
    if chtype == "DateTime64(3, 'UTC')" {
        return match value {
            Value::Null => Err(Error::exception("DateTime parameter value cannot be null")),
            Value::Str(s) => Ok(Value::from(format_text_time(s.as_bytes())?.as_str())),
            other => Err(Error::exception(format!("Invalid datetime value type: {}", other.type_name()))),
        };
    }
    Ok(match value {
        Value::Object(_) | Value::Ext(_) => Value::from(""),
        other => other.clone(),
    })
}

fn apply_cursor(builder: &mut Builder, order: &[(String, &'static str)], cursor: &Array, before: bool) -> Result<Params> {
    let order = resolve_cursor_order(order.to_vec());
    let lookup = |attr: &str| -> Result<&Value> {
        cursor
            .get(&ArrayKey::from(attr))
            .ok_or_else(|| Error::exception(format!("Cursor is missing required attribute '{attr}'")))
    };
    let mut params = Params::new();
    let mut tuples = Vec::new();
    for (i, (attr, direction)) in order.iter().enumerate() {
        lookup(attr)?;
        let direction = if before { if *direction == "DESC" { "ASC" } else { "DESC" } } else { *direction };
        let mut conditions = Vec::new();
        for (j, (prev, _)) in order.iter().enumerate().take(i) {
            let value = lookup(prev)?;
            if matches!(value, Value::Null) {
                return Err(Error::exception(format!("Cursor value for '{prev}' cannot be null")));
            }
            let ty = param_type(prev);
            let name = format!("cursor_eq_{i}_{j}");
            conditions.push(format!("{} = {{{name}:{ty}}}", escape(prev)));
            params.push((name, typed_value(ty, value)?));
        }
        let value = lookup(attr)?;
        if matches!(value, Value::Null) {
            return Err(Error::exception(format!("Cursor value for '{attr}' cannot be null")));
        }
        let ty = param_type(attr);
        let operator = if direction == "DESC" { "<" } else { ">" };
        let name = format!("cursor_cmp_{i}");
        conditions.push(format!("{} {operator} {{{name}:{ty}}}", escape(attr)));
        params.push((name, typed_value(ty, value)?));
        tuples.push(format!("({})", conditions.join(" AND ")));
    }
    builder.where_raw(format!("({})", tuples.join(" OR ")), Array::default());
    Ok(params)
}

fn apply_order(builder: &mut Builder, order: &[(String, &'static str)], flip: bool) {
    for (attribute, direction) in order {
        let descending = (*direction == "DESC") != flip;
        if descending {
            builder.sort_desc(attribute.as_str(), None);
        } else {
            builder.sort_asc(attribute.as_str(), None);
        }
    }
}

fn group_parts(parsed: &Parsed) -> Vec<String> {
    let mut parts = vec!["`metric`".to_owned()];
    if parsed.interval.is_some() {
        parts.push("`bucket`".into());
    }
    parts.extend(parsed.group_by.iter().map(|d| escape(d)));
    parts
}

fn order_clauses(parsed: &Parsed, has_interval: bool) -> Result<Vec<String>> {
    if parsed.order.is_empty() {
        return Ok(vec![if has_interval { "`bucket` ASC" } else { "`value` DESC" }.to_owned()]);
    }
    let mut clauses = Vec::with_capacity(parsed.order.len());
    for (attribute, direction) in &parsed.order {
        let mut attribute = attribute.as_str();
        if attribute == "time" {
            if !has_interval {
                return Err(Error::exception(
                    "orderBy(\"time\") requires groupByInterval — without time bucketing the result has no time column",
                ));
            }
            attribute = "bucket";
        }
        clauses.push(format!("{} {direction}", escape(attribute)));
    }
    Ok(clauses)
}

/// The `time` filters as one half-open window `[lower, upper)` in epoch
/// milliseconds, and the other filters; `None` for anything unreadable.
fn window_bounds(filters: &[Query]) -> Option<(Vec<Query>, Option<i64>, Option<i64>)> {
    let mut kept = Vec::new();
    let mut lower: Option<i64> = None;
    let mut upper: Option<i64> = None;
    for query in filters {
        if query.attribute() != "time" {
            kept.push(query.clone());
            continue;
        }
        let values = query.values();
        let (from, to) = match query.method() {
            Method::GreaterThanEqual => (epoch_millis(first(values)), None),
            Method::GreaterThan => (epoch_millis(first(values)).map(|v| v + 1), None),
            Method::LessThan => (None, epoch_millis(first(values))),
            Method::LessThanEqual => (None, epoch_millis(first(values)).map(|v| v + 1)),
            Method::Between => {
                let from = epoch_millis(first(values))?;
                let to = epoch_millis(values.get(&ArrayKey::Int(1)))?;
                (Some(from), Some(to + 1))
            }
            _ => return None,
        };
        if from.is_none() && to.is_none() {
            return None;
        }
        if let Some(from) = from {
            lower = Some(lower.map_or(from, |l| l.max(from)));
        }
        if let Some(to) = to {
            upper = Some(upper.map_or(to, |u| u.min(to)));
        }
    }
    Some((kept, lower, upper))
}

fn bucket_aligned(filters: &[Query]) -> Option<Bucketed> {
    let (kept, lower, upper) = window_bounds(filters)?;
    let mut conditions = Vec::new();
    let mut bindings = Params::new();
    for (name, operator, bound) in [("bucketFrom", ">=", lower), ("bucketTo", "<", upper)] {
        let Some(bound) = bound else { continue };
        if bound % HOUR_MILLIS != 0 {
            return None;
        }
        conditions.push(format!("{EVENT_TIME_BUCKET} {operator} {{{name}:{}}}", param_type("time")));
        bindings.push((name.to_owned(), Value::from(millis_to_wire(bound).as_str())));
    }
    Some(Bucketed { filters: kept, conditions, bindings })
}

fn projection_covers(parsed: &Parsed) -> bool {
    let filtered: Vec<&str> = parsed.filters.iter().map(Query::attribute).collect();
    EVENT_PROJECTIONS.iter().any(|(_, dims)| {
        if !parsed.group_by.is_empty() && parsed.group_by.iter().map(String::as_str).ne(dims.iter().copied()) {
            return false;
        }
        filtered.iter().all(|f| ["tenant", "metric", "time"].contains(f) || dims.contains(f))
    })
}

fn window_branch(time: &str, name: &str, from: Option<i64>, to: Option<i64>) -> Branch {
    let mut conditions = Vec::new();
    let mut bindings = Params::new();
    for (param, operator, bound) in [(format!("{name}From"), ">=", from), (format!("{name}To"), "<", to)] {
        let Some(bound) = bound else { continue };
        conditions.push(format!("{time} {operator} {{{param}:{}}}", param_type("time")));
        bindings.push((param, Value::from(millis_to_wire(bound).as_str())));
    }
    Branch { time: time.to_owned(), conditions, bindings }
}

fn split_window(filters: &[Query]) -> Option<Split> {
    let (kept, lower, upper) = window_bounds(filters)?;
    if lower.unwrap_or(0) < 0 || upper.unwrap_or(0) < 0 {
        return None;
    }
    let from = lower.map(|l| (l + HOUR_MILLIS - 1) / HOUR_MILLIS * HOUR_MILLIS);
    let to = upper.map(|u| u / HOUR_MILLIS * HOUR_MILLIS);
    if let (Some(f), Some(t)) = (from, to)
        && f >= t
    {
        return None;
    }
    let mut edges = Vec::new();
    if let Some(l) = lower
        && Some(l) != from
    {
        edges.push(("head", Some(l), from));
    }
    if let Some(u) = upper
        && Some(u) != to
    {
        edges.push(("tail", to, Some(u)));
    }
    if edges.is_empty() {
        return None;
    }
    let mut branches = vec![window_branch(EVENT_TIME_BUCKET, "bucket", from, to)];
    for (name, f, t) in edges {
        branches.push(window_branch("`time`", name, f, t));
    }
    Some(Split { filters: kept, branches })
}

fn row_key(key: KeyRef<'_>) -> ArrayKey {
    key.to_owned()
}

/// Rows of a plain read as metrics (`id` as `$id`, `time` in ISO form).
pub(super) fn parse_results(body: &str, kind: Kind) -> Vec<Metric> {
    if body.trim().is_empty() {
        return Vec::new();
    }
    let mut metrics = Vec::new();
    for row in decode_rows(body) {
        let mut document = Array::default();
        for (key, value) in row.iter() {
            let converted = match key {
                KeyRef::Str(b"tenant") => match value {
                    Value::Null => Value::Null,
                    v => Value::Str(php::to_str(v)),
                },
                KeyRef::Str(b"value") => match value {
                    Value::Null => Value::Null,
                    v => Value::Int(php::to_int(v)),
                },
                KeyRef::Str(b"time") => {
                    let text = String::from_utf8_lossy(php::to_str(value).as_bytes()).into_owned();
                    Value::from(iso(&text).as_str())
                }
                KeyRef::Str(b"tags") => match value {
                    Value::Str(s) => match php_std::json::decode_ext::<Object>(
                        s.as_bytes(),
                        Some(true),
                        php_std::json::DEFAULT_DEPTH,
                        php_std::json::Flags::NONE,
                    ) {
                        Ok(Value::Null) | Err(_) => Value::Array(Array::default()),
                        Ok(v) => v,
                    },
                    v => v.clone(),
                },
                _ => value.clone(),
            };
            document.set(row_key(key), converted);
        }
        let id = ArrayKey::from("id");
        if document.get(&id).is_some_and(|v| !matches!(v, Value::Null))
            && let Some(value) = document.remove(&id)
        {
            document.set(ArrayKey::from("$id"), value);
        }
        document.set(ArrayKey::from("type"), Value::from(kind.as_str()));
        metrics.push(Metric::new(document));
    }
    metrics
}

/// Rows of an aggregated read as metrics (`bucket` as `time`).
pub(super) fn parse_aggregated(body: &str, kind: Kind) -> Vec<Metric> {
    if body.trim().is_empty() {
        return Vec::new();
    }
    let mut metrics = Vec::new();
    for row in decode_rows(body) {
        let mut document = Array::default();
        for (key, value) in row.iter() {
            match key {
                KeyRef::Str(b"bucket") => {
                    let text = String::from_utf8_lossy(php::to_str(value).as_bytes()).into_owned();
                    document.set(ArrayKey::from("time"), Value::from(iso(&text).as_str()));
                }
                KeyRef::Str(b"value") => {
                    let converted = match value {
                        Value::Null | Value::Int(_) | Value::Float(_) => value.clone(),
                        Value::Str(s) if php::is_numeric(value) => {
                            let b = s.as_bytes();
                            if b.contains(&b'.') || b.contains(&b'e') || b.contains(&b'E') {
                                Value::Float(php_std::format::str_to_float(b))
                            } else {
                                Value::Int(php_std::format::str_to_int(b))
                            }
                        }
                        other => other.clone(),
                    };
                    document.set(row_key(key), converted);
                }
                _ => {
                    document.set(row_key(key), value.clone());
                }
            }
        }
        document.set(ArrayKey::from("type"), Value::from(kind.as_str()));
        metrics.push(Metric::new(document));
    }
    metrics
}

fn bucket_format(interval: &str) -> &'static str {
    if interval == "1h" { "Y-m-d\\TH:00:00+00:00" } else { "Y-m-d\\T00:00:00+00:00" }
}

fn step(interval: &str) -> &'static [u8] {
    if interval == "1h" { b"+1 hour" } else { b"+1 day" }
}

/// The buckets from `start` to `end`, with their keys.
fn buckets(interval: &str, start: &str, end: &str, mut each: impl FnMut(String)) -> Result<()> {
    let format = bucket_format(interval);
    let mut current: DateTime = php::datetime(start.as_bytes())?;
    let end = php::datetime(end.as_bytes())?;
    while current <= end {
        each(php::format(&current, format));
        current.modify(step(interval))?;
    }
    Ok(())
}

fn zero_fill_series(data: Vec<Point>, interval: &str, start: &str, end: &str) -> Result<Vec<Point>> {
    let format = bucket_format(interval);
    let mut existing: indexmap::IndexMap<String, f64> = indexmap::IndexMap::new();
    for point in data {
        let key = php::format(&php::datetime(point.date.as_bytes())?, format);
        *existing.entry(key).or_insert(0.0) += point.value;
    }
    let mut out = Vec::new();
    buckets(interval, start, end, |key| {
        out.push(Point { value: existing.get(&key).copied().unwrap_or(0.0), date: key });
    })?;
    Ok(out)
}

fn locf_fill(mut data: Vec<Point>, interval: &str, start: &str, end: &str) -> Result<Vec<Point>> {
    let format = bucket_format(interval);
    data.sort_by(|a, b| a.date.as_bytes().cmp(b.date.as_bytes()));
    let mut existing: indexmap::IndexMap<String, f64> = indexmap::IndexMap::new();
    for point in data {
        let key = php::format(&php::datetime(point.date.as_bytes())?, format);
        existing.insert(key, point.value);
    }
    let mut out = Vec::new();
    let mut last = 0.0;
    let mut seen = false;
    buckets(interval, start, end, |key| {
        if let Some(value) = existing.get(&key) {
            last = *value;
            seen = true;
        }
        out.push(Point { value: if seen { last } else { 0.0 }, date: key });
    })?;
    Ok(out)
}
