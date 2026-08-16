//! `ClickHouse` adapter. PHP `Utopia\Audit\Adapter\ClickHouse`.

use std::collections::HashMap;
use std::fmt::Write as _;

use bytes::Bytes;
use chrono::{DateTime, Utc};
use http::{Method, Request};
use regex::Regex;
use serde_json::{json, Map, Value};
use utopia_client::adapter::curl;
use utopia_client::{Client, StreamingClient};
use utopia_database::constants::{INDEX_KEY, LENGTH_KEY, VAR_DATETIME, VAR_STRING};
use utopia_query::value::QueryValue;
use utopia_validators::{Hostname, Validator};

use crate::adapter::sql::{default_attributes, default_indexes, parse_resource, SqlAdapter};
use crate::adapter::Adapter;
use crate::error::{AuditError, Result};
use crate::log::Log;
use crate::query::Query;

#[allow(dead_code)]
const DEFAULT_PORT: i32 = 8123;
const DEFAULT_TABLE: &str = "audits";
const DEFAULT_DATABASE: &str = "default";

const LOW_CARDINALITY_COLUMNS: &[&str] = &[
    "event",
    "actorType",
    "resourceType",
    "country",
    "sdk",
    "continentCode",
    "subdivisions",
    "connectionType",
    "connectionUsageType",
    "osCode",
    "osName",
    "clientType",
    "clientCode",
    "clientName",
    "clientEngine",
    "deviceName",
    "deviceBrand",
];

const VALUE_REQUIRED_METHODS: &[&str] = &[
    Query::TYPE_EQUAL,
    Query::TYPE_NOT_EQUAL,
    Query::TYPE_LESSER,
    Query::TYPE_LESSER_EQUAL,
    Query::TYPE_GREATER,
    Query::TYPE_GREATER_EQUAL,
    Query::TYPE_BETWEEN,
    Query::TYPE_NOT_BETWEEN,
    Query::TYPE_CONTAINS,
    Query::TYPE_NOT_CONTAINS,
    Query::TYPE_STARTS_WITH,
    Query::TYPE_NOT_STARTS_WITH,
    Query::TYPE_ENDS_WITH,
    Query::TYPE_NOT_ENDS_WITH,
    Query::TYPE_REGEX,
    Query::TYPE_SELECT,
];

const SQL_KEYWORDS: &[&str] = &[
    "SELECT", "INSERT", "UPDATE", "DELETE", "DROP", "CREATE", "ALTER", "TABLE", "DATABASE",
];

/// `ClickHouse` HTTP adapter for audit logs.
#[derive(Debug)]
pub struct ClickHouse {
    host: String,
    username: String,
    password: String,
    port: i32,
    secure: bool,
    database: String,
    table: String,
    namespace: String,
    tenant: Option<i64>,
    shared_tables: bool,
    async_cleanup: bool,
    retention: Option<i32>,
    client: Client<curl::Client>,
    /// Override base URL (scheme://host:port) for tests / wiremock.
    base_url: Option<String>,
}

impl ClickHouse {
    /// PHP `__construct(string $host, string $username = 'default', string $password = '', int $port = 8123, bool $secure = false)`.
    pub fn new(
        host: impl Into<String>,
        username: impl Into<String>,
        password: impl Into<String>,
        port: i32,
        secure: bool,
    ) -> Result<Self> {
        let host = host.into();
        validate_host(&host)?;
        if !(1..=65535).contains(&port) {
            return Err(AuditError::message(
                "ClickHouse port must be between 1 and 65535",
            ));
        }
        let client = Client::new(curl::Client::new())
            .with_connection_reuse(true)
            .with_timeout(30.0)
            .map_err(|e| AuditError::message(e.to_string()))?;
        Ok(Self {
            host,
            username: username.into(),
            password: password.into(),
            port,
            secure,
            database: DEFAULT_DATABASE.to_owned(),
            table: DEFAULT_TABLE.to_owned(),
            namespace: String::new(),
            tenant: None,
            shared_tables: false,
            async_cleanup: false,
            retention: None,
            client,
            base_url: None,
        })
    }

    pub fn with_base_url(mut self, url: impl Into<String>) -> Self {
        self.base_url = Some(url.into());
        self
    }

    pub fn set_namespace(&mut self, namespace: impl Into<String>) -> Result<&mut Self> {
        let namespace = namespace.into();
        if !namespace.is_empty() && namespace != "0" {
            validate_identifier(&namespace, "Namespace")?;
        }
        self.namespace = namespace;
        Ok(self)
    }

    pub fn get_namespace(&self) -> &str {
        &self.namespace
    }

