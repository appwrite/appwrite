//! Compat adapter for `nats`: maps `tests/compat/nats/spec.json` operations
//! onto `utopia-nats`: pure values, connections on [`Fake`] (the twin of
//! `tests/compat/nats/Fake.php`, itself the PHP tests' `FakeTransport`), and
//! connections to the compat NATS servers of `services.sh`.

use std::hint::black_box;
use std::rc::Rc;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use async_trait::async_trait;
use bytes::Bytes;
use indexmap::IndexMap;
use serde_json::{Map, Value, json};
use utopia_nats::auth::{Authenticator, CredentialsAuth, NKeyAuth, NoAuth, TokenAuth, UserPassAuth};
use utopia_nats::jetstream::{
    AckPolicy, ConsumerConfig, ConsumerInfo, ConsumerLimits, DeliverPolicy, DiscardPolicy, ExternalStream, JetStream,
    JetStreamMessage, MessageBatch, MsgMetadata, OrderedConsumer, Placement, PubAck, PublishMessage, PublishOptions,
    PushSubscription, ReplayPolicy, Republish, RetentionPolicy, SequenceInfo, StorageType, Stream, StreamConfig,
    StreamInfo, StreamMessage, StreamSource, StreamState, SubjectTransform, Ttl, nanos_to_seconds, seconds_to_nanos,
};
use utopia_nats::key_value::{KeyValue, KeyValueConfig, KeyValueEntry, KeyValueWatchOptions};
use utopia_nats::object_store::{ObjectLink, ObjectMeta, ObjectStore, ObjectStoreConfig};
use utopia_nats::protocol::{Op, Parser, Writer};
use utopia_nats::services::{Endpoint, Group, Handler, Service};
use utopia_nats::transport::{TcpTransport, TlsOptions, TlsTransport, Transport, WebSocketTransport};
use utopia_nats::{
    CallbackError, Connection, ConnectionOptions, Error, Headers, Inbox, Message, MessageCallback, Request,
    RequestMany, ServerInfo, Subscription, SubscriptionState,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "headers.build",
    "headers.from_wire",
    "inbox.create",
    "inbox.generate_id",
    "request.new",
    "writer.connect",
    "writer.pub",
    "writer.hpub",
    "writer.sub",
    "writer.unsub",
    "writer.ping",
    "writer.pong",
    "parser.run",
    "server_info.from_array",
    "connection.closes_connection",
    "connection.reconnects_after",
    "connection.map_server_error",
    "connection.reconnect_backoff",
    "connection.reconnect_buffer_accepts",
    "options.defaults",
    "tls_transport.options",
    "auth.fields",
    "nanos.from_seconds",
    "nanos.to_seconds",
    "config.new",
    "config.from",
    "decode.info",
    "jetstream.check_error",
    "jetstream.prefix",
    "endpoint.new",
    "service_exception.new",
    "subscription.new",
    "subscription.deliver",
    "subscription.next",
    "subscription.set_max",
    "subscription.set_inactive",
    "subscription.state",
    "fake.connect",
    "fake.push",
    "fake.rules",
    "fake.answer_pings",
    "fake.fail_reads",
    "fake.count",
    "fake.mark",
    "fake.written",
    "fake.writes",
    "fake.connect_payload",
    "fake.state",
    "fake.tls_options",
    "conn.connect",
    "conn.publish",
    "conn.subscribe",
    "conn.queue_subscribe",
    "conn.unsubscribe",
    "conn.next_message",
    "conn.sub_state",
    "conn.process_message",
    "conn.wait",
    "conn.request",
    "conn.request_batch",
    "conn.request_many",
    "conn.request_tally",
    "conn.flush",
    "conn.drain",
    "conn.close",
    "conn.tick",
    "conn.state",
    "conn.server_info",
    "conn.new_inbox",
    "conn.options",
    "conn.log",
    "transport.round_trip",
    "js.new",
    "js.create_stream",
    "js.update_stream",
    "js.create_or_update_stream",
    "js.delete_stream",
    "js.get_stream",
    "js.get_stream_info",
    "js.get_stream_names",
    "js.list_streams",
    "js.purge_stream",
    "js.create_consumer",
    "js.update_consumer",
    "js.delete_consumer",
    "js.get_consumer",
    "js.get_consumer_names",
    "js.get_consumers",
    "js.push_subscribe",
    "js.ordered_consumer",
    "js.get_message",
    "js.get_last_message",
    "js.delete_message",
    "js.publish",
    "js.publish_many",
    "js.create_key_value",
    "js.get_key_value",
    "js.delete_key_value",
    "js.get_object_store",
    "js.delete_object_store",
    "js.account_info",
    "js.ack_batch",
    "stream.get",
    "stream.info",
    "stream.create_consumer",
    "stream.get_consumer",
    "stream.delete_consumer",
    "stream.purge",
    "stream.delete",
    "consumer.new",
    "consumer.fetch",
    "consumer.next",
    "consumer.info",
    "consumer.names",
    "jsmsg.new",
    "jsmsg.ack",
    "jsmsg.ack_sync",
    "jsmsg.nak",
    "jsmsg.in_progress",
    "jsmsg.term",
    "jsmsg.metadata",
    "jsmsg.get",
    "batch.new",
    "push.get",
    "push.unsubscribe",
    "push.handle_control",
    "ordered.next",
    "ordered.name",
    "ordered.stop",
    "ordered.skip",
    "kv.get",
    "kv.put",
    "kv.create",
    "kv.update",
    "kv.delete",
    "kv.purge",
    "kv.keys",
    "kv.status",
    "kv.get_revision",
    "kv.history",
    "kv.watch",
    "kv.purge_deletes",
    "kv.bucket",
    "kv.new",
    "obj.new",
    "obj.create_or_update",
    "obj.put",
    "obj.get",
    "obj.get_meta",
    "obj.delete",
    "obj.list",
    "obj.status",
    "obj.watch",
    "obj.add_link",
    "obj.add_bucket_link",
    "obj.update_meta",
    "obj.seal",
    "obj.bucket",
    "obj.read_meta",
    "obj.write_version",
    "obj.delete_version",
    "service.new",
    "service.add_endpoint",
    "service.register_endpoint",
    "service.add_group",
    "group.add_group",
    "group.add_endpoint",
    "service.start",
    "service.run",
    "service.stop",
    "service.get",
    "service.id",
];

/// The benchmark inputs, the same as in Adapter.php.
const REPLY: &str = "$JS.ACK.ORDERS.worker.1.42.17.1700000000000000000.3";

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("parse_msg", "Parse a MSG frame with a reply subject (in-memory transport)"),
    ("parse_hmsg", "Parse an HMSG frame with two headers (in-memory transport)"),
    ("headers", "Encode three headers and parse them back"),
    ("publish", "Publish a 128-byte message with a reply subject (in-memory transport)"),
    ("request", "A request and its reply (in-memory transport)"),
    ("metadata", "Parse a JetStream reply subject into message metadata"),
    ("server_request", "A request answered by a subscriber of the same connection, on the NATS server"),
];

pub async fn bench(name: &str, session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "parse_msg" => {
            let fake = Fake::new(Map::new());
            let parser = Rc::new(tokio::sync::Mutex::new(Parser::new()));
            let mut frame = b"MSG orders.created 7 _INBOX.abc 64\r\n".to_vec();
            frame.extend(std::iter::repeat_n(b'x', 64));
            frame.extend_from_slice(b"\r\n");
            Bench::future(move || {
                let (fake, parser, frame) = (fake.clone(), parser.clone(), frame.clone());
                async move {
                    fake.push(&frame);
                    let mut transport = fake.clone();
                    black_box(parser.lock().await.next(&mut transport, Some(Duration::from_secs(1))).await.ok());
                }
            })
        }
        "parse_hmsg" => {
            let fake = Fake::new(Map::new());
            let parser = Rc::new(tokio::sync::Mutex::new(Parser::new()));
            let block = "NATS/1.0\r\nNats-Msg-Id: 42\r\nX-Trace: abc\r\n\r\n";
            let frame =
                format!("HMSG orders.created 7 {} {}\r\n{block}hello\r\n", block.len(), block.len() + 5).into_bytes();
            Bench::future(move || {
                let (fake, parser, frame) = (fake.clone(), parser.clone(), frame.clone());
                async move {
                    fake.push(&frame);
                    let mut transport = fake.clone();
                    black_box(parser.lock().await.next(&mut transport, Some(Duration::from_secs(1))).await.ok());
                }
            })
        }
        "headers" => Bench::sync(|| {
            let mut headers = Headers::new();
            headers.set("Nats-Msg-Id", "order-1").set("Nats-Expected-Stream", "ORDERS").add("X-Tag", "a");
            black_box(Headers::from_wire(&headers.to_wire()).ok());
        }),
        "publish" => {
            let fake = Fake::new(Map::new());
            let conn = Rc::new(tokio::sync::Mutex::new(
                fake_connection(&fake, ConnectionOptions::default()).await.map_err(|e| Fault::new(e.to_string()))?,
            ));
            let payload = vec![b'p'; 128];
            Bench::future(move || {
                let (conn, fake, payload) = (conn.clone(), fake.clone(), payload.clone());
                async move {
                    black_box(
                        conn.lock().await.publish("orders.created", &payload, Some("_INBOX.reply"), None).await.ok(),
                    );
                    fake.clear_written();
                }
            })
        }
        "request" => {
            let fake = Fake::new(Map::new());
            fake.set_rules(vec![
                json!({"when": {"prefix": "PUB "}, "do": [{"reply": {"subject": "work", "payload": "ok"}}]}),
            ]);
            let conn = Rc::new(tokio::sync::Mutex::new(
                fake_connection(&fake, ConnectionOptions::default()).await.map_err(|e| Fault::new(e.to_string()))?,
            ));
            Bench::future(move || {
                let (conn, fake) = (conn.clone(), fake.clone());
                async move {
                    black_box(conn.lock().await.request("work", "", None, None).await.ok());
                    fake.clear_written();
                }
            })
        }
        "metadata" => Bench::sync(|| {
            black_box(MsgMetadata::from_reply_subject(black_box(REPLY)).ok());
        }),
        "server_request" => {
            let url = session.service("nats")?.to_owned();
            let mut conn =
                Connection::connect(ConnectionOptions::new([url])).await.map_err(|e| Fault::new(e.to_string()))?;
            let subject = format!("bench.{}", Inbox::generate_id());
            let publisher = conn.publisher();
            conn.subscribe(
                &subject,
                Some(Box::new(move |m: Message| {
                    publisher.publish(m.reply_to.unwrap_or_default(), "pong", None, None);
                    Ok(())
                })),
                None,
            )
            .await
            .map_err(|e| Fault::new(e.to_string()))?;
            let conn = Rc::new(tokio::sync::Mutex::new(conn));
            Bench::future(move || {
                let (conn, subject) = (conn.clone(), subject.clone());
                async move {
                    black_box(conn.lock().await.request(&subject, "ping", None, None).await.ok());
                }
            })
        }
        other => return Err(Fault::new(format!("nats: no benchmark `{other}`"))),
    })
}

// --- The fake transport ---

#[derive(Default)]
struct FakeState {
    info: Map<String, Value>,
    written: Vec<u8>,
    writes: Vec<Vec<u8>>,
    tls_upgrades: usize,
    answer_pings: bool,
    rules: Arc<Vec<Value>>,
    fail_reads: Option<Value>,
    connects: i64,
    inbound: Vec<u8>,
    connected: bool,
    captured: Vec<String>,
}

/// The twin of `tests/compat/nats/Fake.php`: an in-memory transport serving
/// an INFO line, answering PINGs, recording writes and running scripted
/// rules on each write. Clones share the state.
#[derive(Clone)]
struct Fake {
    state: Arc<Mutex<FakeState>>,
}

impl Fake {
    fn new(info: Map<String, Value>) -> Self {
        Fake { state: Arc::new(Mutex::new(FakeState { info, answer_pings: true, ..Default::default() })) }
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, FakeState> {
        self.state.lock().unwrap_or_else(std::sync::PoisonError::into_inner)
    }

    fn push(&self, data: &[u8]) {
        self.lock().inbound.extend_from_slice(data);
    }

    fn set_rules(&self, rules: Vec<Value>) {
        self.lock().rules = Arc::new(rules);
    }

    fn clear_written(&self) {
        let mut state = self.lock();
        state.written.clear();
        state.writes.clear();
    }

    fn act(&self, action: &Value, data: &[u8]) -> Result<Option<u64>, Error> {
        if let Some(push) = action.get("push") {
            self.push(&bytes(push).unwrap_or_default());
        } else if let Some(spec) = action.get("throw") {
            return Err(scripted_error(spec));
        } else if let Some(ms) = action.get("sleep_ms").and_then(Value::as_u64) {
            return Ok(Some(ms));
        } else if let Some(reply) = action.get("reply") {
            let subject = reply.get("subject").and_then(Value::as_str).unwrap_or_default();
            let payload = reply.get("payload").and_then(Value::as_str).unwrap_or_default();
            let skip: Vec<&str> = reply
                .get("skip")
                .and_then(Value::as_array)
                .map(|a| a.iter().filter_map(Value::as_str).collect())
                .unwrap_or_default();
            let mut found = publishes(data, subject);
            if reply.get("reverse").and_then(Value::as_bool).unwrap_or(false) {
                found.reverse();
            }
            for (target, reply_to) in found {
                let suffix = &target[subject.len()..];
                if skip.contains(&suffix) {
                    continue;
                }
                let payload = if payload == "$suffix" { suffix } else { payload };
                if let Some(status) = reply.get("status").and_then(Value::as_str) {
                    let block = format!("NATS/1.0 {status}\r\n\r\n");
                    self.push(format!("HMSG {reply_to} 1 {} {}\r\n{block}\r\n", block.len(), block.len()).as_bytes());
                } else {
                    self.push(format!("MSG {reply_to} 1 {}\r\n{payload}\r\n", payload.len()).as_bytes());
                }
            }
        } else if let Some(subject) = action.get("capture").and_then(Value::as_str) {
            self.lock().captured = publishes(data, subject).into_iter().map(|(_, r)| r).collect();
        } else if let Some(payload) = action.get("reply_captured").and_then(Value::as_str) {
            let captured = self.lock().captured.clone();
            for reply_to in captured {
                self.push(format!("MSG {reply_to} 1 {}\r\n{payload}\r\n", payload.len()).as_bytes());
            }
        }
        Ok(None)
    }
}

/// Non-overlapping occurrences of `needle` in `data` (`substr_count()` in
/// Fake.php); none for an empty needle.
fn occurrences(data: &[u8], needle: &[u8]) -> usize {
    if needle.is_empty() {
        return 0;
    }
    let (mut count, mut at) = (0, 0);
    while let Some(p) = data[at..].windows(needle.len()).position(|w| w == needle) {
        count += 1;
        at += p + needle.len();
    }
    count
}

/// The (subject, reply) of each PUB/HPUB with a reply subject in `data`
/// whose subject is `subject`, or under it when it ends with a dot.
fn publishes(data: &[u8], subject: &str) -> Vec<(String, String)> {
    let mut found = Vec::new();
    let mut offset = 0;
    while offset < data.len() {
        let Some(end) = data[offset..].windows(2).position(|w| w == b"\r\n").map(|p| p + offset) else {
            break;
        };
        let line = String::from_utf8_lossy(&data[offset..end]).into_owned();
        let parts: Vec<&str> = line.split(' ').collect();
        offset = end + 2;
        let size = match (parts[0], parts.len()) {
            ("PUB", 4) => php_std::format::str_to_int(parts[3].as_bytes()),
            ("HPUB", 5) => php_std::format::str_to_int(parts[4].as_bytes()),
            ("PUB" | "HPUB", _) => {
                offset += php_std::format::str_to_int(parts[parts.len() - 1].as_bytes()) as usize + 2;
                continue;
            }
            _ => continue,
        };
        offset += size as usize + 2;
        let target = parts[1];
        if target == subject || (subject.ends_with('.') && target.starts_with(subject)) {
            found.push((target.to_owned(), parts[2].to_owned()));
        }
    }
    found
}

fn rule_matches(when: &Value, data: &[u8]) -> bool {
    let text = |k: &str| when.get(k).and_then(bytes);
    if let Some(prefix) = text("prefix") {
        return data.starts_with(&prefix);
    }
    if let Some(equals) = text("equals") {
        return data == equals.as_slice();
    }
    if let Some(contains) = text("contains") {
        return data.windows(contains.len().max(1)).any(|w| w == contains.as_slice()) || contains.is_empty();
    }
    true
}

#[async_trait]
impl Transport for Fake {
    async fn connect(&mut self, _host: &str, _port: u16, _timeout: Duration) -> utopia_nats::Result<()> {
        let mut state = self.lock();
        state.connected = true;
        state.connects += 1;
        let mut info = Map::new();
        for (k, v) in [
            ("server_id", json!("FAKE")),
            ("server_name", json!("fake")),
            ("version", json!("2.10.0")),
            ("proto", json!(1)),
            ("host", json!("127.0.0.1")),
            ("port", json!(4222)),
            ("headers", json!(true)),
            ("auth_required", json!(false)),
            ("tls_required", json!(false)),
            ("tls_available", json!(false)),
            ("max_payload", json!(1048576)),
            ("jetstream", json!(true)),
        ] {
            info.insert(k.into(), v);
        }
        for (k, v) in state.info.clone() {
            info.insert(k, v);
        }
        let line = format!("INFO {}\r\n", Value::Object(info));
        state.inbound.extend_from_slice(line.as_bytes());
        Ok(())
    }

