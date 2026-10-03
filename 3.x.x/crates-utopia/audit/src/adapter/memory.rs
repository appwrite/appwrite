//! In-memory audit adapter for tests and local use.

use std::sync::{Arc, Mutex};

use chrono::{DateTime, TimeZone, Utc};
use serde_json::{json, Map, Value};

use crate::adapter::sql::SqlAdapter;
use crate::adapter::Adapter;
use crate::error::{AuditError, Result};
use crate::log::Log;
use crate::query::Query;
use crate::query::Query as AuditQuery;
use utopia_query::value::QueryValue;

/// In-memory audit store. Not in PHP; used so default tests run without `MariaDB`.
#[derive(Debug, Clone, Default)]
pub struct Memory {
    logs: Arc<Mutex<Vec<Log>>>,
}

impl Memory {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    fn with_logs<R>(&self, f: impl FnOnce(&mut Vec<Log>) -> R) -> R {
        let mut guard = self.logs.lock().unwrap_or_else(|e| e.into_inner());
        f(&mut guard)
    }
}

impl Adapter for Memory {
    fn get_name(&self) -> &'static str {
        "Memory"
    }

    fn setup(&mut self) -> Result<()> {
        Ok(())
    }

    fn get_by_id(&self, id: &str) -> Result<Option<Log>> {
        Ok(self.with_logs(|logs| logs.iter().find(|l| l.get_id() == id).cloned()))
    }

    fn create(&mut self, mut log: Map<String, Value>) -> Result<Log> {
        if !log.contains_key("$id") && !log.contains_key("id") {
            log.insert("$id".into(), json!(uniqid()));
        } else if let Some(id) = log.remove("id") {
            log.insert("$id".into(), id);
        }
        if !log.contains_key("time") {
            log.insert("time".into(), json!(now_db()));
        }
        let created = Log::new(log);
        self.with_logs(|logs| logs.push(created.clone()));
        Ok(created)
    }

    fn create_batch(&mut self, logs: Vec<Map<String, Value>>) -> Result<bool> {
        for log in logs {
            self.create(log)?;
        }
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
        let mut q = vec![Query::equal("userId", user_id)];
        q.extend(time_query(after, before));
        q.push(order_query(ascending));
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
        let mut q = vec![Query::equal("userId", user_id)];
        q.extend(time_query(after, before));
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
        q.extend(time_query(after, before));
        q.push(order_query(ascending));
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
        q.extend(time_query(after, before));
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
            Query::equal("userId", user_id),
            Query::equal(
                "event",
                QueryValue::Array(
                    events
                        .iter()
                        .map(|e| QueryValue::String(e.clone()))
                        .collect(),
                ),
            ),
        ];
        q.extend(time_query(after, before));
        q.push(order_query(ascending));
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
            Query::equal("userId", user_id),
            Query::equal(
                "event",
                QueryValue::Array(
                    events
                        .iter()
                        .map(|e| QueryValue::String(e.clone()))
                        .collect(),
                ),
            ),
        ];
        q.extend(time_query(after, before));
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
            Query::equal(
                "event",
                QueryValue::Array(
                    events
                        .iter()
                        .map(|e| QueryValue::String(e.clone()))
                        .collect(),
                ),
            ),
        ];
        q.extend(time_query(after, before));
        q.push(order_query(ascending));
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
            Query::equal(
                "event",
                QueryValue::Array(
                    events
                        .iter()
                        .map(|e| QueryValue::String(e.clone()))
                        .collect(),
                ),
            ),
        ];
        q.extend(time_query(after, before));
        self.count(&q, max)
    }

    fn cleanup(&mut self, datetime: DateTime<Utc>) -> Result<bool> {
        self.with_logs(|logs| {
            logs.retain(|log| parse_log_time(log).map_or(true, |ts| ts >= datetime));
        });
        Ok(true)
    }

    fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        let parsed = parse_queries(queries)?;
        let mut rows = self.with_logs(|logs| {
            logs.iter()
                .filter(|log| parsed.filters.iter().all(|f| f(log)))
                .cloned()
                .collect::<Vec<_>>()
        });
        if parsed.random_order {
            if parsed.cursor.is_some() {
                return Err(AuditError::message(
                    "Cursor pagination cannot be combined with orderRandom",
                ));
            }
            if !parsed.order.is_empty() {
                return Err(AuditError::message(
                    "orderRandom cannot be combined with orderAsc/orderDesc",
                ));
            }
        } else {
            sort_logs(&mut rows, &parsed.order);
            if parsed.cursor_direction.as_deref() == Some("before") {
                rows.reverse();
            }
            if let Some(cursor) = &parsed.cursor {
                rows.retain(|log| {
                    after_cursor(
                        log,
                        cursor,
                        &parsed.order,
                        parsed.cursor_direction.as_deref(),
                    )
                });
                if parsed.cursor_direction.as_deref() == Some("before") {
                    // already filtered with flipped compare; reverse back after take
                }
            }
        }
        let offset = parsed.offset.unwrap_or(0).max(0) as usize;
        if offset < rows.len() {
            rows = rows.split_off(offset);
        } else {
            rows.clear();
        }
        if let Some(limit) = parsed.limit {
            rows.truncate(limit.max(0) as usize);
        }
        if parsed.cursor_direction.as_deref() == Some("before") {
            rows.reverse();
        }
        Ok(rows)
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
        let n = self.find(&filtered)?.len() as i64;
        Ok(max.map_or(n, |m| n.min(m)))
    }

    fn ping(&self) -> bool {
        true
    }
}

