//! `Utopia\Usage\Engine` over [`utopia_usage`]'s ClickHouse adapter: what
//! `Utopia\Usage\Adapter\Extension` (plain PHP, in `packages/usage`) calls in place of
//! the PHP adapter's own SQL building and response parsing.
//!
//! HTTP stays in PHP. The engine is given a callable and calls it for every request,
//! so requests go through the client Appwrite already pools on Swoole's event loop:
//! the callable suspends the coroutine, and this frame with it, and no worker blocks.
//! For that to be sound, every method takes `&self` and nothing is locked or borrowed
//! across a request.

use std::future::Future;
use std::pin::pin;
use std::sync::Arc;
use std::task::{Context, Poll, Waker};

use ext_php_rs::exception::PhpException;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ArrayKey as ZendKey, ZendCallable, ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::{ClassEntry, ce};
use php_std::ArrayKey;
use php_std::types::KeyRef;
use utopia_query::{Array, Query, Value};
use utopia_usage::adapter::clickhouse::{ClickHouse, Config, Request, Response, Transport};
use utopia_usage::{Adapter, Entry, Error, Kind, Metric, Time, TimeSeries, Totals};

/// `error` as the exception PHP throws for it.
fn thrown(error: Error) -> PhpException {
    let class = ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception);
    PhpException::new(error.message(), 0, class)
}

fn exception(message: impl Into<String>) -> PhpException {
    PhpException::new(message.into(), 0, ce::exception())
}

/// A PHP string of `bytes`.
fn string(bytes: &[u8]) -> Zval {
    let mut zval = Zval::new();
    zval.set_zend_string(ZendStr::new(bytes, false));
    zval
}

/// A PHP array holding `table`.
fn array(table: ext_php_rs::boxed::ZBox<ZendHashTable>) -> Zval {
    let mut zval = Zval::new();
    zval.set_hashtable(table);
    zval
}

/// `value` as PHP holds it.
fn to_zval(value: &Value) -> PhpResult<Zval> {
    let mut zval = Zval::new();
    match value {
        Value::Bool(b) => zval.set_bool(*b),
        Value::Int(i) => zval.set_long(*i),
        Value::Float(f) => zval.set_double(*f),
        Value::Str(s) => return Ok(string(s.as_bytes())),
        Value::Array(a) | Value::Object(a) => return to_array(a),
        Value::Null | Value::Ext(_) => zval.set_null(),
    }
    Ok(zval)
}

/// `values` as a PHP array, keys and order kept.
fn to_array(values: &Array) -> PhpResult<Zval> {
    let mut table = ZendHashTable::new();
    for (key, value) in values.iter() {
        let value = to_zval(value)?;
        match key {
            KeyRef::Int(index) => table.insert_at_index(index, value)?,
            KeyRef::Str(name) => table.insert(String::from_utf8_lossy(name).as_ref(), value)?,
        }
    }
    Ok(array(table))
}

/// A PHP value as the crate holds one. Objects and resources have no place in a
/// metric's tags and read as `null`, which is what `json_encode` makes of most.
fn from_zval(zval: &Zval) -> Value {
    if let Some(b) = zval.bool() {
        Value::Bool(b)
    } else if let Some(i) = zval.long() {
        Value::Int(i)
    } else if let Some(f) = zval.double() {
        Value::Float(f)
    } else if let Some(s) = zval.zend_str() {
        Value::Str(s.as_bytes().into())
    } else if let Some(table) = zval.array() {
        Value::Array(from_table(table))
    } else {
        Value::Null
    }
}

fn from_table(table: &ZendHashTable) -> Array {
    let mut out = Array::default();
    for (key, value) in table {
        let key = match key {
            ZendKey::Long(index) => ArrayKey::from(index),
            ZendKey::String(name) => ArrayKey::from(name.as_str()),
            ZendKey::Str(name) => ArrayKey::from(name),
            ZendKey::ZendString(name) => ArrayKey::Str(name.as_bytes().into()),
        };
        out.set(key, from_zval(value));
    }
    out
}

