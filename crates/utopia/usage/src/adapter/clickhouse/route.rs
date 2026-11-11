//! Routing of event totals: raw events, the daily rollup, or both.

use php_std::ArrayKey;
use php_std::datetime::DateTime;
use utopia_query::{Array, Method, Query, Value};

use super::read::{DAILY_COLUMNS, first, merge, rows_by_metric, stringify_time};
use super::{ClickHouse, Params, RouteEntry, decode_total, named, prefix_bindings};
use crate::adapter::Totals;
use crate::error::{Error, Result};
use crate::metric::Kind;
use crate::php;

/// The shape of a read that routing looks at.
#[derive(Debug, Clone, Default)]
pub(super) struct Plan {
    metric: Option<String>,
    start: Option<String>,
    end: Option<String>,
    filter_columns: Vec<String>,
    dimensions: Vec<String>,
    interval: Option<String>,
    has_cursor: bool,
    irregular: bool,
    end_inclusive: bool,
}

/// Where an event total is read from.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Route {
    Raw,
    Daily,
    Hybrid,
    Split,
}

impl Route {
    fn as_str(self) -> &'static str {
        match self {
            Route::Raw => "raw",
            Route::Daily => "daily",
            Route::Hybrid => "hybrid",
            Route::Split => "split",
        }
    }
}

/// Whether `dt` is a UTC midnight (no time at all counts as one).
pub(super) fn day_aligned(dt: Option<&DateTime>) -> bool {
    dt.is_none_or(|dt| php::format(dt, "H:i:s.u") == "00:00:00.000000")
}

fn is_midnight(text: Option<&str>) -> bool {
    text.and_then(|t| php::datetime_utc(t.as_bytes()).ok()).is_some_and(|dt| day_aligned(Some(&dt)))
}

fn tighten(current: Option<String>, candidate: Option<String>, later: bool) -> Option<String> {
    let (Some(cur), Some(cand)) = (&current, &candidate) else {
        return current.or(candidate);
    };
    let (Ok(c), Ok(n)) = (php::datetime(cur.as_bytes()), php::datetime(cand.as_bytes())) else {
        return current;
    };
    let pick = if later { n > c } else { n < c };
    if pick { candidate } else { current }
}

fn plan(queries: &[Query]) -> Plan {
    let mut plan = Plan::default();
    for query in queries {
        let method = query.method();
        let attribute = query.attribute();
        let values = query.values();
        match method {
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
                    if !plan.dimensions.contains(&dim) {
                        plan.dimensions.push(dim);
                    }
                }
                continue;
            }
            Method::GroupByTimeBucket => {
                plan.interval = stringify_time(first(values));
                continue;
            }
            Method::CursorAfter | Method::CursorBefore => {
                if first(values).is_some_and(|v| !matches!(v, Value::Null)) {
                    plan.has_cursor = true;
                }
                continue;
            }
            Method::OrderAsc | Method::OrderDesc | Method::Limit | Method::Offset => continue,
            _ => {}
        }
        if attribute.is_empty() {
            continue;
        }
        if !plan.filter_columns.iter().any(|c| c == attribute) {
            plan.filter_columns.push(attribute.to_owned());
        }
        if attribute == "metric"
            && method == Method::Equal
            && values.len() == 1
            && let Some(Value::Str(s)) = first(values)
        {
            plan.metric = Some(String::from_utf8_lossy(s.as_bytes()).into_owned());
        }
        if attribute == "time" {
            match method {
                Method::GreaterThanEqual | Method::GreaterThan => {
                    plan.start = tighten(plan.start.take(), stringify_time(first(values)), true);
                }
                Method::LessThanEqual | Method::LessThan => {
                    let tightened = tighten(plan.end.clone(), stringify_time(first(values)), false);
                    if tightened != plan.end {
                        plan.end_inclusive = method == Method::LessThanEqual;
                    }
                    plan.end = tightened;
                }
                Method::Between => {
                    plan.start = tighten(plan.start.take(), stringify_time(first(values)), true);
                    let tightened = tighten(plan.end.clone(), stringify_time(values.get(&ArrayKey::Int(1))), false);
                    if tightened != plan.end {
                        plan.end_inclusive = true;
                    }
                    plan.end = tightened;
                }
                _ => plan.irregular = true,
            }
        }
    }
    plan
}

