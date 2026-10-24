//! Compat adapter for `usage`: maps `tests/compat/usage/spec.json` operations onto `utopia-usage`.

use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TimeZone, TzInfo};
use php_std::types::KeyRef;
use php_std::{ArrayKey, Str};
use serde_json::{Map, Value as Json, json};
use utopia_database::value::to_query_array;
use utopia_query::{Array, Method, Object, Query, Value};
use utopia_usage::adapter::clickhouse::{ClickHouse, Config};
use utopia_usage::adapter::database::{COLLECTION, Database as DatabaseAdapter};
use utopia_usage::{
    Accumulator, Adapter, Entry, Error, Kind, Metric, Number, Sample, SampleGap, SampleRange, SampleResult,
    SampleWatermark, Tenant, Time, TimeSeries, Totals, Usage,
};

use crate::adapter::{Fault, OpResult, Outcome, Session, bytes_value, float_value};

pub const OPS: &[&str] = &[
    "clickhouse.new",
    "database.new",
    "recording.new",
    "recording.succeed",
    "recording.calls",
    "usage.new",
    "usage.adapter",
    "usage.health_check",
    "usage.setup",
    "usage.migrate",
    "usage.add_batch",
    "usage.add_samples",
    "usage.sample_watermark",
    "usage.find_samples",
    "usage.time_series",
    "usage.total",
    "usage.total_batch",
    "usage.purge",
    "usage.find",
    "usage.find_across_tenants",
    "usage.count",
    "usage.sum",
    "usage.find_daily",
    "usage.sum_daily",
    "usage.sum_daily_batch",
    "usage.backfill_daily",
    "tenant.new",
    "tenant.add_batch",
    "tenant.time_series",
    "tenant.total",
    "tenant.total_batch",
    "tenant.purge",
    "tenant.find",
    "tenant.count",
    "tenant.sum",
    "tenant.find_daily",
    "tenant.sum_daily",
    "tenant.sum_daily_batch",
    "clickhouse.route_log",
    "clickhouse.clear_route_log",
    "clickhouse.next_query_id",
    "clickhouse.stats",
    "clickhouse.query",
    "clickhouse.column_type",
    "adapter.schema",
    "metric.new",
    "metric.call",
    "metric.schema",
    "metric.indexes",
    "metric.columns",
    "metric.extract_columns",
    "metric.validate",
    "query.group_by_interval",
    "query.group_by",
    "query.aggregate",
    "query.extract_aggregate",
    "query.constants",
    "sample.new",
    "range.new",
    "watermark.new",
    "watermark.matches",
    "gap.new",
    "accumulator.new",
    "accumulator.collect",
    "accumulator.flush",
    "accumulator.count",
    "accumulator.elapsed",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("accumulator_collect", "Fold 100 tagged events and gauges into an accumulator (the worker hot path)"),
    ("extract_columns", "Extract the dimension columns of an event row from its tags"),
    ("sample_payload_hash", "Hash a canonical sample: its identity and payload"),
    ("clickhouse_add_batch", "Insert 100 events into ClickHouse in one JSONEachRow batch"),
    ("clickhouse_aggregate", "Read an hourly time-bucketed aggregate grouped by service over seeded events"),
    ("clickhouse_total_batch", "Read routed totals of three metrics over a closed window (the daily rollup)"),
];

/// The tests' recording adapter: every call recorded, addBatch() succeeding
/// as told, or failing once after `throw_after` more calls.
#[derive(Debug)]
pub struct Recording {
    succeed: AtomicBool,
    /// Further addBatch() calls to accept before the next one fails once; -1 for never.
    throw_after: AtomicI64,
    calls: Mutex<Vec<Json>>,
}

impl Default for Recording {
    fn default() -> Self {
        Recording { succeed: AtomicBool::new(false), throw_after: AtomicI64::new(-1), calls: Mutex::default() }
    }
}

impl Recording {
    fn record(&self, call: Json) {
        self.calls.lock().unwrap_or_else(|e| e.into_inner()).push(call);
    }

    fn read(&self, method: &str, tenant: &str) {
        self.record(json!({"method": method, "tenant": tenant}));
    }
}

#[async_trait]
impl Adapter for Recording {
    fn name(&self) -> &str {
        "recording"
    }

    async fn health_check(&self) -> Array {
        let mut a = Array::default();
        a.set(ArrayKey::from("healthy"), Value::Bool(true));
        a
    }

    async fn setup(&self) -> utopia_usage::Result<()> {
        Ok(())
    }

    async fn add_batch(&self, metrics: &[Entry], kind: Kind, batch_size: i64) -> utopia_usage::Result<bool> {
        match self.throw_after.load(Ordering::Relaxed) {
            0 => {
                self.throw_after.store(-1, Ordering::Relaxed);
                return Err(Error::exception("Insert failed"));
            }
            n if n > 0 => self.throw_after.store(n - 1, Ordering::Relaxed),
            _ => {}
        }
        let succeed = self.succeed.load(Ordering::Relaxed);
        self.record(json!({
            "method": "addBatch",
            "type": kind.as_str(),
            "batchSize": batch_size,
            "metrics": metrics.iter().map(entry).collect::<Vec<_>>(),
            "succeed": succeed,
        }));
        Ok(succeed)
    }

    async fn time_series(
        &self,
        tenant: &str,
        _metrics: &[String],
        _interval: &str,
        _start: &str,
        _end: &str,
        _queries: &[Query],
        _zero_fill: bool,
        _kind: Option<Kind>,
    ) -> utopia_usage::Result<TimeSeries> {
        self.read("getTimeSeries", tenant);
        Ok(TimeSeries::new())
    }

    async fn total(&self, tenant: &str, _: &str, _: &[Query], _: Option<Kind>) -> utopia_usage::Result<i64> {
        self.read("getTotal", tenant);
        Ok(0)
    }

    async fn total_batch(
        &self,
        tenant: &str,
        _: &[String],
        _: &[Query],
        _: Option<Kind>,
    ) -> utopia_usage::Result<Totals> {
        self.read("getTotalBatch", tenant);
        Ok(Totals::new())
    }

    async fn purge(&self, tenant: &str, _: &[Query], _: Option<Kind>) -> utopia_usage::Result<bool> {
        self.read("purge", tenant);
        Ok(true)
    }

    async fn find(&self, tenant: &str, _: &[Query], _: Option<Kind>) -> utopia_usage::Result<Vec<Metric>> {
        self.read("find", tenant);
        Ok(Vec::new())
    }

    async fn count(&self, tenant: &str, _: &[Query], _: Option<Kind>, _: Option<i64>) -> utopia_usage::Result<i64> {
        self.read("count", tenant);
        Ok(0)
    }

    async fn sum(&self, tenant: &str, _: &[Query], _: &str, _: Kind) -> utopia_usage::Result<i64> {
        self.read("sum", tenant);
        Ok(0)
    }

