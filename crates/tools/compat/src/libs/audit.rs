//! utopia-audit: the twin of `tests/compat/audit/Adapter.php`.
//!
//! Adapters live in the session as [`AnyAdapter`]; an audit handle names its
//! adapter's handle, so an `Audit` is built on the adapter at each call and
//! sees every change made to it (as PHP's shares the object).

use std::collections::VecDeque;
use std::sync::{Arc, Mutex};

use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use php_std::datetime::{DateTime, TzInfo};
use php_std::types::KeyRef;
use php_std::{Array, ArrayKey, Str, Value};
use serde_json::{Map, Value as Json, json};
use utopia_audit::adapter::clickhouse::{ClickHouse, Options};
use utopia_audit::adapter::database::Database as DatabaseAdapter;
use utopia_audit::schema::Sql;
use utopia_audit::{Adapter, Audit, Log, Page, Query, Range, query};
use utopia_cache::{Cache, None as NoCache};
use utopia_client::{Request, Response};
use utopia_database::adapter::memory::Memory;
use utopia_database::adapter::sql::{Engine, Sql as SqlAdapter};
use utopia_database::{Database, Index};
use utopia_query::{Method, Object, Value as QValue};

use crate::adapter::{Fault, OpResult, Outcome, Session, bytes_value, float_value};
use crate::bench::Bench;

pub const OPS: &[&str] = &[
    "clickhouse.new",
    "database.new",
    "clickhouse.call",
    "fake.respond",
    "fake.fail",
    "fake.requests",
    "clock.sleep",
    "audit.new",
    "audit.get_adapter",
    "audit.setup",
    "audit.log",
    "audit.log_batch",
    "audit.get_log_by_id",
    "audit.get_logs_by_user",
    "audit.count_logs_by_user",
    "audit.get_logs_by_resource",
    "audit.count_logs_by_resource",
    "audit.get_logs_by_user_and_events",
    "audit.count_logs_by_user_and_events",
    "audit.get_logs_by_resource_and_events",
    "audit.count_logs_by_resource_and_events",
    "audit.cleanup",
    "audit.find",
    "audit.count",
    "audit.ping",
    "log.read",
    "log.attribute",
    "log.set",
    "log.remove",
    "log.is_set",
    "query.new",
    "query.equal",
    "query.less_than",
    "query.greater_than",
    "query.between",
    "query.parse",
    "query.parse_queries",
    "query.to_string",
    "query.get_value",
    "adapter.get_name",
    "adapter.setup",
    "adapter.ping",
    "adapter.create",
    "adapter.create_batch",
    "adapter.get_by_id",
    "adapter.get_by_user",
    "adapter.count_by_user",
    "adapter.get_by_resource",
    "adapter.count_by_resource",
    "adapter.get_by_user_and_events",
    "adapter.count_by_user_and_events",
    "adapter.get_by_resource_and_events",
    "adapter.count_by_resource_and_events",
    "adapter.cleanup",
    "adapter.find",
    "adapter.count",
    "adapter.get_attributes",
    "adapter.get_attribute_documents",
    "adapter.get_indexes",
    "adapter.get_index_documents",
    "adapter.get_collection_name",
    "adapter.get_attribute",
    "adapter.get_column_definition",
    "adapter.get_all_column_definitions",
    "adapter.parse_resource",
    "adapter.set_namespace",
    "adapter.get_namespace",
    "adapter.set_database",
    "adapter.set_table",
    "adapter.get_table",
    "adapter.set_secure",
    "adapter.set_tenant",
    "adapter.get_tenant",
    "adapter.set_shared_tables",
    "adapter.is_shared_tables",
    "adapter.set_async_cleanup",
    "adapter.is_async_cleanup",
    "adapter.set_retention",
    "adapter.get_retention",
];

pub const BENCHES: &[(&str, &str)] = &[
    (
        "clickhouse_find",
        "Find a page of logs on ClickHouse: filters and an order compiled to typed parameters, the multipart request, and 10 JSON rows parsed into logs (a stub transport answers)",
    ),
    (
        "clickhouse_create_batch",
        "Insert 10 logs on ClickHouse: rows built from the logs (legacy names, data, parsed resources) and the JSONEachRow request (a stub transport answers)",
    ),
    (
        "database_get_by_user",
        "Read a page of 25 logs of a user through the Database adapter, on the in-memory database",
    ),
    ("log_getters", "Read the fields of a log through its getters"),
    ("parse_resource", "Split a resource path into its type, id and parent"),
    ("clickhouse_count", "Count a user's logs on the ClickHouse service: one HTTP round trip"),
];

/// Answers every request with 200 and the same body, for the benchmarks:
/// `StubClient` in `Adapter.php`.
#[derive(Clone)]
pub struct StubClient {
    body: bytes::Bytes,
    options: utopia_client::Options,
}

impl utopia_client::Adapter for StubClient {
    async fn send(&self, _request: Request) -> Result<Response, utopia_client::Error> {
        Ok(Response::new(200)?.with_body(self.body.clone()))
    }

    async fn stream<S: utopia_client::Sink>(
        &self,
        request: Request,
        sink: &mut S,
    ) -> Result<Response, utopia_client::Error> {
        let response = self.send(request).await?;
        sink.write(response.bytes());
        Ok(response)
    }

    fn options(&self) -> &utopia_client::Options {
        &self.options
    }

    fn with_options(&self, options: utopia_client::Options) -> StubClient {
        StubClient { body: self.body.clone(), options }
    }
}

fn stub(body: String) -> R<ClickHouse<StubClient>> {
    let options = Options { username: "user".into(), password: "secret".into(), port: 8123, secure: false };
    let client = StubClient { body: body.into(), options: utopia_client::Options::default() };
    ClickHouse::with_client("clickhouse", options, client).map_err(|e| Fault::new(e.message()))
}

/// A ClickHouse `FORMAT JSON` answer of 10 rows (as `Adapter.php` builds it).
fn bench_rows() -> String {
    let rows: Vec<Json> = (0..10)
        .map(|i| {
            json!({
                "id": format!("6a{i:011x}.{i:08}"), "actorId": format!("user{i}"), "event": "documents.update",
                "resource": format!("database/db1/collection/c1/document/{i}"), "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6)",
                "ip": format!("203.0.113.{i}"), "time": format!("2025-06-15 12:{i:02}:00.000"), "actorType": "user", "resourceType": "document",
                "resourceId": i.to_string(), "projectId": "p1", "country": "us", "data": format!("{{\"key\":\"value\",\"n\":{i}}}"),
            })
        })
        .collect();
    json!({ "meta": [], "data": rows, "rows": 10 }).to_string()
}