    pub fn set_database(&mut self, database: impl Into<String>) -> Result<&mut Self> {
        let database = database.into();
        validate_identifier(&database, "Database")?;
        self.database = database;
        Ok(self)
    }

    pub fn get_database(&self) -> &str {
        &self.database
    }

    pub fn set_table(&mut self, table: impl Into<String>) -> Result<&mut Self> {
        let table = table.into();
        validate_identifier(&table, "Table")?;
        self.table = table;
        Ok(self)
    }

    pub fn get_table(&self) -> &str {
        &self.table
    }

    pub fn set_secure(&mut self, secure: bool) -> &mut Self {
        self.secure = secure;
        self
    }

    pub fn set_tenant(&mut self, tenant: Option<i64>) -> &mut Self {
        self.tenant = tenant;
        self
    }

    pub fn get_tenant(&self) -> Option<i64> {
        self.tenant
    }

    pub fn set_shared_tables(&mut self, shared: bool) -> &mut Self {
        self.shared_tables = shared;
        self
    }

    pub fn is_shared_tables(&self) -> bool {
        self.shared_tables
    }

    pub fn set_async_cleanup(&mut self, async_cleanup: bool) -> &mut Self {
        self.async_cleanup = async_cleanup;
        self
    }

    pub fn is_async_cleanup(&self) -> bool {
        self.async_cleanup
    }

    pub fn set_retention(&mut self, days: Option<i32>) -> Result<&mut Self> {
        if let Some(days) = days {
            if days < 1 {
                return Err(AuditError::message(
                    "Retention must be a positive number of days",
                ));
            }
        }
        self.retention = days;
        Ok(self)
    }

    pub fn get_retention(&self) -> Option<i32> {
        self.retention
    }

    pub fn get_table_name(&self) -> String {
        if !self.namespace.is_empty() && self.namespace != "0" {
            format!("{}_{}", self.namespace, self.table)
        } else {
            self.table.clone()
        }
    }

    pub fn get_create_table_sql(&self) -> Result<String> {
        let mut columns = vec!["id String".to_owned()];
        for attribute in self.get_attributes() {
            let id = attribute
                .get("$id")
                .and_then(Value::as_str)
                .unwrap_or_default();
            if id == "time" {
                columns.push("time DateTime64(3)".into());
            } else {
                columns.push(self.get_column_definition(id)?);
            }
        }
        if self.shared_tables {
            columns.push("tenant Nullable(UInt64)".into());
        }
        let mut indexes = Vec::new();
        for index in self.get_indexes() {
            let name = index.get("$id").and_then(Value::as_str).unwrap_or_default();
            let attrs = index
                .get("attributes")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            let list: Vec<String> = attrs
                .iter()
                .filter_map(|v| v.as_str().map(escape_identifier))
                .collect();
            indexes.push(format!(
                "INDEX {name} ({}) TYPE bloom_filter GRANULARITY 1",
                list.join(", ")
            ));
        }
        let table = format!(
            "{}.{}",
            escape_identifier(&self.database),
            escape_identifier(&self.get_table_name())
        );
        let order = if self.shared_tables {
            "(tenant, time, id)"
        } else {
            "(time, id)"
        };
        let settings = if self.shared_tables {
            "index_granularity = 8192, allow_nullable_key = 1"
        } else {
            "index_granularity = 8192"
        };
        Ok(format!(
            "CREATE TABLE IF NOT EXISTS {table} (\n                {},\n                {}\n            )\n            ENGINE = MergeTree()\n            ORDER BY {order}\n            PARTITION BY toYYYYMM(time)\n            SETTINGS {settings}",
            columns.join(",\n                "),
            indexes.join(",\n                "),
        ))
    }

    fn origin(&self) -> String {
        if let Some(base) = &self.base_url {
            return base.trim_end_matches('/').to_owned();
        }
        let scheme = if self.secure { "https" } else { "http" };
        format!("{scheme}://{}:{}", self.host, self.port)
    }

    fn query(&self, sql: &str, params: &HashMap<String, String>) -> Result<String> {
        let url = format!("{}/", self.origin());
        let (content_type, body) = encode_clickhouse_form(sql, params);
        let (status, body) = clickhouse_request(
            &self.client,
            Method::POST,
            &url,
            &[
                ("X-ClickHouse-User", self.username.as_str()),
                ("X-ClickHouse-Key", self.password.as_str()),
                ("X-ClickHouse-Database", self.database.as_str()),
                ("Content-Type", content_type.as_str()),
            ],
            body,
        )?;
        if !(200..300).contains(&status) {
            return Err(AuditError::message(body));
        }
        Ok(body)
    }