impl SqlAdapter for Memory {
    fn get_column_definition(&self, id: &str) -> Result<String> {
        let attr = self
            .get_attribute(id)
            .ok_or_else(|| AuditError::message(format!("Attribute {id} not found")))?;
        let type_ = attr.get("type").and_then(Value::as_str).unwrap_or("string");
        let size = attr.get("size").and_then(Value::as_i64).unwrap_or(0);
        if size > 0 {
            Ok(format!("{id}: {type_}({size})"))
        } else {
            Ok(format!("{id}: {type_}"))
        }
    }
}

type LogFilter = Box<dyn Fn(&Log) -> bool + Send + Sync>;

struct Parsed {
    filters: Vec<LogFilter>,
    order: Vec<(String, bool)>,
    random_order: bool,
    limit: Option<i64>,
    offset: Option<i64>,
    cursor: Option<Map<String, Value>>,
    cursor_direction: Option<String>,
}

fn parse_queries(queries: &[Query]) -> Result<Parsed> {
    let mut parsed = Parsed {
        filters: Vec::new(),
        order: Vec::new(),
        random_order: false,
        limit: None,
        offset: None,
        cursor: None,
        cursor_direction: None,
    };
    for query in queries {
        let method = query.get_method();
        let attr = translate(query.get_attribute());
        let values = query.get_values();
        if value_required(method) && values.is_empty() {
            let label = method_label(method);
            return Err(AuditError::message(format!(
                "{label} queries require at least one value."
            )));
        }
        match method {
            AuditQuery::TYPE_EQUAL => {
                let vals = values.to_vec();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    attr_matches(log, &key, &vals, MatchMode::Equal)
                }));
            }
            AuditQuery::TYPE_NOT_EQUAL => {
                let vals = values.to_vec();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    !attr_matches(log, &key, &vals, MatchMode::Equal)
                }));
            }
            AuditQuery::TYPE_LESSER => {
                let v = values.first().cloned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    cmp_attr(log, &key, v.as_ref(), std::cmp::Ordering::Less)
                }));
            }
            AuditQuery::TYPE_LESSER_EQUAL => {
                let v = values.first().cloned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    cmp_attr(log, &key, v.as_ref(), std::cmp::Ordering::Less)
                        || attr_matches(log, &key, v.as_slice(), MatchMode::Equal)
                }));
            }
            AuditQuery::TYPE_GREATER => {
                let v = values.first().cloned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    cmp_attr(log, &key, v.as_ref(), std::cmp::Ordering::Greater)
                }));
            }
            AuditQuery::TYPE_GREATER_EQUAL => {
                let v = values.first().cloned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    cmp_attr(log, &key, v.as_ref(), std::cmp::Ordering::Greater)
                        || attr_matches(log, &key, v.as_slice(), MatchMode::Equal)
                }));
            }
            AuditQuery::TYPE_BETWEEN => {
                let start = values.first().cloned();
                let end = values.get(1).cloned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    let ge = cmp_attr(log, &key, start.as_ref(), std::cmp::Ordering::Greater)
                        || attr_matches(log, &key, start.as_slice(), MatchMode::Equal);
                    let le = cmp_attr(log, &key, end.as_ref(), std::cmp::Ordering::Less)
                        || attr_matches(log, &key, end.as_slice(), MatchMode::Equal);
                    ge && le
                }));
            }
            AuditQuery::TYPE_NOT_BETWEEN => {
                let start = values.first().cloned();
                let end = values.get(1).cloned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    let ge = cmp_attr(log, &key, start.as_ref(), std::cmp::Ordering::Greater)
                        || attr_matches(log, &key, start.as_slice(), MatchMode::Equal);
                    let le = cmp_attr(log, &key, end.as_ref(), std::cmp::Ordering::Less)
                        || attr_matches(log, &key, end.as_slice(), MatchMode::Equal);
                    !(ge && le)
                }));
            }
            AuditQuery::TYPE_CONTAINS => {
                let needles: Vec<String> = values
                    .iter()
                    .map(|v| v.as_str().unwrap_or("").to_owned())
                    .collect();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    let hay = log_string(log, &key).unwrap_or_default();
                    needles.iter().any(|n| hay.contains(n.as_str()))
                }));
            }
            AuditQuery::TYPE_NOT_CONTAINS => {
                let needles: Vec<String> = values
                    .iter()
                    .map(|v| v.as_str().unwrap_or("").to_owned())
                    .collect();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    let hay = log_string(log, &key).unwrap_or_default();
                    needles.iter().all(|n| !hay.contains(n.as_str()))
                }));
            }
            AuditQuery::TYPE_IS_NULL => {
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    matches!(log.get_attribute(&key), None | Some(Value::Null))
                }));
            }
            AuditQuery::TYPE_IS_NOT_NULL => {
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    !matches!(log.get_attribute(&key), None | Some(Value::Null))
                }));
            }
            AuditQuery::TYPE_STARTS_WITH => {
                let needle = values
                    .first()
                    .and_then(QueryValue::as_str)
                    .unwrap_or("")
                    .to_owned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    log_string(log, &key).is_some_and(|s| s.starts_with(&needle))
                }));
            }
            AuditQuery::TYPE_NOT_STARTS_WITH => {
                let needle = values
                    .first()
                    .and_then(QueryValue::as_str)
                    .unwrap_or("")
                    .to_owned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    log_string(log, &key).is_some_and(|s| !s.starts_with(&needle))
                }));
            }
            AuditQuery::TYPE_ENDS_WITH => {
                let needle = values
                    .first()
                    .and_then(QueryValue::as_str)
                    .unwrap_or("")
                    .to_owned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    log_string(log, &key).is_some_and(|s| s.ends_with(&needle))
                }));
            }
            AuditQuery::TYPE_NOT_ENDS_WITH => {
                let needle = values
                    .first()
                    .and_then(QueryValue::as_str)
                    .unwrap_or("")
                    .to_owned();
                let key = attr.to_owned();
                parsed.filters.push(Box::new(move |log| {
                    log_string(log, &key).is_some_and(|s| !s.ends_with(&needle))
                }));
            }
            AuditQuery::TYPE_REGEX => {
                let pattern = values
                    .first()
                    .and_then(QueryValue::as_str)
                    .unwrap_or("")
                    .to_owned();
                let key = attr.to_owned();
                let re =
                    regex::Regex::new(&pattern).map_err(|e| AuditError::message(e.to_string()))?;
                parsed.filters.push(Box::new(move |log| {
                    log_string(log, &key).is_some_and(|s| re.is_match(&s))
                }));
            }
            AuditQuery::TYPE_ORDER_DESC => parsed.order.push((attr.to_owned(), false)),
            AuditQuery::TYPE_ORDER_ASC => parsed.order.push((attr.to_owned(), true)),
            AuditQuery::TYPE_ORDER_RANDOM => parsed.random_order = true,
            AuditQuery::TYPE_LIMIT => {
                parsed.limit = values.first().and_then(QueryValue::as_i64);
            }
            AuditQuery::TYPE_OFFSET => {
                parsed.offset = values.first().and_then(QueryValue::as_i64);
            }
            AuditQuery::TYPE_CURSOR_AFTER | AuditQuery::TYPE_CURSOR_BEFORE => {
                if parsed.cursor.is_none() {
                    if let Some(raw) = values.first() {
                        parsed.cursor = Some(normalize_cursor(raw)?);
                        parsed.cursor_direction = Some(
                            if method == AuditQuery::TYPE_CURSOR_AFTER {
                                "after"
                            } else {
                                "before"
                            }
                            .to_owned(),
                        );
                    }
                }
            }
            _ => {}
        }
    }
    Ok(parsed)
}