/// Batch logs with the ClickHouse columns (as `Adapter.php` builds them).
fn bench_batch(count: usize) -> R<Vec<Array>> {
    (0..count)
        .map(|i| {
            plain_array(&json!({
                "id": format!("log-{i}"), "actorId": "user1", "event": "documents.update",
                "resource": format!("database/db1/collection/c1/document/{i}"), "userAgent": "Mozilla/5.0", "ip": "203.0.113.7",
                "time": format!("2025-06-15 12:{:02}:00.000", i % 60),
                "data": {"key": "value", "projectInternalId": "1", "teamId": "t1", "teamInternalId": "1", "hostname": "example.org"},
                "actorType": "user", "projectId": "p1",
            }))
        })
        .collect()
}

fn bench_fault(e: utopia_audit::Error) -> Fault {
    Fault::new(e.message())
}

pub async fn bench(name: &str, session: &mut Session) -> Result<Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    Ok(match name {
        "clickhouse_find" => {
            let adapter = Rc::new(stub(bench_rows())?);
            let queries: Rc<[Query]> = Rc::from(vec![
                query::equal("actorId", "user1"),
                query::greater_than("time", "2025-06-15 00:00:00.000"),
                query::equal(
                    "event",
                    QValue::Array(utopia_query::Array::from_list(vec![
                        QValue::from("documents.update"),
                        QValue::from("documents.create"),
                    ])),
                ),
                Query::order_desc("time", None),
                Query::limit(10),
            ]);
            Bench::future(move || {
                let (adapter, queries) = (adapter.clone(), queries.clone());
                async move {
                    black_box(adapter.find(&queries).await.ok());
                }
            })
        }
        "clickhouse_create_batch" => {
            let adapter = Rc::new(stub(String::new())?);
            let logs = Rc::new(bench_batch(10)?);
            Bench::future(move || {
                let (adapter, logs) = (adapter.clone(), logs.clone());
                async move {
                    black_box(adapter.create_batch((*logs).clone()).await.ok());
                }
            })
        }
        "database_get_by_user" => {
            let args = json!({ "engine": "memory" });
            let handle = match database(&args, session).await? {
                Outcome::Ok(handle) => handle,
                Outcome::Err { message, .. } => return Err(Fault::new(message.to_string())),
            };
            let AnyAdapter::Database(adapter) = session.get::<AnyAdapter>(&handle)? else {
                return Err(Fault::new("not a database adapter"));
            };
            let adapter = Rc::new(adapter.clone());
            adapter.setup().await.map_err(bench_fault)?;
            let logs = (0..50)
                .map(|i| {
                    plain_array(&json!({
                        "userId": "ada", "event": "documents.update", "resource": format!("database/db1/collection/c1/document/{i}"),
                        "userAgent": "Mozilla/5.0", "ip": "203.0.113.7", "time": format!("2025-06-15 12:{i:02}:00.000"), "data": {"key": "value"},
                    }))
                })
                .collect::<R<Vec<_>>>()?;
            adapter.create_batch(logs).await.map_err(bench_fault)?;
            Bench::future(move || {
                let adapter = adapter.clone();
                async move {
                    black_box(adapter.get_by_user("ada", Range::default(), Page::default()).await.ok());
                }
            })
        }
        "log_getters" => {
            let row: Json =
                serde_json::from_str::<Json>(&bench_rows()).map_err(|e| Fault::new(e.to_string()))?["data"][0].clone();
            let attributes = plain_array(&row)?;
            Bench::sync(move || {
                let log = Log::new(black_box(attributes.clone()));
                let length = log.id().len()
                    + log.event().len()
                    + log.resource().len()
                    + log.user_agent().len()
                    + log.ip().len()
                    + log.time().len()
                    + log.actor_id().map_or(0, str::len)
                    + log.sdk().map_or(0, str::len)
                    + log.data().len();
                black_box(length as i64 + log.tenant().unwrap_or(0));
            })
        }
        "parse_resource" => Bench::sync(|| {
            black_box(utopia_audit::schema::parse_resource(black_box(
                "database/6978484940ff05762e1a/collection/watch_history/document/6a06d9a7001c3cd05d20",
            )));
        }),
        "clickhouse_count" => {
            let (host, user, password, port) = service(session.service("audit-clickhouse")?)?;
            let options = Options { username: user, password, port, secure: false };
            let ns = session.ns.clone();
            create_database(&host, &options, &ns).await?;
            let mut adapter = ClickHouse::new(&host, options).map_err(bench_fault)?;
            adapter.set_database(&ns).map_err(bench_fault)?;
            adapter.setup().await.map_err(bench_fault)?;
            adapter.create_batch(bench_batch(10)?).await.map_err(bench_fault)?;
            let adapter = Rc::new(adapter);
            let queries: Rc<[Query]> = Rc::from(vec![query::equal("actorId", "user1")]);
            Bench::future(move || {
                let (adapter, queries) = (adapter.clone(), queries.clone());
                async move {
                    black_box(adapter.count(&queries, None).await.ok());
                }
            })
        }
        other => return Err(Fault::new(format!("audit: unknown benchmark `{other}`"))),
    })
}

type R<T> = Result<T, Fault>;

/// Any adapter a case builds.
enum AnyAdapter {
    Database(DatabaseAdapter),
    ClickHouse(Box<ClickHouse>),
    Fake(Box<ClickHouse<Fake>>),
}

/// An audit: the handle of its adapter.
struct AuditHandle(Json);

macro_rules! with_adapter {
    ($adapter:expr, $a:ident => $body:expr) => {
        match $adapter {
            AnyAdapter::Database($a) => $body,
            AnyAdapter::ClickHouse(boxed) => {
                let $a = &**boxed;
                $body
            }
            AnyAdapter::Fake(boxed) => {
                let $a = &**boxed;
                $body
            }
        }
    };
}

macro_rules! with_clickhouse {
    ($adapter:expr, $a:ident => $body:expr) => {
        match $adapter {
            AnyAdapter::ClickHouse($a) => $body,
            AnyAdapter::Fake($a) => $body,
            AnyAdapter::Database(_) => return fault("not a ClickHouse adapter"),
        }
    };
}

fn fault<T>(message: impl Into<String>) -> R<T> {
    Err(Fault::new(message))
}

fn args<'a>(args: &'a Json, key: &str) -> Option<&'a Json> {
    args.get(key).filter(|v| !v.is_null())
}

fn string(args: &Json, key: &str) -> R<String> {
    match args.get(key) {
        Some(Json::String(s)) => Ok(s.clone()),
        other => fault(format!("`{key}` must be a string, got {other:?}")),
    }
}

fn int(args: &Json, key: &str) -> R<Option<i64>> {
    match args.get(key) {
        None | Some(Json::Null) => Ok(None),
        Some(v) => v.as_i64().map(Some).ok_or_else(|| Fault::new(format!("`{key}` must be an integer"))),
    }
}

fn boolean(args: &Json, key: &str) -> R<bool> {
    Ok(args.get(key).and_then(Json::as_bool).unwrap_or(false))
}