    async fn find_daily(&self, tenant: &str, _: &[Query]) -> utopia_usage::Result<Vec<Metric>> {
        self.read("findDaily", tenant);
        Ok(Vec::new())
    }

    async fn sum_daily(&self, tenant: &str, _: &[Query], _: &str) -> utopia_usage::Result<i64> {
        self.read("sumDaily", tenant);
        Ok(0)
    }

    async fn sum_daily_batch(&self, tenant: &str, _: &[String], _: &[Query]) -> utopia_usage::Result<Totals> {
        self.read("sumDailyBatch", tenant);
        Ok(Totals::new())
    }
}

/// The adapters a handle holds.
#[derive(Clone)]
enum AnyAdapter {
    ClickHouse(Arc<ClickHouse>),
    Database(Arc<DatabaseAdapter>),
    Recording(Arc<Recording>),
}

impl AnyAdapter {
    fn dyn_adapter(&self) -> Arc<dyn Adapter> {
        match self {
            AnyAdapter::ClickHouse(a) => a.clone(),
            AnyAdapter::Database(a) => a.clone(),
            AnyAdapter::Recording(a) => a.clone(),
        }
    }
}

fn entry(e: &Entry) -> Json {
    let mut m = Map::new();
    m.insert("tenant".into(), e.tenant.as_deref().map_or(Json::Null, |t| Json::String(t.into())));
    m.insert("metric".into(), Json::String(e.metric.clone()));
    m.insert("value".into(), Json::from(e.value));
    m.insert("tags".into(), encode(&Value::Array(e.tags.clone())));
    m.insert("allowNegative".into(), Json::Bool(e.allow_negative));
    match &e.time {
        Some(Time::At(dt)) => {
            m.insert(
                "time".into(),
                Json::String(String::from_utf8_lossy(&dt.format(b"Y-m-d\\TH:i:s.uP")).into_owned()),
            );
        }
        Some(Time::Text(t)) => {
            m.insert("time".into(), Json::String(t.clone()));
        }
        None => {}
    }
    Json::Object(m)
}

/// A PHP value as the PHP Codec encodes it.
fn encode(value: &Value) -> Json {
    fn text(b: &[u8]) -> Json {
        match std::str::from_utf8(b) {
            Ok(s) => Json::String(s.to_owned()),
            Err(_) => bytes_value(b),
        }
    }
    fn object(a: &Array) -> Json {
        let mut m = Map::new();
        for (k, v) in a.iter() {
            let key = match k {
                KeyRef::Int(i) => i.to_string(),
                KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
            };
            m.insert(key, encode(v));
        }
        Json::Object(m)
    }
    match value {
        Value::Null => Json::Null,
        Value::Bool(b) => Json::Bool(*b),
        Value::Int(i) => Json::from(*i),
        Value::Float(f) => float_value(*f),
        Value::Str(s) => text(s.as_bytes()),
        Value::Array(a) if a.is_list() => Json::Array(a.values().map(encode).collect()),
        Value::Array(a) | Value::Object(a) => object(a),
        Value::Ext(x) => object(&php_std::Extension::to_array(x)),
    }
}

fn err(e: Error) -> Outcome {
    let bytes = e.message_bytes();
    match std::str::from_utf8(&bytes) {
        Ok(s) => Outcome::err(e.php_class(), s),
        Err(_) => Outcome::err_bytes(e.php_class(), &bytes),
    }
}

fn utc() -> TzInfo {
    TzInfo::utc()
}

/// `{"$time": modifier, "format"?, "midnight"?}` as its string.
fn time_string(o: &Map<String, Json>) -> Result<String, Fault> {
    let modifier = o.get("$time").and_then(Json::as_str).ok_or_else(|| Fault::new("invalid $time"))?;
    let mut dt =
        DateTime::new(modifier.as_bytes(), Some(&TimeZone::utc()), &utc()).map_err(|e| Fault::new(e.to_string()))?;
    if o.get("midnight").and_then(Json::as_bool).unwrap_or(false) {
        dt.set_time(0, 0, 0, 0);
    }
    let format = o.get("format").and_then(Json::as_str).unwrap_or("Y-m-d H:i:s");
    Ok(String::from_utf8_lossy(&dt.format(format.as_bytes())).into_owned())
}

/// A JSON argument as a PHP value (the Codec's decoding, plus the tags of
/// the usage adapters; a `$datetime` is its string here).
fn value(json: &Json) -> Result<Value, Fault> {
    Ok(match json {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => Value::Float(n.as_f64().unwrap_or(0.0)),
        },
        Json::String(s) => Value::Str(Str::from(s.as_str())),
        Json::Array(a) => Value::Array(Array::from_list(a.iter().map(value).collect::<Result<_, _>>()?)),
        Json::Object(o) if o.is_empty() => Value::Object(Array::default()),
        Json::Object(o) => {
            if o.contains_key("$time") {
                return Ok(Value::from(time_string(o)?.as_str()));
            }
            if let Some(s) = o.get("$datetime").and_then(Json::as_str) {
                return Ok(Value::from(s));
            }
            if let Some(Json::String(b)) = o.get("$bytes")
                && o.len() == 1
            {
                use base64::Engine as _;
                let raw =
                    base64::engine::general_purpose::STANDARD.decode(b).map_err(|_| Fault::new("invalid $bytes"))?;
                return Ok(Value::Str(Str::from(raw)));
            }
            if let Some(attrs) = o.get("$metric") {
                let Value::Array(fields) = value(attrs)? else { return Err(Fault::new("invalid $metric")) };
                return Ok(Value::Ext(Object::Row { class: "Utopia\\Usage\\Metric".into(), fields }));
            }
            let mut a = Array::default();
            for (k, v) in o {
                a.set(ArrayKey::normalize(Str::from(k.as_str())), value(v)?);
            }
            Value::Array(a)
        }
    })
}

fn array(json: &Json) -> Result<Array, Fault> {
    match value(json)? {
        Value::Array(a) => Ok(a),
        Value::Object(a) => Ok(a),
        other => Err(Fault::new(format!("expected an array, got {}", other.type_name()))),
    }
}

fn arg<'a>(a: &'a Json, key: &str) -> Result<&'a Json, Fault> {
    a.get(key).ok_or_else(|| Fault::new(format!("missing argument `{key}`")))
}

fn string(a: &Json, key: &str) -> Result<String, Fault> {
    match arg(a, key)? {
        Json::String(s) => Ok(s.clone()),
        Json::Object(o) if o.contains_key("$time") => time_string(o),
        other => Err(Fault::new(format!("`{key}` must be a string, got {other}"))),
    }
}

fn int(a: &Json, key: &str) -> Result<i64, Fault> {
    arg(a, key)?.as_i64().ok_or_else(|| Fault::new(format!("`{key}` must be an integer")))
}

fn opt_int(a: &Json, key: &str) -> Result<Option<i64>, Fault> {
    match a.get(key) {
        None | Some(Json::Null) => Ok(None),
        Some(v) => v.as_i64().map(Some).ok_or_else(|| Fault::new(format!("`{key}` must be an integer"))),
    }
}

