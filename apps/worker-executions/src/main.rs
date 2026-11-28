//! The executions queue's worker: writes function and site execution snapshots to ClickHouse.
//!
//! It takes the place of the PHP `executions` worker on a queue served by NATS JetStream, which is
//! the only broker utopia-queue's Rust worker reads. Both can consume the queue at once.
//!
//! Each message is an execution, a batch of them, or a cancelled execution's delete. Every message
//! one receive takes is written in a single insert, and acknowledged only once ClickHouse has
//! stored it: the queue holds every snapshot until then, and one insert per receive instead of one
//! per snapshot keeps the parts ClickHouse has to merge few without its insert buffer. An insert
//! ClickHouse refuses is rejected and retried on the queue's backoff. A message that holds no
//! execution is dead-lettered at once, since a retry cannot give it one.
//!
//! Configuration, as the PHP worker reads it:
//! - `_APP_CONNECTIONS_QUEUE_EXECUTIONS`: the queue's `nats://` DSN.
//! - `_APP_EXECUTIONS_QUEUE_NAME`: the queue, `v1-executions` by default.
//! - `_APP_CONNECTIONS_DB_EXECUTIONS`, else `_APP_CONNECTIONS_DB_USAGE`: the ClickHouse DSN,
//!   `http://user:password@host:port/database[?secure=true]`.
//! - `_APP_MAINTENANCE_RETENTION_EXECUTION`: seconds a snapshot is kept, 14 days by default.
//!
//! Spans go to stdout through utopia-span: one `worker.executions` span per insert, written only
//! when it failed, was a retry or was slow, as `app/init/span.php` samples the PHP workers'.

mod snapshot;

use std::error::Error;
use std::process::ExitCode;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper_rustls::HttpsConnector;
use hyper_util::client::legacy::Client;
use hyper_util::client::legacy::connect::HttpConnector;
use hyper_util::rt::TokioExecutor;
use php_std::encoding::rawurlencode;
use serde_json::{Map, Value};
use tokio::signal::unix::{Signal, SignalKind, signal};
use utopia_dsn::Dsn;
use utopia_queue::{Delivery, Worker};
use utopia_span::{Failure, Level, Span, Stdout};

use crate::snapshot::Versions;

/// `Store::TABLE`.
const TABLE: &str = "executions";
/// `Store::COLUMNS`, the order of the insert.
const COLUMNS: &str = "projectId, id, createdAt, updatedAt, sequence, permissions, readRoles, \
    resourceInternalId, resourceId, resourceType, deploymentInternalId, deploymentId, trigger, status, \
    responseStatusCode, duration, requestMethod, requestPath, document, expiresAt, deleted, version";
/// Most messages written in one insert. The consumer's maxAckPending caps it further.
const BATCH: usize = 500;
/// How long a receive waits for a first message. Once it has one it waits only for the queue's
/// top-up (250 ms), so a new execution is readable about as soon as with the PHP worker.
const WAIT: Duration = Duration::from_secs(5);
/// The PHP store's HTTP timeout is 3 s per connection; a batch gets longer.
const INSERT_TIMEOUT: Duration = Duration::from_secs(30);
/// How long an idle ClickHouse connection is kept for reuse. Under the server's keep-alive
/// timeout, so an insert is never sent on a connection ClickHouse has just closed.
const IDLE: Duration = Duration::from_secs(5);
/// The span of an insert, as `app/worker.php` names the PHP worker's.
const ACTION: &str = "worker.executions";

fn main() -> ExitCode {
    Span::set_exporters(vec![Arc::new(Stdout::new().sampler(sample))]);
    let start = span("process.start");
    let runtime = match tokio::runtime::Runtime::new() {
        Ok(runtime) => runtime,
        Err(error) => {
            start.finish_with(None, Some(Failure::new("process.start", error.to_string())));
            return ExitCode::FAILURE;
        }
    };
    runtime.block_on(async {
        match connect().await {
            Ok((clickhouse, worker, terminate)) => {
                start.finish();
                run(&clickhouse, &worker, terminate).await;
                span("process.stop").finish();
                ExitCode::SUCCESS
            }
            Err(error) => {
                start.finish_with(None, Some(Failure::new("process.start", error)));
                ExitCode::FAILURE
            }
        }
    })
}

/// A span with what every span of this worker carries, as `app/worker.php` sets it.
fn span(action: &str) -> Span {
    let span = Span::new(action);
    span.set("queue.name", queue());
    if let Ok(region) = std::env::var("_APP_REGION") {
        span.set("region", region);
    }
    span
}