fn strings(args: &Json, key: &str) -> R<Vec<String>> {
    match args.get(key) {
        Some(Json::Array(items)) => items
            .iter()
            .map(|v| v.as_str().map(str::to_owned).ok_or_else(|| Fault::new(format!("`{key}` holds strings"))))
            .collect(),
        _ => fault(format!("`{key}` must be a list of strings")),
    }
}

/// An error result.
fn error(e: &utopia_audit::Error) -> Outcome {
    Outcome::err_bytes(e.php_class(), &e.message_bytes())
}

fn outcome<T>(r: utopia_audit::Result<T>, f: impl FnOnce(T) -> Json) -> Outcome {
    match r {
        Ok(v) => Outcome::Ok(f(v)),
        Err(e) => error(&e),
    }
}

/// A plain value of an argument (`Codec::decode`), `$now` tags resolved.
fn plain(json: &Json) -> R<Value> {
    Ok(match json {
        Json::Null => Value::Null,
        Json::Bool(b) => Value::Bool(*b),
        Json::Number(n) => match n.as_i64() {
            Some(i) => Value::Int(i),
            None => Value::Float(n.as_f64().unwrap_or(0.0)),
        },
        Json::String(s) => Value::Str(Str::from(s.as_str())),
        Json::Array(items) => Value::Array(Array::from_list(items.iter().map(plain).collect::<R<Vec<_>>>()?)),
        Json::Object(o) if o.is_empty() => Value::Object(Array::new()),
        Json::Object(o) => {
            if o.len() == 1 {
                if let Some(b) = o.get("$bytes").and_then(Json::as_str) {
                    return Ok(Value::Str(Str::from(BASE64.decode(b).map_err(|_| Fault::new("invalid $bytes"))?)));
                }
                if let Some(f) = o.get("$float").and_then(Json::as_str) {
                    return Ok(Value::Float(match f {
                        "INF" => f64::INFINITY,
                        "-INF" => f64::NEG_INFINITY,
                        "NAN" => f64::NAN,
                        _ => return fault("invalid $float"),
                    }));
                }
            }
            if let Some(seconds) = o.get("$now").and_then(Json::as_i64)
                && o.len() <= 2
            {
                let mut date = DateTime::new(b"now", None, &TzInfo::utc()).map_err(|e| Fault::new(e.message()))?;
                let formatted =
                    utopia_database::datetime::add_seconds(&mut date, seconds).map_err(|e| Fault::new(e.message()))?;
                let text = if o.get("format").and_then(Json::as_str) == Some("tz") {
                    utopia_database::datetime::format_tz(Some(&formatted)).unwrap_or_default()
                } else {
                    formatted
                };
                return Ok(Value::Str(Str::from(text.as_str())));
            }
            let mut out = Array::with_capacity(o.len());
            for (k, v) in o {
                out.set(ArrayKey::normalize(Str::from(k.as_str())), plain(v)?);
            }
            Value::Array(out)
        }
    })
}

fn plain_array(json: &Json) -> R<Array> {
    match plain(json)? {
        Value::Array(a) => Ok(a),
        Value::Object(a) if a.is_empty() => Ok(Array::new()),
        other => fault(format!("expected an array, got {}", other.type_name())),
    }
}

/// A query value: a plain value, with `{"$log": {...}}` a `Log`.
fn qvalue(json: &Json) -> R<QValue> {
    if let Json::Object(o) = json
        && o.len() == 1
        && let Some(log) = o.get("$log")
    {
        return Ok(QValue::Ext(Object::Row {
            class: "Utopia\\Audit\\Log".into(),
            fields: to_query(&plain_array(log)?),
        }));
    }
    Ok(match json {
        Json::Array(items) => {
            QValue::Array(utopia_query::Array::from_list(items.iter().map(qvalue).collect::<R<Vec<_>>>()?))
        }
        Json::Object(o)
            if !o.is_empty()
                && !o.contains_key("$now")
                && !(o.len() == 1 && (o.contains_key("$bytes") || o.contains_key("$float"))) =>
        {
            let mut out = utopia_query::Array::with_capacity(o.len());
            for (k, v) in o {
                out.set(ArrayKey::normalize(Str::from(k.as_str())), qvalue(v)?);
            }
            QValue::Array(out)
        }
        other => query_value(&plain(other)?),
    })
}

fn query_value(value: &Value) -> QValue {
    match value {
        Value::Null => QValue::Null,
        Value::Bool(b) => QValue::Bool(*b),
        Value::Int(i) => QValue::Int(*i),
        Value::Float(f) => QValue::Float(*f),
        Value::Str(s) => QValue::Str(s.clone()),
        Value::Array(a) => QValue::Array(to_query(a)),
        Value::Object(a) => QValue::Object(to_query(a)),
        Value::Ext(never) => match *never {},
    }
}

fn to_query(array: &Array) -> utopia_query::Array {
    let mut out = utopia_query::Array::with_capacity(array.len());
    for (k, v) in array.iter() {
        out.set(k.to_owned(), query_value(v));
    }
    out
}

/// Queries from `{method, attribute?, values?}` objects (`new Query(...)`).
fn queries(json: Option<&Json>) -> R<Result<Vec<Query>, Outcome>> {
    let mut out = Vec::new();
    let items = match json {
        None | Some(Json::Null) => return Ok(Ok(out)),
        Some(Json::Array(items)) => items,
        Some(_) => return fault("`queries` must be a list"),
    };
    for item in items {
        match new_query(item)? {
            Ok(q) => out.push(q),
            Err(e) => return Ok(Err(e)),
        }
    }
    Ok(Ok(out))
}

fn new_query(item: &Json) -> R<Result<Query, Outcome>> {
    let method = string(item, "method")?;
    let method = match Method::from_value(&method) {
        Ok(m) => m,
        Err(e) => return Ok(Err(Outcome::err_bytes(e.php_class(), &e.message_bytes()))),
    };
    let attribute = match item.get("attribute") {
        Some(Json::String(s)) => s.clone(),
        _ => String::new(),
    };
    let values = match item.get("values") {
        None | Some(Json::Null) => utopia_query::Array::new(),
        Some(v) => match qvalue(v)? {
            QValue::Array(a) | QValue::Object(a) => a,
            _ => return fault("`values` must be a list"),
        },
    };
    Ok(Ok(query::new(method, attribute, values)))
}

/// A result as JSON (`Codec::encode`).
fn encode<X: php_std::Extension>(value: &php_std::Value<X>) -> Json {
    match value {
        php_std::Value::Null => Json::Null,
        php_std::Value::Bool(b) => Json::Bool(*b),
        php_std::Value::Int(i) => Json::from(*i),
        php_std::Value::Float(f) => float_value(*f),
        php_std::Value::Str(s) => bytes_value(s.as_bytes()),
        php_std::Value::Array(a) => encode_array(a),
        php_std::Value::Object(a) if a.is_empty() => Json::Object(Map::new()),
        php_std::Value::Object(a) => encode_object(a),
        php_std::Value::Ext(x) => encode_object(&x.to_array()),
    }
}

