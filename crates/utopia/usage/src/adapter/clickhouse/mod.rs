//! The ClickHouse adapter: events, gauges, a daily rollup of events and
//! canonical samples in ClickHouse tables, over its HTTP interface.
//!
//! PHP: `Utopia\Usage\Adapter\ClickHouse`.
//!
//! Tables (`<namespace>_usage_*`, or `usage_*` without a namespace):
//!
//! | Table | Holds |
//! |---|---|
//! | `_events` (MergeTree) | raw events with their dimension columns, projections per dimension on an hourly bucket |
//! | `_events_daily` (SummingMergeTree) and `_events_daily_mv` | events summed per day, metric and resource |
//! | `_gauges` (MergeTree) | gauge samples, windowed and latest-value projections |
//! | `_samples` (MergeTree) | canonical samples |
//!
//! Reads pick the cheapest source: closed days from the rollup, partial
//! hours and days from the raw table, grouped reads through projections the
//! server matches; [`ClickHouse::route_log`] records each decision.

mod read;
mod route;
mod samples;
mod setup;

use std::collections::VecDeque;
use std::sync::Mutex;
use std::sync::atomic::{AtomicI64, Ordering};

use async_trait::async_trait;
use php_std::ArrayKey;
use php_std::json::{self, Flags};
use utopia_client::{Client, Http, Request};
use utopia_query::builder::ClickHouse as Builder;
use utopia_query::{Array, Object, Query, Value};

use super::{Adapter, Entry, TimeSeries, Totals};
use crate::error::{Error, Message, Result};
use crate::metric::{Kind, Metric};
use crate::php;
use crate::sample::{Sample, SampleRange, SampleResult, SampleWatermark};

const DEFAULT_PORT: i64 = 8123;
const DEFAULT_DATABASE: &str = "default";
const TABLE: &str = "usage";
const INSERT_BATCH_SIZE: i64 = 1_000;
const SAMPLE_BATCH_SIZE: i64 = 1_000;
const ROUTE_LOG_MAX: usize = 1_000;

/// How to reach ClickHouse and lay out the tables.
///
/// PHP: the arguments of `new ClickHouse(...)`.
#[derive(Debug, Clone)]
pub struct Config {
    /// The host name or IP address.
    pub host: String,
    /// The user (`default`).
    pub username: String,
    /// The password (empty).
    pub password: String,
    /// The HTTP port (8123).
    pub port: i64,
    /// Whether to use HTTPS.
    pub secure: bool,
    /// A table name prefix (empty for none).
    pub namespace: String,
    /// The database (`default`).
    pub database: String,
    /// Whether tables are shared across tenants (a `tenant` column).
    pub shared_tables: bool,
    /// Whether to use ClickHouse's async inserts.
    pub async_inserts: bool,
    /// Whether an async insert waits for confirmation.
    pub async_insert_wait: bool,
    /// The share of routed reads re-run on the raw table to check the
    /// rollup (clamped to `0.0..=1.0`).
    pub dual_read_sample_rate: f64,
    /// Days events and the daily rollup are kept; `None` keeps them.
    pub retention: Option<i64>,
}

impl Config {
    /// The defaults for `host`.
    pub fn new(host: impl Into<String>) -> Config {
        Config {
            host: host.into(),
            username: "default".into(),
            password: String::new(),
            port: DEFAULT_PORT,
            secure: false,
            namespace: String::new(),
            database: DEFAULT_DATABASE.into(),
            shared_tables: false,
            async_inserts: false,
            async_insert_wait: true,
            dual_read_sample_rate: 0.0,
            retention: None,
        }
    }
}

/// One routing decision.
///
/// PHP: an entry of `getRouteLog()`.
#[derive(Debug, Clone, PartialEq)]
pub struct RouteEntry {
    /// `sum`, `getTotal`, `getTotalBatch` or `dual_read_warning`.
    pub operation: String,
    /// The metric, when the read named exactly one.
    pub metric: Option<String>,
    /// `raw`, `daily`, `hybrid`, `split` (or `<route>:delta=<d>` for a warning).
    pub route: String,
    /// The window's lower bound, as given.
    pub start: Option<String>,
    /// The window's upper bound, as given.
    pub end: Option<String>,
    /// The grouped dimensions.
    pub dimensions: Vec<String>,
    /// The bucket interval.
    pub interval: Option<String>,
}