/// What `gettype()` calls `zval`'s type.
fn gettype(zval: &Zval) -> &'static str {
    match zval.get_type() {
        DataType::Null | DataType::Undef => "NULL",
        DataType::False | DataType::True | DataType::Bool => "boolean",
        DataType::Long => "integer",
        DataType::Double => "double",
        DataType::String => "string",
        DataType::Array => "array",
        DataType::Resource => "resource",
        _ => "object",
    }
}

/// `isset($row[$key])`: the value, unless it is missing or `null`.
fn set<'a>(row: &'a ZendHashTable, key: &str) -> Option<&'a Zval> {
    row.get(key).filter(|value| !value.is_null())
}

/// The rows `addBatch()` was given, refused with the PHP adapter's own messages
/// where a row is not what it documents.
fn entries(metrics: &ZendHashTable) -> PhpResult<Vec<Entry>> {
    let mut out = Vec::with_capacity(metrics.len());
    for (index, row) in metrics {
        let index = match index {
            ZendKey::Long(index) => index.to_string(),
            ZendKey::String(name) => name,
            ZendKey::Str(name) => name.to_owned(),
            ZendKey::ZendString(name) => String::from_utf8_lossy(name.as_bytes()).into_owned(),
        };
        let empty = ZendHashTable::new();
        let row = row.array().unwrap_or(&empty);
        let metric = set(row, "metric").ok_or_else(|| exception(format!("Metric #{index}: 'metric' is required")))?;
        let value = set(row, "value").ok_or_else(|| exception(format!("Metric #{index}: 'value' is required")))?;
        let Some(name) = metric.zend_str() else {
            return Err(exception(format!("Metric #{index}: 'metric' must be a string, got {}", gettype(metric))));
        };
        let Some(value) = value.long().filter(|_| value.get_type() == DataType::Long) else {
            return Err(exception(format!("Metric #{index}: 'value' must be an integer, got {}", gettype(value))));
        };
        let tenant = match row.get("tenant") {
            None => None,
            Some(tenant) if tenant.is_null() => None,
            Some(tenant) => match tenant.zend_str() {
                Some(tenant) => Some(String::from_utf8_lossy(tenant.as_bytes()).into_owned()),
                None => {
                    return Err(exception(format!(
                        "Metric #{index}: 'tenant' must be a string or null, got {}",
                        gettype(tenant)
                    )));
                }
            },
        };
        out.push(Entry {
            tenant,
            metric: String::from_utf8_lossy(name.as_bytes()).into_owned(),
            value,
            tags: set(row, "tags").and_then(Zval::array).map(from_table).unwrap_or_default(),
            time: set(row, "time").and_then(time),
            allow_negative: row.get("allowNegative").is_some_and(truthy),
        });
    }
    Ok(out)
}

/// `(bool) $zval`, for the scalars a flag is given as.
fn truthy(zval: &Zval) -> bool {
    match from_zval(zval) {
        Value::Bool(b) => b,
        Value::Int(i) => i != 0,
        Value::Float(f) => f != 0.0,
        Value::Str(s) => !matches!(s.as_bytes(), b"" | b"0"),
        Value::Array(a) => !a.is_empty(),
        _ => false,
    }
}

/// A row's `time`: a string, or a `DateTime` as it formats itself (in its own time
/// zone, as the PHP adapter writes it). Anything else is "now", as in PHP.
fn time(zval: &Zval) -> Option<Time> {
    if let Some(text) = zval.zend_str() {
        return Some(Time::Text(String::from_utf8_lossy(text.as_bytes()).into_owned()));
    }
    let object = zval.object()?;
    if !ClassEntry::try_find("DateTime").is_some_and(|class| object.instance_of(class)) {
        return None;
    }
    let formatted = object.try_call_method("format", vec![&"Y-m-d H:i:s.v"]).ok()?;
    formatted.zend_str().map(|text| Time::Text(String::from_utf8_lossy(text.as_bytes()).into_owned()))
}