fn encode_array<X: php_std::Extension>(a: &php_std::Array<X>) -> Json {
    if a.is_list() {
        return Json::Array(a.values().map(encode).collect());
    }
    encode_object(a)
}

fn encode_object<X: php_std::Extension>(a: &php_std::Array<X>) -> Json {
    let mut m = Map::with_capacity(a.len());
    for (k, v) in a.iter() {
        let key = match k {
            KeyRef::Int(i) => i.to_string(),
            KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
        };
        m.insert(key, encode(v));
    }
    Json::Object(m)
}

fn encode_log(log: &Log) -> Json {
    encode_array(log.attributes())
}

fn encode_logs(logs: &[Log]) -> Json {
    Json::Array(logs.iter().map(encode_log).collect())
}

/// A query as `{method, attribute, values}`, a `Log` value as `{"$log": ...}`.
fn encode_query(q: &Query) -> Json {
    let values: Vec<Json> = q
        .values()
        .values()
        .map(|v| match v {
            QValue::Ext(Object::Row { class, fields }) if class == "Utopia\\Audit\\Log" => {
                json!({ "$log": encode_array(fields) })
            }
            other => encode(other),
        })
        .collect();
    json!({ "method": q.method().as_str(), "attribute": q.attribute(), "values": values })
}

/// A date argument (`new \DateTime($text)`), when given.
fn date(args: &Json, key: &str) -> R<Result<Option<DateTime>, Outcome>> {
    let Some(text) = self::args(args, key) else { return Ok(Ok(None)) };
    let text = text.as_str().ok_or_else(|| Fault::new(format!("`{key}` must be a date string")))?;
    Ok(match DateTime::new(text.as_bytes(), None, &TzInfo::utc()) {
        Ok(d) => Ok(Some(d)),
        Err(e) => Err(Outcome::err_bytes(e.php_class(), e.message_bytes())),
    })
}

/// `after` and `before`.
struct Dates(Option<DateTime>, Option<DateTime>);

impl Dates {
    fn range(&self) -> Range<'_> {
        Range { after: self.0.as_ref(), before: self.1.as_ref() }
    }
}

fn dates(a: &Json) -> R<Result<Dates, Outcome>> {
    let after = match date(a, "after")? {
        Ok(d) => d,
        Err(e) => return Ok(Err(e)),
    };
    let before = match date(a, "before")? {
        Ok(d) => d,
        Err(e) => return Ok(Err(e)),
    };
    Ok(Ok(Dates(after, before)))
}

fn page(a: &Json) -> R<Page> {
    Ok(Page {
        limit: int(a, "limit")?.unwrap_or(25),
        offset: int(a, "offset")?.unwrap_or(0),
        ascending: boolean(a, "ascending")?,
    })
}

macro_rules! tri {
    ($e:expr) => {
        match $e? {
            Ok(v) => v,
            Err(outcome) => return Ok(outcome),
        }
    };
}

/// A recording transport answering from a queue, as the PHP suite's
/// `Tests\Adapter\Client` does.
#[derive(Clone, Default)]
pub struct Fake {
    state: Arc<Mutex<FakeState>>,
    options: utopia_client::Options,
}

#[derive(Default)]
struct FakeState {
    requests: Vec<Request>,
    answers: VecDeque<Result<Response, utopia_client::Error>>,
}

impl Fake {
    fn respond(&self, status: u16, body: &[u8]) -> R<()> {
        let response = Response::new(status).map_err(|e| Fault::new(e.message()))?.with_body(body.to_vec());
        self.state.lock().unwrap_or_else(|e| e.into_inner()).answers.push_back(Ok(response));
        Ok(())
    }

    fn fail(&self, message: &str) {
        self.state
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .answers
            .push_back(Err(utopia_client::Error::Connection(message.to_owned())));
    }

    fn requests(&self) -> Vec<Request> {
        self.state.lock().unwrap_or_else(|e| e.into_inner()).requests.clone()
    }
}

impl utopia_client::Adapter for Fake {
    async fn send(&self, request: Request) -> Result<Response, utopia_client::Error> {
        let mut state = self.state.lock().unwrap_or_else(|e| e.into_inner());
        state.requests.push(request);
        state.answers.pop_front().unwrap_or_else(|| Err(utopia_client::Error::Network("No response queued.".into())))
    }

    async fn stream<S: utopia_client::Sink>(
        &self,
        request: Request,
        sink: &mut S,
    ) -> Result<Response, utopia_client::Error> {
        let response = self.send(request).await?;
        sink.write(response.bytes());
        Ok(response)
    }

    fn options(&self) -> &utopia_client::Options {
        &self.options
    }

    fn with_options(&self, options: utopia_client::Options) -> Fake {
        Fake { state: self.state.clone(), options }
    }
}

/// A recorded request as `fake.requests` reports it (see `Adapter.php`).
fn encode_request(request: &Request) -> Json {
    let mut headers = Map::new();
    for (name, values) in request.headers().iter() {
        if name.eq_ignore_ascii_case("Content-Type") {
            continue;
        }
        headers.insert(name.to_owned(), Json::Array(values.iter().map(|v| Json::String(v.clone())).collect()));
    }
    let content_type = request.headers().line("Content-Type");
    let body = match request.get_body() {
        utopia_client::Body::Bytes(b) => b.to_vec(),
        _ => Vec::new(),
    };
    let mut out = Map::new();
    out.insert("method".into(), Json::String(request.method().to_owned()));
    out.insert("uri".into(), Json::String(request.uri().to_string()));
    out.insert("headers".into(), Json::Object(headers));
    if let Some(boundary) = content_type.strip_prefix("multipart/form-data; boundary=") {
        let mut fields = Map::new();
        let delimiter = format!("--{boundary}");
        let text = String::from_utf8_lossy(&body).into_owned();
        for part in text.split(delimiter.as_str()) {
            let Some((head, rest)) = part.split_once("\r\n\r\n") else { continue };
            let name = head.split_once("name=\"").map_or("", |(_, n)| n.split('"').next().unwrap_or(""));
            let value = rest.get(..rest.len().saturating_sub(2)).unwrap_or("");
            fields.insert(name.to_owned(), Json::String(value.to_owned()));
        }
        out.insert("content_type".into(), Json::String("multipart/form-data".into()));
        out.insert("fields".into(), Json::Object(fields));
    } else {
        out.insert("content_type".into(), Json::String(content_type));
        out.insert("body".into(), bytes_value(&body));
    }
    Json::Object(out)
}

/// The URL parts of `http://user:password@host:port`.
fn service(url: &str) -> R<(String, String, String, i64)> {
    let rest = url.strip_prefix("http://").ok_or_else(|| Fault::new("audit-clickhouse must be an http:// URL"))?;
    let (credentials, address) = rest.trim_end_matches('/').rsplit_once('@').unwrap_or(("default:", rest));
    let (user, password) = credentials.split_once(':').unwrap_or((credentials, ""));
    let (host, port) = address.rsplit_once(':').unwrap_or((address, "8123"));
    Ok((host.to_owned(), user.to_owned(), password.to_owned(), port.parse().unwrap_or(8123)))
}

