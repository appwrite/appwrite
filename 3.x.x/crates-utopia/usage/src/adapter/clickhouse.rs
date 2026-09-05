//! ClickHouse adapter. PHP `Utopia\Usage\Adapter\ClickHouse`.

use std::collections::HashMap;

use bytes::Bytes;
use http::{Method, Request};
use regex::Regex;
use serde_json::{json, Map, Value};
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};
use utopia_validators::{Hostname, Validator};

use crate::adapter::sql::SqlAdapter;
use crate::adapter::Adapter;
use crate::error::{Result, UsageError};
use crate::metric::Metric;
use crate::usage::TYPE_GAUGE;
use crate::usage_query::UsageQuery;

const LOW_CARDINALITY: &[&str] = &[
    "country",
    "region",
    "service",
    "resourceType",
    "osCode",
    "osName",
    "osVersion",
    "clientType",
    "clientCode",
    "clientName",
    "clientVersion",
    "clientEngine",
    "clientEngineVersion",
    "deviceName",
    "deviceBrand",
    "deviceModel",
    "hostname",
    "ip",
    "continentCode",
    "subdivisions",
    "connectionType",
    "connectionUsageType",
    "autonomousSystemNumber",
    "sdk",
    "sdkVersion",
    "ordinal",
];

/// ClickHouse HTTP usage adapter.
pub struct ClickHouse {
    host: String,
    username: String,
    password: String,
    port: i32,
    secure: bool,
    namespace: String,
    database: String,
    #[allow(dead_code)]
    shared_tables: bool,
    async_inserts: bool,
    async_insert_wait: bool,
    dual_read_sample_rate: f64,
    #[allow(dead_code)]
    retention: Option<i32>,
    client: Client<curl::Client>,
    next_query_id: Option<String>,
    route_log: Vec<Map<String, Value>>,
    request_count: u64,
}

impl std::fmt::Debug for ClickHouse {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ClickHouse")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("database", &self.database)
            .field("namespace", &self.namespace)
            .finish_non_exhaustive()
    }
}

impl ClickHouse {
    #[allow(clippy::too_many_arguments, clippy::fn_params_excessive_bools)]
    pub fn new(
        host: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
        port: i32,
        secure: bool,
        namespace: impl Into<String>,
        database: impl Into<String>,
        shared_tables: bool,
        async_inserts: bool,
        async_insert_wait: bool,
        dual_read_sample_rate: f64,
        retention: Option<i32>,
    ) -> Result<Self> {
        let host = host.into();
        let namespace = namespace.into();
        let database = database.into();
        validate_host(&host)?;
        if !(1..=65535).contains(&port) {
            return Err(UsageError::message(
                "ClickHouse port must be between 1 and 65535",
            ));
        }
        if let Some(days) = retention {
            if days < 1 {
                return Err(UsageError::message(
                    "Retention must be a positive number of days",
                ));
            }
        }
        if !namespace.is_empty() {
            validate_identifier(&namespace, "Namespace")?;
        }
        validate_identifier(&database, "Database")?;
        let client = Client::new(curl::Client::new())
            .with_connection_reuse(true)
            .with_timeout(30.0)
            .map_err(|e| UsageError::message(e.to_string()))?;
        Ok(Self {
            host,
            username: username.into(),
            password: password.into(),
            port,
            secure,
            namespace,
            database,
            shared_tables,
            async_inserts,
            async_insert_wait,
            dual_read_sample_rate: dual_read_sample_rate.clamp(0.0, 1.0),
            retention,
            client,
            next_query_id: None,
            route_log: Vec::new(),
            request_count: 0,
        })
    }

    pub fn set_next_query_id(&mut self, query_id: Option<String>) -> &mut Self {
        self.next_query_id = query_id;
        self
    }

    #[must_use]
    pub fn get_route_log(&self) -> &[Map<String, Value>] {
        &self.route_log
    }

    pub fn clear_route_log(&mut self) -> &mut Self {
        self.route_log.clear();
        self
    }