fn today() -> Result<DateTime> {
    Ok(php::datetime_utc(b"today")?)
}

/// The whole UTC days `[from, to)` of a window the rollup can answer.
fn split_interior(start: Option<&DateTime>, end: &DateTime, boundary: &DateTime) -> Option<(Option<String>, String)> {
    let from = start.map(|s| {
        let mut from = s.clone();
        if php::format(&from, "H:i:s.u") != "00:00:00.000000" {
            from.set_time(0, 0, 0, 0);
            let _ = from.modify(b"+1 day");
        }
        from
    });
    let mut to = end.clone();
    to.set_time(0, 0, 0, 0);
    if to > *boundary {
        to = boundary.clone();
    }
    if let Some(from) = &from
        && *from >= to
    {
        return None;
    }
    Some((from.map(|f| php::format(&f, "Y-m-d H:i:s.v")), php::format(&to, "Y-m-d H:i:s.v")))
}

fn select_source(plan: &Plan) -> Route {
    if plan.interval.is_some() || plan.end.is_none() || plan.has_cursor || plan.irregular {
        return Route::Raw;
    }
    if plan.filter_columns.iter().any(|c| c == "id" || c == "value") || !plan.dimensions.is_empty() {
        return Route::Raw;
    }
    let Some(end) = &plan.end else { return Route::Raw };
    let (Ok(end), Ok(boundary)) = (php::datetime_utc(end.as_bytes()), php::datetime_utc(b"today")) else {
        return Route::Raw;
    };
    let start = match &plan.start {
        Some(s) => match php::datetime_utc(s.as_bytes()) {
            Ok(s) => Some(s),
            Err(_) => return Route::Raw,
        },
        None => None,
    };
    if start.as_ref().is_some_and(|s| *s >= boundary) {
        return Route::Raw;
    }
    if plan.filter_columns.iter().any(|c| !DAILY_COLUMNS.contains(&c.as_str()) && c != "tenant") {
        return Route::Raw;
    }
    let split_or_raw =
        || if split_interior(start.as_ref(), &end, &boundary).is_some() { Route::Split } else { Route::Raw };
    if !day_aligned(start.as_ref()) {
        return split_or_raw();
    }
    if end >= boundary {
        return Route::Hybrid;
    }
    if !day_aligned(Some(&end)) || plan.end_inclusive {
        return split_or_raw();
    }
    Route::Daily
}

/// Inclusive midnight upper bounds (`<=`, `between`) as exclusive ones.
fn inclusive_midnight_for_daily(queries: &[Query]) -> Vec<Query> {
    let mut out = Vec::with_capacity(queries.len());
    for query in queries {
        if query.attribute() != "time" {
            out.push(query.clone());
            continue;
        }
        let values = query.values();
        if query.method() == Method::LessThanEqual {
            let upper = stringify_time(first(values));
            if is_midnight(upper.as_deref())
                && let Some(upper) = upper
            {
                out.push(Query::less_than("time", upper));
                continue;
            }
        }
        if query.method() == Method::Between && values.len() >= 2 {
            let upper = stringify_time(values.get(&ArrayKey::Int(1)));
            if is_midnight(upper.as_deref())
                && let Some(upper) = upper
            {
                if let Some(lower) = stringify_time(first(values)) {
                    out.push(Query::greater_than_equal("time", lower));
                }
                out.push(Query::less_than("time", upper));
                continue;
            }
        }
        out.push(query.clone());
    }
    out
}

/// `(non-time queries, time window queries)`.
fn split_time(queries: &[Query]) -> (Vec<Query>, Vec<Query>) {
    const TIME: &[Method] = &[
        Method::GreaterThan,
        Method::GreaterThanEqual,
        Method::LessThan,
        Method::LessThanEqual,
        Method::Between,
        Method::NotBetween,
    ];
    queries.iter().cloned().partition(|q| !(q.attribute() == "time" && TIME.contains(&q.method())))
}

