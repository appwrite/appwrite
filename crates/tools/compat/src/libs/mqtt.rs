//! Compat adapter for `mqtt`: maps `tests/compat/mqtt/spec.json` operations
//! onto `utopia-mqtt`: the codec, the store, connections, brokers on the
//! in-process `Memory` adapter, and E2E and interop runs against the `Tokio`
//! adapter serving the handlers of `tests/compat/mqtt/server.php`.

use std::collections::HashMap;
use std::hint::black_box;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use indexmap::IndexMap;
use serde_json::{Value, json};
use tokio::net::TcpStream;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::tungstenite::client::IntoClientRequest;
use tokio_tungstenite::{MaybeTlsStream, WebSocketStream};
use utopia_mqtt::adapter::{Adapter as _, Outbound as _, TimerHook};
use utopia_mqtt::client::{Client, Options};
use utopia_mqtt::memory::Operation;
use utopia_mqtt::packet::{
    self, Auth, Connack, Connect, Disconnect, Filter, Kind, Packet, Puback, Publish, Suback, Subscribe, Unsuback,
    Unsubscribe, Will,
};
use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
use utopia_mqtt::spec::{v3, v5};
use utopia_mqtt::wire::{self, Length};
use utopia_mqtt::{
    Connection, Error, Handler, Memory, NoTimer, Properties, Property, Server, Store, Timer, TimingWheel, Tokio,
    Transport, Value as PropertyValue,
};
use utopia_telemetry::{Kind as Metric, MemoryTelemetry, Number};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value, float, float_value};
use crate::bench::Bench;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "length.decode",
    "length.encode",
    "string.read",
    "string.encode",
    "int16.read",
    "byte.read",
    "packet.parse",
    "packet.new",
    "packet.clean_start",
    "packet.client_id",
    "packet.frames",
    "packet.ping",
    "decode.all",
    "property.wire_type",
    "property.encode",
    "properties.encode",
    "properties.parse",
    "properties.get",
    "properties.skip",
    "connect.decode",
    "auth.decode",
    "auth.new",
    "connack.new",
    "disconnect.decode",
    "disconnect.new",
    "publish.decode",
    "puback.decode",
    "subscribe.decode",
    "unsubscribe.decode",
    "filter.new",
    "will.new",
    "suback.build",
    "unsuback.build",
    "v3.connect",
    "v3.subscribe",
    "v3.unsubscribe",
    "v3.connack",
    "v3.publish",
    "v3.puback",
    "v3.suback",
    "v3.unsuback",
    "v3.disconnect",
    "v5.connect",
    "v5.subscribe",
    "v5.unsubscribe",
    "v5.connack",
    "v5.publish",
    "v5.puback",
    "v5.suback",
    "v5.unsuback",
    "v5.disconnect",
    "v5.auth",
    "store.new",
    "store.subscribe",
    "store.unsubscribe",
    "store.close",
    "store.connection",
    "store.subscribers",
    "store.script",
    "connection.new",
    "connection.set",
    "connection.state",
    "connection.publish",
    "connection.puback",
    "connection.disconnect",
    "connection.update_expires_at",
    "connection.next_packet_id",
    "connection.resume",
    "connection.raise",
    "connection.resumed",
    "connection.cursor",
    "connection.track",
    "connection.acknowledge",
    "connection.set_client_id",
    "connection.client_id",
    "connection.script",
    "memory.new",
    "memory.fail",
    "memory.open",
    "memory.receive",
    "memory.disconnect",
    "memory.tick",
    "memory.state",
    "server.new",
    "server.telemetry",
    "server.on",
    "server.error",
    "server.start",
    "server.stop",
    "server.subscribers",
    "server.unsubscribe",
    "server.log",
    "server.metrics",
    "server.session",
    "wheel.new",
    "wheel.schedule",
    "wheel.clear",
    "wheel.drain",
    "wheel.log",
    "notimer.run",
    "transport.new",
    "swoole.new",
    "client.new",
    "client.hooks",
    "client.send",
    "client.receive",
    "client.is_connected",
    "client.close",
    "client.connect",
    "e2e.run",
    "broker.start",
    "broker.stop",
    "client.session",
];

/// Where services.sh writes the TLS listener's certificate and key.
const CERTS: &str = "/tmp/compat-fs/mqtt-certs";

/// Benchmark inputs, the same as in Adapter.php.
const SUBSCRIPTIONS: i64 = 10_000;
const FANOUT: i64 = 1000;
const PAYLOAD: &[u8] = br#"{"events":["databases.*"],"payload":{"$id":"6650c1f2a4b3e9d7c812"}}"#;
const TOPIC: &[u8] = b"projects/6650c0de/databases/main/documents";

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("decode", "Parse a v5 PUBLISH with three properties and decode it"),
    ("encode", "Encode a v5 PUBLISH with three properties"),
    ("properties", "Parse a property block of three properties and merge its User Properties"),
    ("connect", "Decode a v5 CONNECT with authentication and a User Property"),
    ("match", "Find the subscribers of a topic among 10,000 subscriptions (exact, + and # filters)"),
    ("fanout", "Dispatch a QoS 0 PUBLISH through the broker to 1,000 subscribers on the in-process adapter"),
];

fn bench_properties() -> Properties {
    Properties::new()
        .with(Property::user("projectId", "6650c0de"))
        .with(Property::bytes(Property::CONTENT_TYPE, "application/json"))
        .with(Property::new(Property::MESSAGE_EXPIRY_INTERVAL, PropertyValue::Int(3600)))
}

/// The benchmark store's filter number `i`.
fn bench_filter(i: i64) -> Vec<u8> {
    match i % 4 {
        0 => format!("sensors/room{i}/temp"),
        1 => "sensors/+/temp".to_owned(),
        2 => format!("sensors/room{}/#", i % 50),
        _ => format!("devices/{i}/status"),
    }
    .into_bytes()
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    let fault = |e: Error| Fault::new(e.to_string());
    Ok(match name {
        "decode" => {
            let bytes = v5::publish(TOPIC, PAYLOAD, 1, 42, Some(&bench_properties()), false).map_err(fault)?;
            Bench::sync(move || {
                let packet = Packet::parse(black_box(&bytes)).ok();
                black_box(packet.and_then(|p| Publish::decode(&p, 5).ok()));
            })
        }
        "encode" => Bench::sync(|| {
            black_box(v5::publish(TOPIC, PAYLOAD, 1, 42, Some(&bench_properties()), false).ok());
        }),
        "properties" => {
            let block = bench_properties().encode().map_err(fault)?;
            Bench::sync(move || {
                black_box(Properties::parse(black_box(&block), 0).ok().map(|(p, _)| p.user()));
            })
        }
        "connect" => {
            let properties = Properties::new()
                .with(Property::bytes(Property::AUTHENTICATION_METHOD, "appwrite-jwt"))
                .with(Property::bytes(Property::AUTHENTICATION_DATA, vec![b't'; 180]))
                .with(Property::user("projectId", "6650c0de"));
            let bytes = v5::connect(b"device-6650c0de", 60, true, Some(&properties)).map_err(fault)?;
            Bench::sync(move || {
                let packet = Packet::parse(black_box(&bytes)).ok();
                black_box(packet.and_then(|p| Connect::decode(p.body).ok()));
            })
        }
        "match" => {
            let mut store = Store::new();
            for i in 0..SUBSCRIPTIONS {
                store.subscribe(format!("p{}", i % 10).as_bytes(), b"u", &bench_filter(i), i, (i % 2) as u8);
            }
            Bench::sync(move || {
                black_box(store.subscribers(black_box(b"p3"), black_box(b"sensors/room13/temp")));
            })
        }
        "fanout" => {
            let adapter = Memory::new();
            let server = Server::new(adapter.clone(), Deliver);
            server.start().await;
            let connect = v5::connect(b"c", 0, true, None).map_err(fault)?;
            let subscribe = v5::subscribe(1, &[b"news/+"], 0, None).map_err(fault)?;
            for fd in 1..=FANOUT {
                adapter.open(fd).map_err(fault)?;
                adapter.receive(fd, &connect).map_err(fault)?;
                adapter.receive(fd, &subscribe).map_err(fault)?;
            }
            adapter.take_sent();
            let publish = v5::publish(b"news/tech", PAYLOAD, 0, 0, None, false).map_err(fault)?;
            Bench::sync(move || {
                let _ = adapter.receive(1, black_box(&publish));
                black_box(adapter.take_sent());
            })
        }
        _ => return Err(Fault::new(format!("mqtt: no benchmark `{name}`"))),
    })
}

type Log = Arc<Mutex<Vec<Value>>>;

fn push(log: &Log, entry: Value) {
    log.lock().unwrap_or_else(|e| e.into_inner()).push(entry);
}

fn snapshot(log: &Log) -> Value {
    Value::Array(log.lock().unwrap_or_else(|e| e.into_inner()).clone())
}