fn options(a: &Json, user: String, password: String, port: i64) -> R<Options> {
    Ok(Options {
        username: args(a, "username").and_then(Json::as_str).map_or(user, str::to_owned),
        password: args(a, "password").and_then(Json::as_str).map_or(password, str::to_owned),
        port: int(a, "port")?.unwrap_or(port),
        secure: boolean(a, "secure")?,
    })
}

/// `CREATE DATABASE IF NOT EXISTS` the case's database on the service,
/// outside the library: the adapter names its database in every request
/// (`X-ClickHouse-Database`), so it cannot create its own.
async fn create_database(host: &str, options: &Options, ns: &str) -> R<()> {
    use utopia_client::Adapter as _;
    let request = Request::new("POST", &format!("http://{host}:{}/", options.port))
        .and_then(|r| r.header("X-ClickHouse-User", &options.username))
        .and_then(|r| r.header("X-ClickHouse-Key", &options.password))
        .map_err(|e| Fault::new(e.message()))?
        .body(format!("CREATE DATABASE IF NOT EXISTS `{ns}`"));
    let response = utopia_client::Http::new().send(request).await.map_err(|e| Fault::new(e.message()))?;
    if response.status() != 200 {
        return fault(format!("clickhouse.new: {}", response.text()));
    }
    Ok(())
}

/// `clickhouse.new`.
async fn clickhouse(a: &Json, session: &mut Session) -> R<Outcome> {
    let (host, user, password, port) = match args(a, "host") {
        Some(host) => (host.as_str().unwrap_or_default().to_owned(), "default".to_owned(), String::new(), 8123),
        None => service(session.service("audit-clickhouse")?)?,
    };
    let options = options(a, user, password, port)?;
    let on_service = args(a, "host").is_none();
    let ns = session.ns.clone();
    let adapter = if boolean(a, "fake")? {
        let mut adapter = match ClickHouse::with_client(&host, options, Fake::default()) {
            Ok(adapter) => adapter,
            Err(e) => return Ok(error(&e)),
        };
        if on_service && let Err(e) = adapter.set_database(&ns) {
            return Ok(error(&e));
        }
        AnyAdapter::Fake(Box::new(adapter))
    } else {
        if on_service {
            create_database(&host, &options, &ns).await?;
        }
        let mut adapter = match ClickHouse::new(&host, options) {
            Ok(adapter) => adapter,
            Err(e) => return Ok(error(&e)),
        };
        if on_service && let Err(e) = adapter.set_database(&ns) {
            return Ok(error(&e));
        }
        AnyAdapter::ClickHouse(Box::new(adapter))
    };
    Ok(Outcome::Ok(session.handle(adapter)))
}

/// `database.new`.
async fn database(a: &Json, session: &mut Session) -> R<Outcome> {
    let engine = args(a, "engine").and_then(Json::as_str).unwrap_or("mariadb").to_owned();
    let adapter: Arc<dyn utopia_database::Adapter> = match engine.as_str() {
        "mariadb" => {
            let (dsn, user, pass) = mysql_dsn(session.service("database-mariadb")?);
            match SqlAdapter::connect(Engine::MariaDb, &dsn, Some(&user), Some(&pass)).await {
                Ok(adapter) => Arc::new(adapter),
                Err(e) => return Ok(Outcome::err_bytes(e.php_class(), e.message_bytes())),
            }
        }
        "sqlite" => {
            let dir = format!("/tmp/compat-fs/{}", session.ns);
            std::fs::create_dir_all(&dir).map_err(|e| Fault::new(format!("database.new: {e}")))?;
            match SqlAdapter::connect(Engine::Sqlite, &format!("sqlite:{dir}/audit.sqlite"), None, None).await {
                Ok(adapter) => Arc::new(adapter),
                Err(e) => return Ok(Outcome::err_bytes(e.php_class(), e.message_bytes())),
            }
        }
        "memory" => Arc::new(Memory::new()),
        other => return fault(format!("unknown engine {other}")),
    };
    let db = Database::new(adapter, Arc::new(Cache::from_dyn(Arc::new(NoCache))));
    let name = args(a, "database").and_then(Json::as_str).unwrap_or(&session.ns).to_owned();
    let namespace = args(a, "namespace").and_then(Json::as_str).unwrap_or("namespace").to_owned();
    let setup = async {
        db.set_database(&name);
        db.set_namespace(&namespace);
        if a.get("create").and_then(Json::as_bool).unwrap_or(true) && !db.exists(Some(&name)).await? {
            db.create(None).await?;
        }
        Ok::<_, utopia_database::Error>(())
    };
    if let Err(e) = setup.await {
        return Ok(Outcome::err_bytes(e.php_class(), e.message_bytes()));
    }
    Ok(Outcome::Ok(session.handle(AnyAdapter::Database(DatabaseAdapter::new(db)))))
}

fn adapter<'a>(a: &Json, session: &'a Session) -> R<&'a AnyAdapter> {
    session.get::<AnyAdapter>(a.get("adapter").unwrap_or(&Json::Null))
}

fn adapter_mut<'a>(a: &Json, session: &'a mut Session) -> R<&'a mut AnyAdapter> {
    session.get_mut::<AnyAdapter>(a.get("adapter").unwrap_or(&Json::Null))
}

fn fake<'a>(a: &Json, session: &'a Session) -> R<&'a Fake> {
    match adapter(a, session)? {
        AnyAdapter::Fake(adapter) => Ok(adapter.client()),
        _ => fault("not a ClickHouse adapter over the recording client"),
    }
}

fn audit_adapter<'a>(a: &Json, session: &'a Session) -> R<&'a AnyAdapter> {
    let AuditHandle(handle) = session.get::<AuditHandle>(a.get("audit").unwrap_or(&Json::Null))?;
    session.get::<AnyAdapter>(handle)
}

/// `mysql://user:pass@host:port` as PDO's DSN (`mysql:host=..;port=..;charset=utf8mb4`), user and password.
fn mysql_dsn(url: &str) -> (String, String, String) {
    let rest = url.strip_prefix("mysql://").unwrap_or(url).trim_end_matches('/');
    let (auth, host) = rest.rsplit_once('@').unwrap_or(("", rest));
    let (user, pass) = auth.split_once(':').unwrap_or((auth, ""));
    let (host, port) = host.rsplit_once(':').unwrap_or((host, "3306"));
    (format!("mysql:host={host};port={port};charset=utf8mb4"), user.to_owned(), pass.to_owned())
}

/// An attribute as PHP's adapter reports it (`toDocument()->getArrayCopy()`).
fn attribute_json(attribute: &utopia_audit::schema::Attribute) -> Result<Json, Fault> {
    let document = attribute.to_document().map_err(|e| Fault::new(e.message()))?;
    Ok(encode_array(&document.array_copy()))
}