fn floor_day(value: Option<String>) -> Option<String> {
    let mut dt = php::datetime_utc(value?.as_bytes()).ok()?;
    dt.set_time(0, 0, 0, 0);
    Some(php::format(&dt, "Y-m-d H:i:s.v"))
}

fn ceil_day(value: Option<String>) -> Option<String> {
    let mut dt = php::datetime_utc(value?.as_bytes()).ok()?;
    if php::format(&dt, "H:i:s.u") != "00:00:00.000000" {
        dt.set_time(0, 0, 0, 0);
        let _ = dt.modify(b"+1 day");
    }
    Some(php::format(&dt, "Y-m-d H:i:s.v"))
}

/// Time bounds shrunk to the days a range covers whole (for the rollup's purge).
pub(super) fn day_boundaries(queries: &[Query]) -> Vec<Query> {
    let mut out = Vec::with_capacity(queries.len());
    for query in queries {
        if query.attribute() != "time" {
            out.push(query.clone());
            continue;
        }
        let values = query.values();
        match query.method() {
            Method::GreaterThanEqual | Method::GreaterThan => match ceil_day(stringify_time(first(values))) {
                Some(c) => out.push(Query::greater_than_equal("time", c)),
                None => out.push(query.clone()),
            },
            Method::LessThanEqual | Method::LessThan => match floor_day(stringify_time(first(values))) {
                Some(f) => out.push(Query::less_than("time", f)),
                None => out.push(query.clone()),
            },
            Method::Between => {
                if let Some(lower) = ceil_day(stringify_time(first(values))) {
                    out.push(Query::greater_than_equal("time", lower));
                }
                if let Some(upper) = floor_day(stringify_time(values.get(&ArrayKey::Int(1)))) {
                    out.push(Query::less_than("time", upper));
                }
            }
            _ => out.push(query.clone()),
        }
    }
    out
}

/// The caller's time bounds for a day-bucketed read.
fn daily_time_queries(time: &[Query]) -> Vec<Query> {
    let mut out = Vec::new();
    for query in time {
        let values = query.values();
        match query.method() {
            Method::GreaterThanEqual | Method::GreaterThan => {
                if let Some(f) = floor_day(stringify_time(first(values))) {
                    out.push(Query::greater_than_equal("time", f));
                }
            }
            Method::LessThanEqual | Method::LessThan => {
                let Some(upper) = stringify_time(first(values)) else { continue };
                let inclusive_midnight = query.method() == Method::LessThanEqual && is_midnight(Some(&upper));
                if query.method() == Method::LessThan || inclusive_midnight {
                    out.push(Query::less_than("time", upper));
                } else {
                    out.push(Query::less_than_equal("time", upper));
                }
            }
            Method::Between => {
                if let Some(lower) = floor_day(stringify_time(first(values))) {
                    out.push(Query::greater_than_equal("time", lower));
                }
                if let Some(upper) = stringify_time(values.get(&ArrayKey::Int(1))) {
                    if is_midnight(Some(&upper)) {
                        out.push(Query::less_than("time", upper));
                    } else {
                        out.push(Query::less_than_equal("time", upper));
                    }
                }
            }
            _ => {}
        }
    }
    out
}

/// `round($delta, 4)` printed as PHP prints a float.
fn delta_text(delta: f64) -> String {
    let rounded =
        php_std::format::round(php_std::value::Number::Float(delta), 4, php_std::format::RoundingMode::default());
    php_std::number::to_string(rounded)
}

fn sampled(rate: f64) -> bool {
    if rate <= 0.0 {
        return false;
    }
    let mut bytes = [0u8; 4];
    let _ = getrandom::fill(&mut bytes);
    let draw = (u32::from_le_bytes(bytes) >> 1) as f64 / f64::from(i32::MAX);
    draw <= rate
}

impl<A: utopia_client::Adapter> ClickHouse<A> {
    fn record_route(&self, operation: &str, plan: &Plan, route: &str, metric: Option<String>) {
        self.record(RouteEntry {
            operation: operation.to_owned(),
            metric: metric.or_else(|| plan.metric.clone()),
            route: route.to_owned(),
            start: plan.start.clone(),
            end: plan.end.clone(),
            dimensions: plan.dimensions.clone(),
            interval: plan.interval.clone(),
        });
    }