    fn insert_json_rows(&self, sql: &str, rows: &[Value]) -> Result<()> {
        let mut body = String::new();
        for row in rows {
            body.push_str(
                &serde_json::to_string(row).map_err(|e| AuditError::message(e.to_string()))?,
            );
            body.push('\n');
        }
        let url = format!("{}/?query={}", self.origin(), urlencoding_query(sql));
        let (status, text) = clickhouse_request(
            &self.client,
            Method::POST,
            &url,
            &[
                ("X-ClickHouse-User", self.username.as_str()),
                ("X-ClickHouse-Key", self.password.as_str()),
                ("X-ClickHouse-Database", self.database.as_str()),
                ("Content-Type", "application/x-ndjson"),
            ],
            body.into_bytes(),
        )?;
        if !(200..300).contains(&status) {
            return Err(AuditError::message(text));
        }
        Ok(())
    }

    fn prepare_row(&self, mut log: Map<String, Value>) -> Value {
        if let Some(user) = log.remove("userId") {
            log.insert("actorId".into(), user);
        }
        let resource = log
            .get("resource")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        let parsed = parse_resource(&resource);
        log.entry("resourceId")
            .or_insert_with(|| json!(parsed.resource_id));
        log.entry("resourceType")
            .or_insert_with(|| json!(parsed.resource_type));
        log.entry("resourceParent")
            .or_insert_with(|| json!(parsed.resource_parent));
        if !log.contains_key("id") && !log.contains_key("$id") {
            log.insert("id".into(), json!(uniqid()));
        } else if let Some(id) = log.remove("$id") {
            log.insert("id".into(), id);
        }
        if !log.contains_key("time") {
            log.insert("time".into(), json!(now_db()));
        }
        if self.shared_tables {
            if let Some(tenant) = self.tenant {
                log.entry("tenant").or_insert_with(|| json!(tenant));
            }
        }
        Value::Object(log)
    }

    fn compile(&self, queries: &[Query]) -> Result<Compiled> {
        let mut compiled = Compiled::default();
        let mut select: Option<Vec<String>> = None;
        for query in queries {
            let method = query.get_method();
            if VALUE_REQUIRED_METHODS.contains(&method) && query.get_values().is_empty() {
                return Err(AuditError::message(format!(
                    "{} queries require at least one value.",
                    method_label(method)
                )));
            }
            let attr = translate_attr(query.get_attribute());
            match method {
                Query::TYPE_EQUAL => {
                    compiled
                        .where_sql
                        .push(self.eq_clause(attr, query.get_values(), false));
                }
                Query::TYPE_NOT_EQUAL => {
                    compiled
                        .where_sql
                        .push(self.eq_clause(attr, query.get_values(), true));
                }
                Query::TYPE_LESSER => {
                    compiled
                        .where_sql
                        .push(cmp_clause(attr, "<", query.get_values())?);
                }
                Query::TYPE_LESSER_EQUAL => {
                    compiled
                        .where_sql
                        .push(cmp_clause(attr, "<=", query.get_values())?);
                }
                Query::TYPE_GREATER => {
                    compiled
                        .where_sql
                        .push(cmp_clause(attr, ">", query.get_values())?);
                }
                Query::TYPE_GREATER_EQUAL => {
                    compiled
                        .where_sql
                        .push(cmp_clause(attr, ">=", query.get_values())?);
                }
                Query::TYPE_BETWEEN => {
                    compiled
                        .where_sql
                        .push(between_clause(attr, query.get_values(), false)?);
                }
                Query::TYPE_NOT_BETWEEN => {
                    compiled
                        .where_sql
                        .push(between_clause(attr, query.get_values(), true)?);
                }
                Query::TYPE_CONTAINS => {
                    compiled.where_sql.push(like_clause(
                        attr,
                        query.get_values(),
                        false,
                        "%",
                        "%",
                    )?);
                }
                Query::TYPE_NOT_CONTAINS => {
                    compiled
                        .where_sql
                        .push(like_clause(attr, query.get_values(), true, "%", "%")?);
                }
                Query::TYPE_STARTS_WITH => {
                    compiled
                        .where_sql
                        .push(like_clause(attr, query.get_values(), false, "", "%")?);
                }
                Query::TYPE_NOT_STARTS_WITH => {
                    compiled
                        .where_sql
                        .push(like_clause(attr, query.get_values(), true, "", "%")?);
                }
                Query::TYPE_ENDS_WITH => {
                    compiled
                        .where_sql
                        .push(like_clause(attr, query.get_values(), false, "%", "")?);
                }
                Query::TYPE_NOT_ENDS_WITH => {
                    compiled
                        .where_sql
                        .push(like_clause(attr, query.get_values(), true, "%", "")?);
                }
                Query::TYPE_IS_NULL => compiled
                    .where_sql
                    .push(format!("{} IS NULL", escape_identifier(attr))),
                Query::TYPE_IS_NOT_NULL => compiled
                    .where_sql
                    .push(format!("{} IS NOT NULL", escape_identifier(attr))),
                Query::TYPE_REGEX => {
                    let pat = query
                        .get_values()
                        .first()
                        .map(QueryValue::php_to_string)
                        .unwrap_or_default();
                    compiled.where_sql.push(format!(
                        "match({}, {})",
                        escape_identifier(attr),
                        sql_string(&pat)
                    ));
                }
                Query::TYPE_SELECT => {
                    let cols: Vec<String> = query
                        .get_values()
                        .iter()
                        .map(|v| v.as_str().unwrap_or("").to_owned())
                        .collect();
                    for col in &cols {
                        if col != "id"
                            && col != "tenant"
                            && self.get_attribute(col).is_none()
                            && col != "actorId"
                        {
                            return Err(AuditError::message(format!("Unknown column '{col}'")));
                        }
                    }
                    select = Some(cols);
                }
                Query::TYPE_ORDER_ASC => compiled.order.push((attr.to_owned(), true)),
                Query::TYPE_ORDER_DESC => compiled.order.push((attr.to_owned(), false)),
                Query::TYPE_ORDER_RANDOM => compiled.random = true,
                Query::TYPE_LIMIT => {
                    compiled.limit = query.get_values().first().and_then(QueryValue::as_i64);
                }
                Query::TYPE_OFFSET => {
                    compiled.offset = query.get_values().first().and_then(QueryValue::as_i64);
                }
                Query::TYPE_CURSOR_AFTER | Query::TYPE_CURSOR_BEFORE => {
                    compiled.cursor_before = method == Query::TYPE_CURSOR_BEFORE;
                    compiled.cursor = query.get_values().first().cloned();
                }
                _ => {}
            }
        }
        if compiled.random {
            if compiled.cursor.is_some() {
                return Err(AuditError::message(
                    "Cursor pagination cannot be combined with orderRandom",
                ));
            }
            if !compiled.order.is_empty() {
                return Err(AuditError::message(
                    "orderRandom cannot be combined with orderAsc/orderDesc",
                ));
            }
        }
        compiled.select = select;
        Ok(compiled)
    }