/// `app/init/span.php`'s sampler: a `worker.*` span is written only when it failed, is not
/// `info`, is a retry or was slow. Every other span, such as `process.start`, is written.
fn sample(span: &Span) -> bool {
    if span.error().is_some() || !span.action().starts_with("worker.") {
        return true;
    }
    let slow =
        std::env::var("_APP_SLOW_REQUEST_THRESHOLD_SECONDS").ok().and_then(|value| value.parse().ok()).unwrap_or(15.0);
    span.get("level").as_str() != Some("info")
        || span.get("message.attempt").to_number() > 1.0
        || span.get("span.duration").to_number() > slow
}

/// An error and every error that caused it: hyper's own message never says why a request failed.
fn cause(error: &dyn Error) -> String {
    let mut text = error.to_string();
    let mut source = error.source();
    while let Some(error) = source {
        text = format!("{text}: {error}");
        source = error.source();
    }
    text
}

fn queue() -> String {
    env("_APP_EXECUTIONS_QUEUE_NAME").unwrap_or_else(|_| "v1-executions".to_string())
}

async fn connect() -> Result<(ClickHouse, Worker, Signal), String> {
    let dsn =
        env("_APP_CONNECTIONS_DB_EXECUTIONS").or_else(|_| env("_APP_CONNECTIONS_DB_USAGE")).unwrap_or_else(|_| {
            let password = env("_APP_USAGE_PASS").unwrap_or_else(|_| "appwrite".to_string());
            format!("http://appwrite:{password}@clickhouse:8123/appwrite")
        });
    let clickhouse = ClickHouse::from_dsn(&dsn)?;
    let worker =
        Worker::connect(&env("_APP_CONNECTIONS_QUEUE_EXECUTIONS")?, &queue()).await.map_err(|error| cause(&error))?;
    let terminate = signal(SignalKind::terminate()).map_err(|error| error.to_string())?;
    Ok((clickhouse, worker, terminate))
}

/// Works the queue until a stop signal.
async fn run(clickhouse: &ClickHouse, worker: &Worker, mut terminate: Signal) {
    // A stop is noticed between receives, at most WAIT later, and the batch in hand is written and
    // settled first. Racing it against a receive would drop messages the receive had already
    // pulled, which the queue then redelivers after its ack wait.
    let stopping = Arc::new(AtomicBool::new(false));
    let stop = stopping.clone();
    tokio::spawn(async move {
        tokio::select! {
            _ = terminate.recv() => {}
            _ = tokio::signal::ctrl_c() => {}
        }
        stop.store(true, Ordering::Relaxed);
    });

    let retention =
        env("_APP_MAINTENANCE_RETENTION_EXECUTION").ok().and_then(|value| value.parse().ok()).unwrap_or(1_209_600);
    let mut versions = Versions::default();
    while !stopping.load(Ordering::Relaxed) {
        match worker.receive(BATCH, WAIT).await {
            Ok(deliveries) => {
                write(worker, clickhouse, deliveries, retention, &mut versions).await;
            }
            // The client reconnects by itself; this only keeps a dead server from spinning.
            Err(error) => {
                span(ACTION).finish_with(None, Some(Failure::new("utopia_queue::Error", cause(&error))));
                tokio::time::sleep(Duration::from_secs(1)).await;
            }
        }
    }
}

async fn write(
    worker: &Worker,
    clickhouse: &ClickHouse,
    deliveries: Vec<Delivery>,
    retention: i64,
    versions: &mut Versions,
) {
    let mut held = Vec::new();
    let mut rows = Vec::new();
    for delivery in deliveries {
        match snapshot::rows(&delivery.envelope.payload, retention, versions) {
            Ok(snapshots) => {
                rows.extend(snapshots);
                held.push(delivery);
            }
            Err(error) => {
                let refused = span(ACTION);
                refused.set("message.id", delivery.envelope.pid.as_str()).set("message.attempt", attempt(&delivery));
                settle(worker.reject(delivery, true).await);
                refused.finish_with(None, Some(Failure::new("Exception", error)));
            }
        }
    }
    if held.is_empty() {
        return;
    }

    let insert = span(ACTION);
    insert
        .set("message.count", held.len() as i64)
        .set("executions.count", rows.len() as i64)
        // The batch is a retry if any of it is: the sampler writes retries.
        .set("message.attempt", held.iter().map(attempt).max().unwrap_or(1));
    let refs: Vec<&Delivery> = held.iter().collect();
    let inserted = match worker.hold(&refs, clickhouse.insert(&rows)).await {
        Ok(()) => {
            insert.finish();
            true
        }
        Err((kind, error)) => {
            insert.finish_with(None, Some(Failure::new(kind, error)));
            false
        }
    };
    for delivery in held {
        settle(if inserted { worker.commit(delivery).await } else { worker.reject(delivery, false).await });
    }
}

