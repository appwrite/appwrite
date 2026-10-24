//! Compat adapter for `websocket`: maps `tests/compat/websocket/spec.json`
//! operations onto `utopia-websocket`: servers on the in-process `Memory`
//! adapter, clients on scripted connections, and E2E runs against the
//! `Network` adapter serving the handlers of `tests/compat/websocket/server.php`.

use std::collections::HashMap;
use std::hint::black_box;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use bytes::Bytes;
use futures_util::future::BoxFuture;
use indexmap::IndexMap;
use serde_json::{Value, json};
use utopia_async::io::{AsyncReadExt, AsyncWriteExt};
use utopia_async::net::{TcpSocket, TcpStream};
use utopia_websocket::client::{Frame, NO_CONNECTION, Opcode, Options, Received, Transport, TransportError};
use utopia_websocket::{Adapter, Client, Error, Memory, Network, Request, Server, Stage};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "server.new",
    "server.on",
    "server.error",
    "server.start",
    "server.shutdown",
    "server.send",
    "server.close",
    "server.log",
    "server.dispatch",
    "memory.fail",
    "memory.config",
    "memory.open",
    "memory.message",
    "memory.disconnect",
    "memory.request",
    "memory.state",
    "client.new",
    "client.hooks",
    "client.attach",
    "client.script",
    "client.connect",
    "client.listen",
    "client.receive",
    "client.send",
    "client.close",
    "client.is_connected",
    "client.log",
    "swoole.config",
    "e2e.run",
];

/// Benchmark inputs, the same as in Adapter.php.
const URL: &str = "wss://cloud.appwrite.io/v1/realtime?project=6650c0de&channels%5B%5D=account";
const FANOUT: i64 = 1000;

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("dispatch", "Deliver a message to the server's message hook, which replies to its connection"),
    ("fanout", "Send one message to 1,000 open connections"),
    ("client", "Create a client: parse a wss URL with a path and a query"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "dispatch" => {
            let adapter = Memory::new();
            let server = Server::new(adapter.clone());
            let replies = server.clone();
            server.on_message(move |connection, _| replies.send(&[connection], Bytes::from_static(b"pong")));
            adapter.open(1, &Request::default());
            Bench::sync(move || {
                adapter.message(1, black_box(b"ping"));
                black_box(adapter.take_frames());
            })
        }
        "fanout" => {
            let adapter = Memory::new();
            let server = Server::new(adapter.clone());
            let request = Request::default();
            for id in 1..=FANOUT {
                adapter.open(id, &request);
            }
            let ids: Vec<i64> = (1..=FANOUT).collect();
            let message = Bytes::from_static(br#"{"events":["databases.*"],"payload":{"$id":"6650c1f2a4b3e9d7c812"}}"#);
            Bench::sync(move || {
                server.send(black_box(&ids), message.clone());
                black_box(adapter.take_frames());
            })
        }
        "client" => Bench::sync(|| {
            let options = Options { timeout: Duration::from_secs(10), ..Options::default() };
            black_box(Client::new(black_box(URL), options).ok());
        }),
        _ => return Err(Fault::new(format!("websocket: no benchmark `{name}`"))),
    })
}

type Log = Arc<Mutex<Vec<Value>>>;

fn push(log: &Log, entry: Value) {
    log.lock().unwrap_or_else(|e| e.into_inner()).push(entry);
}

/// A server on the in-process adapter, with what its hooks recorded.
struct Rig {
    adapter: Memory,
    server: Server<Memory>,
    log: Log,
    /// Connections the open hook saw, for broadcasts.
    connections: Arc<Mutex<IndexMap<i64, ()>>>,
    errors: usize,
}

/// A client with what its hooks (and its scripted transport) recorded.
struct Probe {
    client: Client,
    log: Log,
}

/// A scripted connection, the twin of `Scripted` in Adapter.php.
struct Scripted {
    script: std::collections::VecDeque<Value>,
    log: Log,
    push_fail: Option<TransportError>,
}