    fn eq_clause(&self, attr: &str, values: &[QueryValue], not: bool) -> String {
        let col = escape_identifier(attr);
        if values.len() == 1 {
            let lit = sql_literal(&values[0]);
            if not {
                format!("{col} != {lit}")
            } else {
                format!("{col} = {lit}")
            }
        } else {
            let list: Vec<String> = values.iter().map(sql_literal).collect();
            if not {
                format!("{col} NOT IN ({})", list.join(", "))
            } else {
                format!("{col} IN ({})", list.join(", "))
            }
        }
    }

    fn row_to_log(&self, row: Map<String, Value>) -> Log {
        let mut data = row;
        if let Some(id) = data.get("id").cloned() {
            data.insert("$id".into(), id);
        }
        if let Some(actor) = data.get("actorId").cloned() {
            data.entry("userId").or_insert(actor);
        }
        if let Some(Value::String(s)) = data.get("data").cloned() {
            if let Ok(Value::Object(map)) = serde_json::from_str(&s) {
                data.insert("data".into(), Value::Object(map));
            }
        }
        Log::new(data)
    }
}

impl Adapter for ClickHouse {
    fn get_name(&self) -> &'static str {
        "ClickHouse"
    }

    fn setup(&mut self) -> Result<()> {
        let db = escape_identifier(&self.database);
        self.query(
            &format!("CREATE DATABASE IF NOT EXISTS {db}"),
            &HashMap::new(),
        )?;
        let sql = self.get_create_table_sql()?;
        self.query(&sql, &HashMap::new())?;
        let table = format!(
            "{}.{}",
            escape_identifier(&self.database),
            escape_identifier(&self.get_table_name())
        );
        if let Some(days) = self.retention {
            self.query(
                &format!(
                    "ALTER TABLE {table} MODIFY TTL toDateTime(time) + INTERVAL {days} DAY SETTINGS materialize_ttl_after_modify = 0"
                ),
                &HashMap::new(),
            )?;
        } else {
            let _ = self.query(&format!("ALTER TABLE {table} REMOVE TTL"), &HashMap::new());
        }
        Ok(())
    }

    fn get_by_id(&self, id: &str) -> Result<Option<Log>> {
        let logs = self.find(&[Query::equal("id", id), Query::limit(1)])?;
        Ok(logs.into_iter().next())
    }

    fn create(&mut self, log: Map<String, Value>) -> Result<Log> {
        let row = self.prepare_row(log);
        let created = Log::from_value(row.clone());
        self.create_batch_rows(&[row])?;
        Ok(created)
    }

    fn create_batch(&mut self, logs: Vec<Map<String, Value>>) -> Result<bool> {
        let mut rows = Vec::new();
        for log in logs {
            rows.push(self.prepare_row(log));
        }
        self.create_batch_rows(&rows)?;
        Ok(true)
    }

    fn get_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![Query::equal("actorId", user_id)];
        q.extend(time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("time")
        } else {
            Query::order_desc("time")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![Query::equal("actorId", user_id)];
        q.extend(time_queries(after, before));
        self.count(&q, max)
    }

    fn get_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![Query::equal("resource", resource)];
        q.extend(time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("time")
        } else {
            Query::order_desc("time")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![Query::equal("resource", resource)];
        q.extend(time_queries(after, before));
        self.count(&q, max)
    }

    fn get_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![
            Query::equal("actorId", user_id),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("time")
        } else {
            Query::order_desc("time")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![
            Query::equal("actorId", user_id),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(time_queries(after, before));
        self.count(&q, max)
    }

    fn get_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![
            Query::equal("resource", resource),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("time")
        } else {
            Query::order_desc("time")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![
            Query::equal("resource", resource),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(time_queries(after, before));
        self.count(&q, max)
    }

    fn cleanup(&mut self, datetime: DateTime<Utc>) -> Result<bool> {
        let table = format!(
            "{}.{}",
            escape_identifier(&self.database),
            escape_identifier(&self.get_table_name())
        );
        let mut sql = format!(
            "ALTER TABLE {table} DELETE WHERE time < {}",
            sql_string(&datetime.format("%Y-%m-%d %H:%M:%S%.3f").to_string())
        );
        if self.async_cleanup {
            sql.push_str(" SETTINGS lightweight_deletes_sync = 0");
        }
        self.query(&sql, &HashMap::new())?;
        Ok(true)
    }

    fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        let compiled = self.compile(queries)?;
        let table = format!(
            "{}.{}",
            escape_identifier(&self.database),
            escape_identifier(&self.get_table_name())
        );
        let mut cols: Vec<String> = compiled.select.clone().unwrap_or_else(|| {
            let mut c: Vec<String> = self
                .get_attributes()
                .into_iter()
                .filter_map(|a| a.get("$id").and_then(Value::as_str).map(str::to_owned))
                .collect();
            c.insert(0, "id".into());
            c
        });
        if !cols.iter().any(|c| c == "id") {
            cols.insert(0, "id".into());
        }
        if self.shared_tables && !cols.iter().any(|c| c == "tenant") {
            cols.push("tenant".into());
        }
        let select = cols
            .iter()
            .map(|c| escape_identifier(c))
            .collect::<Vec<_>>()
            .join(", ");
        let mut sql = format!("SELECT {select} FROM {table} WHERE 1=1");
        for w in &compiled.where_sql {
            sql.push_str(" AND ");
            sql.push_str(w);
        }
        if self.shared_tables {
            if let Some(tenant) = self.tenant {
                write!(sql, " AND {} = {tenant}", escape_identifier("tenant"))
                    .expect("writing to a String cannot fail");
            }
        }
        if compiled.random {
            sql.push_str(" ORDER BY rand()");
        } else if compiled.order.is_empty() {
            if self.shared_tables {
                sql.push_str(" ORDER BY tenant DESC, time DESC, id DESC");
            } else {
                sql.push_str(" ORDER BY time DESC, id DESC");
            }
        } else {
            let parts: Vec<String> = compiled
                .order
                .iter()
                .map(|(a, asc)| {
                    format!(
                        "{} {}",
                        escape_identifier(a),
                        if *asc { "ASC" } else { "DESC" }
                    )
                })
                .collect();
            sql.push_str(" ORDER BY ");
            sql.push_str(&parts.join(", "));
        }
        if let Some(limit) = compiled.limit {
            write!(sql, " LIMIT {limit}").expect("writing to a String cannot fail");
        }
        if let Some(offset) = compiled.offset {
            write!(sql, " OFFSET {offset}").expect("writing to a String cannot fail");
        }
        sql.push_str(" FORMAT JSONEachRow");
        let body = self.query(&sql, &HashMap::new())?;
        let mut logs = Vec::new();
        for line in body.lines() {
            if line.trim().is_empty() {
                continue;
            }
            let Value::Object(map) =
                serde_json::from_str(line).map_err(|e| AuditError::message(e.to_string()))?
            else {
                continue;
            };
            logs.push(self.row_to_log(map));
        }
        Ok(logs)
    }

    fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let filtered: Vec<Query> = queries
            .iter()
            .filter(|q| {
                !matches!(
                    q.get_method(),
                    Query::TYPE_LIMIT
                        | Query::TYPE_OFFSET
                        | Query::TYPE_CURSOR_AFTER
                        | Query::TYPE_CURSOR_BEFORE
                        | Query::TYPE_ORDER_ASC
                        | Query::TYPE_ORDER_DESC
                        | Query::TYPE_ORDER_RANDOM
                        | Query::TYPE_SELECT
                )
            })
            .cloned()
            .collect();
        let compiled = self.compile(&filtered)?;
        let table = format!(
            "{}.{}",
            escape_identifier(&self.database),
            escape_identifier(&self.get_table_name())
        );
        let expr = match max {
            Some(m) => format!("least(count(), {m})"),
            None => "count()".into(),
        };
        let mut sql = format!("SELECT {expr} AS c FROM {table} WHERE 1=1");
        for w in &compiled.where_sql {
            sql.push_str(" AND ");
            sql.push_str(w);
        }
        if self.shared_tables {
            if let Some(tenant) = self.tenant {
                write!(sql, " AND {} = {tenant}", escape_identifier("tenant"))
                    .expect("writing to a String cannot fail");
            }
        }
        sql.push_str(" FORMAT JSONEachRow");
        let body = self.query(&sql, &HashMap::new())?;
        let line = body.lines().next().unwrap_or("{\"c\":0}");
        let v: Value = serde_json::from_str(line).unwrap_or(json!({"c": 0}));
        Ok(v.get("c").and_then(Value::as_i64).unwrap_or(0))
    }

    fn ping(&self) -> bool {
        let url = format!("{}/ping", self.origin());
        clickhouse_request(&self.client, Method::GET, &url, &[], Vec::new())
            .map(|(status, _)| status == 200)
            .unwrap_or(false)
    }
}