fn strings(a: &Json, key: &str) -> Result<Vec<String>, Fault> {
    arg(a, key)?
        .as_array()
        .ok_or_else(|| Fault::new(format!("`{key}` must be a list")))?
        .iter()
        .map(|v| v.as_str().map(str::to_owned).ok_or_else(|| Fault::new(format!("`{key}` must hold strings"))))
        .collect()
}

fn kind(a: &Json) -> Result<Result<Option<Kind>, Error>, Fault> {
    Ok(match a.get("type") {
        None | Some(Json::Null) => Ok(None),
        Some(Json::String(s)) => Kind::parse(s).map(Some),
        Some(other) => return Err(Fault::new(format!("`type` must be a string, got {other}"))),
    })
}

fn queries(a: &Json) -> Result<Vec<Query>, Fault> {
    let Some(list) = a.get("queries") else { return Ok(Vec::new()) };
    let list = list.as_array().ok_or_else(|| Fault::new("`queries` must be a list"))?;
    list.iter()
        .map(|q| {
            let method = q.get("method").and_then(Json::as_str).ok_or_else(|| Fault::new("query without a method"))?;
            let method =
                Method::try_from_value(method).ok_or_else(|| Fault::new(format!("unknown method `{method}`")))?;
            let attribute = q.get("attribute").and_then(Json::as_str).unwrap_or("");
            let values = match q.get("values") {
                Some(v) => array(v)?,
                None => Array::default(),
            };
            Ok(Query::new(method, attribute, values, ""))
        })
        .collect()
}

fn datetime(json: &Json) -> Result<DateTime, Fault> {
    let text = match json {
        Json::String(s) => s.clone(),
        Json::Object(o) if o.contains_key("$time") => time_string(o)?,
        Json::Object(o) => {
            o.get("$datetime").and_then(Json::as_str).ok_or_else(|| Fault::new("invalid date"))?.to_owned()
        }
        other => return Err(Fault::new(format!("invalid date {other}"))),
    };
    DateTime::new(text.as_bytes(), None, &utc()).map_err(|e| Fault::new(format!("{text}: {e}")))
}

fn entries(json: &Json) -> Result<Vec<Entry>, Fault> {
    let list = json.as_array().ok_or_else(|| Fault::new("`metrics` must be a list"))?;
    list.iter()
        .map(|m| {
            let time = match m.get("time") {
                None | Some(Json::Null) => None,
                Some(Json::Object(o)) if o.contains_key("$datetime") => {
                    Some(Time::At(Box::new(datetime(&Json::Object(o.clone()))?)))
                }
                Some(Json::Object(o)) if o.contains_key("$time") => Some(Time::Text(time_string(o)?)),
                Some(Json::String(s)) => Some(Time::Text(s.clone())),
                Some(other) => return Err(Fault::new(format!("unrepresentable time {other}"))),
            };
            Ok(Entry {
                tenant: m.get("tenant").and_then(Json::as_str).map(str::to_owned),
                metric: m
                    .get("metric")
                    .and_then(Json::as_str)
                    .ok_or_else(|| Fault::new("entry without a metric string"))?
                    .to_owned(),
                value: m
                    .get("value")
                    .and_then(Json::as_i64)
                    .ok_or_else(|| Fault::new("entry without an integer value"))?,
                tags: match m.get("tags") {
                    Some(t) => array(t)?,
                    None => Array::default(),
                },
                time,
                allow_negative: m.get("allowNegative").and_then(Json::as_bool).unwrap_or(false),
            })
        })
        .collect()
}

fn text_of(json: &Json, key: &str) -> Result<String, Fault> {
    json.get(key).and_then(Json::as_str).map(str::to_owned).ok_or_else(|| Fault::new(format!("missing `{key}`")))
}

fn sample(json: &Json) -> Result<Result<Sample, Error>, Fault> {
    Ok(Sample::new(
        &text_of(json, "environment")?,
        &text_of(json, "region")?,
        &text_of(json, "projectInternalId")?,
        &text_of(json, "databaseInternalId")?,
        &text_of(json, "member")?,
        &text_of(json, "generation")?,
        int(json, "sequence")?,
        &text_of(json, "metric")?,
        datetime(arg(json, "intervalStart")?)?,
        datetime(arg(json, "intervalEnd")?)?,
        int(json, "value")?,
        int(json, "eventVersion")?,
    ))
}

fn range(json: &Json) -> Result<Result<SampleRange, Error>, Fault> {
    Ok(SampleRange::new(
        &text_of(json, "environment")?,
        &text_of(json, "region")?,
        &text_of(json, "projectInternalId")?,
        &text_of(json, "databaseInternalId")?,
        &text_of(json, "member")?,
        &text_of(json, "generation")?,
        &text_of(json, "metric")?,
        int(json, "firstSequence")?,
        int(json, "lastSequence")?,
        datetime(arg(json, "intervalStart")?)?,
        datetime(arg(json, "intervalEnd")?)?,
    ))
}

fn encode_sample(s: &Sample) -> Json {
    json!({
        "environment": s.environment,
        "region": s.region,
        "projectInternalId": s.project_internal_id,
        "databaseInternalId": s.database_internal_id,
        "member": s.member,
        "generation": s.generation,
        "sequence": s.sequence,
        "metric": s.metric,
        "intervalStart": s.formatted_interval_start(),
        "intervalEnd": s.formatted_interval_end(),
        "value": s.value,
        "eventVersion": s.event_version,
        "id": s.id(),
        "payloadHash": s.payload_hash(),
    })
}

fn encode_result(r: &SampleResult) -> Json {
    json!({
        "samples": r.samples().iter().map(encode_sample).collect::<Vec<_>>(),
        "conflicts": r.conflicts(),
        "gaps": r.gaps().iter().map(|g| json!({"first": g.first, "last": g.last})).collect::<Vec<_>>(),
        "discontinuities": r.discontinuities(),
        "duplicateCount": r.duplicate_count(),
        "truncated": r.is_truncated(),
        "complete": r.is_complete(),
        "watermark": {"entries": r.watermark().entries(), "truncated": r.watermark().is_truncated()},
    })
}

fn watermark_json(w: SampleWatermark, session: &mut Session) -> Json {
    let entries = w.entries().to_vec();
    let truncated = w.is_truncated();
    json!({"watermark": session.handle(w), "entries": entries, "truncated": truncated})
}

fn metrics(list: &[Metric]) -> Json {
    Json::Array(list.iter().map(|m| encode(&Value::Array(m.attributes().clone()))).collect())
}

fn number(n: Number) -> Json {
    match n {
        Number::Int(i) => Json::from(i),
        Number::Float(f) => float_value(f),
    }
}

/// A map keyed by metric name, as PHP's array of it encodes.
fn keyed(pairs: impl IntoIterator<Item = (String, Value)>) -> Json {
    let mut a = Array::default();
    for (k, v) in pairs {
        a.set(ArrayKey::from(k.as_str()), v);
    }
    encode(&Value::Array(a))
}