    async fn write(&mut self, data: &[u8]) -> utopia_nats::Result<usize> {
        let rules = {
            let mut state = self.lock();
            state.written.extend_from_slice(data);
            state.writes.push(data.to_vec());
            state.rules.clone()
        };
        for rule in rules.iter() {
            if rule_matches(rule.get("when").unwrap_or(&Value::Null), data) {
                for action in rule.get("do").and_then(Value::as_array).map_or(&[][..], Vec::as_slice) {
                    if let Some(ms) = self.act(action, data)? {
                        tokio::time::sleep(Duration::from_millis(ms)).await;
                    }
                }
            }
        }
        let mut state = self.lock();
        if state.answer_pings {
            let pings = occurrences(data, b"PING\r\n");
            for _ in 0..pings {
                state.inbound.extend_from_slice(b"PONG\r\n");
            }
        }
        Ok(data.len())
    }

    async fn read(&mut self, buf: &mut Vec<u8>, max: usize, _timeout: Option<Duration>) -> utopia_nats::Result<usize> {
        let mut state = self.lock();
        if let Some(error) = &state.fail_reads {
            return Err(scripted_error(error));
        }
        if state.inbound.is_empty() {
            return Err(Error::Timeout("No inbound data".into()));
        }
        let n = max.min(state.inbound.len());
        buf.extend(state.inbound.drain(..n));
        Ok(n)
    }

    async fn read_line(&mut self, _timeout: Option<Duration>) -> utopia_nats::Result<Vec<u8>> {
        let mut state = self.lock();
        match state.inbound.iter().position(|&b| b == b'\n') {
            Some(at) => Ok(state.inbound.drain(..=at).collect()),
            None => Err(Error::Timeout("No inbound line".into())),
        }
    }

    async fn upgrade_tls(&mut self, _options: &TlsOptions) -> utopia_nats::Result<()> {
        self.lock().tls_upgrades += 1;
        Ok(())
    }

    fn is_connected(&self) -> bool {
        self.lock().connected
    }

    async fn close(&mut self) {
        self.lock().connected = false;
    }
}

/// An error a scripted fixture raises: {"class": short name, "message"}.
fn scripted_error(spec: &Value) -> Error {
    let message = spec.get("message").and_then(Value::as_str).unwrap_or_default().to_owned();
    match spec.get("class").and_then(Value::as_str).unwrap_or_default() {
        "NatsException" => Error::Nats(message),
        "ConnectionException" => Error::Connection(message),
        "TimeoutException" => Error::Timeout(message),
        "ProtocolException" => Error::protocol(message),
        "AuthenticationException" => Error::Authentication(message),
        "ServiceException" => {
            Error::Service { code: spec.get("code").and_then(Value::as_str).unwrap_or("500").to_owned(), message }
        }
        "RuntimeException" => Error::Runtime(message),
        _ => Error::Nats(message),
    }
}

fn callback_error(spec: &Value) -> CallbackError {
    Box::new(scripted_error(spec))
}

async fn fake_connection(fake: &Fake, options: ConnectionOptions) -> utopia_nats::Result<Connection> {
    let transport = fake.clone();
    let options = ConnectionOptions {
        transport_factory: Some(Arc::new(move |_scheme: &str| Ok(Box::new(transport.clone()) as Box<dyn Transport>))),
        ..options
    };
    Connection::connect(options).await
}

// --- Handles ---

type Shared<T> = Arc<tokio::sync::Mutex<T>>;
type Log = Arc<Mutex<Vec<Value>>>;

/// A connection with what its callbacks recorded, and its fake transport.
struct Conn {
    conn: Connection,
    log: Log,
    fake: Option<Fake>,
}

/// A subscription built on its own, with what its callbacks recorded.
struct Sub {
    state: SubscriptionState,
    log: Log,
}

fn shared<T: Send + 'static>(session: &mut Session, value: T) -> Value {
    session.handle(Arc::new(tokio::sync::Mutex::new(value)))
}

fn get<T: Send + 'static>(session: &Session, args: &Args<'_>, key: &str) -> Result<Shared<T>, Fault> {
    Ok(session.get::<Shared<T>>(args.value(key)?)?.clone())
}

fn record(log: &Log, entry: Value) {
    log.lock().unwrap_or_else(std::sync::PoisonError::into_inner).push(entry);
}

fn lib(error: Error) -> Outcome {
    Outcome::err_bytes(error.php_class(), &error.message_bytes())
}

macro_rules! try_lib {
    ($e:expr) => {
        match $e {
            Ok(v) => v,
            Err(e) => return Ok(lib(e)),
        }
    };
}

// --- Values ---

/// Replaces the random 22-character ids that start the string or follow a
/// dot with `*` (as `norm()` in Adapter.php).
fn norm(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut out = String::with_capacity(value.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i].is_ascii_alphanumeric() {
            let start = i;
            while i < bytes.len() && bytes[i].is_ascii_alphanumeric() {
                i += 1;
            }
            if i - start == 22 && (start == 0 || bytes[start - 1] == b'.' || bytes[start - 1] == b'"') {
                out.push('*');
            } else {
                out.push_str(&value[start..i]);
            }
        } else {
            let ch = value[i..].chars().next().unwrap_or_default();
            out.push(ch);
            i += ch.len_utf8();
        }
    }
    out
}

fn norm_bytes(value: &[u8]) -> Value {
    match std::str::from_utf8(value) {
        Ok(text) => Value::String(norm(text)),
        Err(_) => bytes_value(value),
    }
}

fn build_headers(steps: &Value) -> Result<Headers, Fault> {
    let mut headers = Headers::new();
    for step in steps.as_array().ok_or_else(|| Fault::new("headers must be a list of steps"))? {
        let s = |i: usize| match step {
            Value::Object(o) => {
                o.get(["op", "name", "value"][i.min(2)]).and_then(Value::as_str).unwrap_or_default().to_owned()
            }
            _ => step.get(i).and_then(Value::as_str).unwrap_or_default().to_owned(),
        };
        match s(0).as_str() {
            "set" => {
                headers.set(s(1), s(2));
            }
            "add" => {
                headers.add(s(1), s(2));
            }
            "delete" => {
                headers.delete(&s(1));
            }
            "status" => {
                headers.set_status(s(1), s(2));
            }
            other => return Err(Fault::new(format!("unknown header step {other}"))),
        }
    }
    Ok(headers)
}

fn opt_headers(args: &Args<'_>, key: &str) -> Result<Option<Headers>, Fault> {
    args.opt(key).map(build_headers).transpose()
}

fn headers_value(headers: Option<&Headers>, query: Option<&Value>) -> Value {
    let Some(headers) = headers else {
        return Value::Null;
    };
    let all: Vec<Value> = headers.iter().map(|(name, values)| json!([name, values])).collect();
    let mut out = json!({
        "status": headers.status(),
        "description": headers.description(),
        "all": all,
        "count": headers.len(),
        "all_count": headers.all().len(),
        "wire": bytes_value(&headers.to_wire()),
    });
    if let Some(query) = query.and_then(Value::as_array) {
        let rows: Vec<Value> = query
            .iter()
            .filter_map(Value::as_str)
            .map(|name| json!([name, headers.get(name), headers.get_all(name), headers.has(name)]))
            .collect();
        if !rows.is_empty() {
            out["query"] = Value::Array(rows);
        }
    }
    out
}

fn message_value(message: Option<&Message>) -> Value {
    let Some(m) = message else {
        return Value::Null;
    };
    json!({
        "subject": norm(&m.subject),
        "data": bytes_value(&m.data),
        "reply": m.reply_to.as_deref().map(norm),
        "headers": headers_value(m.headers.as_ref(), None),
        "sid": m.sid,
    })
}

fn inbox_value(inbox: &str) -> Value {
    let dot = inbox.rfind('.').unwrap_or(0);
    let id = &inbox[dot + 1..];
    json!({"prefix": &inbox[..dot], "length": id.len(), "alnum": !id.is_empty() && id.bytes().all(|b| b.is_ascii_alphanumeric())})
}

fn sub_state(state: Option<&SubscriptionState>) -> Value {
    match state {
        Some(s) => json!({
            "active": s.is_active(),
            "received": s.received(),
            "pending_count": s.pending_count(),
            "pending_bytes": s.pending_bytes(),
            "has_callback": s.has_callback(),
        }),
        None => json!({"active": false}),
    }
}

fn server_info(i: &ServerInfo) -> Value {
    json!({
        "server_id": i.server_id, "server_name": i.server_name, "version": i.version, "proto": i.proto, "host": i.host, "port": i.port,
        "headers": i.headers_supported, "auth_required": i.auth_required, "tls_required": i.tls_required, "tls_available": i.tls_available,
        "max_payload": i.max_payload, "connect_urls": i.connect_urls, "nonce": i.nonce, "jetstream": i.jetstream, "client_id": i.client_id, "client_ip": i.client_ip,
    })
}

fn secs(d: Duration) -> Value {
    float_value(d.as_secs_f64())
}

fn options_value(o: &ConnectionOptions) -> Value {
    json!({
        "servers": o.servers, "name": o.name, "user": o.user, "pass": o.pass, "token": o.token, "nkey": o.nkey, "nkeySeed": o.nkey_seed,
        "credentialsFile": o.credentials_file, "tls": o.tls, "tlsCaFile": o.tls_ca_file, "tlsCertFile": o.tls_cert_file, "tlsKeyFile": o.tls_key_file,
        "tlsVerify": o.tls_verify, "tlsServerName": o.tls_server_name, "allowReconnect": o.allow_reconnect, "maxReconnectAttempts": o.max_reconnect_attempts,
        "reconnectWait": secs(o.reconnect_wait), "maxReconnectWait": secs(o.max_reconnect_wait), "reconnectJitter": secs(o.reconnect_jitter), "reconnectBufSize": o.reconnect_buf_size,
        "subPendingMsgsLimit": o.sub_pending_msgs_limit, "subPendingBytesLimit": o.sub_pending_bytes_limit, "connectTimeout": secs(o.connect_timeout),
        "requestTimeout": secs(o.request_timeout), "drainTimeout": secs(o.drain_timeout), "pingInterval": secs(o.ping_interval), "maxPingsOut": o.max_pings_out,
        "verbose": o.verbose, "pedantic": o.pedantic, "echo": o.echo, "noRandomize": o.no_randomize, "inboxPrefix": o.inbox_prefix,
    })
}

/// The `ssl` options array PHP builds, keys sorted.
fn tls_value(o: &TlsOptions, all: bool) -> Value {
    let mut map = std::collections::BTreeMap::new();
    let path = |p: &Option<std::path::PathBuf>| p.as_ref().map(|p| Value::String(p.display().to_string()));
    if all {
        map.insert("cafile", path(&o.ca_file).unwrap_or(Value::Null));
        map.insert("local_cert", path(&o.cert_file).unwrap_or(Value::Null));
        map.insert("local_pk", path(&o.key_file).unwrap_or(Value::Null));
    } else {
        for (k, v) in [("cafile", &o.ca_file), ("local_cert", &o.cert_file), ("local_pk", &o.key_file)] {
            if let Some(v) = path(v) {
                map.insert(k, v);
            }
        }
    }
    if let Some(name) = &o.peer_name {
        map.insert("peer_name", Value::String(name.clone()));
    }
    map.insert("verify_peer", Value::Bool(o.verify_peer));
    map.insert("verify_peer_name", Value::Bool(o.verify_peer_name));
    Value::Object(map.into_iter().map(|(k, v)| (k.to_owned(), v)).collect())
}

fn tls_options(value: Option<&Value>) -> TlsOptions {
    let empty = Value::Null;
    let v = value.unwrap_or(&empty);
    let path = |k: &str| v.get(k).and_then(Value::as_str).map(std::path::PathBuf::from);
    TlsOptions {
        ca_file: path("cafile"),
        cert_file: path("local_cert"),
        key_file: path("local_pk"),
        verify_peer: v.get("verify_peer").and_then(Value::as_bool).unwrap_or(true),
        verify_peer_name: v.get("verify_peer_name").and_then(Value::as_bool).unwrap_or(true),
        peer_name: v.get("peer_name").and_then(Value::as_str).map(str::to_owned),
    }
}