impl ClickHouse {
    fn create_batch_rows(&self, rows: &[Value]) -> Result<()> {
        let table = format!(
            "{}.{}",
            escape_identifier(&self.database),
            escape_identifier(&self.get_table_name())
        );
        let sql = format!("INSERT INTO {table} FORMAT JSONEachRow");
        self.insert_json_rows(&sql, rows)
    }
}

impl SqlAdapter for ClickHouse {
    fn get_attributes(&self) -> Vec<Map<String, Value>> {
        clickhouse_attributes()
    }

    fn get_indexes(&self) -> Vec<Map<String, Value>> {
        clickhouse_indexes()
    }

    fn get_column_definition(&self, id: &str) -> Result<String> {
        let attribute = self
            .get_attribute(id)
            .ok_or_else(|| AuditError::message(format!("Attribute {id} not found")))?;
        let type_ = if attribute.get("type").and_then(Value::as_str) == Some(VAR_DATETIME) {
            "DateTime64(3)"
        } else {
            "String"
        };
        let required = attribute
            .get("required")
            .and_then(Value::as_bool)
            .unwrap_or(false);
        if type_ == "String" && LOW_CARDINALITY_COLUMNS.contains(&id) {
            let column_type = if required {
                "LowCardinality(String)"
            } else {
                "LowCardinality(Nullable(String))"
            };
            return Ok(format!("{id} {column_type}"));
        }
        let column_type = if required {
            type_.to_owned()
        } else {
            format!("Nullable({type_})")
        };
        Ok(format!("{id} {column_type}"))
    }
}