fn totals(t: &Totals) -> Json {
    keyed(t.iter().map(|(k, v)| (k.clone(), Value::Int(*v))))
}

fn series(s: &TimeSeries) -> Json {
    let mut a = Array::default();
    for (name, series) in s {
        let mut entry = Array::default();
        entry.set(ArrayKey::from("total"), series.total.to_value());
        let data: Vec<Value> = series
            .data
            .iter()
            .map(|p| {
                let mut point = Array::default();
                point.set(ArrayKey::from("value"), Value::Float(p.value));
                point.set(ArrayKey::from("date"), Value::from(p.date.as_str()));
                Value::Array(point)
            })
            .collect();
        entry.set(ArrayKey::from("data"), Value::Array(Array::from_list(data)));
        a.set(ArrayKey::from(name.as_str()), Value::Array(entry));
    }
    encode(&Value::Array(a))
}

fn adapter(session: &Session, a: &Json) -> Result<AnyAdapter, Fault> {
    session.get::<AnyAdapter>(arg(a, "adapter")?).cloned()
}

fn clickhouse(session: &Session, a: &Json) -> Result<Arc<ClickHouse>, Fault> {
    match adapter(session, a)? {
        AnyAdapter::ClickHouse(c) => Ok(c),
        _ => Err(Fault::new("not a ClickHouse adapter")),
    }
}

fn usage(session: &Session, a: &Json) -> Result<Usage, Fault> {
    session.get::<Usage>(arg(a, "usage")?).cloned()
}

fn view(session: &Session, a: &Json) -> Result<Tenant, Fault> {
    session.get::<Tenant>(arg(a, "view")?).cloned()
}

fn config(session: &Session, a: &Json) -> Result<Config, Fault> {
    let url = session.service("usage-clickhouse")?;
    let rest = url.strip_prefix("http://").unwrap_or(url);
    let (auth, host) = rest.trim_end_matches('/').rsplit_once('@').unwrap_or(("", rest));
    let (user, pass) = auth.split_once(':').unwrap_or((auth, ""));
    let (host, port) = host.rsplit_once(':').unwrap_or((host, "8123"));
    let mut c = Config::new(a.get("host").and_then(Json::as_str).unwrap_or(host));
    c.username = a.get("username").and_then(Json::as_str).unwrap_or(user).to_owned();
    c.password = a.get("password").and_then(Json::as_str).unwrap_or(pass).to_owned();
    c.port = match a.get("port") {
        Some(p) => p.as_i64().ok_or_else(|| Fault::new("`port` must be an integer"))?,
        None => port.parse().map_err(|_| Fault::new("invalid port"))?,
    };
    c.secure = a.get("secure").and_then(Json::as_bool).unwrap_or(false);
    c.namespace = a.get("namespace").and_then(Json::as_str).unwrap_or("").to_owned();
    c.database = a.get("database").and_then(Json::as_str).unwrap_or("default").to_owned();
    c.shared_tables = a.get("shared_tables").and_then(Json::as_bool).unwrap_or(false);
    c.async_inserts = a.get("async_inserts").and_then(Json::as_bool).unwrap_or(false);
    c.async_insert_wait = a.get("async_insert_wait").and_then(Json::as_bool).unwrap_or(true);
    c.dual_read_sample_rate = a.get("dual_read_sample_rate").and_then(Json::as_f64).unwrap_or(0.0);
    c.retention = opt_int(a, "retention")?;
    Ok(c)
}

async fn database(session: &Session, a: &Json) -> Result<Result<DatabaseAdapter, utopia_database::Error>, Fault> {
    use utopia_database::adapter::sql::{Engine, Sql};
    let url = session.service("database-mariadb")?;
    // `mysql://user:pass@host:port` as PDO's DSN, user and password.
    let rest = url.strip_prefix("mysql://").unwrap_or(url).trim_end_matches('/');
    let (auth, host) = rest.rsplit_once('@').unwrap_or(("", rest));
    let (user, pass) = auth.split_once(':').unwrap_or((auth, ""));
    let (host, port) = host.rsplit_once(':').unwrap_or((host, "3306"));
    let dsn = format!("mysql:host={host};port={port};charset=utf8mb4");
    let (user, pass) = (user.to_owned(), pass.to_owned());
    let name = string(a, "database")?;
    let namespace = a.get("namespace").and_then(Json::as_str).unwrap_or("utopia_usage").to_owned();
    let create = a.get("create").and_then(Json::as_bool).unwrap_or(true);
    Ok(async {
        let adapter = Sql::connect(Engine::MariaDb, &dsn, Some(&user), Some(&pass)).await?;
        let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
        let db = utopia_database::Database::new(Arc::new(adapter), cache);
        db.set_database(&name);
        db.set_namespace(&namespace);
        if create {
            db.create(None).await?;
        }
        Ok(DatabaseAdapter::new(Arc::new(db)))
    }
    .await)
}

macro_rules! tri {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return Ok(err(e)),
        }
    };
}