fn normalize_cursor(raw: &QueryValue) -> Result<Map<String, Value>> {
    match raw {
        QueryValue::Object(map) => {
            let mut out = Map::new();
            for (k, v) in map {
                out.insert(k.clone(), v.to_json());
            }
            if !out.contains_key("id") {
                if let Some(id) = out.remove("$id") {
                    out.insert("id".into(), id);
                }
            }
            Ok(out)
        }
        QueryValue::String(s) => {
            let mut m = Map::new();
            m.insert("id".into(), json!(s));
            Ok(m)
        }
        _ => Err(AuditError::message(
            "Invalid cursor value: expected ArrayObject (Log) or associative array",
        )),
    }
}

fn after_cursor(
    log: &Log,
    cursor: &Map<String, Value>,
    order: &[(String, bool)],
    direction: Option<&str>,
) -> bool {
    let mut attrs = order.to_vec();
    if !attrs.iter().any(|(a, _)| a == "id" || a == "$id") {
        let dir = attrs.last().is_some_and(|(_, d)| *d);
        attrs.push(("id".into(), dir));
    }
    // Simplified keyset: compare first order attr
    if let Some((attr, asc)) = attrs.first() {
        let mut asc = *asc;
        if direction == Some("before") {
            asc = !asc;
        }
        let log_v = if attr == "id" {
            json!(log.get_id())
        } else {
            log.get_attribute(attr).cloned().unwrap_or(Value::Null)
        };
        let cur_v = cursor.get(attr).cloned().unwrap_or(Value::Null);
        let cmp = cmp_json(&log_v, &cur_v);
        if asc {
            cmp == std::cmp::Ordering::Greater
        } else {
            cmp == std::cmp::Ordering::Less
        }
    } else {
        true
    }
}