#[derive(Default)]
struct Compiled {
    where_sql: Vec<String>,
    order: Vec<(String, bool)>,
    random: bool,
    limit: Option<i64>,
    offset: Option<i64>,
    cursor: Option<QueryValue>,
    cursor_before: bool,
    select: Option<Vec<String>>,
}

fn clickhouse_attributes() -> Vec<Map<String, Value>> {
    let mut parent = default_attributes();
    for attr in &mut parent {
        if attr.get("$id").and_then(Value::as_str) == Some("userId") {
            attr.insert("$id".into(), json!("actorId"));
            break;
        }
    }
    let extra_required = [
        "actorType",
        "resourceType",
        "resourceId",
        "projectId",
        "projectInternalId",
        "teamId",
        "teamInternalId",
        "hostname",
    ];
    let extra = [
        "actorType",
        "actorInternalId",
        "resourceParent",
        "resourceType",
        "resourceId",
        "resourceInternalId",
        "country",
        "city",
        "continentCode",
        "subdivisions",
        "isp",
        "autonomousSystemNumber",
        "autonomousSystemOrganization",
        "connectionType",
        "connectionUsageType",
        "connectionOrganization",
        "projectId",
        "projectInternalId",
        "teamId",
        "teamInternalId",
        "hostname",
        "sdk",
        "sdkVersion",
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
    ];
    for id in extra {
        parent.push(string_attr(id, extra_required.contains(&id)));
    }
    parent
}