fn err(e: &Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn pairs(pairs: &[(Vec<u8>, Vec<u8>)]) -> Value {
    Value::Array(pairs.iter().map(|(k, v)| json!([bytes_value(k), bytes_value(v)])).collect())
}

fn opt_bytes(value: Option<&[u8]>) -> Value {
    value.map_or(Value::Null, bytes_value)
}

/// A property value: a User Property as `{pair: [[k, v]]}`.
fn value_out(value: &PropertyValue) -> Value {
    match value {
        PropertyValue::Int(i) => json!(i),
        PropertyValue::Float(f) => float_value(*f),
        PropertyValue::Bytes(b) => bytes_value(b),
        PropertyValue::Pair(k, v) => json!({ "pair": [[bytes_value(k), bytes_value(v)]] }),
    }
}

/// A property value from JSON: `{pair: [[k, v]]}` is a User Property's.
fn value_in(value: &Value) -> Result<PropertyValue, Fault> {
    if let Some(pair) = value.get("pair") {
        let [entry] = pair.as_array().map(Vec::as_slice).unwrap_or_default() else {
            return Err(Fault::new("a Rust User Property holds exactly one pair"));
        };
        let key = entry.get(0).and_then(bytes).ok_or_else(|| Fault::new("pair key"))?;
        let val = entry.get(1).and_then(bytes).ok_or_else(|| Fault::new("pair value"))?;
        return Ok(PropertyValue::Pair(key, val));
    }
    if let Some(i) = value.as_i64().filter(|_| !value.is_f64()) {
        return Ok(PropertyValue::Int(i));
    }
    if let Some(f) = float(value) {
        return Ok(PropertyValue::Float(f));
    }
    bytes(value).map(PropertyValue::Bytes).ok_or_else(|| Fault::new("a property value"))
}

fn properties_out(properties: Option<&Properties>) -> Value {
    properties
        .map_or(Value::Null, |p| Value::Array(p.all().iter().map(|p| json!([p.id, value_out(&p.value)])).collect()))
}

fn block(spec: &Value) -> Result<Properties, Fault> {
    let mut properties = Properties::new();
    for entry in spec.as_array().ok_or_else(|| Fault::new("properties are a list"))? {
        let id = entry.get("id").and_then(Value::as_u64).ok_or_else(|| Fault::new("property id"))?;
        let id = u8::try_from(id).map_err(|_| Fault::new("property ids are bytes"))?;
        properties.add(Property::new(id, value_in(entry.get("value").unwrap_or(&Value::Null))?));
    }
    Ok(properties)
}

fn optional(a: &Args<'_>) -> Result<Option<Properties>, Fault> {
    a.opt("properties").map(block).transpose()
}

fn u8_arg(a: &Args<'_>, key: &str) -> Result<u8, Fault> {
    u8::try_from(a.i64(key)?).map_err(|_| Fault::new(format!("`{key}` is a byte here")))
}

fn u16_arg(a: &Args<'_>, key: &str) -> Result<u16, Fault> {
    u16::try_from(a.i64(key)?).map_err(|_| Fault::new(format!("`{key}` is a 16-bit integer here")))
}

fn usize_arg(a: &Args<'_>, key: &str) -> Result<usize, Fault> {
    usize::try_from(a.i64(key)?).map_err(|_| Fault::new(format!("`{key}` is not negative here")))
}

fn filters(a: &Args<'_>) -> Result<Vec<Vec<u8>>, Fault> {
    a.array("filters")?.iter().map(|f| bytes(f).ok_or_else(|| Fault::new("filters are strings"))).collect()
}

fn length_value(length: Length) -> Value {
    match length {
        Length::Int(i) => json!(i),
        Length::Float(f) => float_value(f),
    }
}

fn packet_out(packet: &Packet<'_>) -> Value {
    json!({
        "type": packet.kind.code(),
        "flags": packet.flags,
        "body": bytes_value(packet.body),
        "name": packet.name(),
        "qos": packet.qos(),
        "dup": packet.dup(),
    })
}

fn will_out(will: &Will) -> Value {
    json!([bytes_value(&will.topic), bytes_value(&will.payload), will.qos, will.retain])
}

fn connect_out(c: &Connect) -> Value {
    json!({
        "protocol_level": c.protocol_level,
        "client_id": bytes_value(&c.client_id),
        "clean_start": c.clean_start,
        "keep_alive": c.keep_alive,
        "username": opt_bytes(c.username.as_deref()),
        "password": opt_bytes(c.password.as_deref()),
        "will": c.will.as_ref().map_or(Value::Null, will_out),
        "auth_method": opt_bytes(c.auth_method.as_deref()),
        "auth_data": opt_bytes(c.auth_data.as_deref()),
        "properties": properties_out(c.properties.as_ref()),
        "user": pairs(&c.user_properties()),
    })
}

fn auth_out(a: &Auth) -> Value {
    json!({
        "reason_code": a.reason_code,
        "method": bytes_value(&a.method),
        "data": bytes_value(&a.data),
        "properties": properties_out(a.properties.as_ref()),
        "user": pairs(&a.user_properties()),
    })
}

fn disconnect_out(d: &Disconnect) -> Value {
    json!({ "reason_code": d.reason_code, "properties": properties_out(d.properties.as_ref()) })
}

fn record_out(store: &Store, fd: i64) -> Value {
    store.connection(fd).map_or(Value::Null, |r| {
        json!({
            "prefix": bytes_value(&r.prefix),
            "user_id": bytes_value(&r.user_id),
            "subs": r.subs.iter().map(|(t, q)| json!([bytes_value(t), q])).collect::<Vec<_>>(),
        })
    })
}

fn fds_out(store: &Store, prefix: &[u8], topic: &[u8]) -> Value {
    Value::Array(store.subscribers(prefix, topic).into_iter().map(|(fd, qos)| json!([fd, qos])).collect())
}

fn ack_out(ack: Option<utopia_mqtt::Ack>) -> Value {
    ack.map_or(Value::Null, |a| json!({ "topic": bytes_value(&a.topic), "sequence": a.sequence, "cursor": a.cursor }))
}

/// A timer that records what the broker schedules and clears; the twin of
/// `Timeline` in Adapter.php.
struct Timeline {
    log: Log,
    tick: RwLock<Option<TimerHook>>,
}

impl Timeline {
    fn tick(&self, id: i64) {
        let hook = self.tick.read().unwrap_or_else(|e| e.into_inner()).clone();
        if let Some(hook) = hook {
            hook(id);
        }
    }
}

impl Timer for Timeline {
    fn on_tick(&self, hook: TimerHook) {
        *self.tick.write().unwrap_or_else(|e| e.into_inner()) = Some(hook);
    }

    fn on_clear(&self, _hook: TimerHook) {}

    fn schedule(&self, id: i64, keep_alive: i64) {
        push(&self.log, json!(["schedule", id, keep_alive]));
    }

    fn clear(&self, id: i64) {
        push(&self.log, json!(["clear", id]));
    }
}

/// The compat suite's broker decisions, logged; the twin of `Recorder` in
/// Adapter.php.
struct Recorder {
    log: Log,
    sequence: AtomicI64,
    /// Called for a publish to "compat/shutdown" (the e2e broker mode).
    shutdown: Option<Arc<dyn Fn() + Send + Sync>>,
    /// Closes a client connecting with the password "hangup" while
    /// `on_connect` runs.
    adapter: Option<Memory>,
}

impl Recorder {
    fn new(log: Log) -> Self {
        Self { log, sequence: AtomicI64::new(0), shutdown: None, adapter: None }
    }
}

impl Handler for Recorder {
    fn on_connect(&self, connect: &Connect, connection: &Connection) -> Result<ConnectReply, Error> {
        let user = connect.user_properties();
        push(
            &self.log,
            json!([
                "connect",
                connection.fd(),
                connect.protocol_level,
                bytes_value(&connect.client_id),
                connect.clean_start,
                connect.keep_alive,
                opt_bytes(connect.username.as_deref()),
                opt_bytes(connect.password.as_deref()),
                connect.will.as_ref().map_or(Value::Null, will_out),
                opt_bytes(connect.auth_method.as_deref()),
                opt_bytes(connect.auth_data.as_deref()),
                pairs(&user),
                bytes_value(&connection.client_id()),
            ]),
        );
        if let Some((_, prefix)) = user.iter().find(|(k, _)| k == b"prefix") {
            connection.state().prefix = prefix.clone();
        }
        if connect.password.as_deref() == Some(b"hangup")
            && let Some(adapter) = &self.adapter
        {
            // The client hangs up while on_connect waits on I/O.
            adapter.close(connection.fd())?;
            let (closed, active) = {
                let state = connection.state();
                (state.closed, state.active)
            };
            push(&self.log, json!(["hangup", connection.fd(), closed, active]));
        }
        if connect.password.as_deref() == Some(b"deny") || connect.auth_data.as_deref() == Some(b"deny") {
            return Ok(ConnectReply::Connack(Connack::refuse(Connack::NOT_AUTHORIZED, None)));
        }
        if connect.password.as_deref() == Some(b"busy") {
            return Ok(ConnectReply::Connack(Connack::refuse(Connack::SERVER_BUSY, None)));
        }
        if connect.auth_method.as_deref() == Some(b"challenge") {
            return Ok(ConnectReply::Auth(Auth::challenge(b"challenge", b"nonce")));
        }
        let properties = connect
            .auth_method
            .as_ref()
            .map(|m| Properties::new().with(Property::bytes(Property::AUTHENTICATION_METHOD, m.clone())));
        Ok(ConnectReply::Connack(Connack::accept(false, properties)))
    }

    fn on_authenticate(&self, auth: &Auth, connection: &Connection) -> Result<AuthReply, Error> {
        push(
            &self.log,
            json!([
                "auth",
                connection.fd(),
                auth.reason_code,
                bytes_value(&auth.method),
                bytes_value(&auth.data),
                pairs(&auth.user_properties())
            ]),
        );
        if auth.data == b"deny" {
            return Ok(AuthReply::Disconnect(Disconnect::refuse(Disconnect::NOT_AUTHORIZED, Some(b"denied"))));
        }
        if auth.reason_code == Auth::CONTINUE {
            return Ok(AuthReply::Connack(Connack::accept(false, None)));
        }
        Ok(AuthReply::Auth(Auth::success(&auth.method)))
    }

    fn on_subscribe(&self, subscribe: &Subscribe, connection: &Connection) -> Result<Suback, Error> {
        let filters: Vec<Value> = subscribe.filters.iter().map(|f| json!([bytes_value(&f.topic), f.qos])).collect();
        push(&self.log, json!(["subscribe", connection.fd(), subscribe.packet_id, filters]));
        let mut suback = Suback::new();
        for filter in &subscribe.filters {
            if filter.topic.starts_with(b"deny") {
                suback.deny(Suback::DENIED);
            } else if filter.topic != b"short" {
                suback.grant(filter.qos);
            }
        }
        Ok(suback)
    }

    fn on_unsubscribe(&self, unsubscribe: &Unsubscribe, connection: &Connection) -> Result<Unsuback, Error> {
        let filters: Vec<Value> = unsubscribe.filters.iter().map(|f| bytes_value(f)).collect();
        push(&self.log, json!(["unsubscribe", connection.fd(), unsubscribe.packet_id, filters]));
        let mut unsuback = Unsuback::new();
        for filter in &unsubscribe.filters {
            if filter.starts_with(b"deny") {
                unsuback.fail(Unsuback::NOT_AUTHORIZED);
            } else {
                unsuback.success();
            }
        }
        Ok(unsuback)
    }

    fn on_publish(&self, publish: &Publish, connection: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
        let list: Vec<Value> = subscribers.iter().map(|(c, q)| json!([c.fd(), q])).collect();
        push(
            &self.log,
            json!([
                "publish",
                connection.fd(),
                bytes_value(&publish.topic),
                bytes_value(&publish.payload),
                publish.qos,
                publish.dup,
                publish.retain,
                publish.packet_id,
                pairs(&publish.user_properties()),
                list
            ]),
        );
        if publish.topic == b"boom" {
            return Err(Error::Runtime("boom".into()));
        }
        if publish.topic == b"compat/shutdown"
            && let Some(shutdown) = &self.shutdown
        {
            shutdown();
            return Ok(());
        }
        for (subscriber, granted) in subscribers {
            let qos = publish.qos.min(*granted).min(1);
            let sequence = (qos == 1).then(|| self.sequence.fetch_add(1, Ordering::Relaxed) + 1);
            subscriber.publish(&publish.topic, &publish.payload, qos, false, sequence)?;
        }
        if publish.qos == 1 {
            connection.puback(publish.packet_id)?;
        }
        Ok(())
    }

    fn on_puback(&self, puback: &Puback, connection: &Connection) -> Result<(), Error> {
        let ack = ack_out(connection.acknowledge(puback.packet_id));
        push(&self.log, json!(["puback", connection.fd(), puback.packet_id, puback.reason_code, ack]));
        Ok(())
    }

    fn on_disconnect(&self, disconnect: Option<&Disconnect>, connection: &Connection) -> Result<(), Error> {
        push(&self.log, json!(["disconnect", connection.fd(), disconnect.map(|d| d.reason_code)]));
        Ok(())
    }
}

/// The benchmark's broker decisions; the twin of `Deliver` in Adapter.php.
struct Deliver;

impl Handler for Deliver {
    fn on_connect(&self, _: &Connect, _: &Connection) -> Result<ConnectReply, Error> {
        Ok(ConnectReply::Connack(Connack::accept(false, None)))
    }

    fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
        Ok(AuthReply::Auth(Auth::success(&auth.method)))
    }

    fn on_subscribe(&self, subscribe: &Subscribe, _: &Connection) -> Result<Suback, Error> {
        let mut suback = Suback::new();
        for _ in &subscribe.filters {
            suback.grant(0);
        }
        Ok(suback)
    }

    fn on_unsubscribe(&self, _: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
        Ok(Unsuback::new())
    }

    fn on_publish(&self, publish: &Publish, _: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
        for (subscriber, _) in subscribers {
            subscriber.publish(&publish.topic, &publish.payload, 0, false, None)?;
        }
        Ok(())
    }

    fn on_puback(&self, _: &Puback, _: &Connection) -> Result<(), Error> {
        Ok(())
    }

    fn on_disconnect(&self, _: Option<&Disconnect>, _: &Connection) -> Result<(), Error> {
        Ok(())
    }
}