/// Request statistics.
///
/// PHP: `getConnectionStats()`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Stats {
    /// Requests sent so far.
    pub request_count: i64,
    /// Whether async inserts are on.
    pub async_inserts: bool,
    /// Whether async inserts wait for confirmation.
    pub async_insert_wait: bool,
}

/// Named parameters of a statement, in order.
type Params = Vec<(String, Value)>;

/// Usage metrics in ClickHouse, through a `utopia-client` transport.
///
/// PHP: `Utopia\Usage\Adapter\ClickHouse`.
pub struct ClickHouse<A: utopia_client::Adapter = Http> {
    config: Config,
    table: String,
    client: Client<A>,
    request_count: AtomicI64,
    context: Mutex<Option<&'static str>>,
    next_query_id: Mutex<Option<String>>,
    route_log: Mutex<VecDeque<RouteEntry>>,
    param_types: [Vec<(String, String)>; 2],
}

impl<A: utopia_client::Adapter> std::fmt::Debug for ClickHouse<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ClickHouse")
            .field("host", &self.config.host)
            .field("port", &self.config.port)
            .field("database", &self.config.database)
            .finish_non_exhaustive()
    }
}

/// `empty($s)` for a string.
fn php_empty(s: &str) -> bool {
    s.is_empty() || s == "0"
}

fn validate_identifier(identifier: &str, kind: &str) -> Result<()> {
    if php_empty(identifier) {
        return Err(Error::exception(format!("{kind} cannot be empty")));
    }
    if identifier.len() > 255 {
        return Err(Error::exception(format!("{kind} cannot exceed 255 characters")));
    }
    // `/^[a-zA-Z_][a-zA-Z0-9_]*$/`: `$` also matches before a final newline.
    let body = identifier.strip_suffix('\n').unwrap_or(identifier);
    let bytes = body.as_bytes();
    let valid = !bytes.is_empty()
        && (bytes[0].is_ascii_alphabetic() || bytes[0] == b'_')
        && bytes.iter().all(|b| b.is_ascii_alphanumeric() || *b == b'_');
    if !valid {
        return Err(Error::exception(format!(
            "{kind} must start with a letter or underscore and contain only alphanumeric characters and underscores"
        )));
    }
    const KEYWORDS: &[&str] = &["SELECT", "INSERT", "UPDATE", "DELETE", "DROP", "CREATE", "ALTER", "TABLE", "DATABASE"];
    if KEYWORDS.contains(&identifier.to_ascii_uppercase().as_str()) {
        return Err(Error::exception(format!("{kind} cannot be a reserved SQL keyword")));
    }
    Ok(())
}

/// `` `name` `` with backticks doubled.
fn escape(identifier: &str) -> String {
    format!("`{}`", identifier.replace('`', "``"))
}

/// The ClickHouse type a value of `attribute` binds as.
fn param_type(attribute: &str) -> &'static str {
    match attribute {
        "time" => "DateTime64(3, 'UTC')",
        "value" => "Int64",
        _ => "String",
    }
}

/// `formatParamValue()`: a value as ClickHouse's HTTP parameter text.
fn format_param(value: &Value) -> Vec<u8> {
    match value {
        Value::Int(_) | Value::Float(_) => value.to_php_string().map(|s| s.as_bytes().to_vec()).unwrap_or_default(),
        Value::Null => Vec::new(),
        Value::Bool(b) => {
            if *b {
                b"1".to_vec()
            } else {
                b"0".to_vec()
            }
        }
        Value::Array(_) => {
            json::encode(value, Flags::NONE, json::DEFAULT_DEPTH).map(String::into_bytes).unwrap_or_default()
        }
        Value::Str(s) => s.as_bytes().to_vec(),
        Value::Object(_) | Value::Ext(_) => Vec::new(),
    }
}