fn error_value(e: &Error) -> Value {
    let mut out = json!({"class": e.php_class(), "message": bytes_value(&e.message_bytes())});
    if let Some(previous) = e.previous() {
        out["previous"] = Value::String(previous.php_class().into());
    }
    out
}

fn timing(elapsed: Duration, args: &Args<'_>) -> Map<String, Value> {
    let mut out = Map::new();
    let elapsed = elapsed.as_secs_f64();
    if let Some(within) = args.opt("within").and_then(float) {
        out.insert("within".into(), Value::Bool(elapsed < within));
    }
    if let Some(at_least) = args.opt("at_least").and_then(float) {
        out.insert("at_least".into(), Value::Bool(elapsed >= at_least));
    }
    out
}

fn timeout(args: &Args<'_>) -> Result<Option<Duration>, Fault> {
    args.opt_f64("timeout")?
        .map(|t| Duration::try_from_secs_f64(t).map_err(|_| Fault::new(format!("timeout {t} is not a duration"))))
        .transpose()
}

fn duration(value: &Value) -> Result<Duration, Fault> {
    let t = float(value).ok_or_else(|| Fault::new("a duration must be a number"))?;
    Duration::try_from_secs_f64(t).map_err(|_| Fault::new(format!("{t} is not a duration")))
}

fn payload(args: &Args<'_>) -> Result<Vec<u8>, Fault> {
    let data = args.opt("data").map(|_| args.bytes("data")).transpose()?.unwrap_or_default();
    Ok(match args.opt_i64("repeat")? {
        Some(n) => data.repeat(n as usize),
        None => data,
    })
}

/// A decoded JSON value as PHP re-encodes it: an empty object is an empty
/// array (`json_decode(..., true)` gives `[]` for `{}`).
fn php_json(value: &Value) -> Value {
    match value {
        Value::Object(o) if o.is_empty() => Value::Array(Vec::new()),
        Value::Object(o) => Value::Object(o.iter().map(|(k, v)| (k.clone(), php_json(v))).collect()),
        Value::Array(a) => Value::Array(a.iter().map(php_json).collect()),
        other => other.clone(),
    }
}

fn sorted_strings(mut names: Vec<String>) -> Value {
    names.sort();
    json!(names)
}

// --- Connection options ---

fn connection_options(v: &Value, log: &Log) -> Result<ConnectionOptions, Fault> {
    let a = Args(v);
    let mut o = ConnectionOptions::default();
    if let Some(servers) = a.opt("servers") {
        o.servers = match servers {
            Value::String(s) => vec![s.clone()],
            Value::Array(list) => list.iter().filter_map(Value::as_str).map(str::to_owned).collect(),
            _ => return Err(Fault::new("servers must be a string or a list")),
        };
    }
    let s = |k: &str| a.opt_str(k).map(|v| v.map(str::to_owned));
    if let Some(name) = s("name")? {
        o.name = name;
    }
    o.user = s("user")?;
    o.pass = s("pass")?;
    o.token = s("token")?;
    o.nkey = s("nkey")?;
    o.nkey_seed = s("nkeySeed")?;
    o.credentials_file = s("credentialsFile")?;
    o.tls_ca_file = s("tlsCaFile")?;
    o.tls_cert_file = s("tlsCertFile")?;
    o.tls_key_file = s("tlsKeyFile")?;
    o.tls_server_name = s("tlsServerName")?;
    if let Some(prefix) = s("inboxPrefix")? {
        o.inbox_prefix = prefix;
    }
    for (key, field) in [
        ("tls", &mut o.tls),
        ("tlsVerify", &mut o.tls_verify),
        ("allowReconnect", &mut o.allow_reconnect),
        ("verbose", &mut o.verbose),
        ("pedantic", &mut o.pedantic),
        ("echo", &mut o.echo),
        ("noRandomize", &mut o.no_randomize),
    ] {
        if let Some(b) = a.opt_bool(key)? {
            *field = b;
        }
    }
    for (key, field) in [
        ("maxReconnectAttempts", &mut o.max_reconnect_attempts),
        ("reconnectBufSize", &mut o.reconnect_buf_size),
        ("subPendingMsgsLimit", &mut o.sub_pending_msgs_limit),
        ("subPendingBytesLimit", &mut o.sub_pending_bytes_limit),
        ("maxPingsOut", &mut o.max_pings_out),
    ] {
        if let Some(n) = a.opt_i64(key)? {
            *field = n;
        }
    }
    for (key, field) in [
        ("reconnectWait", &mut o.reconnect_wait),
        ("maxReconnectWait", &mut o.max_reconnect_wait),
        ("reconnectJitter", &mut o.reconnect_jitter),
        ("connectTimeout", &mut o.connect_timeout),
        ("requestTimeout", &mut o.request_timeout),
        ("drainTimeout", &mut o.drain_timeout),
        ("pingInterval", &mut o.ping_interval),
    ] {
        if let Some(t) = a.opt(key) {
            *field = duration(t)?;
        }
    }
    if a.opt("tokenProvider").is_some() {
        let log = log.clone();
        let calls = Arc::new(Mutex::new(0i64));
        o.token_provider = Some(Arc::new(move || {
            let mut n = calls.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
            *n += 1;
            record(&log, json!(["tokenProvider", *n]));
            Ok(format!("token-{n}"))
        }));
    }
    if let Some(jwt) = s("jwtProvider")? {
        o.jwt_provider = Some(Arc::new(move || Ok(jwt.clone())));
    }
    if let Some(Value::Object(hooks)) = a.opt("hooks") {
        for (hook, spec) in hooks {
            let throw = spec.get("throw").cloned();
            let name = hook.clone();
            let log = log.clone();
            let fire = move |extra: Option<Value>| -> Result<(), CallbackError> {
                let mut entry = vec![Value::String(name.clone())];
                entry.extend(extra);
                record(&log, Value::Array(entry));
                match &throw {
                    Some(spec) => Err(callback_error(spec)),
                    None => Ok(()),
                }
            };
            match hook.as_str() {
                "onError" => {
                    o.on_error = Some(Arc::new(move |e: &Error| {
                        fire(Some(json!([e.php_class(), bytes_value(&e.message_bytes())])))
                    }))
                }
                "onSlowConsumer" => {
                    o.on_slow_consumer = Some(Arc::new(move |s: &SubscriptionState| fire(Some(json!(s.sid())))))
                }
                "onDisconnect" => o.on_disconnect = Some(Arc::new(move || fire(None))),
                "onReconnect" => o.on_reconnect = Some(Arc::new(move || fire(None))),
                "onClose" => o.on_close = Some(Arc::new(move || fire(None))),
                "onLameDuck" => o.on_lame_duck = Some(Arc::new(move || fire(None))),
                other => return Err(Fault::new(format!("unknown hook {other}"))),
            }
        }
    }
    Ok(o)
}

fn subscription_callback(conn: &Conn, args: &Args<'_>) -> Result<Option<MessageCallback>, Fault> {
    let log = conn.log.clone();
    let publisher = conn.conn.publisher();
    Ok(match args.opt_str("mode")?.unwrap_or("queue") {
        "queue" => None,
        "silent" => Some(Box::new(|_| Ok(()))),
        "record" => Some(Box::new(move |m: Message| {
            record(&log, json!(["msg", message_value(Some(&m))]));
            Ok(())
        })),
        "count" => Some(Box::new(move |_| {
            record(&log, json!(["count"]));
            Ok(())
        })),
        "echo" => {
            let prefix = args.opt_str("prefix")?.unwrap_or_default().as_bytes().to_vec();
            Some(Box::new(move |m: Message| {
                record(&log, json!(["msg", message_value(Some(&m))]));
                if let Some(reply) = m.reply_to {
                    let mut body = prefix.clone();
                    body.extend_from_slice(&m.data);
                    publisher.publish(reply, body, None, None);
                }
                Ok(())
            }))
        }
        "throw" => {
            let spec = args.value("error")?.clone();
            Some(Box::new(move |_| Err(callback_error(&spec))))
        }
        other => return Err(Fault::new(format!("unknown subscription mode {other}"))),
    })
}

fn handler(spec: &Value) -> Result<Handler, Fault> {
    let kind = spec.get("kind").and_then(Value::as_str).unwrap_or_default();
    Ok(match kind {
        "echo" => {
            let prefix = spec.get("prefix").and_then(Value::as_str).unwrap_or_default().as_bytes().to_vec();
            Box::new(move |m: &Message| {
                let mut body = prefix.clone();
                body.extend_from_slice(&m.data);
                Ok(Bytes::from(body))
            })
        }
        "fixed" => {
            let payload = Bytes::from(spec.get("payload").and_then(Value::as_str).unwrap_or_default().to_owned());
            Box::new(move |_| Ok(payload.clone()))
        }
        "throw" => {
            let error = spec.get("error").cloned().unwrap_or(Value::Null);
            Box::new(move |_| Err(callback_error(&error)))
        }
        other => return Err(Fault::new(format!("unknown handler {other}"))),
    })
}

fn string_map(value: Option<&Value>) -> IndexMap<String, String> {
    match value {
        Some(Value::Object(m)) => {
            m.iter().map(|(k, v)| (k.clone(), v.as_str().unwrap_or_default().to_owned())).collect()
        }
        _ => IndexMap::new(),
    }
}

// --- JetStream values ---

fn policy<T>(args: &Map<String, Value>, key: &str, parse: fn(&str) -> Option<T>, default: T) -> Result<T, Fault> {
    match args.get(key).and_then(Value::as_str) {
        Some(v) => parse(v).ok_or_else(|| Fault::new(format!("unknown {key} {v}"))),
        None => Ok(default),
    }
}

struct Named<'a>(&'a Map<String, Value>);

impl Named<'_> {
    fn s(&self, k: &str) -> Option<String> {
        self.0.get(k).and_then(Value::as_str).map(str::to_owned)
    }
    fn i(&self, k: &str) -> Option<i64> {
        self.0.get(k).and_then(Value::as_i64)
    }
    fn f(&self, k: &str) -> Option<f64> {
        self.0.get(k).and_then(float)
    }
    fn b(&self, k: &str) -> Option<bool> {
        self.0.get(k).and_then(Value::as_bool)
    }
    fn list(&self, k: &str) -> Option<Vec<String>> {
        self.0.get(k).and_then(Value::as_array).map(|a| a.iter().filter_map(Value::as_str).map(str::to_owned).collect())
    }
    fn map(&self, k: &str) -> Option<IndexMap<String, String>> {
        self.0.get(k).filter(|v| !v.is_null()).map(|v| match v {
            Value::Array(_) => IndexMap::new(),
            other => string_map(Some(other)),
        })
    }
    fn obj(&self, k: &str) -> Option<&Map<String, Value>> {
        self.0.get(k).and_then(Value::as_object)
    }
    fn check(&self, known: &[&str]) -> Result<(), Fault> {
        match self.0.keys().find(|k| !known.contains(&k.as_str())) {
            Some(k) => Err(Fault::new(format!("unknown named parameter {k}"))),
            None => Ok(()),
        }
    }
}

fn empty_map() -> &'static Map<String, Value> {
    static EMPTY: std::sync::OnceLock<Map<String, Value>> = std::sync::OnceLock::new();
    EMPTY.get_or_init(Map::new)
}

fn stream_source(a: &Map<String, Value>) -> Result<StreamSource, Fault> {
    let n = Named(a);
    n.check(&["name", "optStartSeq", "optStartTime", "filterSubject", "subjectTransforms", "external"])?;
    Ok(StreamSource {
        name: n.s("name").unwrap_or_default(),
        opt_start_seq: n.i("optStartSeq"),
        opt_start_time: n.s("optStartTime"),
        filter_subject: n.s("filterSubject"),
        subject_transforms: n
            .0
            .get("subjectTransforms")
            .and_then(Value::as_array)
            .map(|t| {
                t.iter().map(|x| subject_transform(x.as_object().unwrap_or(empty_map()))).collect::<Result<Vec<_>, _>>()
            })
            .transpose()?,
        external: n.obj("external").map(external).transpose()?,
    })
}

fn subject_transform(a: &Map<String, Value>) -> Result<SubjectTransform, Fault> {
    let n = Named(a);
    n.check(&["source", "destination"])?;
    Ok(SubjectTransform {
        source: n.s("source").unwrap_or_default(),
        destination: n.s("destination").unwrap_or_default(),
    })
}

fn external(a: &Map<String, Value>) -> Result<ExternalStream, Fault> {
    let n = Named(a);
    n.check(&["api", "deliver"])?;
    Ok(ExternalStream { api: n.s("api").unwrap_or_default(), deliver: n.s("deliver") })
}

fn republish(a: &Map<String, Value>) -> Result<Republish, Fault> {
    let n = Named(a);
    n.check(&["source", "destination", "headersOnly"])?;
    Ok(Republish {
        source: n.s("source").unwrap_or_default(),
        destination: n.s("destination").unwrap_or_default(),
        headers_only: n.b("headersOnly").unwrap_or(false),
    })
}

fn placement(a: &Map<String, Value>) -> Result<Placement, Fault> {
    let n = Named(a);
    n.check(&["cluster", "tags"])?;
    Ok(Placement { cluster: n.s("cluster"), tags: n.list("tags") })
}

fn consumer_limits(a: &Map<String, Value>) -> Result<ConsumerLimits, Fault> {
    let n = Named(a);
    n.check(&["inactiveThreshold", "maxAckPending"])?;
    Ok(ConsumerLimits { inactive_threshold: n.f("inactiveThreshold"), max_ack_pending: n.i("maxAckPending") })
}