fn sort_logs(rows: &mut [Log], order: &[(String, bool)]) {
    if order.is_empty() {
        rows.sort_by(|a, b| cmp_json(&json!(b.get_time()), &json!(a.get_time())));
        return;
    }
    rows.sort_by(|a, b| {
        for (attr, asc) in order {
            let av = if attr == "id" {
                json!(a.get_id())
            } else {
                a.get_attribute(attr).cloned().unwrap_or(Value::Null)
            };
            let bv = if attr == "id" {
                json!(b.get_id())
            } else {
                b.get_attribute(attr).cloned().unwrap_or(Value::Null)
            };
            let cmp = cmp_json(&av, &bv);
            if cmp != std::cmp::Ordering::Equal {
                return if *asc { cmp } else { cmp.reverse() };
            }
        }
        std::cmp::Ordering::Equal
    });
}

#[derive(Clone, Copy)]
enum MatchMode {
    Equal,
}

fn attr_matches(log: &Log, key: &str, values: &[QueryValue], _mode: MatchMode) -> bool {
    let actual = log_string(log, key);
    values.iter().any(|v| match v {
        QueryValue::Null => actual.is_none(),
        QueryValue::String(s) => actual.as_deref() == Some(s.as_str()),
        QueryValue::Int(n) => actual.as_deref() == Some(&n.to_string()),
        other => actual.as_deref() == Some(&other.php_to_string()),
    })
}

fn cmp_attr(log: &Log, key: &str, value: Option<&QueryValue>, want: std::cmp::Ordering) -> bool {
    let Some(value) = value else {
        return false;
    };
    let left = log.get_attribute(key).cloned().unwrap_or(Value::Null);
    let right = value.to_json();
    cmp_json(&left, &right) == want
}