/// `preg_replace('/\s+/', ' ', $s)` (PCRE `\s`: space, `\t`, `\n`, `\v`, `\f`, `\r`).
fn collapse_whitespace(s: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len());
    let mut in_space = false;
    for &b in s {
        if matches!(b, b' ' | b'\t' | b'\n' | 0x0B | 0x0C | b'\r') {
            if !in_space {
                out.push(b' ');
            }
            in_space = true;
        } else {
            out.push(b);
            in_space = false;
        }
    }
    out
}

/// Renames named placeholders `{key:Type}` to `{<prefix>key:Type}`.
fn prefix_bindings(sql: &str, bindings: Params, prefix: &str) -> (String, Params) {
    let mut sql = sql.to_owned();
    let mut renamed = Vec::with_capacity(bindings.len());
    for (key, value) in bindings {
        let new_key = format!("{prefix}{key}");
        let needle = format!("{{{key}:");
        let mut out = String::with_capacity(sql.len() + 8);
        let mut rest = sql.as_str();
        while let Some(at) = rest.find(&needle) {
            let after = &rest[at + needle.len()..];
            // `(:[^}]+)\}`: at least one character before the closing brace.
            match after.find('}') {
                Some(close) if close > 0 => {
                    out.push_str(&rest[..at]);
                    out.push('{');
                    out.push_str(&new_key);
                    out.push(':');
                    out.push_str(&after[..=close]);
                    rest = &after[close + 1..];
                }
                _ => {
                    out.push_str(&rest[..at + needle.len()]);
                    rest = after;
                }
            }
        }
        out.push_str(rest);
        sql = out;
        renamed.push((new_key, value));
    }
    (sql, renamed)
}

fn named(bindings: Option<Array>) -> Params {
    bindings
        .map(|b| {
            b.iter()
                .map(|(k, v)| {
                    let key = match k {
                        php_std::types::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                        php_std::types::KeyRef::Int(i) => i.to_string(),
                    };
                    (key, v.clone())
                })
                .collect()
        })
        .unwrap_or_default()
}

/// Rows of a `FORMAT JSON` body (`data`), empty when it is not that envelope.
fn decode_rows(body: &str) -> Vec<Array> {
    let Ok(Value::Array(json)) =
        json::decode_ext::<Object>(body.as_bytes(), Some(true), json::DEFAULT_DEPTH, Flags::NONE)
    else {
        return Vec::new();
    };
    let Some(Value::Array(data)) = json.get(&ArrayKey::from("data")) else {
        return Vec::new();
    };
    data.values()
        .filter_map(|row| match row {
            Value::Array(row) => Some(row.clone()),
            _ => None,
        })
        .collect()
}

fn field<'a>(row: &'a Array, key: &str) -> &'a Value {
    static NULL: Value = Value::Null;
    row.get(&ArrayKey::from(key)).unwrap_or(&NULL)
}

/// `data[0].total` of a `FORMAT JSON` body, 0 when absent.
fn decode_total(body: &str) -> i64 {
    let rows = decode_rows(body);
    match rows.first().and_then(|r| r.get(&ArrayKey::from("total"))) {
        Some(Value::Null) | None => 0,
        Some(v) => php::to_int(v),
    }
}

impl ClickHouse<Http> {
    /// The ClickHouse type of attribute `id` of `kind`'s table:
    /// `LowCardinality(Nullable(String))` for low-cardinality dimensions,
    /// `Nullable(...)` for optional attributes.
    ///
    /// PHP: the private `getColumnType()`.
    pub fn column_type(id: &str, kind: Kind) -> Result<String> {
        setup::column_type(id, kind)
    }

    /// An adapter over a fresh HTTP transport reusing its connections.
    ///
    /// PHP: `new ClickHouse($host, ...)` without a client.
    pub fn new(config: Config) -> Result<ClickHouse<Http>> {
        ClickHouse::with_client(config, Client::new(Http::new()).with_connection_reuse(true))
    }
}