/// An index as PHP's adapter reports it (`toDocument()->getArrayCopy()`).
fn index_json(index: &Index) -> Json {
    encode_array(&index.to_document().array_copy())
}

/// `adapter.<method>` on any adapter.
async fn adapter_op(method: &str, a: &Json, session: &mut Session) -> R<Outcome> {
    // The setters change the adapter; everything else reads it.
    match method {
        "set_namespace" | "set_database" | "set_table" => {
            let value = string(a, "value")?;
            let adapter = adapter_mut(a, session)?;
            return Ok(with_clickhouse!(adapter, c => {
                let r = match method {
                    "set_namespace" => c.set_namespace(&value).map(|_| ()),
                    "set_database" => c.set_database(&value).map(|_| ()),
                    _ => c.set_table(&value).map(|_| ()),
                };
                outcome(r, |_| Json::Bool(true))
            }));
        }
        "set_secure" | "set_shared_tables" | "set_async_cleanup" => {
            let value = boolean(a, "value")?;
            let adapter = adapter_mut(a, session)?;
            with_clickhouse!(adapter, c => {
                match method {
                    "set_secure" => c.set_secure(value),
                    "set_shared_tables" => c.set_shared_tables(value),
                    _ => c.set_async_cleanup(value),
                };
            });
            return Ok(Outcome::Ok(Json::Bool(true)));
        }
        "set_tenant" | "set_retention" => {
            let value = int(a, "value")?;
            let adapter = adapter_mut(a, session)?;
            return Ok(with_clickhouse!(adapter, c => {
                if method == "set_tenant" {
                    c.set_tenant(value);
                    Outcome::Ok(Json::Bool(true))
                } else {
                    outcome(c.set_retention(value).map(|_| ()), |_| Json::Bool(true))
                }
            }));
        }
        _ => {}
    }
    let adapter = adapter(a, session)?;
    match method {
        "get_namespace" => return Ok(with_clickhouse!(adapter, c => Outcome::ok(c.namespace()))),
        "get_table" => return Ok(with_clickhouse!(adapter, c => Outcome::ok(c.table()))),
        "get_tenant" => {
            return Ok(with_clickhouse!(adapter, c => Outcome::Ok(c.tenant().map_or(Json::Null, Json::from))));
        }
        "is_shared_tables" => return Ok(with_clickhouse!(adapter, c => Outcome::ok(c.is_shared_tables()))),
        "is_async_cleanup" => return Ok(with_clickhouse!(adapter, c => Outcome::ok(c.is_async_cleanup()))),
        "get_retention" => {
            return Ok(with_clickhouse!(adapter, c => Outcome::Ok(c.retention().map_or(Json::Null, Json::from))));
        }
        _ => {}
    }
    with_adapter!(adapter, x => call_adapter(x, x, method, a).await)
}

async fn call_adapter<T: Adapter + Sql + ?Sized>(adapter: &T, sql: &T, method: &str, a: &Json) -> R<Outcome> {
    Ok(match method {
        "get_name" => Outcome::ok(adapter.name()),
        "setup" => outcome(adapter.setup().await, |_| Json::Null),
        "ping" => Outcome::ok(adapter.ping().await),
        "create" => {
            outcome(adapter.create(plain_array(a.get("log").unwrap_or(&Json::Null))?).await, |l| encode_log(&l))
        }
        "create_batch" => {
            let logs = match a.get("logs") {
                Some(Json::Array(items)) => items.iter().map(plain_array).collect::<R<Vec<_>>>()?,
                _ => return fault("`logs` must be a list"),
            };
            outcome(adapter.create_batch(logs).await, Json::Bool)
        }
        "get_by_id" => {
            outcome(adapter.get_by_id(&string(a, "id")?).await, |l| l.as_ref().map_or(Json::Null, encode_log))
        }
        "get_by_user" | "get_by_resource" => {
            let dates = tri!(dates(a));
            let page = page(a)?;
            let r = if method == "get_by_user" {
                adapter.get_by_user(&string(a, "user_id")?, dates.range(), page).await
            } else {
                adapter.get_by_resource(&string(a, "resource")?, dates.range(), page).await
            };
            outcome(r, |l| encode_logs(&l))
        }
        "count_by_user" | "count_by_resource" => {
            let dates = tri!(dates(a));
            let max = int(a, "max")?;
            let r = if method == "count_by_user" {
                adapter.count_by_user(&string(a, "user_id")?, dates.range(), max).await
            } else {
                adapter.count_by_resource(&string(a, "resource")?, dates.range(), max).await
            };
            outcome(r, Json::from)
        }
        "get_by_user_and_events" | "get_by_resource_and_events" => {
            let dates = tri!(dates(a));
            let page = page(a)?;
            let events = strings(a, "events")?;
            let events: Vec<&str> = events.iter().map(String::as_str).collect();
            let r = if method == "get_by_user_and_events" {
                adapter.get_by_user_and_events(&string(a, "user_id")?, &events, dates.range(), page).await
            } else {
                adapter.get_by_resource_and_events(&string(a, "resource")?, &events, dates.range(), page).await
            };
            outcome(r, |l| encode_logs(&l))
        }
        "count_by_user_and_events" | "count_by_resource_and_events" => {
            let dates = tri!(dates(a));
            let max = int(a, "max")?;
            let events = strings(a, "events")?;
            let events: Vec<&str> = events.iter().map(String::as_str).collect();
            let r = if method == "count_by_user_and_events" {
                adapter.count_by_user_and_events(&string(a, "user_id")?, &events, dates.range(), max).await
            } else {
                adapter.count_by_resource_and_events(&string(a, "resource")?, &events, dates.range(), max).await
            };
            outcome(r, Json::from)
        }
        "cleanup" => {
            let Some(datetime) = tri!(date(a, "datetime")) else { return fault("`datetime` is required") };
            outcome(adapter.cleanup(&datetime).await, Json::Bool)
        }
        "find" => {
            let queries = tri!(queries(a.get("queries")));
            outcome(adapter.find(&queries).await, |l| encode_logs(&l))
        }
        "count" => {
            let queries = tri!(queries(a.get("queries")));
            outcome(adapter.count(&queries, int(a, "max")?).await, Json::from)
        }
        "get_attributes" => {
            Outcome::Ok(Json::Array(sql.attributes().iter().map(attribute_json).collect::<Result<_, _>>()?))
        }
        "get_indexes" => Outcome::Ok(Json::Array(sql.indexes().iter().map(index_json).collect())),
        "get_attribute_documents" => {
            Outcome::Ok(Json::Array(sql.attribute_documents().iter().map(attribute_json).collect::<Result<_, _>>()?))
        }
        "get_index_documents" => Outcome::Ok(Json::Array(sql.index_documents().iter().map(index_json).collect())),
        "get_collection_name" => Outcome::ok(sql.collection_name()),
        "get_attribute" => match sql.attribute(&string(a, "id")?) {
            Some(attribute) => Outcome::Ok(attribute_json(attribute)?),
            None => Outcome::Ok(Json::Null),
        },
        "get_column_definition" => outcome(sql.column_definition(&string(a, "id")?), Json::String),
        "get_all_column_definitions" => {
            outcome(sql.column_definitions(), |d| Json::Array(d.into_iter().map(Json::String).collect()))
        }
        "parse_resource" => {
            let resource = string(a, "resource")?;
            let r = utopia_audit::schema::parse_resource(&resource);
            Outcome::Ok(json!({ "resourceId": r.id, "resourceType": r.kind, "resourceParent": r.parent }))
        }
        other => return fault(format!("audit: unknown adapter method `{other}`")),
    })
}