/// Sends the adapter's requests through a PHP callable:
/// `fn (string $url, array $headers, string $body): array{int, string}`, which answers
/// with the status and body, or with a negative status and the reason it failed.
struct Callback(Zval);

// SAFETY: a PHP value never leaves the thread that made it. The engine object holding
// this is itself a PHP object, reachable only from that thread, and the future that
// borrows it is driven to completion on the same thread by `block_on` below.
unsafe impl Send for Callback {}
// SAFETY: as above; no other thread ever holds a reference to it.
unsafe impl Sync for Callback {}

#[async_trait::async_trait]
impl Transport for Callback {
    async fn send(&self, request: Request) -> Result<Response, String> {
        let mut headers = ZendHashTable::new();
        for (name, value) in &request.headers {
            headers.insert(name.as_str(), string(value.as_bytes())).map_err(|e| e.to_string())?;
        }
        let (url, headers, body) = (string(request.url.as_bytes()), array(headers), string(&request.body));
        let callable = ZendCallable::new(&self.0).map_err(|e| e.to_string())?;
        let answer = callable.try_call(vec![&url, &headers, &body]).map_err(|e| e.to_string())?;
        let answer = answer.array().ok_or("the transport did not answer with [status, body]")?;
        let status = answer.get_index(0).and_then(Zval::long).ok_or("the transport answered without a status")?;
        let body = answer.get_index(1).and_then(Zval::zend_str).map(|body| body.as_bytes().to_vec()).unwrap_or_default();
        match u16::try_from(status) {
            Ok(status) => Ok(Response { status, body }),
            Err(_) => Err(String::from_utf8_lossy(&body).into_owned()),
        }
    }
}

/// Runs `future`, whose only wait is the transport's, and that one happens inside the
/// PHP call: the future is ready the first time it is polled.
fn block_on<T>(future: impl Future<Output = T>) -> PhpResult<T> {
    match pin!(future).poll(&mut Context::from_waker(Waker::noop())) {
        Poll::Ready(value) => Ok(value),
        Poll::Pending => Err(exception("The usage engine waited on something other than its transport")),
    }
}

/// `Usage::TYPE_EVENT` or `Usage::TYPE_GAUGE`, or `null` for either.
fn kind(name: Option<&str>) -> PhpResult<Option<Kind>> {
    name.map(Kind::parse).transpose().map_err(thrown)
}

/// Queries as `Query::toString()` wrote them.
fn queries(encoded: &[String]) -> PhpResult<Vec<Query>> {
    Query::parse_queries(encoded, true).map_err(|error| thrown(Error::Query(error)))
}

/// Metrics as the arrays `new Metric(...)` takes.
fn metrics(found: &[Metric]) -> PhpResult<Zval> {
    let mut table = ZendHashTable::new();
    for metric in found {
        table.push(to_array(metric.attributes())?)?;
    }
    Ok(array(table))
}

/// Totals keyed by metric name.
fn totals(found: &Totals) -> PhpResult<Zval> {
    let mut table = ZendHashTable::new();
    for (name, total) in found {
        table.insert(name.as_str(), *total)?;
    }
    Ok(array(table))
}

/// `[metric => ['total' => ..., 'data' => [['value' => ..., 'date' => ...], ...]]]`.
fn series(found: &TimeSeries) -> PhpResult<Zval> {
    let mut table = ZendHashTable::new();
    for (name, series) in found {
        let mut data = ZendHashTable::new();
        for point in &series.data {
            let mut entry = ZendHashTable::new();
            entry.insert("value", point.value)?;
            entry.insert("date", point.date.as_str())?;
            data.push(array(entry))?;
        }
        let mut entry = ZendHashTable::new();
        entry.insert("total", to_zval(&series.total.to_value())?)?;
        entry.insert("data", array(data))?;
        table.insert(name.as_str(), array(entry))?;
    }
    Ok(array(table))
}