fn stream_config(a: &Map<String, Value>) -> Result<StreamConfig, Fault> {
    let n = Named(a);
    n.check(&[
        "name",
        "subjects",
        "description",
        "retention",
        "maxConsumers",
        "maxMsgs",
        "maxBytes",
        "maxMsgsPerSubject",
        "maxMsgSize",
        "maxAge",
        "storage",
        "replicas",
        "discard",
        "noAck",
        "duplicateWindow",
        "allowDirect",
        "mirrorDirect",
        "sealed",
        "denyDelete",
        "denyPurge",
        "allowRollup",
        "metadata",
        "mirror",
        "sources",
        "republish",
        "subjectTransform",
        "placement",
        "compression",
        "firstSeq",
        "consumerLimits",
        "allowMsgTtl",
        "subjectDeleteMarkerTtl",
    ])?;
    let d = StreamConfig::new(n.s("name").unwrap_or_default());
    Ok(StreamConfig {
        subjects: n.list("subjects").unwrap_or_default(),
        description: n.s("description"),
        retention: policy(a, "retention", RetentionPolicy::parse, RetentionPolicy::Limits)?,
        max_consumers: n.i("maxConsumers").unwrap_or(d.max_consumers),
        max_msgs: n.i("maxMsgs").unwrap_or(d.max_msgs),
        max_bytes: n.i("maxBytes").unwrap_or(d.max_bytes),
        max_msgs_per_subject: n.i("maxMsgsPerSubject").unwrap_or(d.max_msgs_per_subject),
        max_msg_size: n.i("maxMsgSize"),
        max_age: n.f("maxAge"),
        storage: policy(a, "storage", StorageType::parse, StorageType::File)?,
        replicas: n.i("replicas").unwrap_or(1),
        discard: policy(a, "discard", DiscardPolicy::parse, DiscardPolicy::Old)?,
        no_ack: n.b("noAck").unwrap_or(false),
        duplicate_window: n.f("duplicateWindow"),
        allow_direct: n.b("allowDirect").unwrap_or(false),
        mirror_direct: n.b("mirrorDirect").unwrap_or(false),
        sealed: n.b("sealed").unwrap_or(false),
        deny_delete: n.b("denyDelete").unwrap_or(false),
        deny_purge: n.b("denyPurge").unwrap_or(false),
        allow_rollup: n.b("allowRollup").unwrap_or(false),
        metadata: n.map("metadata"),
        mirror: n.obj("mirror").map(stream_source).transpose()?,
        sources: n
            .0
            .get("sources")
            .and_then(Value::as_array)
            .map(|s| {
                s.iter().map(|x| stream_source(x.as_object().unwrap_or(empty_map()))).collect::<Result<Vec<_>, _>>()
            })
            .transpose()?,
        republish: n.obj("republish").map(republish).transpose()?,
        subject_transform: n.obj("subjectTransform").map(subject_transform).transpose()?,
        placement: n.obj("placement").map(placement).transpose()?,
        compression: n.s("compression"),
        first_seq: n.i("firstSeq"),
        consumer_limits: n.obj("consumerLimits").map(consumer_limits).transpose()?,
        allow_msg_ttl: n.b("allowMsgTtl").unwrap_or(false),
        subject_delete_marker_ttl: n.f("subjectDeleteMarkerTtl"),
        name: d.name,
    })
}

fn consumer_config(a: &Map<String, Value>) -> Result<ConsumerConfig, Fault> {
    let n = Named(a);
    n.check(&[
        "name",
        "durableName",
        "description",
        "deliverPolicy",
        "ackPolicy",
        "ackWait",
        "maxDeliver",
        "filterSubject",
        "filterSubjects",
        "replayPolicy",
        "maxWaiting",
        "maxAckPending",
        "inactiveThreshold",
        "optStartSeq",
        "optStartTime",
        "maxBatch",
        "maxBytes",
        "memStorage",
        "numReplicas",
        "deliverSubject",
        "deliverGroup",
        "flowControl",
        "idleHeartbeat",
        "metadata",
        "backoff",
    ])?;
    Ok(ConsumerConfig {
        name: n.s("name"),
        durable_name: n.s("durableName"),
        description: n.s("description"),
        deliver_policy: policy(a, "deliverPolicy", DeliverPolicy::parse, DeliverPolicy::All)?,
        ack_policy: policy(a, "ackPolicy", AckPolicy::parse, AckPolicy::Explicit)?,
        ack_wait: n.f("ackWait"),
        max_deliver: n.i("maxDeliver"),
        filter_subject: n.s("filterSubject"),
        filter_subjects: n.list("filterSubjects"),
        replay_policy: policy(a, "replayPolicy", ReplayPolicy::parse, ReplayPolicy::Instant)?,
        max_waiting: n.i("maxWaiting"),
        max_ack_pending: n.i("maxAckPending"),
        inactive_threshold: n.f("inactiveThreshold"),
        opt_start_seq: n.i("optStartSeq"),
        opt_start_time: n.s("optStartTime"),
        max_batch: n.i("maxBatch"),
        max_bytes: n.i("maxBytes"),
        mem_storage: n.b("memStorage").unwrap_or(false),
        num_replicas: n.i("numReplicas"),
        deliver_subject: n.s("deliverSubject"),
        deliver_group: n.s("deliverGroup"),
        flow_control: n.b("flowControl").unwrap_or(false),
        idle_heartbeat: n.f("idleHeartbeat"),
        metadata: n.map("metadata"),
        backoff: n.0.get("backoff").and_then(Value::as_array).map(|b| b.iter().filter_map(float).collect()),
    })
}

fn kv_config(a: &Map<String, Value>) -> Result<KeyValueConfig, Fault> {
    let n = Named(a);
    n.check(&["bucket", "description", "maxValueSize", "history", "ttl", "maxBytes", "storage", "replicas"])?;
    let d = KeyValueConfig::new(n.s("bucket").unwrap_or_default());
    Ok(KeyValueConfig {
        description: n.s("description"),
        max_value_size: n.i("maxValueSize").unwrap_or(d.max_value_size),
        history: n.i("history").unwrap_or(d.history),
        ttl: n.f("ttl"),
        max_bytes: n.i("maxBytes").unwrap_or(d.max_bytes),
        storage: policy(a, "storage", StorageType::parse, StorageType::File)?,
        replicas: n.i("replicas").unwrap_or(d.replicas),
        bucket: d.bucket,
    })
}

fn obj_config(a: &Map<String, Value>) -> Result<ObjectStoreConfig, Fault> {
    let n = Named(a);
    n.check(&["bucket", "description", "maxBytes", "ttl", "storage", "replicas"])?;
    let d = ObjectStoreConfig::new(n.s("bucket").unwrap_or_default());
    Ok(ObjectStoreConfig {
        description: n.s("description"),
        max_bytes: n.i("maxBytes").unwrap_or(d.max_bytes),
        ttl: n.f("ttl"),
        storage: policy(a, "storage", StorageType::parse, StorageType::File)?,
        replicas: n.i("replicas").unwrap_or(d.replicas),
        bucket: d.bucket,
    })
}

fn object_link(a: &Map<String, Value>) -> Result<ObjectLink, Fault> {
    let n = Named(a);
    n.check(&["bucket", "name"])?;
    Ok(ObjectLink { bucket: n.s("bucket").unwrap_or_default(), name: n.s("name") })
}

fn object_meta(a: &Map<String, Value>) -> Result<ObjectMeta, Fault> {
    let n = Named(a);
    n.check(&[
        "name",
        "bucket",
        "nuid",
        "size",
        "chunks",
        "digest",
        "description",
        "modified",
        "deleted",
        "metadata",
        "link",
    ])?;
    Ok(ObjectMeta {
        name: n.s("name").unwrap_or_default(),
        bucket: n.s("bucket").unwrap_or_default(),
        nuid: n.s("nuid").unwrap_or_default(),
        size: n.i("size").unwrap_or(0),
        chunks: n.i("chunks").unwrap_or(0),
        digest: n.s("digest").unwrap_or_default(),
        description: n.s("description"),
        modified: n.s("modified"),
        deleted: n.b("deleted").unwrap_or(false),
        metadata: n.map("metadata"),
        link: n.obj("link").map(object_link).transpose()?,
    })
}

fn config_new(kind: &str, args: &Value) -> Result<Value, Fault> {
    let a = args.as_object().unwrap_or(empty_map());
    Ok(match kind {
        "StreamConfig" => stream_config(a)?.to_value(),
        "ConsumerConfig" => consumer_config(a)?.to_value(),
        "ConsumerLimits" => consumer_limits(a)?.to_value(),
        "Placement" => placement(a)?.to_value(),
        "Republish" => republish(a)?.to_value(),
        "SubjectTransform" => subject_transform(a)?.to_value(),
        "ExternalStream" => external(a)?.to_value(),
        "StreamSource" => stream_source(a)?.to_value(),
        "KeyValueConfig" => kv_config(a)?.to_stream_config().to_value(),
        "ObjectStoreConfig" => obj_config(a)?.to_stream_config().to_value(),
        "ObjectLink" => object_link(a)?.to_value(),
        "ObjectMeta" => object_meta(a)?.to_value(),
        "KeyValueEntry" => {
            let n = Named(a);
            n.check(&["bucket", "key", "value", "revision", "created", "operation"])?;
            entry_value(&KeyValueEntry {
                bucket: n.s("bucket").unwrap_or_default(),
                key: n.s("key").unwrap_or_default(),
                value: Bytes::from(n.s("value").unwrap_or_default()),
                revision: n.i("revision").unwrap_or(0),
                created: n.s("created"),
                operation: Default::default(),
            })
        }
        "KeyValueWatchOptions" => {
            let n = Named(a);
            n.check(&["includeHistory", "updatesOnly", "ignoreDeletes", "metaOnly"])?;
            let o = KeyValueWatchOptions {
                include_history: n.b("includeHistory").unwrap_or(false),
                updates_only: n.b("updatesOnly").unwrap_or(false),
                ignore_deletes: n.b("ignoreDeletes").unwrap_or(false),
                meta_only: n.b("metaOnly").unwrap_or(false),
            };
            json!([o.include_history, o.updates_only, o.ignore_deletes, o.meta_only])
        }
        other => return Err(Fault::new(format!("unknown config {other}"))),
    })
}

fn opt_float(v: Option<f64>) -> Value {
    v.map_or(Value::Null, float_value)
}

fn config_from(kind: &str, data: &Value) -> Result<Value, Fault> {
    Ok(match kind {
        "StreamConfig" => {
            let c = StreamConfig::from_value(data);
            json!({
                "array": c.to_value(),
                "max_age": opt_float(c.max_age),
                "duplicate_window": opt_float(c.duplicate_window),
                "subject_delete_marker_ttl": opt_float(c.subject_delete_marker_ttl),
                "limits_ttl": opt_float(c.consumer_limits.as_ref().and_then(|l| l.inactive_threshold)),
            })
        }
        "ConsumerConfig" => {
            let c = ConsumerConfig::from_value(data);
            json!({
                "array": c.to_value(),
                "ack_wait": opt_float(c.ack_wait),
                "inactive_threshold": opt_float(c.inactive_threshold),
                "idle_heartbeat": opt_float(c.idle_heartbeat),
                "backoff": c.backoff.as_ref().map(|b| b.iter().map(|s| float_value(*s)).collect::<Vec<_>>()),
            })
        }
        "ConsumerLimits" => json!({"array": ConsumerLimits::from_value(data).to_value()}),
        "Placement" => json!({"array": Placement::from_value(data).to_value()}),
        "Republish" => json!({"array": Republish::from_value(data).to_value()}),
        "SubjectTransform" => json!({"array": SubjectTransform::from_value(data).to_value()}),
        "ExternalStream" => json!({"array": ExternalStream::from_value(data).to_value()}),
        "StreamSource" => json!({"array": StreamSource::from_value(data).to_value()}),
        "ObjectLink" => json!({"array": ObjectLink::from_value(data).to_value()}),
        "ObjectMeta" => json!({"array": ObjectMeta::from_value(data).to_value()}),
        other => return Err(Fault::new(format!("unknown config {other}"))),
    })
}

fn metadata_value(m: &MsgMetadata) -> Value {
    json!({
        "stream": m.stream, "consumer": norm(&m.consumer), "delivered": m.num_delivered, "stream_seq": m.stream_sequence,
        "consumer_seq": m.consumer_sequence, "timestamp": m.timestamp, "pending": m.num_pending, "domain": m.domain,
    })
}

fn stream_message_value(m: &StreamMessage) -> Value {
    json!({"subject": m.subject, "seq": m.sequence, "data": bytes_value(&m.data), "time": m.time.is_some(), "headers": headers_value(m.headers.as_ref(), None)})
}

fn stream_state_value(s: &StreamState, times: bool) -> Value {
    let mut out = json!({
        "messages": s.messages, "bytes": if times { json!(s.bytes) } else { json!(s.bytes > 0) }, "first_seq": s.first_seq, "last_seq": s.last_seq, "consumer_count": s.consumer_count,
        "num_deleted": s.num_deleted, "num_subjects": s.num_subjects,
    });
    if times {
        out["first_ts"] = json!(s.first_ts);
        out["last_ts"] = json!(s.last_ts);
    }
    out
}

fn stream_info_value(i: &StreamInfo) -> Value {
    json!({"config": i.config.to_value(), "state": stream_state_value(&i.state, false), "created": !i.created.is_empty()})
}

fn sequence_value(s: &SequenceInfo, times: bool) -> Value {
    let mut out = json!({"consumer_seq": s.consumer_seq, "stream_seq": s.stream_seq});
    if times {
        out["last_active"] = json!(s.last_active);
    }
    out
}

fn consumer_info_value(i: &ConsumerInfo, raw: bool) -> Value {
    let mut config = i.config.to_value();
    if !raw {
        for k in ["name", "durable_name", "deliver_subject"] {
            if let Some(Value::String(v)) = config.get(k).cloned() {
                config[k] = Value::String(norm(&v));
            }
        }
    }
    json!({
        "stream_name": i.stream_name,
        "name": if raw { i.name.clone() } else { norm(&i.name) },
        "config": config,
        "created": if raw { Value::String(i.created.clone()) } else { Value::Bool(!i.created.is_empty()) },
        "num_ack_pending": i.num_ack_pending, "num_redelivered": i.num_redelivered, "num_waiting": i.num_waiting, "num_pending": i.num_pending,
        "delivered": sequence_value(&i.delivered, raw), "ack_floor": sequence_value(&i.ack_floor, raw), "push_bound": i.push_bound,
        "cluster": i.cluster, "metadata": i.metadata.as_ref().map(|m| m.iter().map(|(k, v)| (k.clone(), json!(v))).collect::<Map<String, Value>>()),
    })
}

fn pub_ack_value(a: &PubAck) -> Value {
    json!({"stream": a.stream, "seq": a.sequence, "domain": a.domain, "duplicate": a.duplicate})
}

fn decode_info(kind: &str, data: &Value) -> Result<Outcome, Fault> {
    Ok(Outcome::ok(match kind {
        "MsgMetadata" => {
            let reply = data.as_str().ok_or_else(|| Fault::new("a reply subject"))?;
            metadata_value(&try_lib!(MsgMetadata::from_reply_subject(reply)))
        }
        "StreamMessage" => stream_message_value(&try_lib!(StreamMessage::from_value(data))),
        "StreamInfo" => {
            let info = StreamInfo::from_value(data);
            let mut out = stream_info_value(&info);
            out["created"] = Value::String(info.created.clone());
            out
        }
        "StreamState" => stream_state_value(&StreamState::from_value(data), true),
        "ConsumerInfo" => consumer_info_value(&ConsumerInfo::from_value(data), true),
        "SequenceInfo" => sequence_value(&SequenceInfo::from_value(data), true),
        "AccountInfo" => {
            let i = utopia_nats::jetstream::AccountInfo::from_value(data);
            json!([
                i.memory,
                i.storage,
                i.streams,
                i.consumers,
                php_json(&i.limits),
                i.api_total,
                i.api_errors,
                i.domain,
                php_json(&i.raw)
            ])
        }
        "ApiError" => {
            let e = utopia_nats::jetstream::ApiError::from_value(data);
            json!([e.code, e.err_code, e.description])
        }
        "PubAck" => pub_ack_value(&PubAck::from_value(data)),
        other => return Err(Fault::new(format!("unknown type {other}"))),
    }))
}