impl Transport for Scripted {
    fn recv(&mut self, _timeout: Duration) -> BoxFuture<'_, Received> {
        let item = self.script.pop_front();
        Box::pin(async move {
            let Some(item) = item else {
                return Received::Failed(TransportError::new(NO_CONNECTION, ""));
            };
            if let Some(opcode) = item.get("opcode").and_then(Value::as_u64) {
                let data = item.get("data").and_then(bytes).unwrap_or_default();
                return Received::Frame(Frame { opcode: Opcode::from_code(opcode as u8), data: Bytes::from(data) });
            }
            if let Some(code) = item.get("code").and_then(Value::as_i64) {
                return Received::Failed(TransportError::new(
                    code,
                    item.get("message").and_then(Value::as_str).unwrap_or(""),
                ));
            }
            Received::Empty
        })
    }

    fn push(&mut self, data: Bytes, opcode: Opcode) -> BoxFuture<'_, Result<(), TransportError>> {
        push(&self.log, json!(["push", bytes_value(&data), opcode.code()]));
        let result = self.push_fail.clone().map_or(Ok(()), Err);
        Box::pin(async move { result })
    }

    fn close(&mut self) -> BoxFuture<'_, ()> {
        push(&self.log, json!(["transport.close"]));
        Box::pin(async {})
    }
}