fn cmp_json(a: &Value, b: &Value) -> std::cmp::Ordering {
    match (a, b) {
        (Value::String(x), Value::String(y)) => x.cmp(y),
        (Value::Number(x), Value::Number(y)) => x
            .as_f64()
            .unwrap_or(0.0)
            .partial_cmp(&y.as_f64().unwrap_or(0.0))
            .unwrap_or(std::cmp::Ordering::Equal),
        (Value::Null, Value::Null) => std::cmp::Ordering::Equal,
        _ => a.to_string().cmp(&b.to_string()),
    }
}

fn log_string(log: &Log, key: &str) -> Option<String> {
    let key = translate(key);
    match log.get_attribute(key) {
        Some(Value::String(s)) => Some(s.clone()),
        Some(Value::Null) | None => {
            if key == "userId" {
                log.get_attribute("actorId")
                    .and_then(|v| v.as_str().map(str::to_owned))
            } else if key == "id" {
                Some(log.get_id())
            } else {
                None
            }
        }
        Some(other) => Some(other.to_string()),
    }
}

fn translate(attribute: &str) -> &str {
    match attribute {
        "userId" => "userId",
        "userType" => "actorType",
        "userInternalId" => "actorInternalId",
        other => other,
    }
}

fn value_required(method: &str) -> bool {
    matches!(
        method,
        AuditQuery::TYPE_EQUAL
            | AuditQuery::TYPE_NOT_EQUAL
            | AuditQuery::TYPE_LESSER
            | AuditQuery::TYPE_LESSER_EQUAL
            | AuditQuery::TYPE_GREATER
            | AuditQuery::TYPE_GREATER_EQUAL
            | AuditQuery::TYPE_BETWEEN
            | AuditQuery::TYPE_NOT_BETWEEN
            | AuditQuery::TYPE_CONTAINS
            | AuditQuery::TYPE_NOT_CONTAINS
            | AuditQuery::TYPE_STARTS_WITH
            | AuditQuery::TYPE_NOT_STARTS_WITH
            | AuditQuery::TYPE_ENDS_WITH
            | AuditQuery::TYPE_NOT_ENDS_WITH
            | AuditQuery::TYPE_REGEX
            | AuditQuery::TYPE_SELECT
    )
}

fn method_label(method: &str) -> String {
    let mut chars = method.chars();
    match chars.next() {
        Some(c) => format!("{}{}", c.to_uppercase(), chars.as_str()),
        None => method.to_owned(),
    }
}

fn time_query(after: Option<DateTime<Utc>>, before: Option<DateTime<Utc>>) -> Vec<Query> {
    match (after, before) {
        (Some(a), Some(b)) => vec![Query::between("time", format_db(a), format_db(b))],
        (Some(a), None) => vec![Query::greater_than("time", format_db(a))],
        (None, Some(b)) => vec![Query::less_than("time", format_db(b))],
        (None, None) => vec![],
    }
}

fn order_query(ascending: bool) -> Query {
    if ascending {
        Query::order_asc("time")
    } else {
        Query::order_desc("time")
    }
}

fn parse_log_time(log: &Log) -> Option<DateTime<Utc>> {
    let s = log.get_time();
    if s.is_empty() {
        return None;
    }
    DateTime::parse_from_rfc3339(&s.replace(' ', "T"))
        .ok()
        .map(|d| d.with_timezone(&Utc))
        .or_else(|| {
            chrono::NaiveDateTime::parse_from_str(&s, "%Y-%m-%d %H:%M:%S%.f")
                .ok()
                .and_then(|n| Utc.from_local_datetime(&n).single())
        })
}

fn now_db() -> String {
    Utc::now().format("%Y-%m-%d %H:%M:%S%.3f").to_string()
}

fn format_db(dt: DateTime<Utc>) -> String {
    dt.format("%Y-%m-%d %H:%M:%S%.3f").to_string()
}

fn uniqid() -> String {
    format!(
        "{:x}.{:x}",
        Utc::now().timestamp_micros(),
        rand::random::<u32>()
    )
}