    fn delta_warning(&self, plan: &Plan, route: Route, metric: Option<String>, raw: i64, rolled: i64) {
        if raw == 0 && rolled == 0 {
            return;
        }
        let denominator = if raw == 0 { rolled.unsigned_abs().max(1) as f64 } else { raw.unsigned_abs() as f64 };
        let delta = (rolled as f64 - raw as f64).abs() / denominator;
        if delta > 0.01 {
            self.record_route(
                "dual_read_warning",
                plan,
                &format!("{}:delta={}", route.as_str(), delta_text(delta)),
                metric.or_else(|| plan.metric.clone()),
            );
        }
    }

    pub(super) async fn routed_sum(&self, tenant: &str, queries: &[Query], operation: &str) -> Result<i64> {
        let plan = plan(queries);
        let route = select_source(&plan);
        self.record_route(operation, &plan, route.as_str(), None);
        let total = match route {
            Route::Daily => self.daily_total(tenant, &inclusive_midnight_for_daily(queries), "value").await?,
            Route::Hybrid => self.sum_hybrid(tenant, queries).await?,
            Route::Split => self.sum_split(tenant, queries, &plan).await?,
            Route::Raw => return self.sum_from(tenant, queries, "value", Kind::Event).await,
        };
        if sampled(self.config.dual_read_sample_rate)
            && let Ok(raw) = self.sum_from(tenant, queries, "value", Kind::Event).await
        {
            self.delta_warning(&plan, route, None, raw, total);
        }
        Ok(total)
    }

    fn start_of_today() -> Result<String> {
        Ok(php::format(&today()?, "Y-m-d H:i:s.v"))
    }

    /// The daily and raw statements of a hybrid read, and their bindings.
    async fn hybrid(
        &self,
        tenant: &str,
        queries: &[Query],
        metrics: Option<&[String]>,
    ) -> Result<(String, String, Params)> {
        let today = Self::start_of_today()?;
        let daily_table = self.daily_table();
        let events_table = self.events_table();
        let (non_time, time) = split_time(queries);
        let mut raw_queries = queries.to_vec();
        raw_queries.push(Query::greater_than_equal("time", today.as_str()));
        let mut daily_queries = non_time;
        daily_queries.extend(daily_time_queries(&time));
        daily_queries.push(Query::less_than("time", today.as_str()));

        let raw_parsed = self.parse(Some(tenant), &raw_queries, Kind::Event)?;
        let daily_parsed = self.parse(Some(tenant), &daily_queries, Kind::Event)?;

        let build = |table: &str, filters: &[Query]| -> Result<(String, Params)> {
            let mut builder = self.builder(Kind::Event);
            builder.from(table, "");
            match metrics {
                None => {
                    builder.sum("value", "total");
                }
                Some(metrics) => {
                    builder
                        .select(["metric"])
                        .select_raw("SUM(`value`) AS `total`", Array::default())
                        .filter([Query::equal("metric", metrics.iter().map(String::as_str).collect::<Vec<_>>())])
                        .group_by_raw("`metric`", Array::default());
                }
            }
            self.apply_filters(&mut builder, Some(tenant), filters)?;
            let statement = builder.build()?;
            Ok((self.qualify(&statement.query, &[table]), named(statement.named_bindings)))
        };
        let (raw_sql, raw_bindings) = build(&events_table, &raw_parsed.filters)?;
        let (daily_sql, daily_bindings) = build(&daily_table, &daily_parsed.filters)?;
        let (daily_sql, daily_bindings) = prefix_bindings(&daily_sql, daily_bindings, "d_");
        let mut bindings = raw_bindings;
        merge(&mut bindings, daily_bindings);
        Ok((daily_sql, raw_sql, bindings))
    }

    async fn sum_hybrid(&self, tenant: &str, queries: &[Query]) -> Result<i64> {
        let (daily, raw, bindings) = self.hybrid(tenant, queries, None).await?;
        let sql = format!(
            "\n            SELECT sum(total) AS total FROM (\n                {daily}\n                UNION ALL\n                {raw}\n            )\n            FORMAT JSON\n        "
        );
        Ok(decode_total(&self.query(&sql, &bindings).await?))
    }