fn err(e: &Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

/// A request from `{method, uri, headers, body}`.
fn request(spec: Option<&Value>) -> Result<Request, Fault> {
    let spec = spec.cloned().unwrap_or_else(|| json!({}));
    let mut builder = http::Request::builder()
        .method(spec.get("method").and_then(Value::as_str).unwrap_or("GET"))
        .uri(spec.get("uri").and_then(Value::as_str).unwrap_or("/"));
    for header in spec.get("headers").and_then(Value::as_array).into_iter().flatten() {
        let name = header.get(0).and_then(Value::as_str).unwrap_or_default();
        let value = header.get(1).and_then(Value::as_str).unwrap_or_default();
        builder = builder.header(name, value);
    }
    let body = spec.get("body").and_then(bytes).unwrap_or_default();
    builder.body(Bytes::from(body)).map_err(|e| Fault::new(format!("request: {e}")))
}

/// Registers the recording hook named `hook` (the commands of the E2E
/// fixture for messages).
fn on(rig: &Rig, hook: &str) -> Result<(), Fault> {
    let log = rig.log.clone();
    let server = &rig.server;
    match hook {
        "start" => server.on_start(move || push(&log, json!(["start"]))),
        "worker_start" => server.on_worker_start(move |w| push(&log, json!(["workerStart", w]))),
        "worker_stop" => server.on_worker_stop(move |w| push(&log, json!(["workerStop", w]))),
        "open" => {
            let connections = rig.connections.clone();
            server.on_open(move |connection, request| {
                connections.lock().unwrap_or_else(|e| e.into_inner()).insert(connection, ());
                push(&log, json!(["open", connection, request.method().as_str(), request.uri().path()]));
            })
        }
        "close" => {
            let connections = rig.connections.clone();
            server.on_close(move |connection| {
                connections.lock().unwrap_or_else(|e| e.into_inner()).shift_remove(&connection);
                push(&log, json!(["close", connection]));
            })
        }
        "message" => {
            let connections = rig.connections.clone();
            let replies = server.clone();
            server.on_message(move |connection, message| {
                push(&log, json!(["message", connection, bytes_value(message)]));
                match message {
                    b"ping" => replies.send(&[connection], "pong"),
                    b"pong" => replies.send(&[connection], "ping"),
                    b"broadcast" => {
                        let all: Vec<i64> =
                            connections.lock().unwrap_or_else(|e| e.into_inner()).keys().copied().collect();
                        replies.send(&all, "broadcast");
                    }
                    b"disconnect" => {
                        replies.send(&[connection], "disconnect");
                        replies.close(connection, 1000);
                    }
                    _ => {
                        if let Some(rest) = message.strip_prefix(b"echo:") {
                            replies.send(&[connection], rest.to_vec());
                        }
                    }
                }
            })
        }
        "request" => server.on_request(move |request, reply| {
            let method = request.method().as_str();
            let path = request.uri().path();
            push(&log, json!(["request", method, path, bytes_value(request.body())]));
            *reply.status_mut() = if path == "/missing" { http::StatusCode::NOT_FOUND } else { http::StatusCode::OK };
            *reply.body_mut() = format!("{method} {path}").into_bytes();
        }),
        _ => return Err(Fault::new(format!("unknown hook {hook}"))),
    };
    Ok(())
}

fn state(adapter: &Memory) -> Value {
    json!({
        "started": adapter.is_started(),
        "connections": adapter.connections(),
        "frames": adapter.frames().iter().map(|f| json!([f.connection, bytes_value(&f.data)])).collect::<Vec<_>>(),
        "closed": adapter.closed().iter().map(|c| json!([c.connection, c.code])).collect::<Vec<_>>(),
    })
}

/// Registers a recording hook for each client event; whether each
/// registration returned the client.
fn hooks(probe: &mut Probe) -> Vec<bool> {
    let c = &mut probe.client;
    let me: *const Client = c;
    let same = |r: &mut Client| std::ptr::eq(r, me);
    let log = &probe.log;
    let (l1, l2, l3, l4, l5, l6) = (log.clone(), log.clone(), log.clone(), log.clone(), log.clone(), log.clone());
    vec![
        same(c.on_message(move |d| push(&l1, json!(["message", bytes_value(d)])))),
        same(c.on_close(move || push(&l2, json!(["close"])))),
        same(c.on_error(move |e| push(&l3, json!(["error", e.php_class(), e.to_string()])))),
        same(c.on_open(move || push(&l4, json!(["open"])))),
        same(c.on_ping(move |d| push(&l5, json!(["ping", bytes_value(d)])))),
        same(c.on_pong(move |d| push(&l6, json!(["pong", bytes_value(d)])))),
    ]
}

/// As ClientTest does: a connected client on a scripted connection.
fn attach(probe: &mut Probe, script: &[Value], push_fail: Option<TransportError>) {
    let transport = Scripted { script: script.iter().cloned().collect(), log: probe.log.clone(), push_fail };
    probe.client.attach(Box::new(transport));
}

fn rig<'s>(session: &'s Session, a: &Args<'_>) -> Result<&'s Rig, Fault> {
    session.get::<Rig>(a.value("server")?)
}

fn options(spec: &Value) -> Result<Options, Fault> {
    let mut options = Options::default();
    if let Some(headers) = spec.get("headers").and_then(Value::as_array) {
        // PHP keeps one value per name (the last), at the name's first position.
        let mut map: IndexMap<String, String> = IndexMap::new();
        for header in headers {
            let name = header.get(0).and_then(Value::as_str).ok_or_else(|| Fault::new("header name"))?;
            let value = header.get(1).and_then(Value::as_str).ok_or_else(|| Fault::new("header value"))?;
            map.insert(name.to_owned(), value.to_owned());
        }
        options.headers = map.into_iter().collect();
    }
    if let Some(timeout) = spec.get("timeout").filter(|v| !v.is_null()) {
        let seconds = crate::adapter::float(timeout).ok_or_else(|| Fault::new("timeout"))?;
        options.timeout = Duration::try_from_secs_f64(seconds).map_err(|e| Fault::new(e.to_string()))?;
    }
    Ok(options)
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let result = match op {
        "server.new" => {
            let adapter = Memory::new();
            let server = Server::new(adapter.clone());
            Outcome::Ok(session.handle(Rig {
                adapter,
                server,
                log: Log::default(),
                connections: Arc::default(),
                errors: 0,
            }))
        }
        "server.on" => {
            on(rig(session, &a)?, a.str("hook")?)?;
            Outcome::Ok(Value::Null)
        }
        "server.error" => {
            let r = session.get_mut::<Rig>(a.value("server")?)?;
            let index = r.errors;
            r.errors += 1;
            let log = r.log.clone();
            r.server.error(move |error, stage| {
                push(&log, json!(["error", index, error.php_class(), error.to_string(), stage.as_str()]));
            });
            Outcome::Ok(Value::Null)
        }
        "server.start" => {
            rig(session, &a)?.server.start().await;
            Outcome::Ok(Value::Null)
        }
        "server.shutdown" => {
            rig(session, &a)?.server.shutdown();
            Outcome::Ok(Value::Null)
        }
        "server.send" => {
            let connections: Vec<i64> = a
                .array("connections")?
                .iter()
                .map(|c| c.as_i64().ok_or_else(|| Fault::new("connection ids are integers")))
                .collect::<Result<_, _>>()?;
            rig(session, &a)?.server.send(&connections, a.bytes("message")?);
            Outcome::Ok(Value::Null)
        }
        "server.close" => {
            rig(session, &a)?.server.close(a.i64("connection")?, a.i64("code")?);
            Outcome::Ok(Value::Null)
        }
        "server.log" => {
            Outcome::Ok(Value::Array(rig(session, &a)?.log.lock().unwrap_or_else(|e| e.into_inner()).clone()))
        }
        "memory.fail" => {
            let stage = Stage::from_name(a.str("stage")?).ok_or_else(|| Fault::new("unknown stage"))?;
            rig(session, &a)?.adapter.fail(stage, a.str("message")?);
            Outcome::Ok(Value::Null)
        }
        "memory.config" => {
            let adapter = &rig(session, &a)?.adapter;
            if let Some(v) = a.0.get("package_max_length") {
                adapter.set_package_max_length(v.as_i64().ok_or_else(|| Fault::new("package_max_length"))?);
            }
            if let Some(v) = a.0.get("compression") {
                adapter.set_compression_enabled(v.as_bool().ok_or_else(|| Fault::new("compression"))?);
            }
            if let Some(v) = a.0.get("workers") {
                adapter.set_worker_number(v.as_i64().ok_or_else(|| Fault::new("workers"))?);
            }
            let s = adapter.settings();
            Outcome::Ok(json!({
                "package_max_length": s.package_max_length,
                "websocket_compression": s.compression,
                "worker_num": s.workers,
            }))
        }
        "memory.open" => {
            let request = request(a.opt("request"))?;
            rig(session, &a)?.adapter.open(a.i64("connection")?, &request);
            Outcome::Ok(Value::Null)
        }
        "memory.message" => Outcome::ok(rig(session, &a)?.adapter.message(a.i64("connection")?, &a.bytes("data")?)),
        "memory.disconnect" => Outcome::ok(rig(session, &a)?.adapter.disconnect(a.i64("connection")?)),
        "memory.request" => {
            let request = request(a.opt("request"))?;
            let reply = rig(session, &a)?.adapter.request(&request);
            Outcome::Ok(json!({ "status": reply.status().as_u16(), "body": bytes_value(reply.body()) }))
        }
        "memory.state" => Outcome::Ok(state(&rig(session, &a)?.adapter)),
        "server.dispatch" => {
            let adapter = Memory::new();
            let r = Rig {
                server: Server::new(adapter.clone()),
                adapter,
                log: Log::default(),
                connections: Arc::default(),
                errors: 0,
            };
            for hook in ["start", "worker_start", "worker_stop", "open", "close", "message", "request"] {
                on(&r, hook)?;
            }
            let log = r.log.clone();
            r.server.error(move |error, stage| {
                push(&log, json!(["error", 0, error.php_class(), error.to_string(), stage.as_str()]));
            });
            let request = request(None)?;
            r.adapter.open(1, &request);
            r.adapter.open(2, &request);
            for message in a.array("messages")? {
                r.adapter.message(1, &bytes(message).ok_or_else(|| Fault::new("a message is a string"))?);
            }
            let log = r.log.lock().unwrap_or_else(|e| e.into_inner()).clone();
            Outcome::Ok(json!({ "log": log, "state": state(&r.adapter) }))
        }
        "client.script" => {
            let mut probe = Probe {
                client: Client::new("ws://localhost:8080", Options::default())
                    .map_err(|e| Fault::new(e.to_string()))?,
                log: Log::default(),
            };
            hooks(&mut probe);
            attach(&mut probe, a.array("script")?, None);
            probe.client.listen().await;
            let log = probe.log.lock().unwrap_or_else(|e| e.into_inner()).clone();
            Outcome::Ok(json!({ "log": log, "connected": probe.client.is_connected() }))
        }
        "client.new" => {
            let options = match a.opt("options") {
                Some(spec) => options(spec)?,
                None => Options::default(),
            };
            match Client::new(a.str("url")?, options) {
                Ok(client) => {
                    let out = json!({
                        "host": client.host(),
                        "port": client.port(),
                        "path": client.path(),
                        "headers": client.headers().iter().map(|(n, v)| json!([n, v])).collect::<Vec<_>>(),
                        "timeout": float_value(client.timeout().as_secs_f64()),
                    });
                    let handle = session.handle(Probe { client, log: Log::default() });
                    let mut map = serde_json::Map::new();
                    map.insert("client".into(), handle);
                    if let Value::Object(rest) = out {
                        map.extend(rest);
                    }
                    Outcome::Ok(Value::Object(map))
                }
                Err(e) => err(&e),
            }
        }
        "client.hooks" => Outcome::ok(hooks(session.get_mut::<Probe>(a.value("client")?)?)),
        "client.attach" => {
            let push_fail = a.opt("push_fail").map(|f| {
                TransportError::new(
                    f.get(0).and_then(Value::as_i64).unwrap_or(0),
                    f.get(1).and_then(Value::as_str).unwrap_or(""),
                )
            });
            let script = a.array("script")?;
            attach(session.get_mut::<Probe>(a.value("client")?)?, script, push_fail);
            Outcome::Ok(Value::Null)
        }
        "client.connect" => match session.get_mut::<Probe>(a.value("client")?)?.client.connect().await {
            Ok(()) => Outcome::Ok(Value::Null),
            Err(e) => err(&e),
        },
        "client.listen" => {
            session.get_mut::<Probe>(a.value("client")?)?.client.listen().await;
            Outcome::Ok(Value::Null)
        }
        "client.receive" => match session.get_mut::<Probe>(a.value("client")?)?.client.receive().await {
            Ok(data) => Outcome::Ok(data.map_or(Value::Null, |d| bytes_value(&d))),
            Err(e) => err(&e),
        },
        "client.send" => {
            let data = a.bytes("data")?;
            match session.get_mut::<Probe>(a.value("client")?)?.client.send(data).await {
                Ok(()) => Outcome::Ok(Value::Null),
                Err(e) => err(&e),
            }
        }
        "client.close" => {
            session.get_mut::<Probe>(a.value("client")?)?.client.close().await;
            Outcome::Ok(Value::Null)
        }
        "client.is_connected" => Outcome::ok(session.get::<Probe>(a.value("client")?)?.client.is_connected()),
        "client.log" => Outcome::Ok(Value::Array(
            session.get::<Probe>(a.value("client")?)?.log.lock().unwrap_or_else(|e| e.into_inner()).clone(),
        )),
        "swoole.config" => {
            let timeout = a.opt_f64("send_timeout")?.unwrap_or(utopia_websocket::net::DEFAULT_SEND_TIMEOUT);
            match Network::new("127.0.0.1", 0, timeout) {
                Ok(adapter) => {
                    if let Some(v) = a.opt_i64("package_max_length")? {
                        adapter.set_package_max_length(v);
                    }
                    if let Some(v) = a.opt_bool("compression")? {
                        adapter.set_compression_enabled(v);
                    }
                    if let Some(v) = a.opt_i64("workers")? {
                        adapter.set_worker_number(v);
                    }
                    let c = adapter.config();
                    Outcome::Ok(json!({
                        "max_connection": c.max_connection,
                        "send_timeout": float_value(c.send_timeout),
                        "package_max_length": c.package_max_length,
                        "websocket_compression": c.compression,
                        "worker_num": c.workers,
                    }))
                }
                Err(e) => err(&e),
            }
        }
        "e2e.run" => {
            Outcome::Ok(Value::Array(e2e(a.array("steps")?, a.opt_f64("send_timeout")?.unwrap_or(1.0)).await?))
        }
        _ => return Err(Fault::new(format!("websocket: unknown operation `{op}`"))),
    };
    Ok(result)
}

/// The fixture's state (server.php's `$connections`, `$batches`, `$batchesSent`).
#[derive(Default)]
struct Fixture {
    connections: IndexMap<i64, ()>,
    batches: HashMap<i64, Vec<Bytes>>,
    sent: i64,
}

/// The handlers of server.php on the Network adapter.
fn fixture(server: &Server<Network>) {
    let state = Arc::new(Mutex::new(Fixture::default()));
    let (s1, s2, s3, s4) = (state.clone(), state.clone(), state.clone(), state);
    let replies = server.clone();
    let requests = server.clone();
    server
        .on_start(|| {})
        .on_worker_start(|_| {})
        .on_worker_stop(|_| {})
        .on_open(move |connection, _| {
            s1.lock().unwrap_or_else(|e| e.into_inner()).connections.insert(connection, ());
        })
        .on_close(move |connection| {
            let mut s = s2.lock().unwrap_or_else(|e| e.into_inner());
            s.connections.shift_remove(&connection);
            s.batches.remove(&connection);
        })
        .on_message(move |connection, message| {
            let (command, payload) = match message.iter().position(|&b| b == b':') {
                Some(i) => (&message[..i], &message[i + 1..]),
                None => (message, &b""[..]),
            };
            match command {
                b"buffer" => {
                    s3.lock()
                        .unwrap_or_else(|e| e.into_inner())
                        .batches
                        .entry(connection)
                        .or_default()
                        .push(Bytes::copy_from_slice(payload));
                }
                b"flush" => {
                    let batch = s3.lock().unwrap_or_else(|e| e.into_inner()).batches.remove(&connection);
                    for payload in batch.unwrap_or_default() {
                        replies.send(&[connection], payload);
                    }
                    s3.lock().unwrap_or_else(|e| e.into_inner()).sent += 1;
                }
                b"ping" => replies.send(&[connection], "pong"),
                b"pong" => replies.send(&[connection], "ping"),
                b"broadcast" => {
                    let all: Vec<i64> =
                        s3.lock().unwrap_or_else(|e| e.into_inner()).connections.keys().copied().collect();
                    replies.send(&all, "broadcast");
                }
                b"disconnect" => {
                    replies.send(&[connection], "disconnect");
                    replies.close(connection, 1000);
                }
                _ => {}
            }
        })
        .on_request(move |request, response| {
            response
                .headers_mut()
                .insert(http::header::CONTENT_TYPE, http::HeaderValue::from_static("application/json"));
            let body = match request.uri().path() {
                "/health" => r#"{"status":"ok"}"#.to_owned(),
                "/info" => {
                    let s = s4.lock().unwrap_or_else(|e| e.into_inner());
                    format!(r#"{{"connections":{},"batches_sent":{}}}"#, s.connections.len(), s.sent)
                }
                "/shutdown" => {
                    requests.shutdown();
                    "{}".to_owned()
                }
                _ => {
                    *response.status_mut() = http::StatusCode::NOT_FOUND;
                    "Not Found".to_owned()
                }
            };
            *response.body_mut() = body.into_bytes();
        });
}

/// Starts the fixture on the Network adapter, runs the steps against it with
/// clients, and returns what each step observed.
async fn e2e(steps: &[Value], send_timeout: f64) -> Result<Vec<Value>, Fault> {
    let adapter = Network::new("127.0.0.1", 0, send_timeout).map_err(|e| Fault::new(e.to_string()))?;
    adapter.set_worker_number(1);
    let server = Server::new(adapter.clone());
    fixture(&server);
    let running = server.clone();
    let task = utopia_async::spawn(async move { running.start().await });
    let port = utopia_async::timeout(Duration::from_secs(5), adapter.listening())
        .await
        .map_err(|_| Fault::new("the fixture server did not start"))?
        .port();
    let mut clients: HashMap<String, Client> = HashMap::new();
    let mut slow: HashMap<String, TcpStream> = HashMap::new();
    let mut out = Vec::with_capacity(steps.len());
    for step in steps {
        let value = match run(step, port, &mut clients, &mut slow).await? {
            Ok(v) => v,
            Err(e) => json!({ "error": e.php_class(), "message": e.to_string() }),
        };
        out.push(value);
    }
    for client in clients.values_mut() {
        client.close().await;
    }
    slow.clear();
    let _ = http(port, "/shutdown").await;
    let _ = utopia_async::timeout(Duration::from_secs(2), task).await;
    Ok(out)
}

fn messages(count: i64, size: usize) -> impl Iterator<Item = Vec<u8>> {
    (0..count).map(move |i| {
        let mut m = format!("{i}:").into_bytes();
        m.resize(m.len() + size, b'x');
        m
    })
}

/// A masked text frame, as a browser sends it.
fn masked(payload: &[u8]) -> Vec<u8> {
    let mask = [0x37, 0xfa, 0x21, 0x3d];
    let mut frame = vec![0x81];
    match payload.len() {
        n if n < 126 => frame.push(0x80 | n as u8),
        n if n <= 0xffff => {
            frame.push(0x80 | 126);
            frame.extend_from_slice(&(n as u16).to_be_bytes());
        }
        n => {
            frame.push(0x80 | 127);
            frame.extend_from_slice(&(n as u64).to_be_bytes());
        }
    }
    frame.extend_from_slice(&mask);
    frame.extend(payload.iter().enumerate().map(|(i, b)| b ^ mask[i % 4]));
    frame
}

async fn http(port: u16, path: &str) -> Result<Value, Fault> {
    let mut stream = TcpStream::connect(("127.0.0.1", port)).await.map_err(|e| Fault::new(e.to_string()))?;
    let request = format!("GET {path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    stream.write_all(request.as_bytes()).await.map_err(|e| Fault::new(e.to_string()))?;
    let mut raw = Vec::new();
    let _ = utopia_async::timeout(Duration::from_secs(2), stream.read_to_end(&mut raw)).await;
    let text = String::from_utf8_lossy(&raw);
    let status = text.split(' ').nth(1).and_then(|s| s.parse::<i64>().ok()).unwrap_or(0);
    let body = text.split_once("\r\n\r\n").map(|(_, b)| b.to_owned()).unwrap_or_default();
    Ok(json!({ "status": status, "body": body }))
}

type Step = Result<Result<Value, Error>, Fault>;

async fn run(
    step: &Value,
    port: u16,
    clients: &mut HashMap<String, Client>,
    slow: &mut HashMap<String, TcpStream>,
) -> Step {
    let (kind, name) =
        step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("a step is an object"))?;
    let key = name.as_str().unwrap_or_default().to_owned();
    let a = Args(step);
    Ok(match kind.as_str() {
        "connect" => {
            let options = Options { timeout: Duration::from_secs(10), ..Options::default() };
            let mut c =
                Client::new(&format!("ws://127.0.0.1:{port}"), options).map_err(|e| Fault::new(e.to_string()))?;
            let r = c.connect().await;
            clients.insert(key, c);
            r.map(|()| Value::Null)
        }
        "send" => {
            let c = clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?;
            c.send(a.bytes("data")?).await.map(|()| Value::Null)
        }
        "receive" => {
            let c = clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?;
            c.receive().await.map(|d| d.map_or(Value::Null, |d| bytes_value(&d)))
        }
        "close" => {
            let c = clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?;
            c.close().await;
            Ok(Value::Null)
        }
        "connected" => Ok(Value::Bool(clients.get(&key).ok_or_else(|| Fault::new("no client"))?.is_connected())),
        "http" => Ok(http(port, &key).await?),
        "batch" => {
            let count = a.i64("count")?;
            let size = a.i64("size")? as usize;
            if let Some(c) = clients.get_mut(&key) {
                for m in messages(count, size) {
                    let mut message = b"buffer:".to_vec();
                    message.extend_from_slice(&m);
                    c.send(message).await.map_err(|e| Fault::new(e.to_string()))?;
                }
                c.send("flush").await.map_err(|e| Fault::new(e.to_string()))?;
            } else {
                let s = slow.get_mut(&key).ok_or_else(|| Fault::new("no slow client"))?;
                for m in messages(count, size) {
                    let mut message = b"buffer:".to_vec();
                    message.extend_from_slice(&m);
                    s.write_all(&masked(&message)).await.map_err(|e| Fault::new(e.to_string()))?;
                }
                s.write_all(&masked(b"flush")).await.map_err(|e| Fault::new(e.to_string()))?;
            }
            Ok(Value::Null)
        }
        "wait_batches" => {
            let expected = name.as_i64().ok_or_else(|| Fault::new("wait_batches takes a count"))?;
            let deadline = Instant::now() + Duration::from_secs(2);
            let mut sent;
            loop {
                utopia_async::sleep(Duration::from_millis(10)).await;
                let info = http(port, "/info").await?;
                let body: Value = serde_json::from_str(info["body"].as_str().unwrap_or("{}")).unwrap_or_default();
                sent = body["batches_sent"].as_i64().unwrap_or(0);
                if sent >= expected || Instant::now() >= deadline {
                    break;
                }
            }
            Ok(Value::Bool(sent >= expected))
        }
        "drain" => {
            let count = a.i64("count")?;
            let size = a.i64("size")? as usize;
            let c = clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?;
            let mut all = true;
            for m in messages(count, size) {
                if c.receive().await.ok().flatten().as_deref() != Some(&m[..]) {
                    all = false;
                    break;
                }
            }
            Ok(Value::Bool(all))
        }
        "slow" => {
            let socket = TcpSocket::new_v4().map_err(|e| Fault::new(e.to_string()))?;
            socket.set_recv_buffer_size(1024).map_err(|e| Fault::new(e.to_string()))?;
            let addr = std::net::SocketAddr::from(([127, 0, 0, 1], port));
            let mut stream = socket.connect(addr).await.map_err(|e| Fault::new(e.to_string()))?;
            stream
                .write_all(b"GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n")
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            let mut head = Vec::new();
            let mut chunk = [0u8; 4096];
            while !head.windows(4).any(|w| w == b"\r\n\r\n") {
                match utopia_async::timeout(Duration::from_secs(2), stream.read(&mut chunk)).await {
                    Ok(Ok(n)) if n > 0 => head.extend_from_slice(&chunk[..n]),
                    _ => return Ok(Ok(Value::Bool(false))),
                }
            }
            slow.insert(key, stream);
            Ok(Value::Bool(head.windows(23).any(|w| w == b"101 Switching Protocols")))
        }
        "probe" => {
            let s = slow.get_mut(&key).ok_or_else(|| Fault::new("no slow client"))?;
            let ping = masked(b"ping");
            let deadline = Instant::now() + Duration::from_secs(6);
            let mut kind = None;
            loop {
                utopia_async::sleep(Duration::from_millis(50)).await;
                match utopia_async::timeout(Duration::from_millis(200), s.write_all(&ping)).await {
                    Ok(Err(e)) => {
                        kind = Some(e.kind());
                        break;
                    }
                    Err(_) => {
                        kind = Some(std::io::ErrorKind::TimedOut);
                        break;
                    }
                    Ok(Ok(())) => {}
                }
                if let Ok(Some(e)) = s.take_error() {
                    kind = Some(e.kind());
                    break;
                }
                if Instant::now() >= deadline {
                    break;
                }
            }
            let gone = matches!(kind, Some(std::io::ErrorKind::ConnectionReset | std::io::ErrorKind::BrokenPipe));
            Ok(Value::String(if gone { "disconnected" } else { "alive" }.into()))
        }
        "drop" => {
            slow.remove(&key);
            Ok(Value::Null)
        }
        other => return Err(Fault::new(format!("unknown step {other}"))),
    })
}