/// `audit.<method>`: the same calls through `Audit`.
async fn audit_op(method: &str, a: &Json, session: &mut Session) -> R<Outcome> {
    let adapter = audit_adapter(a, session)?;
    with_adapter!(adapter, x => call_audit(&Audit::new(x), method, a).await)
}

async fn call_audit<T: Adapter>(audit: &Audit<T>, method: &str, a: &Json) -> R<Outcome> {
    Ok(match method {
        "get_adapter" => Outcome::ok(audit.adapter().name()),
        "setup" => outcome(audit.setup().await, |_| Json::Null),
        "ping" => Outcome::ok(audit.ping().await),
        "log" => {
            let user_id = args(a, "user_id").and_then(Json::as_str).map(str::to_owned);
            let data = match a.get("data") {
                None | Some(Json::Null) => Array::new(),
                Some(d) => plain_array(d)?,
            };
            let r = audit
                .log(
                    user_id.as_deref(),
                    &string(a, "event")?,
                    &string(a, "resource")?,
                    &string(a, "user_agent")?,
                    &string(a, "ip")?,
                    data,
                )
                .await;
            outcome(r, |l| encode_log(&l))
        }
        "log_batch" => {
            let events = match a.get("events") {
                Some(Json::Array(items)) => items.iter().map(plain_array).collect::<R<Vec<_>>>()?,
                _ => return fault("`events` must be a list"),
            };
            outcome(audit.log_batch(events).await, Json::Bool)
        }
        "get_log_by_id" => {
            outcome(audit.log_by_id(&string(a, "id")?).await, |l| l.as_ref().map_or(Json::Null, encode_log))
        }
        "get_logs_by_user" | "get_logs_by_resource" => {
            let dates = tri!(dates(a));
            let page = page(a)?;
            let r = if method == "get_logs_by_user" {
                audit.logs_by_user(&string(a, "user_id")?, dates.range(), page).await
            } else {
                audit.logs_by_resource(&string(a, "resource")?, dates.range(), page).await
            };
            outcome(r, |l| encode_logs(&l))
        }
        "count_logs_by_user" | "count_logs_by_resource" => {
            let dates = tri!(dates(a));
            let max = int(a, "max")?;
            let r = if method == "count_logs_by_user" {
                audit.count_logs_by_user(&string(a, "user_id")?, dates.range(), max).await
            } else {
                audit.count_logs_by_resource(&string(a, "resource")?, dates.range(), max).await
            };
            outcome(r, Json::from)
        }
        "get_logs_by_user_and_events" | "get_logs_by_resource_and_events" => {
            let dates = tri!(dates(a));
            let page = page(a)?;
            let events = strings(a, "events")?;
            let events: Vec<&str> = events.iter().map(String::as_str).collect();
            let r = if method == "get_logs_by_user_and_events" {
                audit.logs_by_user_and_events(&string(a, "user_id")?, &events, dates.range(), page).await
            } else {
                audit.logs_by_resource_and_events(&string(a, "resource")?, &events, dates.range(), page).await
            };
            outcome(r, |l| encode_logs(&l))
        }
        "count_logs_by_user_and_events" | "count_logs_by_resource_and_events" => {
            let dates = tri!(dates(a));
            let max = int(a, "max")?;
            let events = strings(a, "events")?;
            let events: Vec<&str> = events.iter().map(String::as_str).collect();
            let r = if method == "count_logs_by_user_and_events" {
                audit.count_logs_by_user_and_events(&string(a, "user_id")?, &events, dates.range(), max).await
            } else {
                audit.count_logs_by_resource_and_events(&string(a, "resource")?, &events, dates.range(), max).await
            };
            outcome(r, Json::from)
        }
        "cleanup" => {
            let Some(datetime) = tri!(date(a, "datetime")) else { return fault("`datetime` is required") };
            outcome(audit.cleanup(&datetime).await, Json::Bool)
        }
        "find" => {
            let queries = tri!(queries(a.get("queries")));
            outcome(audit.find(&queries).await, |l| encode_logs(&l))
        }
        "count" => {
            let queries = tri!(queries(a.get("queries")));
            outcome(audit.count(&queries, int(a, "max")?).await, Json::from)
        }
        other => return fault(format!("audit: unknown operation `audit.{other}`")),
    })
}

/// `clickhouse.call`: one call on a fresh adapter over the recording client.
async fn clickhouse_call(a: &Json) -> R<Outcome> {
    let client = Fake::default();
    if let Some(Json::Array(responses)) = a.get("responses") {
        for response in responses {
            match response.get("fail").and_then(Json::as_str) {
                Some(message) => client.fail(message),
                None => {
                    let status = response.get("status").and_then(Json::as_u64).unwrap_or(200) as u16;
                    let body = match response.get("body") {
                        Some(body) => match plain(body)? {
                            Value::Str(s) => s.as_bytes().to_vec(),
                            _ => return fault("`body` must be a string"),
                        },
                        None => Vec::new(),
                    };
                    client.respond(status, &body)?;
                }
            }
        }
    }
    let options = Options { username: "user".into(), password: "secret".into(), port: 8124, secure: false };
    let outcome = match ClickHouse::with_client("clickhouse", options, client.clone()) {
        Err(e) => error(&e),
        Ok(adapter) => {
            let mut session = Session::default();
            let handle = session.handle(AnyAdapter::Fake(Box::new(adapter)));
            let mut outcome = None;
            if let Some(Json::Object(config)) = a.get("config") {
                for (setting, value) in config {
                    let args = json!({ "adapter": handle, "value": value });
                    let set = adapter_op(setting, &args, &mut session).await?;
                    if matches!(set, Outcome::Err { .. }) {
                        outcome = Some(set);
                        break;
                    }
                }
            }
            match outcome {
                Some(failed) => failed,
                None => {
                    let mut args = a.get("args").cloned().unwrap_or(json!({}));
                    if let Json::Object(o) = &mut args {
                        o.insert("adapter".into(), handle);
                    }
                    adapter_op(&string(a, "method")?, &args, &mut session).await?
                }
            }
        }
    };
    let (result, error) = match outcome {
        Outcome::Ok(v) => (v, Json::Null),
        Outcome::Err { class, message } => (Json::Null, json!({ "class": class, "message": message })),
    };
    let requests: Vec<Json> = client.requests().iter().map(encode_request).collect();
    Ok(Outcome::Ok(json!({ "result": result, "error": error, "requests": requests })))
}