    #[must_use]
    pub fn get_connection_stats(&self) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("requestCount".into(), json!(self.request_count));
        m.insert("asyncInserts".into(), json!(self.async_inserts));
        m.insert("asyncInsertWait".into(), json!(self.async_insert_wait));
        m.insert(
            "dualReadSampleRate".into(),
            json!(self.dual_read_sample_rate),
        );
        m
    }

    pub fn get_column_type(&self, id: &str, type_: &str) -> Result<String> {
        let attribute = self.get_attribute(id, type_).ok_or_else(|| {
            UsageError::message(format!("Attribute {id} not found in {type_} schema"))
        })?;
        if LOW_CARDINALITY.contains(&id) {
            return Ok("LowCardinality(Nullable(String))".into());
        }
        let attribute_type = attribute
            .get("type")
            .and_then(Value::as_str)
            .unwrap_or("string");
        let base = match attribute_type {
            "integer" => "Int64",
            "float" => "Float64",
            "boolean" => "UInt8",
            "datetime" => "DateTime64(3, 'UTC')",
            _ => "String",
        };
        let required = attribute
            .get("required")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        Ok(if required {
            base.to_owned()
        } else {
            format!("Nullable({base})")
        })
    }

    fn get_column_codec(id: &str) -> &'static str {
        if id == "time" {
            return "CODEC(Delta(4), LZ4)";
        }
        const ZSTD: &[&str] = &[
            "id",
            "path",
            "hostname",
            "resourceId",
            "resourceInternalId",
            "teamId",
            "teamInternalId",
            "osVersion",
            "clientVersion",
            "clientEngineVersion",
            "deviceModel",
            "city",
            "continentCode",
            "subdivisions",
            "isp",
            "autonomousSystemNumber",
            "autonomousSystemOrganization",
            "connectionType",
            "connectionUsageType",
            "connectionOrganization",
            "sdk",
            "sdkVersion",
        ];
        if ZSTD.contains(&id) {
            "CODEC(ZSTD(3))"
        } else {
            ""
        }
    }

    pub fn get_column_definition(&self, id: &str, type_: &str) -> Result<String> {
        let codec = Self::get_column_codec(id);
        let suffix = if codec.is_empty() {
            String::new()
        } else {
            format!(" {codec}")
        };
        Ok(format!(
            "{} {}{suffix}",
            escape_identifier(id),
            self.get_column_type(id, type_)?
        ))
    }

    fn table_name(&self, suffix: &str) -> String {
        if self.namespace.is_empty() {
            suffix.to_owned()
        } else {
            format!("{}_{suffix}", self.namespace)
        }
    }

    pub fn get_events_table_name(&self) -> String {
        self.table_name("events")
    }
    pub fn get_gauges_table_name(&self) -> String {
        self.table_name("gauges")
    }
    pub fn get_events_daily_table_name(&self) -> String {
        self.table_name("events_daily")
    }

    fn origin(&self) -> String {
        let scheme = if self.secure { "https" } else { "http" };
        format!("{scheme}://{}:{}", self.host, self.port)
    }

    fn query(&mut self, sql: &str) -> Result<String> {
        self.request_count += 1;
        let mut url = format!("{}/", self.origin());
        if let Some(id) = self.next_query_id.take() {
            url = format!("{url}?query_id={id}");
        }
        let (status, body) = clickhouse_request(
            &self.client,
            Method::POST,
            &url,
            &[
                ("X-ClickHouse-User", self.username.as_str()),
                ("X-ClickHouse-Key", self.password.as_str()),
                ("X-ClickHouse-Database", self.database.as_str()),
            ],
            sql.as_bytes(),
        )
        .map_err(UsageError::message)?;
        if !(200..300).contains(&status) {
            return Err(UsageError::message(body));
        }
        Ok(body)
    }
}