/// A broker on the in-process adapter, with its log.
struct Rig {
    adapter: Memory,
    timer: Option<Arc<Timeline>>,
    timeline: Log,
    log: Log,
    server: Option<Server<Memory, Recorder>>,
    telemetry: Option<MemoryTelemetry>,
}

impl Rig {
    fn new(timeline: bool) -> Self {
        let log = Log::default();
        if timeline {
            let timer = Arc::new(Timeline { log: log.clone(), tick: RwLock::default() });
            Rig {
                adapter: Memory::with_timer(timer.clone()),
                timer: Some(timer),
                timeline: log,
                log: Log::default(),
                server: None,
                telemetry: None,
            }
        } else {
            Rig {
                adapter: Memory::with_timer(Arc::new(NoTimer)),
                timer: None,
                timeline: Log::default(),
                log: Log::default(),
                server: None,
                telemetry: None,
            }
        }
    }

    fn server(&self) -> Result<&Server<Memory, Recorder>, Fault> {
        self.server.as_ref().ok_or_else(|| Fault::new("no server on this rig"))
    }

    fn state(&self) -> Value {
        json!({
            "started": self.adapter.is_started(),
            "open": self.adapter.connections(),
            "sent": self.adapter.sent().iter().map(|s| json!([s.connection, bytes_value(&s.data)])).collect::<Vec<_>>(),
            "closed": self.adapter.closed(),
            "timer": snapshot(&self.timeline),
            "log": snapshot(&self.log),
        })
    }

    fn metrics(&self) -> Value {
        let Some(telemetry) = &self.telemetry else {
            return json!([]);
        };
        let number = |n: &Number| match n {
            Number::Int(i) => json!(i),
            Number::Float(f) => float_value(*f),
        };
        let mut out = Vec::new();
        for (kind, label) in [(Metric::Counter, "counter"), (Metric::UpDownCounter, "up_down_counter")] {
            for name in telemetry.names(kind) {
                let values = telemetry.values(kind, &name).unwrap_or_default();
                out.push(json!([label, name, values.iter().map(number).collect::<Vec<_>>()]));
            }
        }
        Value::Array(out)
    }
}

/// A timing wheel with what its hooks saw.
struct Wheel {
    wheel: TimingWheel,
    log: Log,
}

/// A client with what its hooks saw.
struct Probe {
    client: Client,
    log: Log,
}

fn rig<'s>(session: &'s Session, a: &Args<'_>) -> Result<&'s Rig, Fault> {
    session.get::<Rig>(a.value("rig")?)
}

fn store<'s>(session: &'s mut Session, a: &Args<'_>) -> Result<&'s mut Store, Fault> {
    session.get_mut::<Store>(a.value("store")?)
}

fn connection<'s>(session: &'s Session, a: &Args<'_>) -> Result<&'s Arc<Connection>, Fault> {
    session.get::<Arc<Connection>>(a.value("connection")?)
}

fn unit(result: Result<(), Error>) -> Outcome {
    match result {
        Ok(()) => Outcome::Ok(Value::Null),
        Err(e) => err(&e),
    }
}