    async fn total_batch_hybrid(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        let (daily, raw, bindings) = self.hybrid(tenant, queries, Some(metrics)).await?;
        let sql = format!(
            "\n            SELECT `metric`, sum(`total`) AS `agg_val` FROM (\n                {daily}\n                UNION ALL\n                {raw}\n            )\n            GROUP BY `metric`\n            FORMAT JSON\n        "
        );
        Ok(rows_by_metric(&self.query(&sql, &bindings).await?, "agg_val"))
    }

    async fn split_branches(
        &self,
        tenant: &str,
        queries: &[Query],
        plan: &Plan,
        metrics: Option<&[String]>,
    ) -> Result<(Vec<String>, Params)> {
        let boundary = today()?;
        let end = php::datetime_utc(plan.end.clone().unwrap_or_default().as_bytes())?;
        let start = match &plan.start {
            Some(s) => Some(php::datetime_utc(s.as_bytes())?),
            None => None,
        };
        let Some((from, to)) = split_interior(start.as_ref(), &end, &boundary) else {
            return Err(Error::exception("Split route selected for a window with no whole interior day."));
        };
        let (non_time, _) = split_time(queries);

        let mut lists: Vec<(bool, Vec<Query>)> = Vec::new();
        let mut daily = non_time;
        if let Some(from) = &from {
            daily.push(Query::greater_than_equal("time", from.as_str()));
        }
        daily.push(Query::less_than("time", to.as_str()));
        lists.push((true, daily));
        if let (Some(from), Some(start)) = (&from, &start)
            && php::format(start, "Y-m-d H:i:s.v") != *from
        {
            let mut head = queries.to_vec();
            head.push(Query::less_than("time", from.as_str()));
            lists.push((false, head));
        }
        if php::format(&end, "Y-m-d H:i:s.v") != to || plan.end_inclusive {
            let mut tail = queries.to_vec();
            tail.push(Query::greater_than_equal("time", to.as_str()));
            lists.push((false, tail));
        }

        let daily_table = self.daily_table();
        let events_table = self.events_table();
        let mut branches = Vec::new();
        let mut bindings = Params::new();
        for (index, (is_daily, list)) in lists.into_iter().enumerate() {
            let table = if is_daily { &daily_table } else { &events_table };
            let parsed = self.parse(Some(tenant), &list, Kind::Event)?;
            let mut builder = self.builder(Kind::Event);
            builder.from(table.as_str(), "");
            match metrics {
                None => {
                    builder.sum("value", "total");
                }
                Some(metrics) => {
                    builder
                        .select(["metric"])
                        .select_raw("SUM(`value`) AS `total`", Array::default())
                        .filter([Query::equal("metric", metrics.iter().map(String::as_str).collect::<Vec<_>>())])
                        .group_by_raw("`metric`", Array::default());
                }
            }
            self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
            let statement = builder.build()?;
            let (sql, renamed) = prefix_bindings(
                &self.qualify(&statement.query, &[table]),
                named(statement.named_bindings),
                &format!("b{index}_"),
            );
            branches.push(sql);
            merge(&mut bindings, renamed);
        }
        Ok((branches, bindings))
    }

    async fn sum_split(&self, tenant: &str, queries: &[Query], plan: &Plan) -> Result<i64> {
        let (branches, bindings) = self.split_branches(tenant, queries, plan, None).await?;
        let sql = format!(
            "\n            SELECT sum(total) AS total FROM (\n                {}\n            )\n            FORMAT JSON\n        ",
            branches.join("\n                UNION ALL\n                ")
        );
        Ok(decode_total(&self.query(&sql, &bindings).await?))
    }

    async fn total_batch_split(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[Query],
        plan: &Plan,
    ) -> Result<Totals> {
        let (branches, bindings) = self.split_branches(tenant, queries, plan, Some(metrics)).await?;
        let sql = format!(
            "\n            SELECT `metric`, sum(`total`) AS `agg_val` FROM (\n                {}\n            )\n            GROUP BY `metric`\n            FORMAT JSON\n        ",
            branches.join("\n                UNION ALL\n                ")
        );
        Ok(rows_by_metric(&self.query(&sql, &bindings).await?, "agg_val"))
    }

