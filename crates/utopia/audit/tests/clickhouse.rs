//! The ClickHouse adapter through its `Transport`: what it sends, what it
//! makes of the answer, and (ignored unless a server is given) a round trip
//! through a real ClickHouse.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::sync::{Arc, Mutex};

use php_std::{Array, ArrayKey, Str, Value};
use utopia_audit::adapter::clickhouse::{ClickHouse, Options};
use utopia_audit::adapter::{Request, Response, Transport};
use utopia_audit::{Audit, Page, Range};

/// Answers every request the same way and keeps what it was sent.
#[derive(Clone)]
struct Canned {
    answer: Result<Response, String>,
    sent: Arc<Mutex<Vec<Request>>>,
}

impl Canned {
    fn new(answer: Result<Response, String>) -> Canned {
        Canned { answer, sent: Arc::default() }
    }

    fn ok(body: &str) -> Canned {
        Canned::new(Ok(Response { status: 200, body: body.as_bytes().to_vec() }))
    }

    fn sent(&self) -> Vec<Request> {
        self.sent.lock().unwrap().clone()
    }
}

#[async_trait::async_trait]
impl Transport for Canned {
    async fn send(&self, request: Request) -> Result<Response, String> {
        self.sent.lock().unwrap().push(request);
        self.answer.clone()
    }
}

fn array(pairs: &[(&str, Value)]) -> Array {
    let mut array = Array::with_capacity(pairs.len());
    for (key, value) in pairs {
        array.set(ArrayKey::normalize(Str::from(*key)), value.clone());
    }
    array
}

/// A log as Cloud's audits worker builds it.
fn log(actor: &str, event: &str, time: &str) -> Array {
    array(&[
        ("actorId", Value::from(actor)),
        ("actorType", Value::from("user")),
        ("actorInternalId", Value::from("7")),
        ("event", Value::from(event)),
        ("resource", Value::from("database/d1/collection/c1")),
        ("userAgent", Value::from("Mozilla/5.0")),
        ("ip", Value::from("203.0.113.7")),
        ("country", Value::from("de")),
        ("hostname", Value::from("cloud.appwrite.io")),
        ("projectId", Value::from("p1")),
        ("projectInternalId", Value::from("42")),
        ("teamId", Value::from("t1")),
        ("teamInternalId", Value::from("9")),
        ("time", Value::from(time)),
        ("data", Value::Array(array(&[("mode", Value::from("admin"))]))),
    ])
}

fn adapter<T: Transport>(transport: T) -> ClickHouse<T> {
    let options = Options { password: "secret".into(), ..Options::default() };
    let mut adapter = ClickHouse::with_transport("clickhouse", options, transport).unwrap();
    adapter.set_database("analytics").unwrap().set_namespace("ns").unwrap();
    adapter.set_shared_tables(true).set_tenant(Some(42));
    adapter
}

fn header<'a>(request: &'a Request, name: &str) -> &'a str {
    &request.headers.iter().find(|(key, _)| key == name).unwrap_or_else(|| panic!("no {name} header")).1
}

#[utopia_async::test]
async fn a_batch_is_one_insert_of_one_json_row_per_log() {
    let transport = Canned::ok("");
    let audit = Audit::new(adapter(transport.clone()));

    let logs = vec![
        log("u1", "documents.create", "2026-11-17 10:00:00"),
        log("u2", "documents.delete", "2026-11-17 10:00:01"),
    ];
    assert!(audit.log_batch(logs).await.unwrap());

    let sent = transport.sent();
    assert_eq!(sent.len(), 1, "one request for the whole batch");
    let request = &sent[0];
    assert_eq!(request.method, "POST");
    let (base, query) = request.url.split_once("/?query=").expect("the statement travels in the URL");
    assert_eq!(base, "http://clickhouse:8123");
    assert!(query.starts_with("INSERT+INTO+%60analytics%60.%60ns_audits%60"), "{query}");
    assert!(query.ends_with("FORMAT+JSONEachRow"), "{query}");
    assert_eq!(header(request, "X-ClickHouse-User"), "default");
    assert_eq!(header(request, "X-ClickHouse-Key"), "secret");
    assert_eq!(header(request, "X-ClickHouse-Database"), "analytics");

    let rows: Vec<serde_json::Value> =
        std::str::from_utf8(&request.body).unwrap().lines().map(|line| serde_json::from_str(line).unwrap()).collect();
    assert_eq!(rows.len(), 2);
    let row = &rows[0];
    assert_eq!(row["actorId"], "u1");
    assert_eq!(row["event"], "documents.create");
    assert_eq!(row["time"], "2026-11-17 10:00:00.000");
    assert_eq!(row["tenant"], 42);
    // Parsed from the resource path, which the log does not carry apart.
    assert_eq!(row["resourceType"], "collection");
    assert_eq!(row["resourceId"], "c1");
    assert_eq!(row["resourceParent"], "database/d1");
    // What is not a column stays in `data`, as a JSON string.
    assert_eq!(serde_json::from_str::<serde_json::Value>(row["data"].as_str().unwrap()).unwrap()["mode"], "admin");
    assert_ne!(rows[0]["id"], rows[1]["id"]);
    assert_eq!(rows[1]["actorId"], "u2");
}