fn js_message_value(m: &JetStreamMessage) -> Value {
    json!({"subject": m.subject(), "data": bytes_value(m.data()), "headers": headers_value(m.headers(), None), "reply": m.message.reply_to.is_some()})
}

fn batch_value(session: &mut Session, batch: MessageBatch) -> Map<String, Value> {
    let count = batch.len();
    let listed = batch.messages().len();
    let messages: Vec<Value> = batch
        .into_iter()
        .map(|m| {
            let mut v = js_message_value(&m);
            let handle = shared(session, m);
            let mut out = Map::new();
            out.insert("handle".into(), handle);
            if let Value::Object(fields) = v.take() {
                out.extend(fields);
            }
            Value::Object(out)
        })
        .collect();
    let mut out = Map::new();
    out.insert("count".into(), json!(count));
    out.insert("listed".into(), json!(listed));
    out.insert("messages".into(), Value::Array(messages));
    out
}

fn entry_value(e: &KeyValueEntry) -> Value {
    json!({"bucket": e.bucket, "key": e.key, "value": bytes_value(&e.value), "revision": e.revision, "created": e.created.is_some(), "operation": e.operation.as_str()})
}

fn obj_meta_value(m: &ObjectMeta) -> Value {
    let mut out = m.to_value();
    if out.get("nuid").and_then(Value::as_str).is_some_and(|n| !n.is_empty()) {
        out["nuid"] = json!("*");
    }
    if out.get("mtime").is_some() {
        out["mtime"] = json!("*");
    }
    out
}

fn with_handle(handle: Value, key: &str, info: Value) -> Value {
    let mut out = Map::new();
    out.insert(key.into(), handle);
    out.insert("info".into(), info);
    Value::Object(out)
}

async fn parse_run(args: &Args<'_>) -> OpResult {
    let inbound = args.bytes("inbound")?;
    let limit = args.opt_i64("limit")?.unwrap_or(64);
    let then = args.opt("then").map(|_| args.bytes("then")).transpose()?;
    {
        let mut fake = Fake::new(Map::new());
        fake.push(&inbound);
        let mut parser = Parser::new();
        let mut ops = Vec::new();
        let mut error = Value::Null;
        for _ in 0..limit {
            match parser.next(&mut fake, Some(Duration::from_millis(10))).await {
                Ok(op) => ops.push(op_value(&op)),
                Err(e) => {
                    error = error_value(&e);
                    break;
                }
            }
        }
        let mut then_value = Value::Null;
        if let Some(then) = then {
            fake.push(&then);
            then_value = match parser.next(&mut fake, Some(Duration::from_millis(10))).await {
                Ok(op) => op_value(&op),
                Err(e) => error_value(&e),
            };
        }
        Ok(Outcome::ok(json!({"ops": ops, "error": error, "then": then_value, "buffered": parser.has_buffered_data()})))
    }
}

fn op_value(op: &Op) -> Value {
    let data = match op {
        Op::Msg(m) | Op::HMsg(m) => {
            json!([m.subject, m.sid, m.reply_to, bytes_value(&m.payload), m.headers.as_deref().map(bytes_value)])
        }
        Op::Info(v) => v.clone(),
        Op::Err(e) => json!(e),
        Op::Ping | Op::Pong | Op::Ok => Value::Null,
    };
    json!([op.kind().as_str(), data])
}

async fn authenticate(args: &Args<'_>, session: &Session) -> OpResult {
    let kind = args.str("kind")?;
    let nonce = args.opt_str("nonce")?;
    let auth: Box<dyn Authenticator> = match kind {
        "none" => Box::new(NoAuth),
        "token" => Box::new(TokenAuth::new(args.str("token")?)),
        "user_pass" => Box::new(UserPassAuth::new(args.str("user")?, args.str("pass")?)),
        "nkey" => {
            let auth = NKeyAuth::new(args.opt_str("public")?.unwrap_or_default(), args.str("seed")?);
            let mut out = Map::new();
            if args.opt_bool("public_key")?.unwrap_or(false) {
                out.insert("public_key".into(), json!(try_lib!(auth.public_key())));
            }
            out.insert("fields".into(), php_json(&Value::Object(try_lib!(auth.authenticate(nonce)))));
            return Ok(Outcome::ok(Value::Object(out)));
        }
        "credentials" => {
            let path = match args.opt("contents") {
                Some(_) => {
                    let dir = format!("/tmp/compat-fs/{}", session.ns);
                    std::fs::create_dir_all(&dir).map_err(|e| Fault::new(e.to_string()))?;
                    let path = format!("{dir}/user.creds");
                    std::fs::write(&path, args.bytes("contents")?).map_err(|e| Fault::new(e.to_string()))?;
                    path
                }
                None => args.str("path")?.to_owned(),
            };
            Box::new(try_lib!(CredentialsAuth::from_file(&path).await))
        }
        other => return Err(Fault::new(format!("unknown auth {other}"))),
    };
    Ok(Outcome::ok(json!({"fields": php_json(&Value::Object(try_lib!(auth.authenticate(nonce))))})))
}

async fn round_trip(args: &Args<'_>, session: &Session) -> OpResult {
    let url = session.service(args.str("service")?)?.to_owned();
    let parsed = php_std::url::parse_url(url.as_bytes()).ok_or_else(|| Fault::new("bad service URL"))?;
    let host = String::from_utf8_lossy(&parsed.host().unwrap_or_default()).into_owned();
    let port = parsed.port().unwrap_or(4222);
    let tls = tls_options(args.opt("tls"));
    let mut transport: Box<dyn Transport> = match args.str("kind")? {
        "tcp" => Box::new(TcpTransport::new()),
        "tls" => Box::new(TlsTransport::new(tls.clone())),
        "ws" => Box::new(WebSocketTransport::new(false, tls.clone(), args.opt_str("path")?.unwrap_or("/"))),
        other => return Err(Fault::new(format!("unknown transport {other}"))),
    };
    let mut out = Vec::new();
    let mut step = |name: &str, result: utopia_nats::Result<Value>| {
        out.push(match result {
            Ok(v) => json!([name, v]),
            Err(e) => json!([name, error_value(&e)]),
        })
    };
    let t = Some(Duration::from_secs(2));
    let connected = transport.connect(&host, port, Duration::from_secs(2)).await;
    let failed = connected.is_err();
    step("connect", connected.map(|()| Value::Null));
    step("connected", Ok(json!(transport.is_connected())));
    if failed {
        return Ok(Outcome::ok(Value::Array(out)));
    }
    step("info", transport.read_line(t).await.map(|l| json!(l.starts_with(b"INFO "))));
    if args.opt_bool("upgrade")?.unwrap_or(false) {
        let upgraded = transport.upgrade_tls(&tls).await;
        let failed = upgraded.is_err();
        step("upgrade", upgraded.map(|()| Value::Null));
        if failed {
            return Ok(Outcome::ok(Value::Array(out)));
        }
    }
    let connect = b"CONNECT {\"verbose\":false}\r\nPING\r\n";
    step("write", transport.write(connect).await.map(|n| json!(n)));
    let pong = async {
        let mut line = transport.read_line(t).await?;
        while line.starts_with(b"INFO ") || line == b"+OK\r\n" {
            line = transport.read_line(t).await?;
        }
        Ok(bytes_value(&line))
    }
    .await;
    step("pong", pong);
    step("ping", transport.write(b"PING\r\n").await.map(|n| json!(n)));
    let mut buf = Vec::new();
    step("read", transport.read(&mut buf, 64, t).await.map(|_| bytes_value(&buf)));
    transport.close().await;
    step("close", Ok(Value::Null));
    step("connected_after", Ok(json!(transport.is_connected())));
    step("write_after", transport.write(b"PING\r\n").await.map(|n| json!(n)));
    let mut buf = Vec::new();
    step("read_after", transport.read(&mut buf, 64, Some(Duration::from_millis(100))).await.map(|_| bytes_value(&buf)));
    Ok(Outcome::ok(Value::Array(out)))
}

fn publish_options(m: &Args<'_>) -> Result<PublishOptions, Fault> {
    Ok(PublishOptions {
        headers: opt_headers(m, "headers")?,
        msg_id: m.opt_str("msg_id")?.map(str::to_owned),
        expected_last_msg_id: m.opt_str("expected_last_msg_id")?.map(str::to_owned),
        expected_last_seq: m.opt_i64("expected_last_seq")?,
        expected_last_subject_seq: m.opt_i64("expected_last_subject_seq")?,
        expected_stream: m.opt_str("expected_stream")?.map(str::to_owned),
        ttl: match m.opt("ttl") {
            None => None,
            Some(Value::String(s)) => Some(Ttl::Duration(s.clone())),
            Some(v) => Some(Ttl::Seconds(v.as_i64().ok_or_else(|| Fault::new("ttl must be an int or a string"))?)),
        },
    })
}