fn clickhouse_indexes() -> Vec<Map<String, Value>> {
    let mut parent = default_indexes();
    for index in &mut parent {
        if index.get("$id").and_then(Value::as_str) == Some("idx_userId_event") {
            index.insert("$id".into(), json!("idx_actorId_event"));
            index.insert("attributes".into(), json!(["actorId", "event"]));
            break;
        }
    }
    let extra = [
        (
            "_key_actor_internal_and_event",
            ["actorInternalId", "event"].as_slice(),
        ),
        ("_key_project_internal_id", &["projectInternalId"]),
        ("_key_team_internal_id", &["teamInternalId"]),
        ("_key_actor_internal_id", &["actorInternalId"]),
        ("_key_actor_type", &["actorType"]),
        ("_key_country", &["country"]),
        ("_key_hostname", &["hostname"]),
        ("_key_sdk", &["sdk"]),
    ];
    for (id, attrs) in extra {
        let mut m = Map::new();
        m.insert("$id".into(), json!(id));
        m.insert("type".into(), json!(INDEX_KEY));
        m.insert("attributes".into(), json!(attrs));
        m.insert("lengths".into(), json!([]));
        m.insert("orders".into(), json!([]));
        parent.push(m);
    }
    parent
}

fn string_attr(id: &str, required: bool) -> Map<String, Value> {
    let mut m = Map::new();
    m.insert("$id".into(), json!(id));
    m.insert("type".into(), json!(VAR_STRING));
    m.insert("size".into(), json!(LENGTH_KEY));
    m.insert("required".into(), json!(required));
    m.insert("default".into(), Value::Null);
    m.insert("signed".into(), json!(true));
    m.insert("array".into(), json!(false));
    m.insert("filters".into(), json!([]));
    m.insert("format".into(), json!(""));
    m
}

fn validate_host(host: &str) -> Result<()> {
    let validator = Hostname::new();
    if !validator.is_valid(&json!(host)) {
        return Err(AuditError::message(
            "ClickHouse host is not a valid hostname or IP address",
        ));
    }
    Ok(())
}

fn validate_identifier(identifier: &str, type_name: &str) -> Result<()> {
    if identifier.is_empty() || identifier == "0" {
        return Err(AuditError::message(format!("{type_name} cannot be empty")));
    }
    if identifier.len() > 255 {
        return Err(AuditError::message(format!(
            "{type_name} cannot exceed 255 characters"
        )));
    }
    let re = Regex::new(r"^[a-zA-Z_]\w*$").expect("identifier regex");
    if !re.is_match(identifier) {
        return Err(AuditError::message(format!(
            "{type_name} must start with a letter or underscore and contain only alphanumeric characters and underscores"
        )));
    }
    if SQL_KEYWORDS
        .iter()
        .any(|k| k.eq_ignore_ascii_case(identifier))
    {
        return Err(AuditError::message(format!(
            "{type_name} cannot be a reserved SQL keyword"
        )));
    }
    Ok(())
}

fn escape_identifier(identifier: &str) -> String {
    format!("`{}`", identifier.replace('`', "``"))
}

fn translate_attr(attribute: &str) -> &str {
    match attribute {
        "userId" => "actorId",
        "userType" => "actorType",
        "userInternalId" => "actorInternalId",
        other => other,
    }
}

fn method_label(method: &str) -> String {
    let mut chars = method.chars();
    match chars.next() {
        Some(c) => format!("{}{}", c.to_uppercase(), chars.as_str()),
        None => method.to_owned(),
    }
}

fn sql_string(value: &str) -> String {
    format!("'{}'", value.replace('\'', "''"))
}

fn sql_literal(value: &QueryValue) -> String {
    match value {
        QueryValue::Null => "NULL".into(),
        QueryValue::Bool(true) => "1".into(),
        QueryValue::Bool(false) => "0".into(),
        QueryValue::Int(n) => n.to_string(),
        QueryValue::UInt(n) => n.to_string(),
        QueryValue::Float(n) => n.to_string(),
        QueryValue::String(s) => sql_string(s),
        other => sql_string(&other.php_to_string()),
    }
}