impl<A: utopia_client::Adapter> ClickHouse<A> {
    /// An adapter sending through `client` (timeouts, TLS, retries and
    /// pooling are the client's; it must not retry inserts).
    ///
    /// PHP: `new ClickHouse($host, ..., client: $client)`.
    pub fn with_client(mut config: Config, client: Client<A>) -> Result<ClickHouse<A>> {
        if !utopia_validators::Hostname::default().matches(&config.host) {
            return Err(Error::exception("ClickHouse host is not a valid hostname or IP address"));
        }
        if !(1..=65535).contains(&config.port) {
            return Err(Error::exception("ClickHouse port must be between 1 and 65535"));
        }
        if config.retention.is_some_and(|r| r < 1) {
            return Err(Error::exception("Retention must be a positive number of days"));
        }
        if !php_empty(&config.namespace) {
            validate_identifier(&config.namespace, "Namespace")?;
        }
        validate_identifier(&config.database, "Database")?;
        config.dual_read_sample_rate = config.dual_read_sample_rate.clamp(0.0, 1.0);
        if config.dual_read_sample_rate.is_nan() {
            config.dual_read_sample_rate = 0.0;
        }
        let param_types = [Kind::Event, Kind::Gauge].map(|kind| {
            let mut map: Vec<(String, String)> =
                vec![("id".into(), "String".into()), ("metric".into(), "String".into())];
            for attribute in kind.schema() {
                map.retain(|(k, _)| k != attribute.id);
                map.push((attribute.id.to_owned(), param_type(attribute.id).to_owned()));
            }
            if config.shared_tables {
                map.push(("tenant".into(), "String".into()));
            }
            map
        });
        let table = TABLE.to_owned();
        Ok(ClickHouse {
            config,
            table,
            client,
            request_count: AtomicI64::new(0),
            context: Mutex::new(None),
            next_query_id: Mutex::new(None),
            route_log: Mutex::new(VecDeque::new()),
            param_types,
        })
    }

    /// The configuration.
    pub fn config(&self) -> &Config {
        &self.config
    }

    /// Sends `query_id` with the next query only.
    ///
    /// PHP: `setNextQueryId($queryId)`.
    pub fn set_next_query_id(&self, query_id: Option<String>) -> &Self {
        *self.next_query_id.lock().unwrap_or_else(|e| e.into_inner()) = query_id;
        self
    }

    /// The routing decisions recorded so far (the last 1000).
    ///
    /// PHP: `getRouteLog()`.
    pub fn route_log(&self) -> Vec<RouteEntry> {
        self.route_log.lock().unwrap_or_else(|e| e.into_inner()).iter().cloned().collect()
    }

    /// Forgets the routing decisions.
    ///
    /// PHP: `clearRouteLog()`.
    pub fn clear_route_log(&self) -> &Self {
        self.route_log.lock().unwrap_or_else(|e| e.into_inner()).clear();
        self
    }

    /// Request statistics.
    ///
    /// PHP: `getConnectionStats()`.
    pub fn connection_stats(&self) -> Stats {
        Stats {
            request_count: self.request_count.load(Ordering::Relaxed),
            async_inserts: self.config.async_inserts,
            async_insert_wait: self.config.async_insert_wait,
        }
    }

    fn set_context(&self, context: &'static str) {
        *self.context.lock().unwrap_or_else(|e| e.into_inner()) = Some(context);
    }

    /// `<namespace>_usage`, or `usage`.
    fn table_name(&self) -> String {
        if php_empty(&self.config.namespace) {
            self.table.clone()
        } else {
            format!("{}_{}", self.config.namespace, self.table)
        }
    }

    fn events_table(&self) -> String {
        format!("{}_events", self.table_name())
    }

    fn gauges_table(&self) -> String {
        format!("{}_gauges", self.table_name())
    }

    fn daily_table(&self) -> String {
        format!("{}_events_daily", self.table_name())
    }

    fn samples_table(&self) -> String {
        format!("{}_samples", self.table_name())
    }

    fn table_for(&self, kind: Kind) -> String {
        match kind {
            Kind::Gauge => self.gauges_table(),
            Kind::Event => self.events_table(),
        }
    }