/// `Utopia\Usage\Engine`: the ClickHouse adapter's statements and response handling.
/// `Utopia\Usage\Adapter\Extension` converts to and from the package's own types.
#[php_class]
#[php(name = "Utopia\\Usage\\Engine")]
pub struct Engine {
    adapter: Arc<ClickHouse<Callback>>,
}

#[php_impl]
impl Engine {
    /// `$config` holds the PHP adapter's constructor arguments by name; `$send` is
    /// the transport (see [`Callback`]).
    pub fn __construct(config: &ZendHashTable, send: &Zval) -> PhpResult<Self> {
        if !send.is_callable() {
            return Err(exception("The usage engine needs a callable transport"));
        }
        let text = |key: &str| config.get(key).and_then(Zval::string);
        let flag = |key: &str| config.get(key).is_some_and(truthy);
        let host = text("host").ok_or_else(|| exception("The usage engine needs a host"))?;
        let mut settings = Config::new(&host);
        if let Some(username) = text("username") {
            settings.username = username;
        }
        if let Some(password) = text("password") {
            settings.password = password;
        }
        if let Some(port) = config.get("port").and_then(Zval::long) {
            settings.port = port;
        }
        if let Some(namespace) = text("namespace") {
            settings.namespace = namespace;
        }
        if let Some(database) = text("database") {
            settings.database = database;
        }
        settings.secure = flag("secure");
        settings.shared_tables = flag("sharedTables");
        settings.async_inserts = flag("asyncInserts");
        settings.async_insert_wait = config.get("asyncInsertWait").is_none_or(truthy);
        settings.dual_read_sample_rate = config.get("dualReadSampleRate").and_then(Zval::double).unwrap_or(0.0);
        settings.retention = config.get("retention").and_then(Zval::long);
        let adapter = ClickHouse::with_transport(settings, Callback(send.shallow_clone())).map_err(thrown)?;
        Ok(Self { adapter: Arc::new(adapter) })
    }

    pub fn set_next_query_id(&self, query_id: Option<String>) {
        self.adapter.set_next_query_id(query_id);
    }

    pub fn get_route_log(&self) -> PhpResult<Zval> {
        let text = |value: &Option<String>| value.as_deref().map_or_else(Zval::new, |text| string(text.as_bytes()));
        let mut log = ZendHashTable::new();
        for entry in self.adapter.route_log() {
            let mut dimensions = ZendHashTable::new();
            for dimension in &entry.dimensions {
                dimensions.push(dimension.as_str())?;
            }
            let mut row = ZendHashTable::new();
            row.insert("operation", entry.operation.as_str())?;
            row.insert("metric", text(&entry.metric))?;
            row.insert("route", entry.route.as_str())?;
            row.insert("start", text(&entry.start))?;
            row.insert("end", text(&entry.end))?;
            row.insert("dimensions", array(dimensions))?;
            row.insert("interval", text(&entry.interval))?;
            log.push(array(row))?;
        }
        Ok(array(log))
    }

    pub fn clear_route_log(&self) {
        self.adapter.clear_route_log();
    }

    pub fn get_request_count(&self) -> i64 {
        self.adapter.connection_stats().request_count
    }

    pub fn health_check(&self) -> PhpResult<Zval> {
        to_array(&block_on(self.adapter.health_check())?)
    }

    pub fn setup(&self) -> PhpResult<()> {
        block_on(self.adapter.setup())?.map_err(thrown)
    }

    pub fn migrate(&self) -> PhpResult<()> {
        block_on(self.adapter.migrate())?.map_err(thrown)
    }

    pub fn backfill_daily(&self, from: &str, to: &str, force: bool) -> PhpResult<()> {
        block_on(self.adapter.backfill_daily(from, to, force))?.map_err(thrown)
    }