    pub(super) async fn total_from_events(&self, tenant: &str, metric: &str, queries: &[Query]) -> Result<i64> {
        let mut queries = queries.to_vec();
        queries.push(Query::equal("metric", [metric]));
        self.routed_sum(tenant, &queries, "getTotal").await
    }

    pub(super) async fn total_from_gauges(&self, tenant: &str, metric: &str, queries: &[Query]) -> Result<i64> {
        let mut queries = queries.to_vec();
        queries.push(Query::equal("metric", [metric]));
        let plan = plan(&queries);
        self.record_route("getTotal", &plan, "raw", None);
        let table = self.gauges_table();
        let parsed = self.parse(Some(tenant), &queries, Kind::Gauge)?;
        let mut builder = self.builder(Kind::Gauge);
        builder.from(table.as_str(), "").select_raw("argMax(`value`, `time`) AS `total`", Array::default());
        self.apply_filters(&mut builder, Some(tenant), &parsed.filters)?;
        let statement = builder.build()?;
        let body = self
            .query(
                &format!("{} FORMAT JSON", self.qualify(&statement.query, &[&table])),
                &named(statement.named_bindings),
            )
            .await?;
        Ok(decode_total(&body))
    }

    async fn routed_total_batch(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        let mut planned = queries.to_vec();
        planned.push(Query::equal("metric", metrics.iter().map(String::as_str).collect::<Vec<_>>()));
        let plan = plan(&planned);
        let route = select_source(&plan);
        self.record_route("getTotalBatch", &plan, route.as_str(), None);
        let totals = match route {
            Route::Daily => self.daily_batch(tenant, metrics, &inclusive_midnight_for_daily(queries)).await?,
            Route::Hybrid => self.total_batch_hybrid(tenant, metrics, queries).await?,
            Route::Split => self.total_batch_split(tenant, metrics, queries, &plan).await?,
            Route::Raw => return self.total_batch_from(tenant, metrics, queries, Kind::Event).await,
        };
        if sampled(self.config.dual_read_sample_rate)
            && let Ok(raw) = self.total_batch_from(tenant, metrics, queries, Kind::Event).await
        {
            for metric in metrics {
                let raw = raw.get(metric).copied().unwrap_or(0);
                let rolled = totals.get(metric).copied().unwrap_or(0);
                self.delta_warning(&plan, route, Some(metric.clone()), raw, rolled);
            }
        }
        Ok(totals)
    }

    pub(super) async fn totals(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[Query],
        kind: Option<Kind>,
    ) -> Result<Totals> {
        if metrics.is_empty() {
            return Ok(Totals::new());
        }
        self.set_context("getTotalBatch()");
        let mut totals: Totals = metrics.iter().map(|m| (m.clone(), 0)).collect();
        let mut contributing: Vec<(String, Kind)> = Vec::new();
        let kinds: Vec<Kind> = match kind {
            Some(k) => vec![k],
            None => vec![Kind::Event, Kind::Gauge],
        };
        for query_kind in kinds {
            let type_totals = if query_kind == Kind::Event {
                self.routed_total_batch(tenant, metrics, queries).await?
            } else {
                self.total_batch_from(tenant, metrics, queries, query_kind).await?
            };
            for (name, value) in type_totals {
                let Some(slot) = totals.get_mut(&name) else { continue };
                if value == 0 {
                    continue;
                }
                if kind.is_none()
                    && let Some((_, k)) = contributing.iter().find(|(n, _)| *n == name)
                    && *k != query_kind
                {
                    return Err(Error::exception(format!(
                        "Metric '{name}' exists in both event and gauge tables. Specify $type explicitly to avoid ambiguous aggregation."
                    )));
                }
                match contributing.iter_mut().find(|(n, _)| *n == name) {
                    Some(entry) => entry.1 = query_kind,
                    None => contributing.push((name.clone(), query_kind)),
                }
                *slot = value;
            }
        }
        Ok(totals)
    }
}