    /// `` `database`.`table` ``.
    fn reference(&self, table: &str) -> String {
        format!("{}.{}", escape(&self.config.database), escape(table))
    }

    /// Rewrites bare `` `table` `` references to `` `database`.`table` ``.
    fn qualify(&self, sql: &str, tables: &[&str]) -> String {
        let mut sql = sql.to_owned();
        for table in tables {
            sql = sql.replace(&escape(table), &self.reference(table));
        }
        sql
    }

    fn builder(&self, kind: Kind) -> Builder {
        let mut builder = Builder::new();
        builder.use_named_bindings(true);
        let types = &self.param_types[usize::from(kind == Kind::Gauge)];
        if let Err(e) = builder.with_param_types(types.iter().map(|(k, v)| (k.clone(), v.as_str()))) {
            unreachable!("the parameter types are valid ClickHouse types: {e}");
        }
        builder
    }

    fn error_message(&self, base: &[u8], table: Option<&str>, sql: Option<&[u8]>) -> Message {
        let mut parts: Vec<Vec<u8>> = Vec::new();
        if let Some(context) = *self.context.lock().unwrap_or_else(|e| e.into_inner()) {
            parts.push(format!("Operation: {context}").into_bytes());
        }
        if let Some(table) = table {
            parts.push(format!("Table: {table}").into_bytes());
        }
        if let Some(sql) = sql {
            let truncated = if sql.len() > 200 { [&sql[..200], b"..."].concat() } else { sql.to_vec() };
            let mut part = b"Query: ".to_vec();
            part.extend_from_slice(&collapse_whitespace(&truncated));
            parts.push(part);
        }
        let mut message = base.to_vec();
        if !parts.is_empty() {
            message.extend_from_slice(b" [");
            message.extend_from_slice(&parts.join(&b", "[..]));
            message.push(b']');
        }
        Message::from(message)
    }

    fn headers(&self, request: Request) -> std::result::Result<Request, utopia_client::Error> {
        request
            .header("X-ClickHouse-User", &self.config.username)?
            .header("X-ClickHouse-Key", &self.config.password)?
            .header("X-ClickHouse-Database", &self.config.database)
    }

    fn base_url(&self) -> String {
        format!("{}://{}:{}/", if self.config.secure { "https" } else { "http" }, self.config.host, self.config.port)
    }

    /// Runs `sql` with named `params` (sent in a multipart body); the
    /// response body.
    ///
    /// PHP: the private `query()`.
    pub async fn query(&self, sql: &str, params: &[(String, Value)]) -> Result<String> {
        let query_id = self.next_query_id.lock().unwrap_or_else(|e| e.into_inner()).take();
        let mut url = self.base_url();
        if let Some(id) = query_id {
            url.push('?');
            url.push_str(&build_query(&[("query_id", id.as_str())]));
        }

        let boundary = format!("utopia-{}", php::random_hex(16));
        let mut body = Vec::with_capacity(sql.len() + 128 + params.len() * 64);
        let mut part = |name: &str, value: &[u8]| {
            body.extend_from_slice(b"--");
            body.extend_from_slice(boundary.as_bytes());
            body.extend_from_slice(b"\r\nContent-Disposition: form-data; name=\"");
            body.extend_from_slice(&php_std::string::addcslashes(name.as_bytes(), b"\\\""));
            body.extend_from_slice(b"\"\r\n\r\n");
            body.extend_from_slice(value);
            body.extend_from_slice(b"\r\n");
        };
        part("query", sql.as_bytes());
        for (key, value) in params {
            part(&format!("param_{key}"), &format_param(value));
        }
        body.extend_from_slice(b"--");
        body.extend_from_slice(boundary.as_bytes());
        body.extend_from_slice(b"--\r\n");

        self.request_count.fetch_add(1, Ordering::Relaxed);

        let request = Request::new("POST", &url)
            .and_then(|r| self.headers(r))
            .and_then(|r| r.header("Content-Type", &format!("multipart/form-data; boundary={boundary}")))
            .map(|r| r.body(body));
        let response = match request {
            Ok(request) => self.client.send(request).await,
            Err(e) => Err(e),
        };
        let response = match response {
            Ok(response) => response,
            Err(e) => {
                let base = format!("ClickHouse query failed: {e}");
                return Err(Error::Exception(self.error_message(base.as_bytes(), None, Some(sql.as_bytes()))));
            }
        };
        let text = String::from_utf8_lossy(response.bytes()).into_owned();
        if response.status() != 200 {
            let mut base = format!("ClickHouse query failed with HTTP {}: ", response.status()).into_bytes();
            base.extend_from_slice(response.bytes());
            return Err(Error::Exception(self.error_message(&base, None, Some(sql.as_bytes()))));
        }
        Ok(text)
    }