fn encoded(result: Result<Vec<u8>, Error>) -> Outcome {
    match result {
        Ok(bytes) => Outcome::Ok(bytes_value(&bytes)),
        Err(e) => err(&e),
    }
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    let a = Args(args);
    let result = match op {
        // Primitives.
        "length.decode" => {
            let (value, count) = wire::decode_length(&a.bytes("data")?, usize_arg(&a, "offset")?);
            Outcome::Ok(json!([length_value(value), count]))
        }
        "length.encode" => {
            let length = u64::try_from(a.i64("length")?).map_err(|_| Fault::new("lengths are not negative here"))?;
            let mut out = Vec::new();
            wire::encode_length(length, &mut out);
            Outcome::Ok(bytes_value(&out))
        }
        "string.read" => {
            let data = a.bytes("data")?;
            let (value, offset) = wire::read_string(&data, usize_arg(&a, "offset")?);
            Outcome::Ok(json!([bytes_value(value), offset]))
        }
        "string.encode" => {
            let mut out = Vec::new();
            wire::encode_string(&a.bytes("value")?, &mut out);
            Outcome::Ok(bytes_value(&out))
        }
        "int16.read" => {
            let (value, offset) = wire::read_int16(&a.bytes("data")?, usize_arg(&a, "offset")?);
            Outcome::Ok(json!([value, offset]))
        }
        "byte.read" => {
            let (value, offset) = wire::read_byte(&a.bytes("data")?, usize_arg(&a, "offset")?);
            Outcome::Ok(json!([value, offset]))
        }

        // Packets.
        "packet.parse" => {
            let data = a.bytes("data")?;
            match Packet::parse(&data) {
                Ok(packet) => Outcome::Ok(packet_out(&packet)),
                Err(e) => err(&e),
            }
        }
        "packet.new" => {
            let body = a.bytes("body")?;
            let packet =
                Packet { kind: Kind::from_code(u8_arg(&a, "type")?), flags: u8_arg(&a, "flags")?, body: &body };
            Outcome::Ok(packet_out(&packet))
        }
        "packet.clean_start" => Outcome::ok(Packet::is_clean_start(&a.bytes("body")?)),
        "packet.client_id" => match Packet::client_id(&a.bytes("body")?) {
            Ok(id) => Outcome::Ok(bytes_value(&id)),
            Err(e) => err(&e),
        },
        "packet.frames" => {
            let buffer = a.bytes("buffer")?;
            let (packets, rest) = packet::frames(&buffer);
            Outcome::Ok(json!([packets.iter().map(|p| bytes_value(p)).collect::<Vec<_>>(), bytes_value(rest)]))
        }
        "packet.ping" => Outcome::Ok(json!([bytes_value(&packet::pingreq()), bytes_value(&packet::pingresp())])),
        "decode.all" => {
            let data = data(args)?;
            let protocol = u8_arg(&a, "protocol")?;
            let (length, count) = wire::decode_length(&data, 1);
            let (frames, rest) = packet::frames(&data);
            let mut out = serde_json::Map::new();
            out.insert("length".into(), json!([length_value(length), count]));
            out.insert(
                "frames".into(),
                json!([frames.iter().map(|p| bytes_value(p)).collect::<Vec<_>>(), bytes_value(rest)]),
            );
            let packet = match Packet::parse(&data) {
                Ok(packet) => packet,
                Err(e) => {
                    out.insert("parse".into(), error_value(&e));
                    return Ok(Outcome::Ok(Value::Object(out)));
                }
            };
            let body = packet.body;
            out.insert("parse".into(), packet_out(&packet));
            out.insert("clean_start".into(), json!(Packet::is_clean_start(body)));
            out.insert("client_id".into(), attempt(Packet::client_id(body).map(|id| bytes_value(&id))));
            out.insert("connect".into(), attempt(Connect::decode(body).map(|c| connect_out(&c))));
            out.insert("auth".into(), attempt(Auth::decode(body).map(|a| auth_out(&a))));
            out.insert("disconnect".into(), disconnect_out(&Disconnect::decode(body)));
            out.insert(
                "publish".into(),
                attempt(Publish::decode(&packet, protocol).map(|p| {
                    json!({
                        "topic": bytes_value(&p.topic),
                        "payload": bytes_value(&p.payload),
                        "qos": p.qos,
                        "dup": p.dup,
                        "retain": p.retain,
                        "packet_id": p.packet_id,
                        "properties": properties_out(p.properties.as_ref()),
                        "user": pairs(&p.user_properties()),
                    })
                })),
            );
            let puback = Puback::decode(body);
            out.insert("puback".into(), json!({ "packet_id": puback.packet_id, "reason_code": puback.reason_code }));
            out.insert(
                "subscribe".into(),
                attempt(Subscribe::decode(body, protocol).map(|p| {
                    json!({
                        "packet_id": p.packet_id,
                        "filters": p.filters.iter().map(|f| json!([bytes_value(&f.topic), f.qos])).collect::<Vec<_>>(),
                        "properties": properties_out(p.properties.as_ref()),
                    })
                })),
            );
            out.insert(
                "unsubscribe".into(),
                attempt(Unsubscribe::decode(body, protocol).map(|p| {
                    json!({
                        "packet_id": p.packet_id,
                        "filters": p.filters.iter().map(|f| bytes_value(f)).collect::<Vec<_>>(),
                        "properties": properties_out(p.properties.as_ref()),
                    })
                })),
            );
            out.insert(
                "properties".into(),
                attempt(Properties::parse(body, 0).map(|(p, offset)| {
                    json!({ "properties": properties_out(Some(&p)), "offset": offset, "user": pairs(&p.user()) })
                })),
            );
            out.insert("skip".into(), attempt(Properties::skip(body, 0).map(|o| json!(o))));
            Outcome::Ok(Value::Object(out))
        }

        // Properties.
        "property.wire_type" => match Property::wire_type(u8_arg(&a, "id")?) {
            Ok(kind) => Outcome::ok(kind.as_str()),
            Err(e) => err(&e),
        },
        "property.encode" => encoded(Property::new(u8_arg(&a, "id")?, value_in(a.value("value")?)?).encode()),
        "properties.encode" => encoded(block(a.value("properties")?)?.encode()),
        "properties.parse" => match Properties::parse(&a.bytes("data")?, usize_arg(&a, "offset")?) {
            Ok((properties, offset)) => Outcome::Ok(json!({
                "properties": properties_out(Some(&properties)),
                "offset": offset,
                "user": pairs(&properties.user()),
            })),
            Err(e) => err(&e),
        },
        "properties.get" => {
            let properties = block(a.value("properties")?)?;
            let mut out = Vec::new();
            for id in a.array("ids")? {
                let id = id.as_u64().and_then(|i| u8::try_from(i).ok()).ok_or_else(|| Fault::new("ids are bytes"))?;
                out.push(properties.get(id).map_or(Value::Null, value_out));
            }
            Outcome::Ok(Value::Array(out))
        }
        "properties.skip" => match Properties::skip(&a.bytes("data")?, usize_arg(&a, "offset")?) {
            Ok(offset) => Outcome::ok(offset),
            Err(e) => err(&e),
        },

        // Decoded packets.
        "connect.decode" => match Connect::decode(&a.bytes("body")?) {
            Ok(c) => Outcome::Ok(connect_out(&c)),
            Err(e) => err(&e),
        },
        "auth.decode" => match Auth::decode(&a.bytes("body")?) {
            Ok(auth) => Outcome::Ok(auth_out(&auth)),
            Err(e) => err(&e),
        },
        "auth.new" => {
            let auth = match a.str("kind")? {
                "success" => Auth::success(&a.opt("method").and_then(bytes).unwrap_or_default()),
                "challenge" => Auth::challenge(&a.bytes("method")?, &a.bytes("data")?),
                _ => return Err(Fault::new("unknown kind")),
            };
            Outcome::Ok(auth_out(&auth))
        }
        "connack.new" => {
            let properties = optional(&a)?;
            let connack = match a.str("kind")? {
                "accept" => Connack::accept(a.opt_bool("session_present")?.unwrap_or(false), properties),
                "refuse" => Connack::refuse(
                    a.opt_i64("reason_code")?.map_or(Ok(Connack::NOT_AUTHORIZED), |c| {
                        u8::try_from(c).map_err(|_| Fault::new("reason codes are bytes"))
                    })?,
                    properties,
                ),
                _ => return Err(Fault::new("unknown kind")),
            };
            Outcome::Ok(json!({
                "reason_code": connack.reason_code,
                "session_present": connack.session_present,
                "properties": properties_out(connack.properties.as_ref()),
                "accepted": connack.accepted(),
            }))
        }
        "disconnect.decode" => Outcome::Ok(disconnect_out(&Disconnect::decode(&a.bytes("body")?))),
        "disconnect.new" => {
            let reason = a.opt("reason").and_then(bytes);
            let disconnect = match a.str("kind")? {
                "refuse" => Disconnect::refuse(
                    a.opt_i64("reason_code")?.map_or(Ok(Disconnect::NOT_AUTHORIZED), |c| {
                        u8::try_from(c).map_err(|_| Fault::new("reason codes are bytes"))
                    })?,
                    reason.as_deref(),
                ),
                "normal" => Disconnect::normal(reason.as_deref()),
                _ => return Err(Fault::new("unknown kind")),
            };
            Outcome::Ok(disconnect_out(&disconnect))
        }
        "publish.decode" => {
            let data = a.bytes("data")?;
            let protocol = u8_arg(&a, "protocol")?;
            match Packet::parse(&data).and_then(|p| Publish::decode(&p, protocol)) {
                Ok(p) => Outcome::Ok(json!({
                    "topic": bytes_value(&p.topic),
                    "payload": bytes_value(&p.payload),
                    "qos": p.qos,
                    "dup": p.dup,
                    "retain": p.retain,
                    "packet_id": p.packet_id,
                    "properties": properties_out(p.properties.as_ref()),
                    "user": pairs(&p.user_properties()),
                })),
                Err(e) => err(&e),
            }
        }
        "puback.decode" => {
            let p = Puback::decode(&a.bytes("body")?);
            Outcome::Ok(json!({ "packet_id": p.packet_id, "reason_code": p.reason_code }))
        }
        "subscribe.decode" => match Subscribe::decode(&a.bytes("body")?, u8_arg(&a, "protocol")?) {
            Ok(p) => Outcome::Ok(json!({
                "packet_id": p.packet_id,
                "filters": p.filters.iter().map(|f| json!([bytes_value(&f.topic), f.qos])).collect::<Vec<_>>(),
                "properties": properties_out(p.properties.as_ref()),
            })),
            Err(e) => err(&e),
        },
        "unsubscribe.decode" => match Unsubscribe::decode(&a.bytes("body")?, u8_arg(&a, "protocol")?) {
            Ok(p) => Outcome::Ok(json!({
                "packet_id": p.packet_id,
                "filters": p.filters.iter().map(|f| bytes_value(f)).collect::<Vec<_>>(),
                "properties": properties_out(p.properties.as_ref()),
            })),
            Err(e) => err(&e),
        },
        "filter.new" => {
            let f = Filter { topic: a.bytes("topic")?, qos: u8_arg(&a, "qos")? };
            Outcome::Ok(json!([bytes_value(&f.topic), f.qos]))
        }
        "will.new" => {
            let w = Will {
                topic: a.bytes("topic")?,
                payload: a.bytes("payload")?,
                qos: u8_arg(&a, "qos")?,
                retain: a.bool("retain")?,
            };
            Outcome::Ok(will_out(&w))
        }
        "suback.build" => {
            let mut suback = Suback::new();
            for step in a.array("steps")? {
                let step = step.as_array().ok_or_else(|| Fault::new("a step is a list"))?;
                let code = step.get(1).and_then(Value::as_u64).map(|c| c as u8);
                match step.first().and_then(Value::as_str) {
                    Some("grant") => suback.grant(code.ok_or_else(|| Fault::new("grant takes a QoS"))?),
                    Some("deny") => suback.deny(code.unwrap_or(Suback::DENIED)),
                    _ => return Err(Fault::new("unknown step")),
                };
            }
            Outcome::ok(suback.codes().to_vec())
        }
        "unsuback.build" => {
            let mut unsuback = Unsuback::new();
            for step in a.array("steps")? {
                let step = step.as_array().ok_or_else(|| Fault::new("a step is a list"))?;
                let code = step.get(1).and_then(Value::as_u64).map(|c| c as u8);
                match step.first().and_then(Value::as_str) {
                    Some("success") => unsuback.success(),
                    Some("fail") => unsuback.fail(code.unwrap_or(Unsuback::NOT_AUTHORIZED)),
                    _ => return Err(Fault::new("unknown step")),
                };
            }
            Outcome::ok(unsuback.codes().to_vec())
        }

        // Encoders.
        "v3.connect" => Outcome::Ok(bytes_value(&v3::connect(
            &a.bytes("client_id")?,
            a.opt_i64("keep_alive")?.map_or(Ok(60), |k| u16::try_from(k).map_err(|_| Fault::new("keep_alive")))?,
            a.opt_bool("clean_session")?.unwrap_or(true),
            &a.opt("username").and_then(bytes).unwrap_or_default(),
            &a.opt("password").and_then(bytes).unwrap_or_default(),
        ))),
        "v3.subscribe" => Outcome::Ok(bytes_value(&v3::subscribe(
            u16_arg(&a, "packet_id")?,
            &filters(&a)?,
            a.opt_i64("qos")?.map_or(0, |q| q as u8),
        ))),
        "v3.unsubscribe" => Outcome::Ok(bytes_value(&v3::unsubscribe(u16_arg(&a, "packet_id")?, &filters(&a)?))),
        "v3.connack" => Outcome::Ok(bytes_value(&v3::connack(u8_arg(&a, "code")?))),
        "v3.publish" => Outcome::Ok(bytes_value(&v3::publish(
            &a.bytes("topic")?,
            &a.bytes("payload")?,
            u8_arg(&a, "qos")?,
            u16_arg(&a, "packet_id")?,
            a.opt_bool("dup")?.unwrap_or(false),
        ))),
        "v3.puback" => Outcome::Ok(bytes_value(&v3::puback(&a.bytes("packet_id")?))),
        "v3.suback" => Outcome::Ok(bytes_value(&v3::suback(&a.bytes("packet_id")?, &a.bytes("codes")?))),
        "v3.unsuback" => Outcome::Ok(bytes_value(&v3::unsuback(&a.bytes("packet_id")?))),
        "v3.disconnect" => Outcome::Ok(bytes_value(&v3::disconnect())),
        "v5.connect" => encoded(v5::connect(
            &a.bytes("client_id")?,
            a.opt_i64("keep_alive")?.map_or(Ok(60), |k| u16::try_from(k).map_err(|_| Fault::new("keep_alive")))?,
            a.opt_bool("clean_start")?.unwrap_or(true),
            optional(&a)?.as_ref(),
        )),
        "v5.subscribe" => encoded(v5::subscribe(
            u16_arg(&a, "packet_id")?,
            &filters(&a)?,
            a.opt_i64("qos")?.map_or(0, |q| q as u8),
            optional(&a)?.as_ref(),
        )),
        "v5.unsubscribe" => encoded(v5::unsubscribe(u16_arg(&a, "packet_id")?, &filters(&a)?, optional(&a)?.as_ref())),
        "v5.connack" => encoded(v5::connack(u8_arg(&a, "code")?, optional(&a)?.as_ref())),
        "v5.publish" => encoded(v5::publish(
            &a.bytes("topic")?,
            &a.bytes("payload")?,
            u8_arg(&a, "qos")?,
            u16_arg(&a, "packet_id")?,
            optional(&a)?.as_ref(),
            a.opt_bool("dup")?.unwrap_or(false),
        )),
        "v5.puback" => Outcome::Ok(bytes_value(&v5::puback(&a.bytes("packet_id")?))),
        "v5.suback" => encoded(v5::suback(&a.bytes("packet_id")?, &a.bytes("codes")?, optional(&a)?.as_ref())),
        "v5.unsuback" => encoded(v5::unsuback(&a.bytes("packet_id")?, usize_arg(&a, "count")?, optional(&a)?.as_ref())),
        "v5.disconnect" => encoded(v5::disconnect(u8_arg(&a, "code")?, optional(&a)?.as_ref())),
        "v5.auth" => encoded(v5::auth(u8_arg(&a, "code")?, optional(&a)?.as_ref())),

        // The subscription store.
        "store.new" => Outcome::Ok(session.handle(Store::new())),
        "store.subscribe" => {
            let (prefix, user, topic, fd) = (a.bytes("prefix")?, a.bytes("user")?, a.bytes("topic")?, a.i64("fd")?);
            let qos = a.opt_i64("qos")?.map_or(1, |q| q as u8);
            store(session, &a)?.subscribe(&prefix, &user, &topic, fd, qos);
            Outcome::Ok(Value::Null)
        }
        "store.unsubscribe" => {
            let (topic, fd) = (a.bytes("topic")?, a.i64("fd")?);
            store(session, &a)?.unsubscribe(&topic, fd);
            Outcome::Ok(Value::Null)
        }
        "store.close" => {
            let fd = a.i64("fd")?;
            store(session, &a)?.close(fd);
            Outcome::Ok(Value::Null)
        }
        "store.connection" => {
            let fd = a.i64("fd")?;
            Outcome::Ok(record_out(store(session, &a)?, fd))
        }
        "store.subscribers" => {
            let (prefix, topic) = (a.bytes("prefix")?, a.bytes("topic")?);
            Outcome::Ok(fds_out(store(session, &a)?, &prefix, &topic))
        }
        "store.script" => {
            let mut store = Store::new();
            let mut out = Vec::new();
            for step in a.array("steps")? {
                let s = Args(step);
                out.push(match s.str("op")? {
                    "subscribe" => {
                        let qos = u8_arg(&s, "qos")?;
                        store.subscribe(&s.bytes("prefix")?, &s.bytes("user")?, &s.bytes("topic")?, s.i64("fd")?, qos);
                        Value::Null
                    }
                    "unsubscribe" => {
                        store.unsubscribe(&s.bytes("topic")?, s.i64("fd")?);
                        Value::Null
                    }
                    "close" => {
                        store.close(s.i64("fd")?);
                        Value::Null
                    }
                    "connection" => record_out(&store, s.i64("fd")?),
                    "subscribers" => fds_out(&store, &s.bytes("prefix")?, &s.bytes("topic")?),
                    _ => return Err(Fault::new("unknown step")),
                });
            }
            Outcome::Ok(Value::Array(out))
        }

        // Connections.
        "connection.new" => {
            let outbound: Option<Arc<dyn utopia_mqtt::Outbound>> = match a.opt("rig") {
                Some(handle) => Some(Arc::new(session.get::<Rig>(handle)?.adapter.clone())),
                None => None,
            };
            let connection = Arc::new(Connection::new(a.i64("fd")?, outbound));
            Outcome::Ok(session.handle(connection))
        }
        "connection.set" => {
            let c = connection(session, &a)?;
            let mut state = c.state();
            if let Some(v) = a.opt_i64("protocol")? {
                state.protocol = u8::try_from(v).map_err(|_| Fault::new("protocol"))?;
            }
            if let Some(v) = a.opt("prefix") {
                state.prefix = bytes(v).ok_or_else(|| Fault::new("prefix"))?;
            }
            if let Some(v) = a.opt_bool("clean_start")? {
                state.clean_start = v;
            }
            if let Some(v) = a.opt("identity") {
                state.identity = Some(Arc::new(v.clone()));
            }
            if let Some(v) = a.opt_bool("active")? {
                state.active = v;
            }
            if let Some(v) = a.opt_bool("closed")? {
                state.closed = v;
            }
            if let Some(v) = a.opt_i64("keep_alive")? {
                state.keep_alive = u16::try_from(v).map_err(|_| Fault::new("keep_alive"))?;
            }
            if let Some(v) = a.opt_f64("expires_at")? {
                state.expires_at = v;
            }
            if let Some(v) = a.opt_i64("wheel_slot")? {
                state.wheel_slot = v;
            }
            Outcome::Ok(Value::Null)
        }
        "connection.state" => {
            let c = connection(session, &a)?;
            let client_id = c.client_id();
            let state = c.state().clone();
            let identity = state.identity.as_ref().and_then(|i| i.downcast_ref::<Value>()).cloned();
            Outcome::Ok(json!({
                "fd": c.fd(),
                "protocol": state.protocol,
                "prefix": bytes_value(&state.prefix),
                "client_id": bytes_value(&client_id),
                "clean_start": state.clean_start,
                "identity": identity,
                "active": state.active,
                "closed": state.closed,
                "keep_alive": state.keep_alive,
                "expires_at": float_value(state.expires_at),
                "wheel_slot": state.wheel_slot,
                "opened": c.opened_at() > 0.0,
            }))
        }
        "connection.publish" => {
            let c = connection(session, &a)?;
            unit(c.publish(
                &a.bytes("topic")?,
                &a.bytes("payload")?,
                a.opt_i64("qos")?.map_or(0, |q| q as u8),
                a.opt_bool("dup")?.unwrap_or(false),
                a.opt_i64("sequence")?,
            ))
        }
        "connection.puback" => unit(connection(session, &a)?.puback(u16_arg(&a, "packet_id")?)),
        "connection.disconnect" => {
            let reason = a.opt_i64("reason")?.map_or(0, |r| r as u8);
            let reason_string = a.opt("reason_string").and_then(bytes);
            unit(connection(session, &a)?.disconnect(reason, reason_string.as_deref()))
        }
        "connection.update_expires_at" => {
            connection(session, &a)?.update_expires_at(a.f64("now")?, a.f64("multiplier")?);
            Outcome::Ok(Value::Null)
        }
        "connection.next_packet_id" => Outcome::ok(connection(session, &a)?.next_packet_id()),
        "connection.resume" => {
            connection(session, &a)?.resume(&a.bytes("topic")?, a.i64("cursor")?);
            Outcome::Ok(Value::Null)
        }
        "connection.raise" => {
            connection(session, &a)?.raise(&a.bytes("topic")?, a.i64("cursor")?);
            Outcome::Ok(Value::Null)
        }
        "connection.resumed" => Outcome::ok(connection(session, &a)?.resumed(&a.bytes("topic")?)),
        "connection.cursor" => Outcome::Ok(json!(connection(session, &a)?.cursor(&a.bytes("topic")?))),
        "connection.track" => {
            connection(session, &a)?.track(u16_arg(&a, "packet_id")?, &a.bytes("topic")?, a.i64("sequence")?);
            Outcome::Ok(Value::Null)
        }
        "connection.acknowledge" => {
            Outcome::Ok(ack_out(connection(session, &a)?.acknowledge(u16_arg(&a, "packet_id")?)))
        }
        "connection.set_client_id" => {
            connection(session, &a)?.set_client_id(&a.bytes("client_id")?);
            Outcome::Ok(Value::Null)
        }
        "connection.client_id" => Outcome::Ok(bytes_value(&connection(session, &a)?.client_id())),
        "connection.script" => {
            let c = Connection::new(1, None);
            let mut out = Vec::new();
            for step in a.array("steps")? {
                let s = Args(step);
                out.push(match s.str("op")? {
                    "track" => {
                        c.track(u16_arg(&s, "packet_id")?, &s.bytes("topic")?, s.i64("sequence")?);
                        Value::Null
                    }
                    "resume" => {
                        c.resume(&s.bytes("topic")?, s.i64("cursor")?);
                        Value::Null
                    }
                    "raise" => {
                        c.raise(&s.bytes("topic")?, s.i64("cursor")?);
                        Value::Null
                    }
                    "resumed" => json!(c.resumed(&s.bytes("topic")?)),
                    "cursor" => json!(c.cursor(&s.bytes("topic")?)),
                    "acknowledge" => ack_out(c.acknowledge(u16_arg(&s, "packet_id")?)),
                    "next" => json!(c.next_packet_id()),
                    _ => return Err(Fault::new("unknown step")),
                });
            }
            Outcome::Ok(Value::Array(out))
        }

        // The in-process adapter and the broker on it.
        "memory.new" => {
            let timeline = a.opt_str("timer")?.unwrap_or("timeline") == "timeline";
            Outcome::Ok(session.handle(Rig::new(timeline)))
        }
        "memory.fail" => {
            let operation = match a.str("operation")? {
                "send" => Operation::Send,
                "close" => Operation::Close,
                "start" => Operation::Start,
                "shutdown" => Operation::Shutdown,
                _ => return Err(Fault::new("unknown operation")),
            };
            rig(session, &a)?.adapter.fail(operation, a.str("message")?);
            Outcome::Ok(Value::Null)
        }
        "memory.open" => unit(rig(session, &a)?.adapter.open(a.i64("fd")?)),
        "memory.receive" => unit(rig(session, &a)?.adapter.receive(a.i64("fd")?, &a.bytes("data")?)),
        "memory.disconnect" => match rig(session, &a)?.adapter.disconnect(a.i64("fd")?) {
            Ok(open) => Outcome::ok(open),
            Err(e) => err(&e),
        },
        "memory.tick" => {
            if let Some(timer) = &rig(session, &a)?.timer {
                timer.tick(a.i64("id")?);
            }
            Outcome::Ok(Value::Null)
        }
        "memory.state" => Outcome::Ok(rig(session, &a)?.state()),
        "server.new" => {
            let r = session.get_mut::<Rig>(a.value("rig")?)?;
            let recorder = Recorder { adapter: Some(r.adapter.clone()), ..Recorder::new(r.log.clone()) };
            r.server = Some(Server::new(r.adapter.clone(), recorder));
            Outcome::Ok(Value::Null)
        }
        "server.telemetry" => {
            let r = session.get_mut::<Rig>(a.value("rig")?)?;
            let telemetry = MemoryTelemetry::new();
            r.server()?.set_telemetry(&telemetry);
            r.telemetry = Some(telemetry);
            Outcome::ok(true)
        }
        "server.on" => {
            let r = rig(session, &a)?;
            let log = r.log.clone();
            let server = r.server()?;
            match a.str("hook")? {
                "start" => server.on_start(move || push(&log, json!(["start"]))),
                "worker_start" => server.on_worker_start(move |w| push(&log, json!(["workerStart", w]))),
                "stop" => server.on_stop(move || push(&log, json!(["stop"]))),
                _ => return Err(Fault::new("unknown hook")),
            };
            Outcome::ok(true)
        }
        "server.error" => {
            let r = rig(session, &a)?;
            let log = r.log.clone();
            r.server()?.error(move |e, stage| push(&log, json!(["error", e.php_class(), e.to_string(), stage])));
            Outcome::ok(true)
        }
        "server.start" => {
            rig(session, &a)?.server()?.start().await;
            Outcome::Ok(Value::Null)
        }
        "server.stop" => {
            rig(session, &a)?.server()?.stop();
            Outcome::Ok(Value::Null)
        }
        "server.subscribers" => {
            let subscribers = rig(session, &a)?.server()?.subscribers(&a.bytes("prefix")?, &a.bytes("topic")?);
            Outcome::Ok(Value::Array(subscribers.iter().map(|(c, q)| json!([c.fd(), c.fd(), q])).collect()))
        }
        "server.unsubscribe" => {
            rig(session, &a)?.server()?.unsubscribe(&a.bytes("topic")?, a.i64("fd")?);
            Outcome::Ok(Value::Null)
        }
        "server.log" => Outcome::Ok(snapshot(&rig(session, &a)?.log)),
        "server.metrics" => Outcome::Ok(rig(session, &a)?.metrics()),
        "server.session" => {
            let mut r = Rig::new(true);
            let server = Server::new(r.adapter.clone(), Recorder::new(r.log.clone()));
            let telemetry = MemoryTelemetry::new();
            server.set_telemetry(&telemetry);
            r.telemetry = Some(telemetry);
            let log = r.log.clone();
            server.error(move |e, stage| push(&log, json!(["error", e.php_class(), e.to_string(), stage])));
            server.start().await;
            r.server = Some(server);
            let open: Vec<i64> = match a.opt("open") {
                Some(v) => v.as_array().into_iter().flatten().filter_map(Value::as_i64).collect(),
                None => vec![1, 2],
            };
            for fd in open {
                let _ = r.adapter.open(fd);
            }
            let mut outcomes = Vec::new();
            for packet in a.array("packets")? {
                let (fd, data) = match packet.get("fd") {
                    Some(fd) => (fd.as_i64(), Some(data(packet)?)),
                    None => (packet.get(0).and_then(Value::as_i64), packet.get(1).and_then(bytes)),
                };
                let fd = fd.ok_or_else(|| Fault::new("a packet's fd"))?;
                let data = data.ok_or_else(|| Fault::new("a packet's bytes"))?;
                outcomes.push(match r.adapter.receive(fd, &data) {
                    Ok(()) => Value::Null,
                    Err(e) => json!([e.php_class(), e.to_string()]),
                });
            }
            Outcome::Ok(json!({ "outcomes": outcomes, "state": r.state(), "metrics": r.metrics() }))
        }

        // Timers.
        "wheel.new" => {
            let interval = a.opt_i64("interval")?.unwrap_or(20);
            let multiplier = a.opt_f64("multiplier")?.unwrap_or(1.5);
            let now = a.opt_i64("now_offset")?.map(|o| php_std::datetime::time() + o);
            let wheel = TimingWheel::new(interval, multiplier, now);
            let log = Log::default();
            let (ticks, clears) = (log.clone(), log.clone());
            wheel.on_tick(Arc::new(move |id| push(&ticks, json!(["tick", id]))));
            wheel.on_clear(Arc::new(move |id| push(&clears, json!(["clear", id]))));
            let out = json!({ "interval": wheel.interval, "multiplier": float_value(wheel.multiplier), "chained": [true, true] });
            let handle = session.handle(Wheel { wheel, log });
            let mut map = serde_json::Map::new();
            map.insert("wheel".into(), handle);
            if let Value::Object(rest) = out {
                map.extend(rest);
            }
            Outcome::Ok(Value::Object(map))
        }
        "wheel.schedule" => {
            session.get::<Wheel>(a.value("wheel")?)?.wheel.schedule(a.i64("id")?, a.i64("keep_alive")?);
            Outcome::Ok(Value::Null)
        }
        "wheel.clear" => {
            session.get::<Wheel>(a.value("wheel")?)?.wheel.clear(a.i64("id")?);
            Outcome::Ok(Value::Null)
        }
        "wheel.drain" => {
            let now = php_std::datetime::time() + a.i64("offset")?;
            session.get::<Wheel>(a.value("wheel")?)?.wheel.drain(now);
            Outcome::Ok(Value::Null)
        }
        "wheel.log" => Outcome::Ok(snapshot(&session.get::<Wheel>(a.value("wheel")?)?.log)),
        "notimer.run" => {
            let timer = NoTimer;
            timer.on_tick(Arc::new(|_| {}));
            timer.on_clear(Arc::new(|_| {}));
            timer.schedule(1, 10);
            timer.clear(1);
            Outcome::Ok(json!([true, true]))
        }

        // The network adapter's transports and construction.
        "transport.new" => {
            let host = a.opt_str("host")?.unwrap_or("0.0.0.0");
            let port = |default: u16| -> Result<u16, Fault> {
                a.opt_i64("port")?.map_or(Ok(default), |p| u16::try_from(p).map_err(|_| Fault::new("port")))
            };
            let mut transport = match a.str("kind")? {
                "tcp" => Transport::tcp(host, port(1883)?),
                "tls" => Transport::tls(
                    host,
                    port(8883)?,
                    a.opt_str("cert")?.unwrap_or_default(),
                    a.opt_str("key")?.unwrap_or_default(),
                ),
                "websocket" => Transport::websocket(host, port(8083)?),
                _ => return Err(Fault::new("unknown transport")),
            };
            if let Some(size) = a.opt_i64("max_packet_size")? {
                transport = transport.with_max_packet_size(usize::try_from(size).map_err(|_| Fault::new("size"))?);
            }
            let (cert, key) = match &transport {
                Transport::Tls { cert, key, .. } => (json!(cert), json!(key)),
                _ => (Value::Null, Value::Null),
            };
            Outcome::Ok(json!({
                "host": transport.host(),
                "port": transport.port(),
                "tls": matches!(transport, Transport::Tls { .. }),
                "websocket": matches!(transport, Transport::WebSocket { .. }),
                "max_packet_size": transport.max_packet_size(),
                "cert": cert,
                "key": key,
            }))
        }
        "swoole.new" => {
            if !a.array("transports")?.is_empty() {
                return Err(Fault::new("swoole.new only constructs without transports"));
            }
            match Tokio::new(Vec::new(), 1, None) {
                Ok(_) => Outcome::Ok(Value::Null),
                Err(e) => err(&e),
            }
        }

        // The client.
        "client.new" => {
            let mut options = Options::default();
            if let Some(timeout) = a.opt_f64("timeout")? {
                options.timeout = timeout;
            }
            match Client::new(a.str("url")?, options) {
                Ok(client) => {
                    let out = json!({
                        "host": bytes_value(client.host()),
                        "port": client.port(),
                        "tls": client.is_tls(),
                        "timeout": float_value(client.timeout()),
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
        "client.hooks" => {
            let probe = session.get_mut::<Probe>(a.value("client")?)?;
            probe.client.on_open(|| {}).on_receive(|_| Ok(())).on_close(|| {}).on_error(|_| {});
            Outcome::ok(true)
        }
        "client.send" => {
            let data = a.bytes("data")?;
            unit(session.get_mut::<Probe>(a.value("client")?)?.client.send(&data).await)
        }
        "client.receive" => match session.get_mut::<Probe>(a.value("client")?)?.client.receive().await {
            Ok(data) => Outcome::Ok(data.map_or(Value::Null, |d| bytes_value(&d))),
            Err(e) => err(&e),
        },
        "client.is_connected" => Outcome::ok(session.get::<Probe>(a.value("client")?)?.client.is_connected()),
        "client.close" => {
            session.get_mut::<Probe>(a.value("client")?)?.client.close().await;
            Outcome::Ok(Value::Null)
        }
        "client.connect" => {
            let probe = session.get_mut::<Probe>(a.value("client")?)?;
            let (errors, opens) = (probe.log.clone(), probe.log.clone());
            probe.client.on_error(move |e| push(&errors, json!(["error", e.php_class(), e.to_string()])));
            probe.client.on_open(move || push(&opens, json!(["open"])));
            let error = match probe.client.connect().await {
                Ok(()) => Value::Null,
                Err(e) => json!([e.php_class(), e.to_string()]),
            };
            Outcome::Ok(
                json!({ "error": error, "log": snapshot(&probe.log), "connected": probe.client.is_connected() }),
            )
        }

        // Brokers on the network.
        "e2e.run" => {
            let broker = match a.opt_str("mode")?.unwrap_or("fixture") {
                "broker" => start_broker("127.0.0.1").await?,
                _ => start_fixture("127.0.0.1").await?,
            };
            let ports = broker.local_addrs();
            let endpoints = endpoints("127.0.0.1", &ports);
            let out = run_session(&endpoints, a.array("steps")?).await?;
            broker.shutdown().map_err(|e| Fault::new(e.to_string()))?;
            Outcome::Ok(Value::Array(out))
        }
        "broker.start" => {
            let broker = start_fixture("0.0.0.0").await?;
            let ports = broker.local_addrs();
            let port = |i: usize| ports.get(i).map_or(Value::Null, |a| json!(a.port()));
            let handle = session.handle(broker);
            Outcome::Ok(json!({ "runtime": "rust", "tcp": port(0), "ws": port(1), "tls": port(2), "broker": handle }))
        }
        "broker.stop" => {
            if let Some(handle) = a.value("broker")?.get("broker") {
                session.get::<Tokio>(handle)?.shutdown().map_err(|e| Fault::new(e.to_string()))?;
            }
            Outcome::Ok(Value::Null)
        }
        "client.session" => {
            let broker = a.value("broker")?;
            let endpoints: HashMap<&str, String> = if broker.get("runtime").and_then(Value::as_str) == Some("php") {
                HashMap::from([
                    ("tcp", session.service("mqtt-tcp")?.to_owned()),
                    ("ws", session.service("mqtt-ws")?.to_owned()),
                    ("tls", session.service("mqtt-tls")?.to_owned()),
                ])
            } else {
                let host = session.service("mqtt-host")?.to_owned();
                ["tcp", "ws", "tls"]
                    .into_iter()
                    .filter_map(|k| broker.get(k).and_then(Value::as_u64).map(|p| (k, format!("{host}:{p}"))))
                    .collect()
            };
            Outcome::Ok(Value::Array(run_session(&endpoints, a.array("steps")?).await?))
        }
        _ => return Err(Fault::new(format!("mqtt: unknown operation `{op}`"))),
    };
    Ok(result)
}

/// The bytes a fuzz input describes: `data`, or `base` with `edits` applied
/// in order, then cut to `cut` bytes; the twin of `data()` in Adapter.php.
fn data(spec: &Value) -> Result<Vec<u8>, Fault> {
    if let Some(data) = spec.get("data") {
        return bytes(data).ok_or_else(|| Fault::new("data is bytes"));
    }
    let mut data = spec.get("base").and_then(bytes).ok_or_else(|| Fault::new("base is bytes"))?;
    for edit in spec.get("edits").and_then(Value::as_array).into_iter().flatten() {
        let at = edit.get("at").and_then(Value::as_u64).ok_or_else(|| Fault::new("edit position"))? as usize;
        let byte = edit.get("byte").and_then(Value::as_u64).unwrap_or(0) as u8;
        let length = data.len();
        match edit.get("op").and_then(Value::as_str) {
            Some("set") if length > 0 => data[at % length] = byte,
            Some("insert") => data.insert(at % (length + 1), byte),
            Some("delete") if length > 0 => {
                data.remove(at % length);
            }
            _ => {}
        }
    }
    if let Some(cut) = spec.get("cut").and_then(Value::as_u64) {
        data.truncate(cut as usize);
    }
    Ok(data)
}

fn error_value(e: &Error) -> Value {
    json!({ "$error": { "class": e.php_class(), "message": e.to_string() } })
}

/// The value, or the error as `{$error: {class, message}}`.
fn attempt(result: Result<Value, Error>) -> Value {
    result.unwrap_or_else(|e| error_value(&e))
}

fn endpoints(host: &str, addrs: &[std::net::SocketAddr]) -> HashMap<&'static str, String> {
    ["tcp", "ws", "tls"].into_iter().zip(addrs).map(|(k, a)| (k, format!("{host}:{}", a.port()))).collect()
}

/// The fixture's state (server.php's `$protocol`, `$subscriptions`).
#[derive(Default)]
struct Fixture {
    protocol: HashMap<i64, u8>,
    subscriptions: IndexMap<i64, Vec<Vec<u8>>>,
}

/// server.php's `$matches`.
fn matches(filter: &[u8], topic: &[u8]) -> bool {
    let f: Vec<&[u8]> = filter.split(|&b| b == b'/').collect();
    let t: Vec<&[u8]> = topic.split(|&b| b == b'/').collect();
    for (i, segment) in f.iter().enumerate() {
        if *segment == b"#" {
            return true;
        }
        let Some(level) = t.get(i) else {
            return false;
        };
        if *segment != b"+" && segment != level {
            return false;
        }
    }
    f.len() == t.len()
}

/// The handlers of server.php, on the Tokio adapter.
fn fixture(adapter: &Tokio, packet: &[u8], fd: i64, state: &Mutex<Fixture>) -> Result<(), Error> {
    let packet = Packet::parse(packet)?;
    let body = packet.body;
    let level_of = |fd: i64| state.lock().unwrap_or_else(|e| e.into_inner()).protocol.get(&fd).copied();
    match packet.kind {
        Kind::Connect => {
            let (_, offset) = wire::read_string(body, 0);
            let level = body.get(offset).copied().unwrap_or(0);
            let flags = body.get(offset + 1).copied().unwrap_or(0);
            let offset = offset + 4;
            state.lock().unwrap_or_else(|e| e.into_inner()).protocol.insert(fd, level);
            if level >= 5 {
                let (properties, _) = Properties::parse(body, offset)?;
                if properties.get(Property::AUTHENTICATION_DATA) == Some(&PropertyValue::Bytes(b"deny".to_vec())) {
                    adapter.send(fd, &v5::connack(v5::REASON_NOT_AUTHORIZED, None)?)?;
                    return adapter.close(fd);
                }
                let method = match properties.get(Property::AUTHENTICATION_METHOD) {
                    Some(PropertyValue::Bytes(b)) => b.clone(),
                    Some(PropertyValue::Int(i)) => i.to_string().into_bytes(),
                    _ => Vec::new(),
                };
                let mut ack = Properties::new().with(Property::bytes(Property::AUTHENTICATION_METHOD, method));
                for (key, value) in properties.user() {
                    ack.add(Property::user(key, value));
                }
                return adapter.send(fd, &v5::connack(v5::REASON_SUCCESS, Some(&ack))?);
            }
            let (_, mut offset) = wire::read_string(body, offset);
            let mut password: &[u8] = b"";
            if flags & 0x80 != 0 {
                offset = wire::read_string(body, offset).1;
            }
            if flags & 0x40 != 0 {
                password = wire::read_string(body, offset).0;
            }
            if password == b"deny" {
                adapter.send(fd, &v3::connack(v3::RETURN_NOT_AUTHORIZED))?;
                return adapter.close(fd);
            }
            adapter.send(fd, &v3::connack(v3::RETURN_ACCEPTED))
        }
        Kind::Subscribe => {
            let packet_id = &body[..body.len().min(2)];
            let v5 = level_of(fd).unwrap_or(4) >= 5;
            let mut offset = if v5 { Properties::skip(body, 2)? } else { 2 };
            let mut codes = Vec::new();
            while offset < body.len() {
                let (filter, next) = wire::read_string(body, offset);
                offset = next + 1;
                state
                    .lock()
                    .unwrap_or_else(|e| e.into_inner())
                    .subscriptions
                    .entry(fd)
                    .or_default()
                    .push(filter.to_vec());
                codes.push(Packet::QOS_1);
            }
            adapter.send(fd, &if v5 { v5::suback(packet_id, &codes, None)? } else { v3::suback(packet_id, &codes) })
        }
        Kind::Unsubscribe => {
            let packet_id = &body[..body.len().min(2)];
            let v5 = level_of(fd).unwrap_or(4) >= 5;
            let mut offset = if v5 { Properties::skip(body, 2)? } else { 2 };
            let mut count = 0;
            while offset < body.len() {
                let (filter, next) = wire::read_string(body, offset);
                offset = next;
                let mut s = state.lock().unwrap_or_else(|e| e.into_inner());
                let filters = s.subscriptions.entry(fd).or_default();
                filters.retain(|f| f != filter);
                count += 1;
            }
            adapter.send(fd, &if v5 { v5::unsuback(packet_id, count, None)? } else { v3::unsuback(packet_id) })
        }
        Kind::Publish => {
            let qos = packet.qos();
            let (topic, mut offset) = wire::read_string(body, 0);
            let mut packet_id: &[u8] = b"";
            if qos > 0 {
                packet_id = &body[offset.min(body.len())..(offset + 2).min(body.len())];
                offset += 2;
            }
            let mut properties = Properties::new();
            if level_of(fd).unwrap_or(4) >= 5 {
                (properties, offset) = Properties::parse(body, offset)?;
            }
            let payload = &body[offset.min(body.len())..];
            if topic == b"compat/shutdown" {
                return adapter.shutdown();
            }
            let targets: Vec<(i64, u8)> = {
                let s = state.lock().unwrap_or_else(|e| e.into_inner());
                s.subscriptions
                    .iter()
                    .filter(|(_, filters)| filters.iter().any(|f| matches(f, topic)))
                    .map(|(&subscriber, _)| (subscriber, s.protocol.get(&subscriber).copied().unwrap_or(4)))
                    .collect()
            };
            for (subscriber, level) in targets {
                let delivery = if level >= 5 {
                    v5::publish(topic, payload, 0, 0, Some(&properties), false)?
                } else {
                    v3::publish(topic, payload, 0, 0, false)
                };
                adapter.send(subscriber, &delivery)?;
            }
            if qos == 1 {
                let v5 = level_of(fd).unwrap_or(0) >= 5;
                adapter.send(fd, &if v5 { v5::puback(packet_id) } else { v3::puback(packet_id) })?;
            }
            Ok(())
        }
        Kind::Pingreq => adapter.send(fd, &packet::pingresp()),
        Kind::Auth => adapter.send(fd, &v5::auth(v5::AUTH_SUCCESS, None)?),
        Kind::Disconnect => adapter.close(fd),
        _ => Ok(()),
    }
}

/// The listeners of an e2e or interop broker on `host`: TCP, WebSocket, and
/// TLS when services.sh wrote the certificate.
fn transports(host: &str) -> Vec<Transport> {
    let mut transports = vec![Transport::tcp(host, 0), Transport::websocket(host, 0)];
    let cert = format!("{CERTS}/server.pem");
    if std::path::Path::new(&cert).is_file() {
        transports.push(Transport::tls(host, 0, &cert, &format!("{CERTS}/server.key")));
    }
    transports
}

/// Starts a Server with the Recorder handler on the Tokio adapter, its
/// keep-alives on a timing wheel draining every second (server.php's broker
/// mode), and waits until it listens.
async fn start_broker(host: &str) -> Result<Tokio, Fault> {
    let wheel: Arc<dyn Timer> = Arc::new(TimingWheel::new(1, 1.0, None));
    let adapter = Tokio::new(transports(host), 1, Some(wheel)).map_err(|e| Fault::new(e.to_string()))?;
    let stopping = adapter.clone();
    let recorder = Recorder {
        shutdown: Some(Arc::new(move || {
            let _ = stopping.shutdown();
        })),
        ..Recorder::new(Log::default())
    };
    let server = Server::new(adapter.clone(), recorder);
    tokio::spawn(async move { server.start().await });
    tokio::time::timeout(Duration::from_secs(5), adapter.listening())
        .await
        .map_err(|_| Fault::new("the broker did not start"))?;
    Ok(adapter)
}

/// Starts the fixture on the Tokio adapter, listening on `host` (TCP,
/// WebSocket, and TLS when services.sh wrote the certificate), and waits until
/// it listens.
async fn start_fixture(host: &str) -> Result<Tokio, Fault> {
    let adapter = Tokio::new(transports(host), 1, None).map_err(|e| Fault::new(e.to_string()))?;
    let state = Arc::new(Mutex::new(Fixture::default()));
    let (receiving, closing) = (state.clone(), state);
    let sender = adapter.clone();
    adapter.on_start(Arc::new(|| {}));
    adapter.on_receive(Arc::new(move |fd, data| fixture(&sender, data, fd, &receiving)));
    adapter.on_close(Arc::new(move |fd| {
        let mut s = closing.lock().unwrap_or_else(|e| e.into_inner());
        s.protocol.remove(&fd);
        s.subscriptions.shift_remove(&fd);
        Ok(())
    }));
    let running = adapter.clone();
    tokio::spawn(async move { running.start().await });
    tokio::time::timeout(Duration::from_secs(5), adapter.listening())
        .await
        .map_err(|_| Fault::new("the fixture broker did not start"))?;
    Ok(adapter)
}

type Socket = WebSocketStream<MaybeTlsStream<TcpStream>>;

/// Runs steps against a broker with clients; the twin of `session()` in
/// Adapter.php.
async fn run_session(endpoints: &HashMap<&str, String>, steps: &[Value]) -> Result<Vec<Value>, Fault> {
    let mut clients: HashMap<String, Probe> = HashMap::new();
    let mut sockets: HashMap<String, (Socket, Vec<u8>)> = HashMap::new();
    let mut out = Vec::with_capacity(steps.len());
    for step in steps {
        out.push(match run_step(step, endpoints, &mut clients, &mut sockets).await? {
            Ok(v) => v,
            Err(e) => json!({ "error": e.php_class(), "message": e.to_string() }),
        });
    }
    for probe in clients.values_mut() {
        probe.client.close().await;
    }
    for (mut socket, _) in sockets.into_values() {
        let _ = socket.close(None).await;
    }
    Ok(out)
}

async fn run_step(
    step: &Value,
    endpoints: &HashMap<&str, String>,
    clients: &mut HashMap<String, Probe>,
    sockets: &mut HashMap<String, (Socket, Vec<u8>)>,
) -> Result<Result<Value, Error>, Fault> {
    let (kind, name) =
        step.as_object().and_then(|o| o.iter().next()).ok_or_else(|| Fault::new("a step is an object"))?;
    let key = name.as_str().unwrap_or_default().to_owned();
    let a = Args(step);
    Ok(match kind.as_str() {
        "connect" => {
            let tls = a.opt_str("transport")?.unwrap_or("tcp") == "tls";
            let endpoint = endpoints.get(if tls { "tls" } else { "tcp" }).ok_or_else(|| Fault::new("no endpoint"))?;
            let url = format!("{}{endpoint}", if tls { "mqtts://" } else { "mqtt://" });
            let timeout = a.opt_f64("timeout")?.unwrap_or(5.0);
            let mut client = Client::new(&url, Options { timeout }).map_err(|e| Fault::new(e.to_string()))?;
            let log = Log::default();
            let (l1, l2, l3, l4) = (log.clone(), log.clone(), log.clone(), log.clone());
            client
                .on_open(move || push(&l1, json!(["open"])))
                .on_receive(move |d| {
                    push(&l2, json!(["receive", bytes_value(d)]));
                    Ok(())
                })
                .on_close(move || push(&l3, json!(["close"])))
                .on_error(move |e| push(&l4, json!(["error", e.php_class()])));
            let result = client.connect().await;
            clients.insert(key, Probe { client, log });
            result.map(|()| Value::Null)
        }
        "send" => {
            let data = a.bytes("data")?;
            let p = clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?;
            p.client.send(&data).await.map(|()| Value::Null)
        }
        "receive" => {
            let p = clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?;
            p.client.receive().await.map(|d| d.map_or(Value::Null, |d| bytes_value(&d)))
        }
        "connected" => Ok(Value::Bool(clients.get(&key).ok_or_else(|| Fault::new("no client"))?.client.is_connected())),
        "close" => {
            clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?.client.close().await;
            Ok(Value::Null)
        }
        "listen" => {
            clients.get_mut(&key).ok_or_else(|| Fault::new("no client"))?.client.listen().await;
            Ok(Value::Null)
        }
        "log" => Ok(snapshot(&clients.get(&key).ok_or_else(|| Fault::new("no client"))?.log)),
        "ws_connect" => {
            let endpoint = endpoints.get("ws").ok_or_else(|| Fault::new("no endpoint"))?;
            let mut request =
                format!("ws://{endpoint}/mqtt").into_client_request().map_err(|e| Fault::new(e.to_string()))?;
            if let Some(protocol) = a.opt_str("protocol")? {
                request
                    .headers_mut()
                    .insert("Sec-WebSocket-Protocol", protocol.parse().map_err(|_| Fault::new("a header value"))?);
            }
            match tokio::time::timeout(Duration::from_secs(5), tokio_tungstenite::connect_async(request)).await {
                Ok(Ok((socket, response))) => {
                    let protocol = response
                        .headers()
                        .get("sec-websocket-protocol")
                        .map(|v| bytes_value(v.as_bytes()))
                        .unwrap_or(Value::Null);
                    sockets.insert(key, (socket, Vec::new()));
                    Ok(json!({ "upgraded": true, "protocol": protocol }))
                }
                _ => Ok(json!({ "upgraded": false, "protocol": null })),
            }
        }
        "ws_send" => {
            let data = a.bytes("data")?;
            let (socket, _) = sockets.get_mut(&key).ok_or_else(|| Fault::new("no socket"))?;
            Ok(Value::Bool(socket.send(Message::Binary(data.into())).await.is_ok()))
        }
        "ws_receive" => {
            let (socket, buffer) = sockets.get_mut(&key).ok_or_else(|| Fault::new("no socket"))?;
            loop {
                let (packets, _) = packet::frames(buffer);
                if let Some(first) = packets.first() {
                    let first = first.to_vec();
                    buffer.drain(..first.len());
                    break Ok(bytes_value(&first));
                }
                match tokio::time::timeout(Duration::from_secs(5), socket.next()).await {
                    Ok(Some(Ok(Message::Binary(data)))) => buffer.extend_from_slice(&data),
                    Ok(Some(Ok(Message::Text(text)))) => buffer.extend_from_slice(text.as_bytes()),
                    Ok(Some(Ok(_))) => {}
                    _ => break Ok(Value::Null),
                }
            }
        }
        "ws_close" => {
            if let Some((mut socket, _)) = sockets.remove(&key) {
                let _ = socket.close(None).await;
            }
            Ok(Value::Null)
        }
        "sleep" => {
            tokio::time::sleep(Duration::from_millis(name.as_u64().unwrap_or(0))).await;
            Ok(Value::Null)
        }
        other => return Err(Fault::new(format!("unknown step {other}"))),
    })
}