#[utopia_async::test]
async fn a_read_sends_its_statement_and_parameters_as_form_fields() {
    let transport = Canned::ok("3\n");
    let audit = Audit::new(adapter(transport.clone()));

    assert_eq!(audit.count_logs_by_user("u1", Range::default(), None).await.unwrap(), 3);

    let sent = transport.sent();
    let request = &sent[0];
    assert_eq!(request.url, "http://clickhouse:8123/");
    let boundary = header(request, "Content-Type").strip_prefix("multipart/form-data; boundary=").unwrap();
    let body = std::str::from_utf8(&request.body).unwrap();
    assert!(body.ends_with(&format!("--{boundary}--\r\n")), "the body closes its last part");

    let mut fields = Vec::new();
    for part in body.split(&format!("--{boundary}\r\n")).skip(1) {
        let part = part.strip_suffix(&format!("--{boundary}--\r\n")).unwrap_or(part);
        let (head, value) = part.split_once("\r\n\r\n").unwrap();
        let name = head.strip_prefix("Content-Disposition: form-data; name=\"").unwrap().strip_suffix('"').unwrap();
        fields.push((name.to_owned(), value.strip_suffix("\r\n").unwrap().to_owned()));
    }
    assert_eq!(fields[0].0, "query");
    assert!(fields[0].1.starts_with("SELECT COUNT(*)"), "{}", fields[0].1);
    let values: Vec<&str> = fields[1..].iter().map(|(_, value)| value.as_str()).collect();
    assert!(fields[1..].iter().all(|(name, _)| name.starts_with("param_")), "{fields:?}");
    assert!(values.contains(&"u1"), "the user is a parameter, not part of the statement: {fields:?}");
    assert!(values.contains(&"42"), "so is the tenant: {fields:?}");
}

#[utopia_async::test]
async fn a_refused_or_unanswered_request_is_an_error_naming_the_reason() {
    let refused = Canned::new(Ok(Response { status: 400, body: b"Code: 62. Syntax error".to_vec() }));
    let error = Audit::new(adapter(refused)).log_batch(vec![log("u1", "e", "2026-11-17 10:00:00")]).await.unwrap_err();
    assert_eq!(
        error.message(),
        "ClickHouse query execution failed: ClickHouse query failed with HTTP 400: Code: 62. Syntax error"
    );

    let unanswered = Canned::new(Err("connection refused".into()));
    let audit = Audit::new(adapter(unanswered.clone()));
    let error = audit.log_batch(vec![log("u1", "e", "2026-11-17 10:00:00")]).await.unwrap_err();
    assert_eq!(error.message(), "ClickHouse query execution failed: connection refused");
    assert_eq!(unanswered.sent().len(), 1, "an insert is not retried");
}

/// A blocking HTTP/1.0 client: enough to reach a local ClickHouse.
#[derive(Clone)]
struct Tcp;

#[async_trait::async_trait]
impl Transport for Tcp {
    async fn send(&self, request: Request) -> Result<Response, String> {
        let rest = request.url.strip_prefix("http://").ok_or("http only")?;
        let (authority, path) = rest.split_once('/').unwrap_or((rest, ""));
        let mut stream = TcpStream::connect(authority).map_err(|e| e.to_string())?;
        let mut head = format!("{} /{path} HTTP/1.0\r\nHost: {authority}\r\n", request.method);
        for (name, value) in &request.headers {
            head.push_str(&format!("{name}: {value}\r\n"));
        }
        head.push_str(&format!("Content-Length: {}\r\n\r\n", request.body.len()));
        stream.write_all(head.as_bytes()).and_then(|()| stream.write_all(&request.body)).map_err(|e| e.to_string())?;
        let mut answer = Vec::new();
        stream.read_to_end(&mut answer).map_err(|e| e.to_string())?;
        let split = answer.windows(4).position(|w| w == b"\r\n\r\n").ok_or("no response head")?;
        let status = std::str::from_utf8(&answer[..split])
            .ok()
            .and_then(|head| head.split(' ').nth(1))
            .and_then(|code| code.parse().ok())
            .ok_or("no status")?;
        Ok(Response { status, body: answer[split + 4..].to_vec() })
    }
}

/// `AUDIT_CLICKHOUSE=127.0.0.1:8123 AUDIT_CLICKHOUSE_PASSWORD=... cargo test -p utopia-audit -- --ignored`
#[utopia_async::test]
#[ignore = "needs a ClickHouse server"]
async fn logs_written_to_a_server_are_read_back() {
    let address = std::env::var("AUDIT_CLICKHOUSE").expect("AUDIT_CLICKHOUSE=host:port");
    let (host, port) = address.split_once(':').unwrap();
    let options = Options {
        password: std::env::var("AUDIT_CLICKHOUSE_PASSWORD").unwrap_or_default(),
        port: port.parse().unwrap(),
        ..Options::default()
    };
    let namespace = format!("t{}", std::process::id());
    let mut adapter = ClickHouse::with_transport(host, options, Tcp).unwrap();
    adapter.set_namespace(&namespace).unwrap();
    adapter.set_shared_tables(true).set_tenant(Some(42));
    let audit = Audit::new(adapter.clone());

    assert!(utopia_audit::Adapter::ping(audit.adapter()).await);
    audit.setup().await.unwrap();
    audit.setup().await.unwrap();

    let logs = vec![
        log("u1", "documents.create", "2026-11-17 10:00:00"),
        log("u1", "documents.delete", "2026-11-17 10:00:01"),
        log("u2", "documents.create", "2026-11-17 10:00:02"),
    ];
    assert!(audit.log_batch(logs).await.unwrap());

    assert_eq!(audit.count_logs_by_user("u1", Range::default(), None).await.unwrap(), 2);
    let found = audit.logs_by_user("u1", Range::default(), Page::default()).await.unwrap();
    assert_eq!(found.iter().map(|log| log.event()).collect::<Vec<_>>(), ["documents.delete", "documents.create"]);
    assert_eq!(found[0].resource(), "database/d1/collection/c1");
    assert_eq!(found[0].actor_type(), Some("user"));

    // Another tenant of the same table sees none of them.
    adapter.set_tenant(Some(43));
    assert_eq!(Audit::new(adapter).count_logs_by_user("u1", Range::default(), None).await.unwrap(), 0);
}