    /// Sends a `FORMAT JSONEachRow` insert of `rows`.
    async fn insert(&self, table: &str, sql: &str, rows: &[String], durable: bool) -> Result<()> {
        if rows.is_empty() {
            return Ok(());
        }
        let wait = if durable || self.config.async_insert_wait { "1" } else { "0" };
        let mut pairs = vec![("query", sql)];
        if self.config.async_inserts {
            pairs.push(("async_insert", "1"));
            pairs.push(("wait_for_async_insert", wait));
        }
        let encoded = build_query(&pairs);
        let url = format!("{}?{encoded}", self.base_url());

        self.request_count.fetch_add(1, Ordering::Relaxed);
        let body = rows.join("\n");
        let described = format!("INSERT INTO {table} ({} rows)", rows.len());

        let request = Request::new("POST", &url)
            .and_then(|r| self.headers(r))
            .and_then(|r| r.header("Content-Type", "application/x-ndjson"))
            .map(|r| r.body(body));
        let response = match request {
            Ok(request) => self.client.send(request).await,
            Err(e) => Err(e),
        };
        let response = match response {
            Ok(response) => response,
            Err(e) => {
                let base = format!("ClickHouse insert failed: {e}");
                return Err(Error::Exception(self.error_message(
                    base.as_bytes(),
                    Some(table),
                    Some(described.as_bytes()),
                )));
            }
        };
        if response.status() != 200 {
            let mut base = format!("ClickHouse insert failed with HTTP {}: ", response.status()).into_bytes();
            base.extend_from_slice(response.bytes());
            return Err(Error::Exception(self.error_message(&base, Some(table), Some(described.as_bytes()))));
        }
        Ok(())
    }

    /// `formatDateTime()`: now, or a string through `new DateTime()`.
    fn format_time(&self, time: Option<&super::Time>) -> Result<String> {
        match time {
            None => Ok(php::format(&php::datetime(b"now")?, "Y-m-d H:i:s.v")),
            Some(super::Time::At(dt)) => Ok(php::format(dt, "Y-m-d H:i:s.v")),
            Some(super::Time::Text(text)) => format_text_time(text.as_bytes()),
        }
    }

    fn attribute_exists(&self, name: &str, kind: Kind) -> bool {
        name == "id" || (name == "tenant" && self.config.shared_tables) || kind.has_attribute(name)
    }

    fn validate_attribute(&self, name: &str, kind: Kind) -> Result<()> {
        if self.attribute_exists(name, kind) {
            return Ok(());
        }
        Err(Error::exception(format!("Invalid attribute name: {name}")))
    }

    /// Whether every filter attribute exists on the kind's table.
    fn queries_match(&self, queries: &[Query], kind: Kind) -> bool {
        queries.iter().all(|q| {
            let attribute = q.attribute();
            attribute.is_empty() || attribute == "id" || self.attribute_exists(attribute, kind)
        })
    }

    fn validate_batch(&self, metrics: &[Entry]) -> Result<()> {
        for (index, entry) in metrics.iter().enumerate() {
            let prefix = format!("Metric #{index}: ");
            if php_empty(&entry.metric) {
                return Err(Error::exception(format!("{prefix}Metric cannot be empty")));
            }
            if entry.metric.len() > 255 {
                return Err(Error::exception(format!("{prefix}Metric exceeds maximum size of 255 characters")));
            }
            if entry.value < 0 && !entry.allow_negative {
                return Err(Error::exception(format!("{prefix}Value cannot be negative")));
            }
            if self.config.shared_tables && entry.tenant.as_deref().is_none_or(str::is_empty) {
                return Err(Error::exception(format!(
                    "Metric #{index}: 'tenant' is required (non-empty string) when shared tables are enabled"
                )));
            }
        }
        Ok(())
    }