// --- Operations ---

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    match op {
        "headers.build" => {
            Ok(Outcome::ok(headers_value(Some(&build_headers(a.opt("steps").unwrap_or(&json!([])))?), a.opt("query"))))
        }
        "headers.from_wire" => {
            Ok(Outcome::ok(headers_value(Some(&try_lib!(Headers::from_wire(&a.bytes("raw")?))), a.opt("query"))))
        }
        "inbox.create" => {
            let create = || match a.opt_str("prefix") {
                Ok(Some(prefix)) => Inbox::create(prefix),
                _ => Inbox::create("_INBOX"),
            };
            let inbox = create();
            let mut out = inbox_value(&inbox);
            out["unique"] = json!(inbox != create());
            Ok(Outcome::ok(out))
        }
        "inbox.generate_id" => {
            let id = Inbox::generate_id();
            Ok(Outcome::ok(
                json!({"length": id.len(), "alnum": id.bytes().all(|b| b.is_ascii_alphanumeric()), "unique": id != Inbox::generate_id()}),
            ))
        }
        "request.new" => {
            let headers = opt_headers(&a, "headers")?;
            let data = a.opt("data").map(|_| a.bytes("data")).transpose()?.unwrap_or_default();
            let request = try_lib!(Request::new(a.str("subject")?, data, headers));
            Ok(Outcome::ok(
                json!({"subject": request.subject(), "data": bytes_value(request.data()), "headers": headers_value(request.headers(), None)}),
            ))
        }
        "writer.connect" => Ok(Outcome::ok(bytes_value(&try_lib!(Writer::connect(a.value("options")?))))),
        "writer.pub" => {
            Ok(Outcome::ok(bytes_value(&Writer::publish(a.str("subject")?, &a.bytes("payload")?, a.opt_str("reply")?))))
        }
        "writer.hpub" => Ok(Outcome::ok(bytes_value(&Writer::hpub(
            a.str("subject")?,
            &a.bytes("headers")?,
            &a.bytes("payload")?,
            a.opt_str("reply")?,
        )))),
        "writer.sub" => {
            Ok(Outcome::ok(bytes_value(&Writer::sub(a.str("subject")?, a.str("sid")?, a.opt_str("queue")?))))
        }
        "writer.unsub" => Ok(Outcome::ok(bytes_value(&Writer::unsub(a.str("sid")?, a.opt_i64("max")?)))),
        "writer.ping" => Ok(Outcome::ok(bytes_value(Writer::ping()))),
        "writer.pong" => Ok(Outcome::ok(bytes_value(Writer::pong()))),
        "parser.run" => parse_run(&a).await,
        "server_info.from_array" => Ok(Outcome::ok(server_info(&ServerInfo::from_value(a.value("data")?)))),
        "connection.closes_connection" => Ok(Outcome::ok(Connection::closes_connection(a.str("message")?))),
        "connection.reconnects_after" => Ok(Outcome::ok(Connection::reconnects_after(a.str("message")?))),
        "connection.map_server_error" => {
            let e = Connection::map_server_error(a.str("message")?);
            Ok(Outcome::ok(json!([e.php_class(), bytes_value(&e.message_bytes())])))
        }
        "connection.reconnect_backoff" => Ok(Outcome::ok(float_value(Connection::reconnect_backoff(
            a.i64("attempt")?,
            a.f64("base")?,
            a.f64("cap")?,
            a.opt_f64("factor")?.unwrap_or(2.0),
        )))),
        "connection.reconnect_buffer_accepts" => {
            Ok(Outcome::ok(Connection::reconnect_buffer_accepts(a.i64("current")?, a.i64("incoming")?, a.i64("cap")?)))
        }
        "options.defaults" => {
            let options = match a.opt("servers") {
                Some(servers) => connection_options(&json!({"servers": servers}), &Log::default())?,
                None => ConnectionOptions::default(),
            };
            Ok(Outcome::ok(options_value(&options)))
        }
        "tls_transport.options" => {
            Ok(Outcome::ok(tls_value(TlsTransport::new(tls_options(a.opt("options"))).options(), false)))
        }
        "auth.fields" => authenticate(&a, session).await,
        "nanos.from_seconds" => Ok(Outcome::ok(seconds_to_nanos(a.f64("seconds")?))),
        "nanos.to_seconds" => Ok(Outcome::ok(float_value(nanos_to_seconds(a.i64("nanos")?)))),
        "config.new" => Ok(Outcome::ok(config_new(a.str("type")?, a.opt("args").unwrap_or(&json!({})))?)),
        "config.from" => Ok(Outcome::ok(config_from(a.str("type")?, a.value("data")?)?)),
        "decode.info" => decode_info(a.str("type")?, a.value("data")?),
        "jetstream.check_error" => {
            try_lib!(JetStream::check_error(a.value("data")?));
            Ok(Outcome::ok(Value::Null))
        }
        "jetstream.prefix" => Ok(Outcome::ok(JetStream::new(a.opt_str("domain")?, a.opt_str("prefix")?).api_prefix())),
        "endpoint.new" => {
            let endpoint = Endpoint::new(
                a.str("name")?,
                a.str("subject")?,
                Box::new(|_: &Message| Ok(Bytes::new())),
                a.opt_str("queue")?.unwrap_or("q"),
                string_map(a.opt("metadata")),
            );
            Ok(Outcome::ok(json!({"info": endpoint.info(), "stats": endpoint.stats()})))
        }
        "service_exception.new" => {
            let e = Error::Service { code: a.str("code")?.to_owned(), message: a.str("message")?.to_owned() };
            let Error::Service { code, message } = &e else { unreachable!() };
            Ok(Outcome::ok(json!([e.php_class(), code, message])))
        }

        "subscription.new" => {
            let log = Log::default();
            let callback: Option<MessageCallback> = if a.opt_str("mode")? == Some("callback") {
                let log = log.clone();
                Some(Box::new(move |m: Message| {
                    record(&log, json!(["message", bytes_value(&m.data)]));
                    Ok(())
                }))
            } else {
                None
            };
            let slow: Option<utopia_nats::SlowConsumerHook> = if a.opt_bool("slow")?.unwrap_or(false) {
                let log = log.clone();
                Some(Arc::new(move |s: &SubscriptionState| {
                    record(&log, json!(["slow", s.sid()]));
                    Ok(())
                }))
            } else {
                None
            };
            let state = SubscriptionState::new(
                a.str("sid")?,
                a.str("subject")?,
                a.opt_str("queue")?.map(str::to_owned),
                callback,
                a.opt_i64("msgs_limit")?.unwrap_or(65536),
                a.opt_i64("bytes_limit")?.unwrap_or(67_108_864),
                slow,
            );
            Ok(Outcome::ok(shared(session, Sub { state, log })))
        }
        "subscription.deliver" => {
            let sub = get::<Sub>(session, &a, "sub")?;
            let mut sub = sub.lock().await;
            try_lib!(sub.state.deliver(Message::new(a.opt_str("subject")?.unwrap_or("foo"), a.bytes("data")?)));
            Ok(Outcome::ok(Value::Null))
        }
        "subscription.next" => {
            let sub = get::<Sub>(session, &a, "sub")?;
            let mut sub = sub.lock().await;
            Ok(Outcome::ok(message_value(sub.state.next_message().as_ref())))
        }
        "subscription.set_max" => {
            let sub = get::<Sub>(session, &a, "sub")?;
            sub.lock().await.state.set_max_messages(a.i64("max")?);
            Ok(Outcome::ok(Value::Null))
        }
        "subscription.set_inactive" => {
            let sub = get::<Sub>(session, &a, "sub")?;
            sub.lock().await.state.set_inactive();
            Ok(Outcome::ok(Value::Null))
        }
        "subscription.state" => {
            let sub = get::<Sub>(session, &a, "sub")?;
            let sub = sub.lock().await;
            let mut out = sub_state(Some(&sub.state));
            out["sid"] = json!(sub.state.sid());
            out["subject"] = json!(sub.state.subject());
            out["queue"] = json!(sub.state.queue());
            out["log"] = Value::Array(sub.log.lock().unwrap_or_else(std::sync::PoisonError::into_inner).clone());
            Ok(Outcome::ok(out))
        }

        "fake.connect" => {
            let info = a.opt("info").and_then(Value::as_object).cloned().unwrap_or_default();
            let fake = Fake::new(info);
            fake.set_rules(a.opt("rules").and_then(Value::as_array).cloned().unwrap_or_default());
            let log = Log::default();
            let options = connection_options(a.opt("options").unwrap_or(&json!({})), &log)?;
            let transport = fake.clone();
            let factory_log = log.clone();
            let options = ConnectionOptions {
                transport_factory: Some(Arc::new(move |scheme: &str| {
                    record(&factory_log, json!(["transport", scheme]));
                    Ok(Box::new(transport.clone()) as Box<dyn Transport>)
                })),
                ..options
            };
            let conn = try_lib!(Connection::connect(options).await);
            Ok(Outcome::ok(shared(session, Conn { conn, log, fake: Some(fake) })))
        }
        "fake.push" => {
            fake_of(session, &a).await?.push(&a.bytes("data")?);
            Ok(Outcome::ok(Value::Null))
        }
        "fake.rules" => {
            fake_of(session, &a).await?.set_rules(a.array("rules")?.clone());
            Ok(Outcome::ok(Value::Null))
        }
        "fake.answer_pings" => {
            fake_of(session, &a).await?.lock().answer_pings = a.bool("answer")?;
            Ok(Outcome::ok(Value::Null))
        }
        "fake.fail_reads" => {
            fake_of(session, &a).await?.lock().fail_reads = a.opt("error").cloned();
            Ok(Outcome::ok(Value::Null))
        }
        "fake.count" => {
            let fake = fake_of(session, &a).await?;
            let state = fake.lock();
            let from = (a.opt_i64("from")?.unwrap_or(0) as usize).min(state.written.len());
            let needle = a.bytes("needle")?;
            Ok(Outcome::ok(occurrences(&state.written[from..], &needle)))
        }
        "fake.mark" => Ok(Outcome::ok(fake_of(session, &a).await?.lock().written.len())),
        "fake.written" => {
            let fake = fake_of(session, &a).await?;
            let state = fake.lock();
            let from = (a.opt_i64("from")?.unwrap_or(0) as usize).min(state.written.len());
            Ok(Outcome::ok(norm_bytes(&state.written[from..])))
        }
        "fake.writes" => Ok(Outcome::ok(Value::Array(
            fake_of(session, &a).await?.lock().writes.iter().map(|w| norm_bytes(w)).collect(),
        ))),
        "fake.connect_payload" => {
            let fake = fake_of(session, &a).await?;
            let written = fake.lock().written.clone();
            let start =
                written.windows(8).position(|w| w == b"CONNECT ").ok_or_else(|| Fault::new("no CONNECT written"))?;
            let end =
                written[start..].windows(2).position(|w| w == b"\r\n").map(|p| p + start).unwrap_or(written.len());
            let payload: Value =
                serde_json::from_slice(&written[start + 8..end]).map_err(|e| Fault::new(e.to_string()))?;
            Ok(Outcome::ok(payload))
        }
        "fake.state" => {
            let fake = fake_of(session, &a).await?;
            let state = fake.lock();
            Ok(Outcome::ok(
                json!({"connected": state.connected, "connects": state.connects, "tls_upgrades": state.tls_upgrades}),
            ))
        }
        "fake.tls_options" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let conn = conn.lock().await;
            Ok(Outcome::ok(tls_value(&conn.conn.options().tls_options(), true)))
        }

        "conn.connect" => {
            let url = match a.opt_str("url")? {
                Some(url) => url.to_owned(),
                None => session.service(a.opt_str("service")?.unwrap_or("nats"))?.to_owned(),
            };
            let mut options = a.opt("options").cloned().unwrap_or_else(|| json!({}));
            if options.get("servers").is_none() {
                options["servers"] = json!(url);
            }
            let log = Log::default();
            let options = connection_options(&options, &log)?;
            let conn = try_lib!(Connection::connect(options).await);
            Ok(Outcome::ok(shared(session, Conn { conn, log, fake: None })))
        }
        "conn.publish" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let headers = opt_headers(&a, "headers")?;
            let data = a.opt("data").map(|_| a.bytes("data")).transpose()?.unwrap_or_default();
            try_lib!(
                conn.lock().await.conn.publish(a.str("subject")?, data, a.opt_str("reply")?, headers.as_ref()).await
            );
            Ok(Outcome::ok(Value::Null))
        }
        "conn.subscribe" | "conn.queue_subscribe" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let mut conn = conn.lock().await;
            let callback = subscription_callback(&conn, &a)?;
            let sub = try_lib!(conn.conn.subscribe(a.str("subject")?, callback, a.opt_str("queue")?).await);
            drop(conn);
            Ok(Outcome::ok(shared(session, sub)))
        }
        "conn.unsubscribe" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let sub = get::<Subscription>(session, &a, "sub")?.lock().await.clone();
            try_lib!(conn.lock().await.conn.unsubscribe(&sub, a.opt_i64("max")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "conn.next_message" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let sub = get::<Subscription>(session, &a, "sub")?.lock().await.clone();
            let message = try_lib!(conn.lock().await.conn.next_message(&sub, timeout(&a)?).await);
            Ok(Outcome::ok(message_value(message.as_ref())))
        }
        "conn.sub_state" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let sub = get::<Subscription>(session, &a, "sub")?.lock().await.clone();
            Ok(Outcome::ok(sub_state(conn.lock().await.conn.subscription_state(&sub))))
        }
        "conn.process_message" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let message = try_lib!(conn.lock().await.conn.process_message(timeout(&a)?).await);
            if a.opt_bool("quiet")?.unwrap_or(false) {
                return Ok(Outcome::ok(message.is_some()));
            }
            Ok(Outcome::ok(message_value(message.as_ref())))
        }
        "conn.wait" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            try_lib!(conn.lock().await.conn.wait(a.opt_i64("count")?.unwrap_or(0) as usize, timeout(&a)?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "conn.request" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let headers = opt_headers(&a, "headers")?;
            let data = a.opt("data").map(|_| a.bytes("data")).transpose()?.unwrap_or_default();
            let reply =
                try_lib!(conn.lock().await.conn.request(a.str("subject")?, data, timeout(&a)?, headers.as_ref()).await);
            if a.opt_bool("json")?.unwrap_or(false) {
                let decoded: Value = serde_json::from_slice(&reply.data).map_err(|e| Fault::new(e.to_string()))?;
                return Ok(Outcome::ok(
                    json!({"json": php_json(&decoded), "headers": headers_value(reply.headers.as_ref(), None)}),
                ));
            }
            Ok(Outcome::ok(message_value(Some(&reply))))
        }
        "conn.request_tally" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let mut conn = conn.lock().await;
            let mut bodies = Vec::new();
            for i in 0..a.i64("count")? {
                let reply = try_lib!(conn.conn.request(a.str("subject")?, i.to_string(), timeout(&a)?, None).await);
                bodies.push(reply.data.to_vec());
            }
            let total = bodies.len();
            bodies.sort();
            bodies.dedup();
            Ok(Outcome::ok(
                json!({"total": total, "distinct": bodies.iter().map(|b| bytes_value(b)).collect::<Vec<_>>()}),
            ))
        }
        "conn.request_batch" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let mut requests = Vec::new();
            for r in a.array("requests")? {
                let r = Args(r);
                let data = r.opt("data").map(|_| r.bytes("data")).transpose()?.unwrap_or_default();
                requests.push(try_lib!(Request::new(r.str("subject")?, data, opt_headers(&r, "headers")?)));
            }
            let mode = a.opt_str("callback")?.unwrap_or("record").to_owned();
            let error = a.opt("error").cloned().unwrap_or(Value::Null);
            let sleep = a.opt_f64("sleep_ms")?.unwrap_or(0.0);
            let mut results = Vec::new();
            let t = match a.opt("timeout") {
                Some(v) => {
                    Some(Duration::try_from_secs_f64(float(v).unwrap_or(0.0)).map_err(|_| Fault::new("timeout"))?)
                }
                None => None,
            };
            let outcome = conn
                .lock()
                .await
                .conn
                .request_batch(
                    &requests,
                    |index, result| {
                        results.push(json!([
                            index,
                            match &result {
                                Ok(m) => message_value(Some(m)),
                                Err(e) => json!({"error": [e.php_class(), bytes_value(&e.message_bytes())]}),
                            }
                        ]));
                        if mode == "throw" {
                            return Err(callback_error(&error));
                        }
                        if mode == "sleep_first" && index == 0 {
                            std::thread::sleep(Duration::from_secs_f64(sleep / 1000.0));
                        }
                        Ok(())
                    },
                    t,
                )
                .await;
            try_lib!(outcome);
            Ok(Outcome::ok(Value::Array(results)))
        }
        "conn.request_many" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let options = RequestMany {
                max: a.opt_i64("max")?.map(|m| m as usize),
                timeout: a.opt("timeout").map(duration).transpose()?,
                stall: a.opt("stall").map(duration).transpose()?,
            };
            let data = a.opt("data").map(|_| a.bytes("data")).transpose()?.unwrap_or_default();
            let start = Instant::now();
            let replies = try_lib!(conn.lock().await.conn.request_many(a.str("subject")?, data, options).await);
            let mut bodies: Vec<Vec<u8>> = replies.iter().map(|m| m.data.to_vec()).collect();
            bodies.sort();
            let mut out = Map::new();
            out.insert("bodies".into(), Value::Array(bodies.iter().map(|b| bytes_value(b)).collect()));
            out.extend(timing(start.elapsed(), &a));
            Ok(Outcome::ok(Value::Object(out)))
        }
        "conn.flush" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            try_lib!(conn.lock().await.conn.flush(timeout(&a)?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "conn.drain" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let start = Instant::now();
            try_lib!(conn.lock().await.conn.drain(timeout(&a)?).await);
            Ok(Outcome::ok(php_json(&Value::Object(timing(start.elapsed(), &a)))))
        }
        "conn.close" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            try_lib!(conn.lock().await.conn.close().await);
            Ok(Outcome::ok(Value::Null))
        }
        "conn.tick" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            try_lib!(conn.lock().await.conn.tick().await);
            Ok(Outcome::ok(Value::Null))
        }
        "conn.state" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let conn = conn.lock().await;
            let c = &conn.conn;
            Ok(Outcome::ok(json!({
                "connected": c.is_connected(),
                "closed": c.is_closed(),
                "reconnecting": c.is_reconnecting(),
                "status": c.status().as_str(),
                "subscriptions": c.subscription_count(),
            })))
        }
        "conn.server_info" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let conn = conn.lock().await;
            Ok(Outcome::ok(conn.conn.server_info().map_or(Value::Null, server_info)))
        }
        "conn.new_inbox" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            Ok(Outcome::ok(inbox_value(&conn.lock().await.conn.new_inbox())))
        }
        "conn.options" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let conn = conn.lock().await;
            let o = conn.conn.options();
            Ok(Outcome::ok(json!({"servers": o.servers, "name": o.name, "inbox_prefix": o.inbox_prefix})))
        }
        "conn.log" => {
            let conn = get::<Conn>(session, &a, "conn")?;
            let conn = conn.lock().await;
            Ok(Outcome::ok(Value::Array(conn.log.lock().unwrap_or_else(std::sync::PoisonError::into_inner).clone())))
        }

        "transport.round_trip" => round_trip(&a, session).await,

        _ => call_jetstream(op, &a, session).await,
    }
}

async fn fake_of(session: &Session, a: &Args<'_>) -> Result<Fake, Fault> {
    let conn = get::<Conn>(session, a, "conn")?;
    let conn = conn.lock().await;
    conn.fake.clone().ok_or_else(|| Fault::new("not a fake connection"))
}