    pub fn add_batch(&self, metrics: &ZendHashTable, r#type: &str, batch_size: i64) -> PhpResult<bool> {
        if metrics.is_empty() {
            return Ok(true);
        }
        let entries = entries(metrics)?;
        // The PHP adapter checks every row before it looks at the type.
        let kind = Kind::parse(r#type).map_err(|error| match entries.first() {
            Some(_) => PhpException::new(
                format!("Metric #0: {}", error.message()),
                0,
                ClassEntry::try_find(error.php_class()).unwrap_or_else(ce::exception),
            ),
            None => thrown(error),
        })?;
        block_on(self.adapter.add_batch(&entries, kind, batch_size))?.map_err(thrown)
    }

    #[expect(clippy::too_many_arguments, reason = "the PHP method takes these eight")]
    pub fn get_time_series(
        &self,
        tenant: &str,
        metrics: Vec<String>,
        interval: &str,
        start_date: &str,
        end_date: &str,
        queries: Vec<String>,
        zero_fill: bool,
        r#type: Option<&str>,
    ) -> PhpResult<Zval> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        let found = block_on(self.adapter.time_series(
            tenant, &metrics, interval, start_date, end_date, &queries, zero_fill, kind,
        ))?;
        series(&found.map_err(thrown)?)
    }

    pub fn get_total(&self, tenant: &str, metric: &str, queries: Vec<String>, r#type: Option<&str>) -> PhpResult<i64> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        block_on(self.adapter.total(tenant, metric, &queries, kind))?.map_err(thrown)
    }

    pub fn get_total_batch(
        &self,
        tenant: &str,
        metrics: Vec<String>,
        queries: Vec<String>,
        r#type: Option<&str>,
    ) -> PhpResult<Zval> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        totals(&block_on(self.adapter.total_batch(tenant, &metrics, &queries, kind))?.map_err(thrown)?)
    }

    pub fn purge(&self, tenant: &str, queries: Vec<String>, r#type: Option<&str>) -> PhpResult<bool> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        block_on(self.adapter.purge(tenant, &queries, kind))?.map_err(thrown)
    }

    pub fn find(&self, tenant: &str, queries: Vec<String>, r#type: Option<&str>) -> PhpResult<Zval> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        metrics(&block_on(self.adapter.find(tenant, &queries, kind))?.map_err(thrown)?)
    }

    pub fn find_across_tenants(&self, queries: Vec<String>, r#type: Option<&str>) -> PhpResult<Zval> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        metrics(&block_on(self.adapter.find_across_tenants(&queries, kind))?.map_err(thrown)?)
    }

    pub fn count(&self, tenant: &str, queries: Vec<String>, r#type: Option<&str>, max: Option<i64>) -> PhpResult<i64> {
        let (queries, kind) = (self::queries(&queries)?, kind(r#type)?);
        block_on(self.adapter.count(tenant, &queries, kind, max))?.map_err(thrown)
    }

    pub fn sum(&self, tenant: &str, queries: Vec<String>, attribute: &str, r#type: &str) -> PhpResult<i64> {
        let (queries, kind) = (self::queries(&queries)?, Kind::parse(r#type).map_err(thrown)?);
        block_on(self.adapter.sum(tenant, &queries, attribute, kind))?.map_err(thrown)
    }

    pub fn find_daily(&self, tenant: &str, queries: Vec<String>) -> PhpResult<Zval> {
        let queries = self::queries(&queries)?;
        metrics(&block_on(self.adapter.find_daily(tenant, &queries))?.map_err(thrown)?)
    }

    pub fn sum_daily(&self, tenant: &str, queries: Vec<String>, attribute: &str) -> PhpResult<i64> {
        let queries = self::queries(&queries)?;
        block_on(self.adapter.sum_daily(tenant, &queries, attribute))?.map_err(thrown)
    }

    pub fn sum_daily_batch(&self, tenant: &str, metrics: Vec<String>, queries: Vec<String>) -> PhpResult<Zval> {
        let queries = self::queries(&queries)?;
        totals(&block_on(self.adapter.sum_daily_batch(tenant, &metrics, &queries))?.map_err(thrown)?)
    }
}