    fn insert_columns(&self, kind: Kind) -> Vec<&'static str> {
        let mut columns = vec!["id", "metric", "value", "time"];
        columns.extend_from_slice(kind.columns());
        if self.config.shared_tables {
            columns.push("tenant");
        }
        columns
    }

    fn record(&self, entry: RouteEntry) {
        let mut log = self.route_log.lock().unwrap_or_else(|e| e.into_inner());
        log.push_back(entry);
        while log.len() > ROUTE_LOG_MAX {
            log.pop_front();
        }
    }
}

/// `http_build_query($pairs)` (RFC 1738: spaces as `+`).
fn build_query(pairs: &[(&str, &str)]) -> String {
    let mut out = Vec::new();
    for (i, (k, v)) in pairs.iter().enumerate() {
        if i > 0 {
            out.push(b'&');
        }
        out.extend_from_slice(php_std::encoding::urlencode(k.as_bytes()).as_bytes());
        out.push(b'=');
        out.extend_from_slice(php_std::encoding::urlencode(v.as_bytes()).as_bytes());
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `formatDateTime()` of a string: `new DateTime($s)->format('Y-m-d H:i:s.v')`.
fn format_text_time(text: &[u8]) -> Result<String> {
    match php::datetime(text) {
        Ok(dt) => Ok(php::format(&dt, "Y-m-d H:i:s.v")),
        Err(_) => {
            let mut message = b"Invalid datetime string: ".to_vec();
            message.extend_from_slice(text);
            Err(Error::exception(message))
        }
    }
}

#[async_trait]
impl<A: utopia_client::Adapter + 'static> Adapter for ClickHouse<A> {
    fn name(&self) -> &str {
        "ClickHouse"
    }

    async fn health_check(&self) -> Array {
        self.health().await
    }

    async fn setup(&self) -> Result<()> {
        self.create().await
    }

    async fn migrate(&self) -> Result<()> {
        self.migrate_schema().await
    }

    async fn add_batch(&self, metrics: &[Entry], kind: Kind, batch_size: i64) -> Result<bool> {
        if metrics.is_empty() {
            return Ok(true);
        }
        self.set_context("addBatch()");
        self.validate_batch(metrics)?;
        let batch_size = batch_size.clamp(1, INSERT_BATCH_SIZE) as usize;
        let table = self.table_for(kind);

        let mut builder = self.builder(kind);
        Builder::into(&mut builder, table.as_str());
        builder.insert_format("JSONEachRow", self.insert_columns(kind))?;
        let statement = builder.formatted_insert()?.unwrap_or_else(|| unreachable!("insert_format() was set"));
        let sql = self.qualify(&statement.query, &[&table]);

        for chunk in metrics.chunks(batch_size) {
            let mut rows = Vec::with_capacity(chunk.len());
            for entry in chunk {
                let columns = Metric::extract_columns(&entry.tags, kind)?;
                let mut row = Array::default();
                row.set(ArrayKey::from("id"), Value::from(php::random_hex(16).as_str()));
                row.set(ArrayKey::from("metric"), Value::from(entry.metric.as_str()));
                row.set(ArrayKey::from("value"), Value::Int(entry.value));
                row.set(ArrayKey::from("time"), Value::from(self.format_time(entry.time.as_ref())?.as_str()));
                for (column, value) in columns {
                    row.set(ArrayKey::from(column), value.map_or(Value::Null, Value::Str));
                }
                if self.config.shared_tables {
                    row.set(ArrayKey::from("tenant"), entry.tenant.as_deref().map_or(Value::Null, Value::from));
                }
                match json::encode(&Value::Array(row), Flags::NONE, json::DEFAULT_DEPTH) {
                    Ok(encoded) => rows.push(encoded),
                    Err(e) => {
                        return Err(Error::exception(format!("Failed to JSON encode metric row: {}", e.message())));
                    }
                }
            }
            self.insert(&table, &sql, &rows, false).await?;
        }
        Ok(true)
    }

    async fn add_samples(&self, samples: &[Sample], batch_size: i64) -> Result<bool> {
        self.insert_samples(samples, batch_size).await
    }

    async fn sample_watermark(&self, range: &SampleRange, limit: i64) -> Result<SampleWatermark> {
        self.watermark(range, limit).await
    }

    async fn find_samples(&self, range: &SampleRange, watermark: &SampleWatermark, limit: i64) -> Result<SampleResult> {
        self.read_samples(range, watermark, limit).await
    }

    async fn time_series(
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
        self.series(tenant, metrics, interval, start, end, queries, zero_fill, kind).await
    }

    async fn total(&self, tenant: &str, metric: &str, queries: &[Query], kind: Option<Kind>) -> Result<i64> {
        self.set_context("getTotal()");
        match kind {
            Some(Kind::Event) => self.total_from_events(tenant, metric, queries).await,
            Some(Kind::Gauge) => self.total_from_gauges(tenant, metric, queries).await,
            None => {
                let events = self.total_from_events(tenant, metric, queries).await?;
                let gauges = self.total_from_gauges(tenant, metric, queries).await?;
                if events > 0 && gauges > 0 {
                    return Err(Error::exception(format!(
                        "Metric '{metric}' exists in both event and gauge tables. Specify $type explicitly to avoid ambiguous aggregation."
                    )));
                }
                Ok(if events > 0 { events } else { gauges })
            }
        }
    }

    async fn total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[Query],
        kind: Option<Kind>,
    ) -> Result<Totals> {
        self.totals(tenant, metrics, queries, kind).await
    }

    async fn purge(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<bool> {
        self.delete(tenant, queries, kind).await
    }

    async fn find(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        self.set_context("find()");
        self.find_scoped(Some(tenant), queries, kind).await
    }

    async fn find_across_tenants(&self, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        self.set_context("findAcrossTenants()");
        if !self.config.shared_tables {
            return Err(Error::exception("findAcrossTenants() requires shared-tables mode; use find() instead"));
        }
        self.find_scoped(None, queries, kind).await
    }

    async fn count(&self, tenant: &str, queries: &[Query], kind: Option<Kind>, max: Option<i64>) -> Result<i64> {
        self.set_context("count()");
        if let Some(kind) = kind {
            return self.count_from(tenant, queries, kind, max).await;
        }
        let events = if self.queries_match(queries, Kind::Event) {
            self.count_from(tenant, queries, Kind::Event, max).await?
        } else {
            0
        };
        let gauges = if self.queries_match(queries, Kind::Gauge) {
            self.count_from(tenant, queries, Kind::Gauge, max).await?
        } else {
            0
        };
        let total = events.wrapping_add(gauges);
        Ok(match max {
            Some(max) if total > max => max,
            _ => total,
        })
    }

    async fn sum(&self, tenant: &str, queries: &[Query], attribute: &str, kind: Kind) -> Result<i64> {
        self.set_context("sum()");
        if kind == Kind::Event && attribute == "value" {
            return self.routed_sum(tenant, queries, "sum").await;
        }
        self.sum_from(tenant, queries, attribute, kind).await
    }

    async fn find_daily(&self, tenant: &str, queries: &[Query]) -> Result<Vec<Metric>> {
        self.daily_rows(tenant, queries).await
    }

    async fn sum_daily(&self, tenant: &str, queries: &[Query], attribute: &str) -> Result<i64> {
        self.set_context("sumDaily()");
        self.daily_total(tenant, queries, attribute).await
    }

    async fn sum_daily_batch(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        self.daily_batch(tenant, metrics, queries).await
    }

    async fn backfill_daily(&self, from: &str, to: &str, force: bool) -> Result<()> {
        self.backfill(from, to, force).await
    }
}