/// A log of `attributes`.
fn log_of(a: &Json) -> R<Log> {
    Ok(Log::new(plain_array(a.get("attributes").unwrap_or(&Json::Null))?))
}

fn opt_str(value: Option<&str>) -> Json {
    value.map_or(Json::Null, |s| Json::String(s.to_owned()))
}

fn read(log: &Log) -> Json {
    json!({
        "id": log.id(),
        "userId": opt_str(log.user_id()),
        "actorId": opt_str(log.actor_id()),
        "actorType": opt_str(log.actor_type()),
        "actorInternalId": opt_str(log.actor_internal_id()),
        "event": log.event(),
        "resource": log.resource(),
        "sdk": opt_str(log.sdk()),
        "sdkVersion": opt_str(log.sdk_version()),
        "osCode": opt_str(log.os_code()),
        "osName": opt_str(log.os_name()),
        "osVersion": opt_str(log.os_version()),
        "clientType": opt_str(log.client_type()),
        "clientCode": opt_str(log.client_code()),
        "clientName": opt_str(log.client_name()),
        "clientVersion": opt_str(log.client_version()),
        "clientEngine": opt_str(log.client_engine()),
        "clientEngineVersion": opt_str(log.client_engine_version()),
        "deviceName": opt_str(log.device_name()),
        "deviceBrand": opt_str(log.device_brand()),
        "deviceModel": opt_str(log.device_model()),
        "userAgent": log.user_agent(),
        "ip": log.ip(),
        "time": log.time(),
        "data": encode_array(&log.data()),
        "tenant": log.tenant().map_or(Json::Null, Json::from),
        "array": encode_array(log.attributes()),
    })
}

fn query_outcome(r: Result<Query, utopia_query::Error>) -> Outcome {
    match r {
        Ok(q) => Outcome::Ok(encode_query(&q)),
        Err(e) => Outcome::err_bytes(e.php_class(), &e.message_bytes()),
    }
}

pub async fn call(op: &str, a: &Json, session: &mut Session) -> OpResult {
    match op {
        "clickhouse.new" => clickhouse(a, session).await,
        "database.new" => database(a, session).await,
        "clickhouse.call" => clickhouse_call(a).await,
        "fake.respond" => {
            let status = a.get("status").and_then(Json::as_u64).unwrap_or(200) as u16;
            let body = match a.get("body") {
                Some(body) => match plain(body)? {
                    Value::Str(s) => s.as_bytes().to_vec(),
                    _ => return fault("`body` must be a string"),
                },
                None => Vec::new(),
            };
            fake(a, session)?.respond(status, &body)?;
            Ok(Outcome::Ok(Json::Null))
        }
        "fake.fail" => {
            fake(a, session)?.fail(&string(a, "message")?);
            Ok(Outcome::Ok(Json::Null))
        }
        "fake.requests" => {
            Ok(Outcome::Ok(Json::Array(fake(a, session)?.requests().iter().map(encode_request).collect())))
        }
        "clock.sleep" => {
            let ms = int(a, "ms")?.unwrap_or(0).max(0) as u64;
            utopia_async::sleep(std::time::Duration::from_millis(ms)).await;
            Ok(Outcome::Ok(Json::Null))
        }
        "audit.new" => {
            adapter(a, session)?;
            let handle = a.get("adapter").cloned().unwrap_or(Json::Null);
            Ok(Outcome::Ok(session.handle(AuditHandle(handle))))
        }
        "log.read" => Ok(Outcome::Ok(read(&log_of(a)?))),
        "log.attribute" => {
            let log = log_of(a)?;
            let default = plain(a.get("default").unwrap_or(&Json::Null))?;
            Ok(Outcome::Ok(encode(log.get(&string(a, "key")?).unwrap_or(&default))))
        }
        "log.set" => {
            let mut log = log_of(a)?;
            log.set(&string(a, "key")?, plain(a.get("value").unwrap_or(&Json::Null))?);
            Ok(Outcome::Ok(encode_log(&log)))
        }
        "log.remove" => {
            let mut log = log_of(a)?;
            log.remove(&string(a, "key")?);
            Ok(Outcome::Ok(encode_log(&log)))
        }
        "log.is_set" => Ok(Outcome::ok(log_of(a)?.is_set(&string(a, "key")?))),
        "query.new" => Ok(match new_query(a)? {
            Ok(q) => Outcome::Ok(encode_query(&q)),
            Err(e) => e,
        }),
        "query.equal" => Ok(Outcome::Ok(encode_query(&query::equal(
            string(a, "attribute")?,
            qvalue(a.get("value").unwrap_or(&Json::Null))?,
        )))),
        "query.less_than" => Ok(Outcome::Ok(encode_query(&query::less_than(
            string(a, "attribute")?,
            qvalue(a.get("value").unwrap_or(&Json::Null))?,
        )))),
        "query.greater_than" => Ok(Outcome::Ok(encode_query(&query::greater_than(
            string(a, "attribute")?,
            qvalue(a.get("value").unwrap_or(&Json::Null))?,
        )))),
        "query.between" => Ok(Outcome::Ok(encode_query(&query::between(
            string(a, "attribute")?,
            qvalue(a.get("start").unwrap_or(&Json::Null))?,
            qvalue(a.get("end").unwrap_or(&Json::Null))?,
        )))),
        "query.parse" => Ok(query_outcome(Query::parse(&string(a, "query")?, false))),
        "query.parse_queries" => {
            let items = strings(a, "queries")?;
            Ok(match Query::parse_queries(&items, false) {
                Ok(qs) => Outcome::Ok(Json::Array(qs.iter().map(encode_query).collect())),
                Err(e) => Outcome::err_bytes(e.php_class(), &e.message_bytes()),
            })
        }
        "query.to_string" => {
            let q = match new_query(a.get("query").unwrap_or(&Json::Null))? {
                Ok(q) => q,
                Err(e) => return Ok(e),
            };
            Ok(match q.to_string() {
                Ok(s) => Outcome::Ok(Json::String(s)),
                Err(e) => Outcome::err_bytes(e.php_class(), &e.message_bytes()),
            })
        }
        "query.get_value" => {
            let q = match new_query(a.get("query").unwrap_or(&Json::Null))? {
                Ok(q) => q,
                Err(e) => return Ok(e),
            };
            let default = qvalue(a.get("default").unwrap_or(&Json::Null))?;
            Ok(Outcome::Ok(encode(q.value().unwrap_or(&default))))
        }
        other => {
            if let Some(method) = other.strip_prefix("adapter.") {
                return adapter_op(method, a, session).await;
            }
            if let Some(method) = other.strip_prefix("audit.") {
                return audit_op(method, a, session).await;
            }
            fault(format!("audit: unknown operation `{other}`"))
        }
    }
}