/// The calls `usage.*` (on a Usage, tenant as an argument) and `tenant.*`
/// (on a Tenant) share.
async fn shared(op: &str, a: &Json, session: &mut Session, explicit: bool) -> OpResult {
    let (u, t) = if explicit { (Some(usage(session, a)?), None) } else { (None, Some(view(session, a)?)) };
    let tenant = if explicit { string(a, "tenant").ok() } else { None };
    let tenant = tenant.as_deref().unwrap_or("");
    let qs = queries(a)?;
    let kind_arg = kind(a)?;
    Ok(match op {
        "add_batch" => {
            let k = tri!(Kind::parse(&string(a, "type")?));
            let metrics = entries(arg(a, "metrics")?)?;
            let size = opt_int(a, "batch_size")?.unwrap_or(1000);
            let r = match (&u, &t) {
                (Some(u), _) => u.add_batch(&metrics, k, size).await,
                (_, Some(t)) => t.add_batch(&metrics, k, size).await,
                _ => unreachable!(),
            };
            Outcome::ok(Json::Bool(tri!(r)))
        }
        "time_series" => {
            let names = strings(a, "metrics")?;
            let interval = string(a, "interval")?;
            let start = string(a, "start")?;
            let end = string(a, "end")?;
            let zero = a.get("zero_fill").and_then(Json::as_bool).unwrap_or(true);
            let k = tri!(kind_arg);
            let r = match (&u, &t) {
                (Some(u), _) => u.time_series(tenant, &names, &interval, &start, &end, &qs, zero, k).await,
                (_, Some(t)) => t.time_series(&names, &interval, &start, &end, &qs, zero, k).await,
                _ => unreachable!(),
            };
            Outcome::ok(series(&tri!(r)))
        }
        "total" => {
            let metric = string(a, "metric")?;
            let k = tri!(kind_arg);
            let r = match (&u, &t) {
                (Some(u), _) => u.total(tenant, &metric, &qs, k).await,
                (_, Some(t)) => t.total(&metric, &qs, k).await,
                _ => unreachable!(),
            };
            Outcome::ok(Json::from(tri!(r)))
        }
        "total_batch" => {
            let names = strings(a, "metrics")?;
            let k = tri!(kind_arg);
            let r = match (&u, &t) {
                (Some(u), _) => u.total_batch(tenant, &names, &qs, k).await,
                (_, Some(t)) => t.total_batch(&names, &qs, k).await,
                _ => unreachable!(),
            };
            Outcome::ok(totals(&tri!(r)))
        }
        "purge" => {
            let k = tri!(kind_arg);
            let r = match (&u, &t) {
                (Some(u), _) => u.purge(tenant, &qs, k).await,
                (_, Some(t)) => t.purge(&qs, k).await,
                _ => unreachable!(),
            };
            Outcome::ok(Json::Bool(tri!(r)))
        }
        "find" => {
            let k = tri!(kind_arg);
            let r = match (&u, &t) {
                (Some(u), _) => u.find(tenant, &qs, k).await,
                (_, Some(t)) => t.find(&qs, k).await,
                _ => unreachable!(),
            };
            Outcome::ok(metrics(&tri!(r)))
        }
        "count" => {
            let k = tri!(kind_arg);
            let max = opt_int(a, "max")?;
            let r = match (&u, &t) {
                (Some(u), _) => u.count(tenant, &qs, k, max).await,
                (_, Some(t)) => t.count(&qs, k, max).await,
                _ => unreachable!(),
            };
            Outcome::ok(Json::from(tri!(r)))
        }
        "sum" => {
            let k = tri!(kind_arg).unwrap_or(Kind::Event);
            let attribute = a.get("attribute").and_then(Json::as_str).unwrap_or("value").to_owned();
            let r = match (&u, &t) {
                (Some(u), _) => u.sum(tenant, &qs, &attribute, k).await,
                (_, Some(t)) => t.sum(&qs, &attribute, k).await,
                _ => unreachable!(),
            };
            Outcome::ok(Json::from(tri!(r)))
        }
        "find_daily" => {
            let r = match (&u, &t) {
                (Some(u), _) => u.find_daily(tenant, &qs).await,
                (_, Some(t)) => t.find_daily(&qs).await,
                _ => unreachable!(),
            };
            Outcome::ok(metrics(&tri!(r)))
        }
        "sum_daily" => {
            let attribute = a.get("attribute").and_then(Json::as_str).unwrap_or("value").to_owned();
            let r = match (&u, &t) {
                (Some(u), _) => u.sum_daily(tenant, &qs, &attribute).await,
                (_, Some(t)) => t.sum_daily(&qs, &attribute).await,
                _ => unreachable!(),
            };
            Outcome::ok(Json::from(tri!(r)))
        }
        "sum_daily_batch" => {
            let names = strings(a, "metrics")?;
            let r = match (&u, &t) {
                (Some(u), _) => u.sum_daily_batch(tenant, &names, &qs).await,
                (_, Some(t)) => t.sum_daily_batch(&names, &qs).await,
                _ => unreachable!(),
            };
            Outcome::ok(totals(&tri!(r)))
        }
        other => return Err(Fault::new(format!("usage: unknown operation `{other}`"))),
    })
}

fn query_json(q: &Query) -> Json {
    json!({
        "class": "Utopia\\Usage\\UsageQuery",
        "method": q.method().as_str(),
        "attribute": q.attribute(),
        "values": encode(&Value::Array(q.values().clone())),
    })
}