/// Which delivery of the message this is, from 1.
fn attempt(delivery: &Delivery) -> i64 {
    delivery.attempts as i64 + 1
}

/// A settlement that fails leaves the message to come back after the ack wait.
fn settle(result: utopia_queue::Result<()>) {
    if let Err(error) = result {
        span(ACTION).finish_with(Some(Level::Warn), Some(Failure::new("utopia_queue::Error", cause(&error))));
    }
}

fn env(name: &str) -> Result<String, String> {
    std::env::var(name).ok().filter(|value| !value.is_empty()).ok_or_else(|| format!("Missing {name}"))
}

struct ClickHouse {
    http: Client<HttpsConnector<HttpConnector>, Full<Bytes>>,
    url: String,
    user: String,
    password: String,
    database: String,
}

impl ClickHouse {
    /// What `Store::connect()` reads from the same DSN: the scheme is ignored, and `secure=true`
    /// means https.
    fn from_dsn(dsn: &str) -> Result<Self, String> {
        let dsn = Dsn::parse(dsn).map_err(|error| format!("Invalid execution ClickHouse DSN: {error}"))?;
        let text = |value: Option<&[u8]>| String::from_utf8_lossy(value.unwrap_or_default()).into_owned();
        let secure = dsn.param("secure").ok().flatten().is_some_and(|value| value.eq_ignore_ascii_case(b"true"));
        let database = text(Some(dsn.path())).trim_start_matches('/').to_string();
        if !database.is_empty() && !database.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_') {
            return Err(format!("Invalid ClickHouse identifier: {database}"));
        }
        let mut connector = HttpConnector::new();
        connector.enforce_http(false);
        let https = hyper_rustls::HttpsConnectorBuilder::new()
            .with_provider_and_webpki_roots(rustls::crypto::ring::default_provider())
            .map_err(|error| error.to_string())?
            .https_or_http()
            .enable_http1()
            .wrap_connector(connector);

        Ok(Self {
            http: Client::builder(TokioExecutor::new()).pool_idle_timeout(IDLE).build(https),
            url: format!("{}://{}:{}/", if secure { "https" } else { "http" }, dsn.host(), dsn.port().unwrap_or(8123)),
            user: match text(dsn.user()) {
                user if user.is_empty() => "default".to_string(),
                user => user,
            },
            password: text(dsn.password()),
            database: if database.is_empty() { "default".to_string() } else { database },
        })
    }

    /// One `INSERT … FORMAT JSONEachRow` of every row, written at once rather than through
    /// ClickHouse's insert buffer: the batch already is the buffer. A failure comes back with its
    /// type, for the span's `error.type`. PHP: `Store::insertRows()`.
    async fn insert(&self, rows: &[Map<String, Value>]) -> Result<(), (&'static str, String)> {
        let mut body = Vec::new();
        for row in rows {
            serde_json::to_writer(&mut body, row).map_err(|error| ("serde_json::Error", error.to_string()))?;
            body.push(b'\n');
        }

        let query = format!("INSERT INTO `{}`.`{TABLE}` ({COLUMNS}) FORMAT JSONEachRow", self.database);
        let request =
            hyper::Request::post(format!("{}?query={}&async_insert=0", self.url, rawurlencode(query.as_bytes())))
                .header("Content-Type", "application/x-ndjson")
                .header("X-ClickHouse-Database", &self.database)
                .header("X-ClickHouse-User", &self.user)
                .header("X-ClickHouse-Key", &self.password)
                .body(Full::new(Bytes::from(body)))
                .map_err(|error| ("hyper::Error", error.to_string()))?;
        let exchange = async {
            let response = self.http.request(request).await.map_err(|error| ("hyper::Error", cause(&error)))?;
            let status = response.status();
            let body = response.into_body().collect().await.map_err(|error| ("hyper::Error", cause(&error)))?;
            if status.is_success() {
                return Ok(());
            }
            Err(("ClickHouse", format!("HTTP {status}: {}", String::from_utf8_lossy(&body.to_bytes()))))
        };
        tokio::time::timeout(INSERT_TIMEOUT, exchange)
            .await
            .unwrap_or_else(|_| Err(("Timeout", format!("No answer in {} s", INSERT_TIMEOUT.as_secs()))))
    }
}

#[cfg(test)]
mod tests {
    use std::sync::Mutex;

    use async_nats::jetstream::{self, consumer, stream};
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    use super::*;