async fn call_jetstream(op: &str, a: &Args<'_>, session: &mut Session) -> OpResult {
    let conn = match a.opt("conn") {
        Some(_) => Some(get::<Conn>(session, a, "conn")?),
        None => None,
    };
    let conn = conn.ok_or_else(|| Fault::new(format!("{op}: missing argument `conn`")));
    let js_handle = match a.opt("js") {
        Some(h) => Some(session.get::<Shared<JetStream>>(h)?.lock().await.clone()),
        None => None,
    };
    let js = || js_handle.clone().ok_or_else(|| Fault::new(format!("{op}: missing argument `js`")));
    macro_rules! conn {
        () => {
            &mut conn.clone()?.lock().await.conn
        };
    }
    match op {
        "js.new" => {
            let c = conn?;
            let js = c.lock().await.conn.jetstream(a.opt_str("domain")?, a.opt_str("prefix")?);
            Ok(Outcome::ok(shared(session, js)))
        }
        "js.create_stream" | "js.update_stream" | "js.create_or_update_stream" => {
            let js = js()?;
            let config = stream_config(a.value("config")?.as_object().unwrap_or(empty_map()))?;
            let c = conn?;
            let mut c = c.lock().await;
            let stream = try_lib!(match op {
                "js.create_stream" => js.create_stream(&mut c.conn, &config).await,
                "js.update_stream" => js.update_stream(&mut c.conn, &config).await,
                _ => js.create_or_update_stream(&mut c.conn, &config).await,
            });
            drop(c);
            Ok(Outcome::ok(stream_result(session, stream).await))
        }
        "js.delete_stream" => {
            let js = js()?;
            try_lib!(js.delete_stream(conn!(), a.str("name")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "js.get_stream" => {
            let js = js()?;
            let stream = try_lib!(js.get_stream(conn!(), a.str("name")?).await);
            Ok(Outcome::ok(stream_result(session, stream).await))
        }
        "js.get_stream_info" => {
            let js = js()?;
            Ok(Outcome::ok(stream_info_value(&try_lib!(js.get_stream_info(conn!(), a.str("name")?).await))))
        }
        "js.get_stream_names" => {
            let js = js()?;
            let names = try_lib!(js.get_stream_names(conn!(), a.opt_str("subject")?).await);
            let contains = a.str("contains")?;
            Ok(Outcome::ok(sorted_strings(names.into_iter().filter(|n| n.contains(contains)).collect())))
        }
        "js.list_streams" => {
            let js = js()?;
            let infos = try_lib!(js.list_streams(conn!(), a.opt_str("subject")?).await);
            let contains = a.str("contains")?;
            Ok(Outcome::ok(sorted_strings(
                infos.into_iter().map(|i| i.config.name).filter(|n| n.contains(contains)).collect(),
            )))
        }
        "js.purge_stream" => {
            let js = js()?;
            try_lib!(js.purge_stream(conn!(), a.str("name")?, a.opt_str("subject")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "js.create_consumer" | "js.update_consumer" => {
            let js = js()?;
            let config = consumer_config(a.opt("config").and_then(Value::as_object).unwrap_or(empty_map()))?;
            let consumer = try_lib!(if op == "js.create_consumer" {
                js.create_consumer(conn!(), a.str("stream")?, &config).await
            } else {
                js.update_consumer(conn!(), a.str("stream")?, &config).await
            });
            let info = consumer_info_value(consumer.cached_info(), false);
            Ok(Outcome::ok(with_handle(shared(session, consumer), "consumer", info)))
        }
        "js.delete_consumer" => {
            let js = js()?;
            try_lib!(js.delete_consumer(conn!(), a.str("stream")?, a.str("name")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "js.get_consumer" => {
            let js = js()?;
            let consumer = try_lib!(js.get_consumer(conn!(), a.str("stream")?, a.str("name")?).await);
            let info = consumer_info_value(consumer.cached_info(), false);
            Ok(Outcome::ok(with_handle(shared(session, consumer), "consumer", info)))
        }
        "js.get_consumer_names" => {
            let js = js()?;
            Ok(Outcome::ok(sorted_strings(try_lib!(js.get_consumer_names(conn!(), a.str("stream")?).await))))
        }
        "js.get_consumers" => {
            let js = js()?;
            let mut infos: Vec<(String, String)> = try_lib!(js.get_consumers(conn!(), a.str("stream")?).await)
                .into_iter()
                .map(|i| (i.stream_name, i.name))
                .collect();
            infos.sort();
            Ok(Outcome::ok(Value::Array(infos.into_iter().map(|(s, n)| json!([s, n])).collect())))
        }
        "js.push_subscribe" => {
            let js = js()?;
            let c = conn?;
            let mut c = c.lock().await;
            let config = consumer_config(a.opt("config").and_then(Value::as_object).unwrap_or(empty_map()))?;
            let log = c.log.clone();
            let publisher = c.conn.publisher();
            let ack = a.opt_bool("ack")?.unwrap_or(true);
            let callback = Box::new(move |m: JetStreamMessage| -> Result<(), CallbackError> {
                record(&log, json!(["push", js_message_value(&m)]));
                if ack {
                    m.respond_via(&publisher, utopia_nats::jetstream::AckKind::Ack)?;
                }
                Ok(())
            });
            let push = try_lib!(js.push_subscribe(&mut c.conn, a.str("stream")?, &config, callback).await);
            drop(c);
            Ok(Outcome::ok(shared(session, push)))
        }
        "js.ordered_consumer" => {
            let js = js()?;
            let policy = DeliverPolicy::parse(a.opt_str("deliver_policy")?.unwrap_or("all"))
                .ok_or_else(|| Fault::new("deliver policy"))?;
            let ordered = try_lib!(
                js.ordered_consumer(
                    conn!(),
                    a.str("stream")?,
                    policy,
                    a.opt_str("filter")?,
                    a.opt_f64("heartbeat")?.unwrap_or(5.0)
                )
                .await
            );
            Ok(Outcome::ok(shared(session, ordered)))
        }
        "js.get_message" => {
            let js = js()?;
            Ok(Outcome::ok(stream_message_value(&try_lib!(
                js.get_message(conn!(), a.str("stream")?, a.i64("seq")?).await
            ))))
        }
        "js.get_last_message" => {
            let js = js()?;
            Ok(Outcome::ok(stream_message_value(&try_lib!(
                js.get_last_message(conn!(), a.str("stream")?, a.str("subject")?).await
            ))))
        }
        "js.delete_message" => {
            let js = js()?;
            try_lib!(
                js.delete_message(conn!(), a.str("stream")?, a.i64("seq")?, a.opt_bool("no_erase")?.unwrap_or(false))
                    .await
            );
            Ok(Outcome::ok(Value::Null))
        }
        "js.publish" => {
            let js = js()?;
            let data = a.opt("data").map(|_| a.bytes("data")).transpose()?.unwrap_or_default();
            let options = publish_options(a)?;
            Ok(Outcome::ok(pub_ack_value(&try_lib!(
                js.publish(conn!(), a.str("subject")?, &data, options, a.opt_i64("retry")?.unwrap_or(0)).await
            ))))
        }
        "js.publish_many" => {
            let js = js()?;
            let mut messages = Vec::new();
            for m in a.array("messages")? {
                let m = Args(m);
                messages.push(PublishMessage {
                    subject: m.str("subject")?.to_owned(),
                    data: m.opt("data").map(|_| m.bytes("data")).transpose()?.unwrap_or_default(),
                    options: publish_options(&m)?,
                });
            }
            let t = a.opt("timeout").map(duration).transpose()?;
            let acks = try_lib!(js.publish_many(conn!(), &messages, t, a.opt_i64("window")?.unwrap_or(256)).await);
            Ok(Outcome::ok(Value::Array(acks.iter().map(pub_ack_value).collect())))
        }
        "js.create_key_value" => {
            let js = js()?;
            let config = kv_config(a.value("config")?.as_object().unwrap_or(empty_map()))?;
            let kv = try_lib!(js.create_key_value(conn!(), &config).await);
            Ok(Outcome::ok(shared(session, kv)))
        }
        "js.get_key_value" => {
            let js = js()?;
            let kv = try_lib!(js.get_key_value(conn!(), a.str("bucket")?).await);
            Ok(Outcome::ok(shared(session, kv)))
        }
        "js.delete_key_value" => {
            let js = js()?;
            try_lib!(js.delete_key_value(conn!(), a.str("bucket")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "js.get_object_store" => {
            let js = js()?;
            let store = try_lib!(js.get_object_store(conn!(), a.str("bucket")?).await);
            Ok(Outcome::ok(shared(session, store)))
        }
        "js.delete_object_store" => {
            let js = js()?;
            try_lib!(js.delete_object_store(conn!(), a.str("bucket")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "js.account_info" => {
            let js = js()?;
            let i = try_lib!(js.account_info(conn!()).await);
            Ok(Outcome::ok(json!({
                "streams_ge_1": i.streams >= 1, "memory_ge_0": i.memory >= 0, "api_total_ge_0": i.api_total >= 0,
                "raw_has_memory": i.raw.get("memory").is_some(), "limits_is_array": i.limits.is_object() || i.limits.is_array(),
            })))
        }
        "js.ack_batch" => {
            let js = js()?;
            let mut messages = Vec::new();
            for h in a.array("messages")? {
                messages.push(session.get::<Shared<JetStreamMessage>>(h)?.lock().await.clone());
            }
            let mut confirmed = Vec::new();
            let t = a.opt("timeout").map(duration).transpose()?;
            try_lib!(
                js.ack_batch(
                    conn!(),
                    &messages,
                    |index, error| {
                        confirmed.push((index, error.map(|e| json!([e.php_class(), bytes_value(&e.message_bytes())]))));
                        Ok(())
                    },
                    t
                )
                .await
            );
            confirmed.sort_by_key(|(i, _)| *i);
            Ok(Outcome::ok(Value::Array(confirmed.into_iter().map(|(i, e)| json!([i, e])).collect())))
        }

        "stream.get" => {
            let stream = get::<Stream>(session, a, "stream")?;
            let stream = stream.lock().await;
            Ok(Outcome::ok(
                json!({"name": stream.name(), "config": stream.config().to_value(), "state": stream_state_value(stream.state(), false)}),
            ))
        }
        "stream.info" => {
            let stream = get::<Stream>(session, a, "stream")?;
            let mut stream = stream.lock().await;
            Ok(Outcome::ok(stream_info_value(try_lib!(
                stream.info(conn!(), a.opt_bool("refresh")?.unwrap_or(false)).await
            ))))
        }
        "stream.create_consumer" | "stream.get_consumer" => {
            let stream = get::<Stream>(session, a, "stream")?.lock().await.clone();
            let consumer = try_lib!(if op == "stream.create_consumer" {
                let config = consumer_config(a.opt("config").and_then(Value::as_object).unwrap_or(empty_map()))?;
                stream.create_consumer(conn!(), &config).await
            } else {
                stream.get_consumer(conn!(), a.str("name")?).await
            });
            let info = consumer_info_value(consumer.cached_info(), false);
            Ok(Outcome::ok(with_handle(shared(session, consumer), "consumer", info)))
        }
        "stream.delete_consumer" => {
            let stream = get::<Stream>(session, a, "stream")?.lock().await.clone();
            try_lib!(stream.delete_consumer(conn!(), a.str("name")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "stream.purge" => {
            let stream = get::<Stream>(session, a, "stream")?.lock().await.clone();
            try_lib!(stream.purge(conn!(), a.opt_str("subject")?).await);
            Ok(Outcome::ok(Value::Null))
        }
        "stream.delete" => {
            let stream = get::<Stream>(session, a, "stream")?.lock().await.clone();
            try_lib!(stream.delete(conn!()).await);
            Ok(Outcome::ok(Value::Null))
        }

        "consumer.new" => {
            let info = ConsumerInfo::from_value(a.value("info")?);
            let consumer = utopia_nats::jetstream::Consumer::new(
                a.str("stream")?,
                info,
                a.opt_str("prefix")?.unwrap_or("$JS.API"),
            );
            Ok(Outcome::ok(shared(session, consumer)))
        }
        "consumer.fetch" => {
            let consumer = get::<utopia_nats::jetstream::Consumer>(session, a, "consumer")?.lock().await.clone();
            let start = Instant::now();
            let batch = try_lib!(
                consumer
                    .fetch(
                        conn!(),
                        a.opt_i64("batch")?.unwrap_or(1),
                        timeout(a)?,
                        a.opt_bool("no_wait")?.unwrap_or(false),
                        a.opt_i64("max_bytes")?
                    )
                    .await
            );
            let elapsed = start.elapsed();
            let mut out = batch_value(session, batch);
            out.extend(timing(elapsed, a));
            Ok(Outcome::ok(Value::Object(out)))
        }
        "consumer.next" => {
            let consumer = get::<utopia_nats::jetstream::Consumer>(session, a, "consumer")?.lock().await.clone();
            let message = try_lib!(consumer.next(conn!(), timeout(a)?).await);
            Ok(Outcome::ok(match message {
                None => Value::Null,
                Some(m) => {
                    let value = js_message_value(&m);
                    let mut out = Map::new();
                    out.insert("handle".into(), shared(session, m));
                    if let Value::Object(fields) = value {
                        out.extend(fields);
                    }
                    Value::Object(out)
                }
            }))
        }
        "consumer.info" => {
            let consumer = get::<utopia_nats::jetstream::Consumer>(session, a, "consumer")?;
            let mut consumer = consumer.lock().await;
            Ok(Outcome::ok(consumer_info_value(
                try_lib!(consumer.info(conn!(), a.opt_bool("refresh")?.unwrap_or(false)).await),
                false,
            )))
        }
        "consumer.names" => {
            let consumer = get::<utopia_nats::jetstream::Consumer>(session, a, "consumer")?;
            let consumer = consumer.lock().await;
            Ok(Outcome::ok(json!([norm(consumer.name()), consumer.stream()])))
        }

        "jsmsg.new" => {
            let mut message = Message::new(
                a.opt_str("subject")?.unwrap_or("foo"),
                a.opt("data").map(|_| a.bytes("data")).transpose()?.unwrap_or_default(),
            );
            message.reply_to = a.opt_str("reply")?.map(str::to_owned);
            Ok(Outcome::ok(shared(session, JetStreamMessage::new(message))))
        }
        "jsmsg.ack" | "jsmsg.nak" | "jsmsg.in_progress" | "jsmsg.term" | "jsmsg.ack_sync" => {
            let m = get::<JetStreamMessage>(session, a, "msg")?.lock().await.clone();
            try_lib!(match op {
                "jsmsg.ack" => m.ack(conn!()).await,
                "jsmsg.nak" => m.nak(conn!(), a.opt_f64("delay")?).await,
                "jsmsg.in_progress" => m.in_progress(conn!()).await,
                "jsmsg.term" => m.term(conn!(), a.opt_str("reason")?).await,
                _ => m.ack_sync(conn!(), timeout(a)?).await,
            });
            Ok(Outcome::ok(Value::Null))
        }
        "jsmsg.metadata" => {
            let m = get::<JetStreamMessage>(session, a, "msg")?.lock().await.clone();
            Ok(Outcome::ok(metadata_value(&try_lib!(m.metadata()))))
        }
        "jsmsg.get" => {
            let m = get::<JetStreamMessage>(session, a, "msg")?.lock().await.clone();
            Ok(Outcome::ok(js_message_value(&m)))
        }
        "batch.new" => {
            conn?;
            let mut batch = MessageBatch::default();
            for m in a.array("messages")? {
                let m = Args(m);
                let mut message = Message::new(
                    m.opt_str("subject")?.unwrap_or("foo"),
                    m.opt("data").map(|_| m.bytes("data")).transpose()?.unwrap_or_default(),
                );
                message.reply_to = m.opt_str("reply")?.map(str::to_owned);
                batch.add_message(message);
            }
            Ok(Outcome::ok(Value::Object(batch_value(session, batch))))
        }

        "push.get" => {
            let push = get::<PushSubscription>(session, a, "push")?;
            let push = push.lock().await;
            Ok(Outcome::ok(json!({
                "consumer": norm(push.consumer_name()),
                "info_name": norm(&push.consumer_info().name),
                "subject": norm(&push.subscription().subject),
            })))
        }
        "push.unsubscribe" => {
            let push = get::<PushSubscription>(session, a, "push")?.lock().await.clone();
            try_lib!(push.unsubscribe(conn!()).await);
            Ok(Outcome::ok(Value::Null))
        }
        "push.handle_control" => {
            let c = conn?;
            let mut c = c.lock().await;
            let m = Args(a.value("message")?);
            let mut message = Message::new(
                m.opt_str("subject")?.unwrap_or("foo"),
                m.opt("data").map(|_| m.bytes("data")).transpose()?.unwrap_or_default(),
            );
            message.reply_to = m.opt_str("reply")?.map(str::to_owned);
            message.headers = opt_headers(&m, "headers")?;
            let handled = PushSubscription::handle_control(&c.conn.publisher(), &message);
            try_lib!(c.conn.send_queued().await);
            Ok(Outcome::ok(handled))
        }

        "ordered.next" => {
            let ordered = get::<OrderedConsumer>(session, a, "ordered")?;
            let mut ordered = ordered.lock().await;
            let message = try_lib!(ordered.next(conn!(), timeout(a)?).await);
            Ok(Outcome::ok(match message {
                None => Value::Null,
                Some(m) => {
                    let meta = try_lib!(m.metadata());
                    let mut out = js_message_value(&m);
                    out["stream_seq"] = json!(meta.stream_sequence);
                    out["consumer_seq"] = json!(meta.consumer_sequence);
                    out
                }
            }))
        }
        "ordered.name" => {
            let ordered = get::<OrderedConsumer>(session, a, "ordered")?;
            let name = ordered.lock().await.consumer_name().to_owned();
            Ok(Outcome::ok(if a.opt_bool("raw")?.unwrap_or(false) { name } else { norm(&name) }))
        }
        "ordered.stop" => {
            let ordered = get::<OrderedConsumer>(session, a, "ordered")?;
            try_lib!(ordered.lock().await.stop(conn!()).await);
            Ok(Outcome::ok(Value::Null))
        }
        "ordered.skip" => {
            let ordered = get::<OrderedConsumer>(session, a, "ordered")?;
            let mut ordered = ordered.lock().await;
            let next = ordered.expected_sequence() + a.opt_i64("by")?.unwrap_or(1);
            ordered.set_expected_sequence(next);
            Ok(Outcome::ok(next))
        }

        op if op.starts_with("kv.") => call_kv(op, a, session, conn?).await,
        op if op.starts_with("obj.") => call_obj(op, a, session, conn?).await,
        op if op.starts_with("service.") || op.starts_with("group.") => call_service(op, a, session, conn).await,
        other => Err(Fault::new(format!("nats: unknown operation `{other}`"))),
    }
}

async fn stream_result(session: &mut Session, stream: Stream) -> Value {
    let info = stream_info_value(stream.cached_info());
    with_handle(shared(session, stream), "stream", info)
}

async fn call_kv(op: &str, a: &Args<'_>, session: &mut Session, conn: Shared<Conn>) -> OpResult {
    if op == "kv.new" {
        let js = session.get::<Shared<JetStream>>(a.value("js")?)?.lock().await.clone();
        return Ok(Outcome::ok(shared(session, KeyValue::new(js, a.str("bucket")?))));
    }
    let kv = get::<KeyValue>(session, a, "kv")?.lock().await.clone();
    let mut guard = conn.lock().await;
    let log = guard.log.clone();
    let c = &mut guard.conn;
    Ok(Outcome::ok(match op {
        "kv.get" => entry_value(&try_lib!(kv.get(c, a.str("key")?).await)),
        "kv.put" => json!(try_lib!(kv.put(c, a.str("key")?, a.bytes("value")?).await)),
        "kv.create" => json!(try_lib!(kv.create(c, a.str("key")?, a.bytes("value")?).await)),
        "kv.update" => json!(try_lib!(kv.update(c, a.str("key")?, a.bytes("value")?, a.i64("revision")?).await)),
        "kv.delete" => {
            try_lib!(kv.delete(c, a.str("key")?).await);
            Value::Null
        }
        "kv.purge" => {
            try_lib!(kv.purge(c, a.str("key")?).await);
            Value::Null
        }
        "kv.keys" => sorted_strings(try_lib!(kv.keys(c).await)),
        "kv.status" => {
            let s = try_lib!(kv.status(c).await);
            json!({"bucket": s.bucket, "values": s.values, "bytes_gt_0": s.bytes > 0, "history": s.history, "ttl": opt_float(s.ttl), "stream": s.stream_info.config.name})
        }
        "kv.get_revision" => entry_value(&try_lib!(kv.get_revision(c, a.str("key")?, a.i64("seq")?).await)),
        "kv.history" => Value::Array(try_lib!(kv.history(c, a.str("key")?).await).iter().map(entry_value).collect()),
        "kv.watch" => {
            let options = a.opt("options").map(|o| KeyValueWatchOptions {
                include_history: o.get("include_history").and_then(Value::as_bool).unwrap_or(false),
                updates_only: o.get("updates_only").and_then(Value::as_bool).unwrap_or(false),
                ignore_deletes: o.get("ignore_deletes").and_then(Value::as_bool).unwrap_or(false),
                meta_only: o.get("meta_only").and_then(Value::as_bool).unwrap_or(false),
            });
            let entries = log.clone();
            let callback = Box::new(move |e: KeyValueEntry| -> Result<(), CallbackError> {
                record(&entries, json!(["entry", entry_value(&e)]));
                Ok(())
            });
            let init: Option<utopia_nats::key_value::InitCallback> = if a.opt_bool("init")?.unwrap_or(false) {
                let log = log.clone();
                Some(Box::new(move || {
                    let count = log.lock().unwrap_or_else(std::sync::PoisonError::into_inner).len();
                    record(&log, json!(["init", count]));
                    Ok(())
                }))
            } else {
                None
            };
            let sub = try_lib!(kv.watch(c, a.str("pattern")?, callback, options, init).await);
            drop(guard);
            return Ok(Outcome::ok(shared(session, sub)));
        }
        "kv.purge_deletes" => json!(try_lib!(kv.purge_deletes(c, a.opt_f64("threshold")?).await)),
        "kv.bucket" => json!(kv.bucket()),
        other => return Err(Fault::new(format!("nats: unknown operation `{other}`"))),
    }))
}

async fn call_obj(op: &str, a: &Args<'_>, session: &mut Session, conn: Shared<Conn>) -> OpResult {
    if op == "obj.new" || op == "obj.create_or_update" {
        let js = session.get::<Shared<JetStream>>(a.value("js")?)?.lock().await.clone();
        let store = if op == "obj.new" {
            ObjectStore::new(js, a.str("bucket")?)
        } else {
            let config = obj_config(a.value("config")?.as_object().unwrap_or(empty_map()))?;
            try_lib!(ObjectStore::create_or_update(&mut conn.lock().await.conn, &js, &config).await)
        };
        return Ok(Outcome::ok(shared(session, store)));
    }
    let store = get::<ObjectStore>(session, a, "obj")?.lock().await.clone();
    let previous = match a.opt("previous") {
        Some(h) => Some(session.get::<Shared<ObjectMeta>>(h)?.lock().await.clone()),
        None => None,
    };
    let meta = match a.opt("meta") {
        Some(h) => Some(session.get::<Shared<ObjectMeta>>(h)?.lock().await.clone()),
        None => None,
    };
    let mut guard = conn.lock().await;
    let log = guard.log.clone();
    let c = &mut guard.conn;
    Ok(Outcome::ok(match op {
        "obj.put" => obj_meta_value(&try_lib!(store.put(c, a.str("name")?, &payload(a)?).await)),
        "obj.get" => {
            let data = try_lib!(store.get(c, a.str("name")?).await);
            json!({
                "size": data.len(),
                "sha256": php_std::string::hash(b"sha256", &data).unwrap_or_default(),
                "data": if data.len() <= 256 { bytes_value(&data) } else { Value::Null },
            })
        }
        "obj.get_meta" => obj_meta_value(&try_lib!(store.get_meta(c, a.str("name")?).await)),
        "obj.delete" => {
            try_lib!(store.delete(c, a.str("name")?).await);
            Value::Null
        }
        "obj.list" => sorted_strings(try_lib!(store.list(c).await).into_iter().map(|m| m.name).collect()),
        "obj.status" => {
            let info = try_lib!(store.status(c).await);
            json!({"name": info.config.name, "messages": info.state.messages})
        }
        "obj.watch" => {
            let callback = Box::new(move |m: ObjectMeta| -> Result<(), CallbackError> {
                record(&log, json!(["meta", obj_meta_value(&m)]));
                Ok(())
            });
            let sub = try_lib!(store.watch(c, callback, a.opt_bool("include_history")?.unwrap_or(false)).await);
            drop(guard);
            return Ok(Outcome::ok(shared(session, sub)));
        }
        "obj.add_link" => obj_meta_value(&try_lib!(store.add_link(c, a.str("name")?, a.str("target")?).await)),
        "obj.add_bucket_link" => {
            obj_meta_value(&try_lib!(store.add_bucket_link(c, a.str("name")?, a.str("bucket")?).await))
        }
        "obj.update_meta" => {
            let metadata = a.opt("metadata").map(|m| string_map(Some(m)));
            obj_meta_value(&try_lib!(store.update_meta(c, a.str("name")?, a.opt_str("description")?, metadata).await))
        }
        "obj.seal" => {
            try_lib!(store.seal(c).await);
            Value::Null
        }
        "obj.bucket" => json!(store.bucket()),
        "obj.read_meta" => {
            let (meta, seq) = try_lib!(store.read_meta_with_seq(c, a.str("name")?).await);
            drop(guard);
            let meta = meta.map(|m| shared(session, m)).unwrap_or(Value::Null);
            return Ok(Outcome::ok(json!({"meta": meta, "seq": seq})));
        }
        "obj.write_version" => obj_meta_value(&try_lib!(
            store.write_version(c, a.str("name")?, &payload(a)?, previous.as_ref(), a.i64("seq")?).await
        )),
        "obj.delete_version" => {
            let meta = meta.ok_or_else(|| Fault::new("missing argument `meta`"))?;
            try_lib!(store.delete_version(c, &meta, a.i64("seq")?).await);
            Value::Null
        }
        other => return Err(Fault::new(format!("nats: unknown operation `{other}`"))),
    }))
}

async fn call_service(op: &str, a: &Args<'_>, session: &mut Session, conn: Result<Shared<Conn>, Fault>) -> OpResult {
    let conn = move || conn.clone();
    match op {
        "service.new" => {
            let service = Service::new(
                a.str("name")?,
                a.str("version")?,
                a.opt_str("description")?.unwrap_or_default(),
                string_map(a.opt("metadata")),
            );
            Ok(Outcome::ok(shared(session, service)))
        }
        "service.add_endpoint" | "service.register_endpoint" => {
            let service = get::<Service>(session, a, "service")?.lock().await.clone();
            let c = conn()?;
            let mut c = c.lock().await;
            let h = handler(a.value("handler")?)?;
            let metadata = string_map(a.opt("metadata"));
            if op == "service.add_endpoint" {
                try_lib!(
                    service
                        .add_endpoint(&mut c.conn, a.str("name")?, a.str("subject")?, h, a.opt_str("queue")?, metadata)
                        .await
                );
                Ok(Outcome::ok(true))
            } else {
                try_lib!(
                    service
                        .register_endpoint(
                            &mut c.conn,
                            a.str("name")?,
                            a.str("subject")?,
                            h,
                            a.opt_str("queue")?,
                            metadata
                        )
                        .await
                );
                Ok(Outcome::ok(Value::Null))
            }
        }
        "service.add_group" => {
            let service = get::<Service>(session, a, "service")?.lock().await.clone();
            Ok(Outcome::ok(shared(session, service.add_group(a.str("name")?, a.opt_str("queue")?))))
        }
        "group.add_group" => {
            let group = get::<Group>(session, a, "group")?.lock().await.clone();
            Ok(Outcome::ok(shared(session, group.add_group(a.str("name")?, a.opt_str("queue")?))))
        }
        "group.add_endpoint" => {
            let group = get::<Group>(session, a, "group")?.lock().await.clone();
            let c = conn()?;
            let mut c = c.lock().await;
            try_lib!(
                group
                    .add_endpoint(
                        &mut c.conn,
                        a.str("name")?,
                        handler(a.value("handler")?)?,
                        a.opt_str("subject")?,
                        a.opt_str("queue")?,
                        string_map(a.opt("metadata"))
                    )
                    .await
            );
            Ok(Outcome::ok(true))
        }
        "service.start" => {
            let service = get::<Service>(session, a, "service")?.lock().await.clone();
            try_lib!(service.start(&mut conn()?.lock().await.conn).await);
            Ok(Outcome::ok(true))
        }
        "service.run" => {
            let service = get::<Service>(session, a, "service")?.lock().await.clone();
            try_lib!(service.run(&mut conn()?.lock().await.conn).await);
            Ok(Outcome::ok(Value::Null))
        }
        "service.stop" => {
            let service = get::<Service>(session, a, "service")?.lock().await.clone();
            service.stop(&mut conn()?.lock().await.conn).await;
            Ok(Outcome::ok(Value::Null))
        }
        "service.get" => {
            let service = get::<Service>(session, a, "service")?.lock().await.clone();
            let id = service.id();
            Ok(Outcome::ok(json!({
                "name": service.name(),
                "id_length": id.len(),
                "id_hex": id.bytes().all(|b| b.is_ascii_hexdigit()) && id.to_uppercase() == id,
            })))
        }
        "service.id" => Ok(Outcome::ok(get::<Service>(session, a, "service")?.lock().await.id())),
        other => Err(Fault::new(format!("nats: unknown operation `{other}`"))),
    }
}