fn metric_call(attributes: Array, method: &str, args: &[Value]) -> Result<Json, Fault> {
    let mut metric = Metric::new(attributes);
    let text = |v: Option<&[u8]>| v.map_or(Json::Null, |b| encode(&Value::Str(Str::copy_from(b))));
    let getter = |name: &str| -> Option<&str> {
        Some(match name {
            "getTime" => "time",
            "getPath" => "path",
            "getMethod" => "method",
            "getStatus" => "status",
            "getResourceType" => "resourceType",
            "getResourceId" => "resourceId",
            "getCountry" => "country",
            "getService" => "service",
            "getResourceInternalId" => "resourceInternalId",
            "getOrdinal" => "ordinal",
            "getTeamId" => "teamId",
            "getTeamInternalId" => "teamInternalId",
            "getRegion" => "region",
            "getHostname" => "hostname",
            "getIp" => "ip",
            "getOsCode" => "osCode",
            "getOsName" => "osName",
            "getOsVersion" => "osVersion",
            "getClientType" => "clientType",
            "getClientCode" => "clientCode",
            "getClientName" => "clientName",
            "getClientVersion" => "clientVersion",
            "getClientEngine" => "clientEngine",
            "getClientEngineVersion" => "clientEngineVersion",
            "getDeviceName" => "deviceName",
            "getDeviceBrand" => "deviceBrand",
            "getDeviceModel" => "deviceModel",
            _ => return None,
        })
    };
    let str_arg = |i: usize| -> Result<String, Fault> {
        match args.get(i) {
            Some(Value::Str(s)) => Ok(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => Err(Fault::new(format!("{method}: argument {i} must be a string"))),
        }
    };
    let result = match method {
        "getId" => text(Some(metric.id())),
        "getMetric" => text(Some(metric.metric())),
        "getType" => text(Some(metric.kind())),
        "getValue" => {
            let default = match args.first() {
                None | Some(Value::Null) => None,
                Some(Value::Int(i)) => Some(Number::Int(*i)),
                Some(Value::Float(f)) => Some(Number::Float(*f)),
                Some(_) => return Err(Fault::new("getValue: the default must be a number")),
            };
            metric.value(default).map_or(Json::Null, number)
        }
        "getTenant" => metric.tenant().map_or(Json::Null, |s| encode(&Value::Str(s))),
        "getAttributes" | "toArray" => encode(&Value::Array(metric.attributes().clone())),
        "getAttribute" => {
            let name = str_arg(0)?;
            let default = args.get(1).cloned().unwrap_or(Value::Null);
            encode(metric.attribute(&name, &default))
        }
        "setAttribute" => {
            let name = str_arg(0)?;
            metric.set_attribute(&name, args.get(1).cloned().unwrap_or(Value::Null));
            Json::String("$this".into())
        }
        "hasAttribute" => Json::Bool(metric.has_attribute(&str_arg(0)?)),
        "removeAttribute" => {
            metric.remove_attribute(&str_arg(0)?);
            Json::String("$this".into())
        }
        "isEmpty" => Json::Bool(metric.is_empty()),
        other => match getter(other) {
            Some(name) => text(metric.text(name)),
            None => return Err(Fault::new(format!("metric: unknown method `{other}`"))),
        },
    };
    Ok(json!({"result": result, "attributes": encode(&Value::Array(metric.into_attributes()))}))
}

pub async fn call(op: &str, a: &Json, session: &mut Session) -> OpResult {
    Ok(match op {
        "clickhouse.new" => {
            let c = config(session, a)?;
            let adapter = tri!(ClickHouse::new(c));
            Outcome::ok(session.handle(AnyAdapter::ClickHouse(Arc::new(adapter))))
        }
        "database.new" => match database(session, a).await? {
            Ok(adapter) => Outcome::ok(session.handle(AnyAdapter::Database(Arc::new(adapter)))),
            Err(e) => return Ok(err(Error::Database(e))),
        },
        "recording.new" => {
            let r = Recording::default();
            r.succeed.store(a.get("succeed").and_then(Json::as_bool).unwrap_or(true), Ordering::Relaxed);
            r.throw_after.store(a.get("throw_after").and_then(Json::as_i64).unwrap_or(-1), Ordering::Relaxed);
            Outcome::ok(session.handle(AnyAdapter::Recording(Arc::new(r))))
        }
        "recording.succeed" | "recording.calls" => {
            let AnyAdapter::Recording(r) = adapter(session, a)? else {
                return Err(Fault::new("not a recording adapter"));
            };
            if op == "recording.succeed" {
                r.succeed.store(arg(a, "succeed")?.as_bool().unwrap_or(false), Ordering::Relaxed);
                Outcome::ok(Json::Bool(true))
            } else {
                let mut calls = std::mem::take(&mut *r.calls.lock().unwrap_or_else(|e| e.into_inner()));
                // `tag`: each batch's metrics as the values of that tag.
                if let Some(tag) = a.get("tag").and_then(Json::as_str) {
                    for call in &mut calls {
                        if let Some(Json::Array(metrics)) = call.get_mut("metrics") {
                            for m in metrics.iter_mut() {
                                *m = m.get("tags").and_then(|t| t.get(tag)).cloned().unwrap_or(Json::Null);
                            }
                        }
                    }
                }
                Outcome::ok(Json::Array(calls))
            }
        }
        "usage.new" => {
            let adapter = adapter(session, a)?.dyn_adapter();
            Outcome::ok(session.handle(Usage::new(adapter)))
        }
        "usage.adapter" => Outcome::ok(Json::String(usage(session, a)?.adapter().name().to_owned())),
        "usage.health_check" => Outcome::ok(encode(&Value::Array(usage(session, a)?.health_check().await))),
        "usage.setup" => {
            tri!(usage(session, a)?.setup().await);
            Outcome::ok(Json::Null)
        }
        "usage.migrate" => {
            tri!(usage(session, a)?.migrate().await);
            Outcome::ok(Json::Null)
        }
        "usage.add_samples" => {
            let list = arg(a, "samples")?.as_array().ok_or_else(|| Fault::new("`samples` must be a list"))?;
            let mut samples = Vec::with_capacity(list.len());
            for s in list {
                samples.push(tri!(sample(s)?));
            }
            let size = opt_int(a, "batch_size")?.unwrap_or(1000);
            Outcome::ok(Json::Bool(tri!(usage(session, a)?.add_samples(&samples, size).await)))
        }
        "usage.sample_watermark" => {
            let r = tri!(range(arg(a, "range")?)?);
            let w = tri!(usage(session, a)?.sample_watermark(&r, int(a, "limit")?).await);
            Outcome::ok(watermark_json(w, session))
        }
        "usage.find_samples" => {
            let r = tri!(range(arg(a, "range")?)?);
            let w = session.get::<SampleWatermark>(arg(a, "watermark")?)?.clone();
            let result = tri!(usage(session, a)?.find_samples(&r, &w, int(a, "limit")?).await);
            Outcome::ok(encode_result(&result))
        }
        "usage.find_across_tenants" => {
            let k = tri!(kind(a)?);
            Outcome::ok(metrics(&tri!(usage(session, a)?.find_across_tenants(&queries(a)?, k).await)))
        }
        "usage.backfill_daily" => {
            let (from, to) = (string(a, "from")?, string(a, "to")?);
            let force = a.get("force").and_then(Json::as_bool).unwrap_or(false);
            tri!(usage(session, a)?.backfill_daily(&from, &to, force).await);
            Outcome::ok(Json::Null)
        }
        "tenant.new" => {
            let t = tri!(Tenant::new(usage(session, a)?, string(a, "tenant")?));
            Outcome::ok(session.handle(t))
        }
        op if op.starts_with("usage.") => return shared(&op["usage.".len()..], a, session, true).await,
        op if op.starts_with("tenant.") => return shared(&op["tenant.".len()..], a, session, false).await,
        "clickhouse.route_log" => {
            let log = clickhouse(session, a)?.route_log();
            Outcome::ok(Json::Array(
                log.iter()
                    .map(|e| {
                        json!({
                            "operation": e.operation,
                            "metric": e.metric,
                            "route": e.route,
                            "start": e.start,
                            "end": e.end,
                            "dimensions": e.dimensions,
                            "interval": e.interval,
                        })
                    })
                    .collect(),
            ))
        }
        "clickhouse.clear_route_log" => {
            clickhouse(session, a)?.clear_route_log();
            Outcome::ok(Json::Bool(true))
        }
        "clickhouse.next_query_id" => {
            let id = a.get("id").and_then(Json::as_str).map(str::to_owned);
            clickhouse(session, a)?.set_next_query_id(id);
            Outcome::ok(Json::Bool(true))
        }
        "clickhouse.stats" => {
            let s = clickhouse(session, a)?.connection_stats();
            Outcome::ok(json!({
                "request_count": s.request_count,
                "async_inserts": s.async_inserts,
                "async_insert_wait": s.async_insert_wait,
            }))
        }
        "clickhouse.query" => {
            let sql = string(a, "sql")?;
            let params: Vec<(String, Value)> = match a.get("params") {
                Some(Json::Object(o)) => {
                    o.iter().map(|(k, v)| Ok((k.clone(), value(v)?))).collect::<Result<_, Fault>>()?
                }
                _ => Vec::new(),
            };
            let body = tri!(clickhouse(session, a)?.query(&sql, &params).await);
            match serde_json::from_str::<Json>(&body) {
                Ok(Json::Object(o)) if o.get("data").is_some_and(Json::is_array) => {
                    Outcome::ok(o.get("data").cloned().unwrap_or(Json::Null))
                }
                _ => Outcome::ok(Json::String(body)),
            }
        }
        "clickhouse.column_type" => {
            let k = tri!(Kind::parse(a.get("type").and_then(Json::as_str).unwrap_or("event")));
            Outcome::ok(Json::String(tri!(ClickHouse::column_type(&string(a, "id")?, k))))
        }
        "adapter.schema" => {
            let method = string(a, "method")?;
            let k = match a.get("type").and_then(Json::as_str) {
                Some("gauge") => Kind::Gauge,
                _ => Kind::Event,
            };
            let attributes =
                |k: Kind| Json::Array(k.schema().iter().map(|x| encode(&Value::Array(x.to_array()))).collect());
            let indexes =
                |k: Kind| Json::Array(k.indexes().iter().map(|x| encode(&Value::Array(x.to_array()))).collect());
            match method.as_str() {
                "getCollectionName" => Outcome::ok(Json::String(COLLECTION.into())),
                "getEventAttributes" => Outcome::ok(attributes(Kind::Event)),
                "getGaugeAttributes" => Outcome::ok(attributes(Kind::Gauge)),
                "getAttributes" => Outcome::ok(attributes(k)),
                "getAttributeDocuments" => {
                    let documents = tri!(utopia_usage::adapter::database::attribute_documents(k))
                        .iter()
                        .map(|x| x.to_document().map(|d| encode(&Value::Array(to_query_array(&d.array_copy())))))
                        .collect::<Result<Vec<_>, _>>()
                        .map_err(Error::from);
                    Outcome::ok(Json::Array(tri!(documents)))
                }
                "getIndexDocuments" => {
                    let documents = tri!(utopia_usage::adapter::database::index_documents(k))
                        .iter()
                        .map(|x| encode(&Value::Array(to_query_array(&x.to_document().array_copy()))))
                        .collect();
                    Outcome::ok(Json::Array(documents))
                }
                "getEventIndexes" => Outcome::ok(indexes(Kind::Event)),
                "getGaugeIndexes" => Outcome::ok(indexes(Kind::Gauge)),
                "getIndexes" => Outcome::ok(indexes(k)),
                other => return Err(Fault::new(format!("adapter.schema: unknown method `{other}`"))),
            }
        }
        "metric.new" => Outcome::ok(encode(&Value::Array(match a.get("attributes") {
            Some(v) => array(v)?,
            None => Array::default(),
        }))),
        "metric.call" => {
            let attributes = match a.get("attributes") {
                Some(v) => array(v)?,
                None => Array::default(),
            };
            let args: Vec<Value> = match a.get("args") {
                Some(Json::Array(l)) => l.iter().map(value).collect::<Result<_, _>>()?,
                _ => Vec::new(),
            };
            Outcome::ok(metric_call(attributes, &string(a, "method")?, &args)?)
        }
        "metric.schema" => {
            let list = match a.get("kind").and_then(Json::as_str) {
                Some("gauge") => Metric::gauge_schema(),
                _ => Metric::event_schema(),
            };
            Outcome::ok(Json::Array(list.iter().map(|x| encode(&Value::Array(x.to_array()))).collect()))
        }
        "metric.indexes" => {
            let list = match a.get("kind").and_then(Json::as_str) {
                Some("gauge") => Metric::gauge_indexes(),
                _ => Metric::event_indexes(),
            };
            Outcome::ok(Json::Array(list.iter().map(|x| encode(&Value::Array(x.to_array()))).collect()))
        }
        "metric.columns" => Outcome::ok(json!({
            "event": utopia_usage::metric::EVENT_COLUMNS,
            "gauge": utopia_usage::metric::GAUGE_COLUMNS,
        })),
        "metric.extract_columns" => {
            let tags = array(arg(a, "tags")?)?;
            let k = tri!(Kind::parse(&string(a, "type")?));
            let columns = tri!(Metric::extract_columns(&tags, k));
            let mut out = Array::default();
            for (c, v) in columns {
                out.set(ArrayKey::from(c), v.map_or(Value::Null, Value::Str));
            }
            Outcome::ok(encode(&Value::Array(out)))
        }
        "metric.validate" => {
            let data = array(arg(a, "data")?)?;
            let k = tri!(Kind::parse(a.get("type").and_then(Json::as_str).unwrap_or("event")));
            tri!(Metric::validate(&data, k));
            Outcome::ok(Json::Null)
        }
        "query.group_by_interval" => Outcome::ok(query_json(&tri!(utopia_usage::query::group_by_interval(
            &string(a, "attribute")?,
            &string(a, "interval")?
        )))),
        "query.group_by" => match arg(a, "attributes")? {
            Json::String(s) => Outcome::ok(query_json(&utopia_usage::query::group_by(s))),
            _ => Outcome::ok(query_json(&utopia_usage::query::group_by_many(strings(a, "attributes")?))),
        },
        "query.aggregate" => Outcome::ok(query_json(&tri!(utopia_usage::query::aggregate(&string(a, "function")?)))),
        "query.extract_aggregate" => Outcome::ok(
            utopia_usage::query::extract_aggregate(&queries(a)?).map_or(Json::Null, |f| Json::String(f.into())),
        ),
        "query.constants" => {
            let mut intervals = Map::new();
            for (k, v) in utopia_usage::query::VALID_INTERVALS {
                intervals.insert((*k).into(), Json::String((*v).into()));
            }
            Outcome::ok(json!({"intervals": intervals, "aggregates": utopia_usage::query::VALID_AGGREGATES}))
        }
        "sample.new" => Outcome::ok(encode_sample(&tri!(sample(a)?))),
        "range.new" => {
            let r = tri!(range(a)?);
            Outcome::ok(json!({"start": r.formatted_interval_start(), "end": r.formatted_interval_end()}))
        }
        "watermark.new" => {
            let r = tri!(range(arg(a, "range")?)?);
            let truncated = a.get("truncated").and_then(Json::as_bool).unwrap_or(false);
            let w = tri!(SampleWatermark::new(r, strings(a, "entries")?, truncated));
            Outcome::ok(watermark_json(w, session))
        }
        "watermark.matches" => {
            let r = tri!(range(arg(a, "range")?)?);
            Outcome::ok(Json::Bool(session.get::<SampleWatermark>(arg(a, "watermark")?)?.matches(&r)))
        }
        "gap.new" => {
            let g = tri!(SampleGap::new(int(a, "first")?, int(a, "last")?));
            Outcome::ok(json!({"first": g.first, "last": g.last}))
        }
        "accumulator.new" => {
            let acc = Accumulator::new(usage(session, a)?);
            Outcome::ok(session.handle(acc))
        }
        "accumulator.collect" => {
            let tenant = string(a, "tenant")?;
            let metric = string(a, "metric")?;
            let v = int(a, "value")?;
            let k = tri!(Kind::parse(&string(a, "type")?));
            let tags = match a.get("tags") {
                Some(t) => array(t)?,
                None => Array::default(),
            };
            let time = match a.get("time") {
                None | Some(Json::Null) => None,
                Some(t) => Some(datetime(t)?),
            };
            let allow = a.get("allow_negative").and_then(Json::as_bool).unwrap_or(false);
            let acc = session.get_mut::<Accumulator>(arg(a, "accumulator")?)?;
            // `repeat: n` collects n entries, tagged `resourceId` 0 to n - 1.
            match a.get("repeat").and_then(Json::as_i64) {
                Some(n) => {
                    for i in 0..n {
                        let mut tags = tags.clone();
                        tags.set(ArrayKey::from("resourceId"), Value::from(i.to_string().as_str()));
                        tri!(acc.collect(&tenant, &metric, v, k, tags, time.clone(), allow));
                    }
                }
                None => {
                    tri!(acc.collect(&tenant, &metric, v, k, tags, time, allow));
                }
            }
            Outcome::ok(Json::Bool(true))
        }
        "accumulator.flush" => {
            let handle = arg(a, "accumulator")?.clone();
            let acc = session.get_mut::<Accumulator>(&handle)?;
            Outcome::ok(Json::Bool(tri!(acc.flush().await)))
        }
        "accumulator.count" => Outcome::ok(Json::from(session.get::<Accumulator>(arg(a, "accumulator")?)?.count())),
        "accumulator.elapsed" => {
            Outcome::ok(float_value(session.get::<Accumulator>(arg(a, "accumulator")?)?.elapsed_seconds()))
        }
        other => return Err(Fault::new(format!("usage: unknown operation `{other}`"))),
    })
}

const BENCH_SAMPLE: &str = r#"{"environment":"production","region":"fra1","projectInternalId":"101","databaseInternalId":"202","member":"mysql-0","generation":"generation-1","sequence":7,"metric":"bandwidth.inbound","intervalStart":"2026-08-01T00:00:00Z","intervalEnd":"2026-08-01T00:01:00Z","value":42,"eventVersion":1}"#;

/// A Usage on ClickHouse for a benchmark, seeded like Adapter.php's `benchUsage()`.
async fn bench_usage(session: &mut Session, name: &str) -> Result<Usage, Fault> {
    let admin =
        ClickHouse::new(config(session, &json!({"database": "default"}))?).map_err(|e| Fault::new(e.to_string()))?;
    admin
        .query(&format!("CREATE DATABASE IF NOT EXISTS `{}_bench`", session.ns), &[])
        .await
        .map_err(|e| Fault::new(e.to_string()))?;
    let args = json!({"database": format!("{}_bench", session.ns), "namespace": name, "shared_tables": true});
    let adapter = Arc::new(ClickHouse::new(config(session, &args)?).map_err(|e| Fault::new(e.to_string()))?);
    let usage = Usage::new(adapter.clone());
    let f = |e: Error| Fault::new(e.to_string());
    usage.setup().await.map_err(f)?;
    usage.purge("1", &[], None).await.map_err(f)?;
    let mut metrics = Vec::new();
    for metric in ["requests", "bandwidth", "executions"] {
        for i in 0..240i64 {
            let mut tags = Array::default();
            tags.set(ArrayKey::from("service"), Value::from(["storage", "databases", "functions"][(i % 3) as usize]));
            metrics.push(Entry::new("1", metric, i).with_tags(tags).at(Time::Text(format!(
                "2025-01-01 {:02}:{:02}:00",
                i / 10,
                (i % 10) * 6
            ))));
        }
    }
    usage.add_batch(&metrics, Kind::Event, 1000).await.map_err(f)?;
    adapter.backfill_daily("2025-01-01 00:00:00", "2025-01-02 00:00:00", true).await.map_err(f)?;
    Ok(usage)
}

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    Ok(match name {
        "accumulator_collect" => {
            let mut acc = Accumulator::new(Usage::new(Arc::new(Recording::default())));
            let ids: Vec<String> = (0..10).map(|i| format!("b{i}")).collect();
            Bench::sync(move || {
                for i in 0..100usize {
                    let mut tags = Array::default();
                    tags.set(ArrayKey::from("service"), Value::from("storage"));
                    tags.set(ArrayKey::from("resourceId"), Value::from(ids[i % 10].as_str()));
                    let _ = acc.collect("42", "bandwidth", 1024, Kind::Event, tags, None, false);
                    let mut tags = Array::default();
                    tags.set(ArrayKey::from("resourceId"), Value::from(ids[i % 10].as_str()));
                    let _ = acc.collect("42", "storage", i as i64, Kind::Gauge, tags, None, false);
                }
                black_box(acc.count());
            })
        }
        "extract_columns" => {
            let tags = array(
                &json!({"path": "/v1/storage/files", "method": "POST", "status": "201", "service": "storage", "country": "US", "region": "EU-West", "resourceId": "bucket1", "osName": "iOS"}),
            )?;
            Bench::sync(move || {
                black_box(Metric::extract_columns(black_box(&tags), Kind::Event).ok());
            })
        }
        "sample_payload_hash" => {
            let s = sample(&serde_json::from_str(BENCH_SAMPLE).map_err(|e| Fault::new(e.to_string()))?)?
                .map_err(|e| Fault::new(e.to_string()))?;
            Bench::sync(move || {
                black_box(s.payload_hash());
            })
        }
        "clickhouse_add_batch" => {
            let usage = Rc::new(bench_usage(session, "inserts").await?);
            let mut tags = Array::default();
            tags.set(ArrayKey::from("service"), Value::from("storage"));
            tags.set(ArrayKey::from("path"), Value::from("/v1/storage/files"));
            let metrics: Rc<Vec<Entry>> =
                Rc::new((0..100).map(|_| Entry::new("1", "requests", 1).with_tags(tags.clone())).collect());
            Bench::future(move || {
                let usage = usage.clone();
                let metrics = metrics.clone();
                async move {
                    black_box(usage.add_batch(&metrics, Kind::Event, 1000).await.ok());
                }
            })
        }
        "clickhouse_aggregate" => {
            let usage = Rc::new(bench_usage(session, "aggregates").await?);
            let qs = Rc::new(queries(&json!({"queries": [
                {"method": "groupByTimeBucket", "attribute": "time", "values": ["1h"]},
                {"method": "groupBy", "attribute": "service", "values": []},
                {"method": "equal", "attribute": "metric", "values": ["requests"]},
                {"method": "greaterThanEqual", "attribute": "time", "values": ["2025-01-01 00:00:00"]},
                {"method": "lessThan", "attribute": "time", "values": ["2025-01-02 00:00:00"]},
            ]}))?);
            Bench::future(move || {
                let usage = usage.clone();
                let qs = qs.clone();
                async move {
                    black_box(usage.find("1", &qs, Some(Kind::Event)).await.ok());
                }
            })
        }
        "clickhouse_total_batch" => {
            let usage = Rc::new(bench_usage(session, "totals").await?);
            let qs = Rc::new(queries(&json!({"queries": [
                {"method": "greaterThanEqual", "attribute": "time", "values": ["2025-01-01 00:00:00"]},
                {"method": "lessThan", "attribute": "time", "values": ["2025-01-02 00:00:00"]},
            ]}))?);
            let names: Rc<Vec<String>> = Rc::new(["requests", "bandwidth", "executions"].map(String::from).to_vec());
            Bench::future(move || {
                let usage = usage.clone();
                let qs = qs.clone();
                let names = names.clone();
                async move {
                    black_box(usage.total_batch("1", &names, &qs, Some(Kind::Event)).await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("usage: no benchmark `{name}`"))),
    })
}