    /// An insert ClickHouse refuses leaves the execution on the queue, the retry it stores
    /// acknowledges it, and a message without an execution is dead-lettered without an insert.
    /// Against a JetStream server: `NATS_URL=nats://localhost:4222 cargo test -- --include-ignored`.
    #[tokio::test]
    #[ignore = "needs a JetStream server at NATS_URL"]
    async fn a_refused_insert_is_retried_not_acknowledged() {
        let js = jetstream::new(async_nats::connect(std::env::var("NATS_URL").unwrap()).await.unwrap());
        let queue = format!("test-executions-{}", std::process::id());
        let name = format!("Q_{}", queue.to_uppercase());
        let subject = format!("q.{queue}");
        let stream = js
            .create_stream(stream::Config {
                name: name.clone(),
                subjects: vec![subject.clone(), format!("q.{queue}.dead.>")],
                ..Default::default()
            })
            .await
            .unwrap();
        stream
            .create_consumer(consumer::pull::Config {
                durable_name: Some("worker".to_string()),
                filter_subject: subject.clone(),
                ack_policy: consumer::AckPolicy::Explicit,
                max_deliver: 3,
                // Also the first delivery's ack wait, so longer than a receive's top-up.
                backoff: vec![Duration::from_secs(1)],
                ..Default::default()
            })
            .await
            .unwrap();
        for payload in [
            serde_json::json!({"project": {"$id": "p1"}, "execution": {"$id": "e1", "status": "completed"}}),
            serde_json::json!({"project": {"$id": "p1"}}),
        ] {
            let message = serde_json::json!({"pid": "1", "queue": queue, "timestamp": 0, "payload": payload});
            js.publish(subject.clone(), message.to_string().into()).await.unwrap().await.unwrap();
        }

        let (clickhouse, inserts) = clickhouse(&[500, 200]).await;
        let worker = Worker::join(js.clone(), &queue).await.unwrap();
        let mut versions = Versions::default();

        let first = worker.receive(BATCH, WAIT).await.unwrap();
        assert_eq!(first.len(), 2);
        write(&worker, &clickhouse, first, 60, &mut versions).await;

        let retry = worker.receive(BATCH, WAIT).await.unwrap();
        assert_eq!(retry.len(), 1, "only the refused execution comes back");
        assert_eq!(retry[0].attempts, 1);
        write(&worker, &clickhouse, retry, 60, &mut versions).await;

        let mut consumer: consumer::PullConsumer = stream.get_consumer("worker").await.unwrap();
        let info = consumer.info().await.unwrap();
        assert_eq!((info.num_pending, info.num_ack_pending), (0, 0), "the stored execution is acknowledged");
        let inserts = inserts.lock().unwrap().clone();
        assert_eq!(inserts.len(), 2);
        for body in inserts.iter() {
            let row: Value = serde_json::from_str(body.trim_end()).unwrap();
            assert_eq!((&row["projectId"], &row["id"]), (&"p1".into(), &"e1".into()));
        }
        let mut dead: consumer::PullConsumer = stream
            .create_consumer(consumer::pull::Config {
                filter_subject: format!("q.{queue}.dead.>"),
                ..Default::default()
            })
            .await
            .unwrap();
        assert_eq!(dead.info().await.unwrap().num_pending, 1, "the message without an execution is dead-lettered");

        js.delete_stream(&name).await.unwrap();
    }

    /// A ClickHouse that answers each insert with the next of `statuses`, and the bodies it was sent.
    async fn clickhouse(statuses: &[u16]) -> (ClickHouse, Arc<Mutex<Vec<String>>>) {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let inserts = Arc::new(Mutex::new(Vec::new()));
        let (statuses, seen) = (statuses.to_vec(), inserts.clone());
        tokio::spawn(async move {
            for status in statuses {
                let (mut socket, _) = listener.accept().await.unwrap();
                let mut request = Vec::new();
                let mut buffer = [0; 4096];
                let body = loop {
                    let read = socket.read(&mut buffer).await.unwrap();
                    request.extend_from_slice(&buffer[..read]);
                    let text = String::from_utf8_lossy(&request).into_owned();
                    let Some(end) = text.find("\r\n\r\n") else {
                        continue;
                    };
                    let length: usize = text[..end]
                        .lines()
                        .find_map(|line| {
                            let line = line.to_ascii_lowercase();
                            line.strip_prefix("content-length:").map(|value| value.trim().parse().unwrap())
                        })
                        .unwrap_or(0);
                    if text.len() >= end + 4 + length {
                        break text[end + 4..end + 4 + length].to_string();
                    }
                };
                seen.lock().unwrap().push(body);
                let response = format!("HTTP/1.1 {status} X\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                socket.write_all(response.as_bytes()).await.unwrap();
            }
        });
        let dsn = format!("http://appwrite:secret@127.0.0.1:{port}/appwrite");
        (ClickHouse::from_dsn(&dsn).unwrap(), inserts)
    }
}