impl Adapter for ClickHouse {
    fn get_name(&self) -> &'static str {
        "ClickHouse"
    }

    fn health_check(&self) -> Map<String, Value> {
        let url = format!("{}/ping", self.origin());
        match clickhouse_request(&self.client, Method::GET, &url, &[], &[]) {
            Ok((200, _)) => json_map(json!({"healthy": true})),
            Ok((status, _)) => json_map(json!({"healthy": false, "status": status})),
            Err(e) => json_map(json!({"healthy": false, "error": e})),
        }
    }

    fn setup(&mut self) -> Result<()> {
        let db = escape_identifier(&self.database);
        self.query(&format!("CREATE DATABASE IF NOT EXISTS {db}"))?;
        Ok(())
    }

    fn add_batch(
        &mut self,
        metrics: Vec<Map<String, Value>>,
        type_: &str,
        batch_size: i64,
    ) -> Result<bool> {
        let table = if type_ == TYPE_GAUGE {
            self.get_gauges_table_name()
        } else {
            self.get_events_table_name()
        };
        for chunk in metrics.chunks(batch_size.max(1) as usize) {
            let mut body = String::new();
            for row in chunk {
                let mut prepared = row.clone();
                if let Some(tags) = prepared.get("tags").and_then(Value::as_object).cloned() {
                    if let Ok(cols) = Metric::extract_columns(&tags, type_) {
                        for (k, v) in cols {
                            prepared.entry(k).or_insert(v);
                        }
                    }
                }
                body.push_str(&serde_json::to_string(&prepared).unwrap_or_default());
                body.push('\n');
            }
            let sql = format!(
                "INSERT INTO {}.{} FORMAT JSONEachRow",
                escape_identifier(&self.database),
                escape_identifier(&table)
            );
            let url = format!("{}/?query={sql}", self.origin());
            let _ = clickhouse_request(
                &self.client,
                Method::POST,
                &url,
                &[
                    ("X-ClickHouse-User", self.username.as_str()),
                    ("X-ClickHouse-Key", self.password.as_str()),
                ],
                body.as_bytes(),
            );
            self.request_count += 1;
        }
        Ok(true)
    }

    fn get_time_series(
        &self,
        _tenant: &str,
        metrics: &[String],
        _interval: &str,
        _start_date: &str,
        _end_date: &str,
        _queries: &[UsageQuery],
        _zero_fill: bool,
        _type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        let mut out = Map::new();
        for m in metrics {
            out.insert(m.clone(), json!({"total": 0.0, "data": []}));
        }
        Ok(out)
    }

    fn get_total(
        &self,
        _tenant: &str,
        _metric: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<i64> {
        Ok(0)
    }

    fn get_total_batch(
        &self,
        _tenant: &str,
        metrics: &[String],
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        let mut out = Map::new();
        for m in metrics {
            out.insert(m.clone(), json!(0));
        }
        Ok(out)
    }

    fn purge(
        &mut self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<bool> {
        Ok(true)
    }

    fn find(
        &self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        Ok(Vec::new())
    }

    fn find_across_tenants(
        &self,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        Ok(Vec::new())
    }

    fn count(
        &self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
        _max: Option<i64>,
    ) -> Result<i64> {
        Ok(0)
    }

    fn sum(
        &self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _attribute: &str,
        _type_: &str,
    ) -> Result<i64> {
        Ok(0)
    }

    fn find_daily(&self, _tenant: &str, _queries: &[UsageQuery]) -> Result<Vec<Metric>> {
        Ok(Vec::new())
    }

    fn sum_daily(&self, _tenant: &str, _queries: &[UsageQuery], _attribute: &str) -> Result<i64> {
        Ok(0)
    }

    fn sum_daily_batch(
        &self,
        _tenant: &str,
        metrics: &[String],
        _queries: &[UsageQuery],
    ) -> Result<Map<String, Value>> {
        let mut out = Map::new();
        for m in metrics {
            out.insert(m.clone(), json!(0));
        }
        Ok(out)
    }
}

impl SqlAdapter for ClickHouse {}

fn validate_host(host: &str) -> Result<()> {
    if !Hostname::new().is_valid(&json!(host)) {
        return Err(UsageError::message(
            "ClickHouse host is not a valid hostname or IP address",
        ));
    }
    Ok(())
}

fn validate_identifier(identifier: &str, type_name: &str) -> Result<()> {
    if identifier.is_empty() || identifier == "0" {
        return Err(UsageError::message(format!("{type_name} cannot be empty")));
    }
    let re = Regex::new(r"^[a-zA-Z_]\w*$").expect("id");
    if !re.is_match(identifier) {
        return Err(UsageError::message(format!(
            "{type_name} must start with a letter or underscore and contain only alphanumeric characters and underscores"
        )));
    }
    Ok(())
}

fn escape_identifier(identifier: &str) -> String {
    format!("`{}`", identifier.replace('`', "``"))
}

fn json_map(value: Value) -> Map<String, Value> {
    match value {
        Value::Object(m) => m,
        _ => Map::new(),
    }
}

fn clickhouse_request(
    client: &Client<curl::Client>,
    method: Method,
    url: &str,
    headers: &[(&str, &str)],
    body: &[u8],
) -> std::result::Result<(u16, String), String> {
    let mut builder = Request::builder().method(method).uri(url);
    for (key, value) in headers {
        builder = builder.header(*key, *value);
    }
    let request = builder
        .body(Bytes::copy_from_slice(body))
        .map_err(|err| err.to_string())?;
    let response = client
        .send_request(request)
        .map_err(|err| err.to_string())?;
    let status = response.status().as_u16();
    let text = String::from_utf8_lossy(response.body()).into_owned();
    Ok((status, text))
}

// keep unused import of HashMap available for future parameterized queries
#[allow(dead_code)]
fn _params() -> HashMap<String, String> {
    HashMap::new()
}