fn cmp_clause(attr: &str, op: &str, values: &[QueryValue]) -> Result<String> {
    let lit = values
        .first()
        .map(sql_literal)
        .ok_or_else(|| AuditError::message("comparison requires a value"))?;
    Ok(format!("{} {op} {lit}", escape_identifier(attr)))
}

fn between_clause(attr: &str, values: &[QueryValue], not: bool) -> Result<String> {
    let start = values
        .first()
        .map(sql_literal)
        .ok_or_else(|| AuditError::message("between requires values"))?;
    let end = values
        .get(1)
        .map(sql_literal)
        .ok_or_else(|| AuditError::message("between requires values"))?;
    let col = escape_identifier(attr);
    Ok(if not {
        format!("NOT ({col} BETWEEN {start} AND {end})")
    } else {
        format!("{col} BETWEEN {start} AND {end}")
    })
}

fn like_clause(
    attr: &str,
    values: &[QueryValue],
    not: bool,
    prefix: &str,
    suffix: &str,
) -> Result<String> {
    let col = escape_identifier(attr);
    let mut parts = Vec::new();
    for v in values {
        let raw = v
            .php_to_string()
            .replace('\\', "\\\\")
            .replace('%', "\\%")
            .replace('_', "\\_");
        let pat = sql_string(&format!("{prefix}{raw}{suffix}"));
        if not {
            parts.push(format!("{col} NOT LIKE {pat}"));
        } else {
            parts.push(format!("{col} LIKE {pat}"));
        }
    }
    if parts.is_empty() {
        return Err(AuditError::message(
            "contains queries require at least one value.",
        ));
    }
    let joined = if not {
        parts.join(" AND ")
    } else {
        parts.join(" OR ")
    };
    Ok(format!("({joined})"))
}

fn time_queries(after: Option<DateTime<Utc>>, before: Option<DateTime<Utc>>) -> Vec<Query> {
    match (after, before) {
        (Some(a), Some(b)) => vec![Query::between("time", format_db(a), format_db(b))],
        (Some(a), None) => vec![Query::greater_than("time", format_db(a))],
        (None, Some(b)) => vec![Query::less_than("time", format_db(b))],
        (None, None) => vec![],
    }
}

fn format_db(dt: DateTime<Utc>) -> String {
    dt.format("%Y-%m-%d %H:%M:%S%.3f").to_string()
}

fn now_db() -> String {
    Utc::now().format("%Y-%m-%d %H:%M:%S%.3f").to_string()
}

fn uniqid() -> String {
    format!(
        "{:x}{:x}",
        Utc::now().timestamp_micros(),
        rand::random::<u32>()
    )
}

fn urlencoding_query(sql: &str) -> String {
    let mut out = String::new();
    for b in sql.as_bytes() {
        match *b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(*b as char);
            }
            b' ' => out.push('+'),
            _ => write!(out, "%{b:02X}").expect("writing to a String cannot fail"),
        }
    }
    out
}

fn clickhouse_request(
    client: &Client<curl::Client>,
    method: Method,
    url: &str,
    headers: &[(&str, &str)],
    body: Vec<u8>,
) -> Result<(u16, String)> {
    let mut builder = Request::builder().method(method).uri(url);
    for (key, value) in headers {
        builder = builder.header(*key, *value);
    }
    let request = builder
        .body(Bytes::from(body))
        .map_err(|err| AuditError::message(err.to_string()))?;
    let response = client
        .send_request(request)
        .map_err(|err| AuditError::message(err.to_string()))?;
    let status = response.status().as_u16();
    let text = String::from_utf8_lossy(response.body()).into_owned();
    Ok((status, text))
}

fn encode_clickhouse_form(sql: &str, params: &HashMap<String, String>) -> (String, Vec<u8>) {
    let boundary = "----UtopiaClickHouseBoundary";
    let mut out = Vec::new();
    write_form_field(&mut out, boundary, "query", sql);
    for (key, value) in params {
        write_form_field(&mut out, boundary, &format!("param_{key}"), value);
    }
    out.extend_from_slice(format!("--{boundary}--\r\n").as_bytes());
    (format!("multipart/form-data; boundary={boundary}"), out)
}

fn write_form_field(out: &mut Vec<u8>, boundary: &str, name: &str, value: &str) {
    out.extend_from_slice(format!("--{boundary}\r\n").as_bytes());
    out.extend_from_slice(
        format!("Content-Disposition: form-data; name=\"{name}\"\r\n\r\n").as_bytes(),
    );
    out.extend_from_slice(value.as_bytes());
    out.extend_from_slice(b"\r\n");
}
