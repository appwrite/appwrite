//! Compat adapter for `queue`: maps `tests/compat/queue/spec.json` operations onto `utopia-queue`.
//!
//! The fixtures mirror `tests/compat/queue/Memory.php` (a consumer over a list
//! of envelopes that records what the worker did) and `Clock.php` (the pinned
//! clock both runtimes' brokers read during a case).

use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use php_std::types::{Array, Value};
use serde_json::{Value as Json, json};
use utopia_dsn::Dsn;
use utopia_queue::adapter::{Adapter, ConsumerFactory, Runtime};
use utopia_queue::broker::{Background, Nats, NatsConfig, Pool, PoolResources, Provisioning, Redis};
use utopia_queue::codec::{Codec, Compat, Igbinary, Json as JsonCodec};
use utopia_queue::connection::{BoxFuture, Connection, Locking, Redis as RedisConnection, RedisCluster, Reply};
use utopia_queue::hook::{Check, Hook, Param};
use utopia_queue::{AsyncPublisher, Clock, Consumer, Error, Message, Publisher, Queue, Server, SystemClock};
use utopia_validators::{Integer, Text};

use super::php_std::typed::{decode, decode_array, dump};
use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes_value};

pub const OPS: &[&str] = &[
    "clock.set",
    "queue.new",
    "message.new",
    "message.set",
    "message.warn_if_large",
    "codec.encode",
    "codec.decode",
    "codec.content_type",
    "codec.round_trip",
    "connection.new",
    "connection.call",
    "redis.cmd",
    "broker.redis",
    "broker.round_trip",
    "broker.pool",
    "broker.publish",
    "broker.publish_many",
    "broker.receive",
    "broker.commit",
    "broker.commit_all",
    "broker.reject",
    "broker.release",
    "broker.extend",
    "broker.extend_interval",
    "broker.maintain",
    "broker.retry",
    "broker.reap",
    "broker.queue_size",
    "broker.failed_count",
    "broker.in_flight_ceiling",
    "broker.close",
    "broker.reconnects",
    "background.new",
    "background.run",
    "background.size",
    "background.failed_count",
    "background.retry",
    "background.publish",
    "background.publish_many",
    "memory.new",
    "memory.log",
    "server.configure",
    "server.run",
    "nats.broker",
    "nats.provision",
    "nats.duplicates",
    "nats.reconnect",
    "nats.reports",
    "nats.stream",
    "nats.consumer",
    "nats.message",
    "nats.delete",
    "adapter.consume",
    "adapter.maintain",
];

/// The pinned clock: `time()`, counted pids, counted tokens.
struct Fixed {
    time: i64,
    prefix: String,
    ids: AtomicI64,
    tokens: AtomicI64,
}

impl Clock for Fixed {
    fn time(&self) -> i64 {
        self.time
    }

    fn uniqid(&self) -> String {
        format!("{}{:04}", self.prefix, self.ids.fetch_add(1, Ordering::SeqCst) + 1)
    }

    fn token(&self) -> String {
        hex(format!("{:016}", self.tokens.fetch_add(1, Ordering::SeqCst) + 1).as_bytes())
    }
}

fn hex(b: &[u8]) -> String {
    b.iter().map(|x| format!("{x:02x}")).collect()
}

/// PHP's clock is process-wide (the function shadows read one static), so
/// the Rust one is too: a broker created with `time` pins it, one without
/// releases it.
static CLOCK: Mutex<Option<Arc<Fixed>>> = Mutex::new(None);

/// What every broker reads: the clock currently pinned, or the system's.
struct Shared;

impl Clock for Shared {
    fn time(&self) -> i64 {
        current().map_or_else(|| SystemClock.time(), |c| c.time())
    }

    fn uniqid(&self) -> String {
        current().map_or_else(|| SystemClock.uniqid(), |c| c.uniqid())
    }

    fn token(&self) -> String {
        current().map_or_else(|| SystemClock.token(), |c| c.token())
    }
}

fn current() -> Option<Arc<Fixed>> {
    CLOCK.lock().unwrap_or_else(|e| e.into_inner()).clone()
}

fn clock(a: &Args) -> Result<Arc<dyn Clock>, Fault> {
    // `continue` moves a pinned clock to another time, keeping its counters:
    // those of the clock already pinned under the prefix, else the current one's.
    let prefix = a.opt_str("prefix")?.unwrap_or("pid");
    let previous = current().filter(|_| a.opt_bool("continue").ok().flatten().unwrap_or(false)).map(|current| {
        PINNED
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .as_ref()
            .and_then(|p| p.get(prefix).cloned())
            .unwrap_or(current)
    });
    let fixed = a.opt_i64("time")?.map(|time| {
        Arc::new(Fixed {
            time,
            prefix: prefix.to_owned(),
            ids: AtomicI64::new(previous.as_ref().map_or(0, |p| p.ids.load(Ordering::SeqCst))),
            tokens: AtomicI64::new(previous.as_ref().map_or(0, |p| p.tokens.load(Ordering::SeqCst))),
        })
    });
    if let Some(fixed) = &fixed {
        PINNED
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .get_or_insert_with(Default::default)
            .insert(fixed.prefix.clone(), fixed.clone());
    }
    *CLOCK.lock().unwrap_or_else(|e| e.into_inner()) = fixed;
    Ok(Arc::new(Shared))
}

/// Every clock pinned in this process, by prefix, as `Clock.php` keeps them.
static PINNED: Mutex<Option<std::collections::HashMap<String, Arc<Fixed>>>> = Mutex::new(None);

/// The namespace of the case the pinned clocks belong to.
static CASE: Mutex<String> = Mutex::new(String::new());

/// Forgets the clocks pinned by an earlier case.
fn scope(ns: &str) {
    let mut case = CASE.lock().unwrap_or_else(|e| e.into_inner());
    if *case != ns {
        ns.clone_into(&mut case);
        *PINNED.lock().unwrap_or_else(|e| e.into_inner()) = None;
    }
}

/// Switches to the clock pinned under `prefix`, if there is one: an interop
/// case's two sides each keep their own, in two processes or (recording) one.
fn activate(prefix: &str) {
    let pinned = PINNED.lock().unwrap_or_else(|e| e.into_inner()).as_ref().and_then(|p| p.get(prefix).cloned());
    if let Some(fixed) = pinned {
        *CLOCK.lock().unwrap_or_else(|e| e.into_inner()) = Some(fixed);
    }
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn fault(e: impl std::fmt::Display) -> Fault {
    Fault::new(e.to_string())
}

fn queue(v: &Json) -> Result<Result<Queue, Error>, Fault> {
    let a = Args(v);
    Ok(Queue::with(
        a.str("name")?,
        a.opt_str("namespace")?.unwrap_or("utopia-queue"),
        a.opt_i64("job_ttl")?.unwrap_or(0),
    ))
}

macro_rules! q {
    ($a:expr) => {
        match queue($a.value("queue")?)? {
            Ok(q) => q,
            Err(e) => return Ok(err(e)),
        }
    };
}

fn codec(name: &str) -> Result<Arc<dyn Codec>, Fault> {
    Ok(match name {
        "json" => Arc::new(JsonCodec),
        "igbinary" => Arc::new(Igbinary),
        "compat" => Arc::new(Compat::new(JsonCodec)),
        "compat-igbinary" => Arc::new(Compat::new(Igbinary)),
        other => return Err(Fault::new(format!("unknown codec {other}"))),
    })
}

fn host_port(url: &str) -> (String, u16) {
    let rest = url.split("://").nth(1).unwrap_or(url);
    let rest = rest.rsplit('@').next().unwrap_or(rest);
    let rest = rest.split('/').next().unwrap_or(rest);
    match rest.rsplit_once(':') {
        Some((h, p)) => (h.to_owned(), p.parse().unwrap_or(6379)),
        None => (rest.to_owned(), 6379),
    }
}

fn redis_connection(session: &Session) -> Result<RedisConnection, Fault> {
    let (host, port) = host_port(session.service("redis")?);
    Ok(RedisConnection::new(host, port))
}

fn connection(a: &Args, session: &Session) -> Result<Result<Arc<dyn Connection>, Error>, Fault> {
    let (host, port) = host_port(session.service(a.opt_str("service")?.unwrap_or("redis"))?);
    let text = |k: &str| a.opt_str(k).map(|v| v.map(str::to_owned));
    Ok(Ok(match a.str("kind")? {
        "redis" => Arc::new(RedisConnection::with(
            a.opt_str("host")?.map_or(host, str::to_owned),
            a.opt_i64("port")?.map_or(port, |p| p as u16),
            text("user")?,
            text("password")?,
            -1.0,
            -1.0,
        )),
        "redis-dsn" => Arc::new(RedisConnection::from_dsn(
            &Dsn::parse(&a.str("dsn")?.replace("{host}", &format!("{host}:{port}"))).map_err(fault)?,
            -1.0,
            -1.0,
        )),
        "locking" => Arc::new(Locking::new(RedisConnection::new(host, port))),
        "cluster" => Arc::new(RedisCluster::new(
            match a.opt("seeds").and_then(Json::as_array) {
                Some(seeds) => seeds.iter().filter_map(|s| s.as_str().map(str::to_owned)).collect(),
                None => session
                    .service("queue-redis-cluster")?
                    .trim_start_matches("redis-cluster://")
                    .split(',')
                    .map(str::to_owned)
                    .collect(),
            },
            -1.0,
            -1.0,
            text("user")?,
            text("password")?,
        )),
        "cluster-dsn" => match RedisCluster::from_dsn(
            &Dsn::parse(&a.str("dsn")?.replace(
                "{cluster}",
                &session.service("queue-redis-cluster")?.trim_start_matches("redis-cluster://").replace(',', ";"),
            ))
            .map_err(fault)?,
            -1.0,
            -1.0,
        ) {
            Ok(c) => Arc::new(c),
            Err(e) => return Ok(Err(e)),
        },
        other => return Err(Fault::new(format!("unknown connection {other}"))),
    }))
}

/// A Redis reply as phpredis returns it.
fn reply(r: Reply) -> Value {
    match r {
        Reply::Nil => Value::Bool(false),
        Reply::Int(i) => Value::Int(i),
        Reply::BulkString(b) => Value::Str(b.into()),
        Reply::SimpleString(s) => Value::Str(s.into()),
        Reply::Okay => Value::Bool(true),
        Reply::Array(items) | Reply::Set(items) => Value::Array(items.into_iter().map(reply).collect()),
        _ => Value::Bool(false),
    }
}

fn opt_bytes(b: Option<Vec<u8>>) -> Value {
    b.map_or(Value::Bool(false), |b| Value::Str(b.into()))
}

fn arg_bytes(v: &Json) -> Result<Vec<u8>, Fault> {
    match decode(v)? {
        Value::Str(s) => Ok(s.as_bytes().to_vec()),
        Value::Int(i) => Ok(i.to_string().into_bytes()),
        other => Err(Fault::new(format!("expected a string, got {other:?}"))),
    }
}

fn arg_int(v: &Json) -> Result<i64, Fault> {
    v.as_i64().ok_or_else(|| Fault::new("expected an integer"))
}

async fn call_connection(c: &dyn Connection, method: &str, args: &[Json]) -> Result<Result<Value, Error>, Fault> {
    let s = |i: usize| -> Result<Vec<u8>, Fault> { arg_bytes(args.get(i).ok_or_else(|| Fault::new("missing arg"))?) };
    let n = |i: usize| -> Result<i64, Fault> { arg_int(args.get(i).ok_or_else(|| Fault::new("missing arg"))?) };
    let n_or = |i: usize, d: i64| -> Result<i64, Fault> { args.get(i).map_or(Ok(d), arg_int) };
    let list = |i: usize| -> Result<Vec<Vec<u8>>, Fault> {
        args.get(i)
            .and_then(Json::as_array)
            .ok_or_else(|| Fault::new("expected a list"))?
            .iter()
            .map(arg_bytes)
            .collect()
    };
    let array =
        |i: usize| -> Result<Array, Fault> { decode_array(args.get(i).ok_or_else(|| Fault::new("missing arg"))?) };
    let r: Result<Value, Error> = match method {
        "execute" => {
            let (script, keys, argv) = (s(0)?, list(1)?, list(2)?);
            c.execute(&String::from_utf8_lossy(&script), &keys, &argv).await.map(reply)
        }
        "rightPushArray" => c.right_push_array(&s(0)?, &array(1)?).await.map(Value::Bool),
        "leftPushArray" => c.left_push_array(&s(0)?, &array(1)?).await.map(Value::Bool),
        "rightPushMany" => c.right_push_many(&s(0)?, &list(1)?).await.map(Value::Bool),
        "leftPushMany" => c.left_push_many(&s(0)?, &list(1)?).await.map(Value::Bool),
        "rightPopArray" => c.right_pop_array(&s(0)?, n(1)?).await.map(|v| v.unwrap_or(Value::Bool(false))),
        "leftPopArray" => c.left_pop_array(&s(0)?, n(1)?).await.map(|v| v.unwrap_or(Value::Bool(false))),
        "rightPopLeftPushArray" => {
            c.right_pop_left_push_array(&s(0)?, &s(1)?, n(2)?).await.map(|v| v.unwrap_or(Value::Bool(false)))
        }
        "rightPush" => c.right_push(&s(0)?, &s(1)?).await.map(Value::Bool),
        "leftPush" => c.left_push(&s(0)?, &s(1)?).await.map(Value::Bool),
        "rightPop" => c.right_pop(&s(0)?, n(1)?).await.map(opt_bytes),
        "leftPop" => c.left_pop(&s(0)?, n(1)?).await.map(opt_bytes),
        "rightPopMany" => c
            .right_pop_many(&s(0)?, n(1)?, n(2)?)
            .await
            .map(|v| Value::Array(v.into_iter().map(|b| Value::Str(b.into())).collect())),
        "rightPopLeftPush" => c.right_pop_left_push(&s(0)?, &s(1)?, n(2)?).await.map(opt_bytes),
        "listRemove" => c.list_remove(&s(0)?, &s(1)?).await.map(Value::Bool),
        "listSize" => c.list_size(&s(0)?).await.map(Value::Int),
        "listRange" => c
            .list_range(&s(0)?, n(1)?, n(2)?)
            .await
            .map(|v| Value::Array(v.into_iter().map(|b| Value::Str(b.into())).collect())),
        "remove" => c.remove(&s(0)?).await.map(Value::Bool),
        "set" => c.set(&s(0)?, &s(1)?, n_or(2, 0)?).await.map(Value::Bool),
        "setNotExists" => c.set_not_exists(&s(0)?, &s(1)?, n_or(2, 0)?).await.map(Value::Bool),
        "get" => c.get(&s(0)?).await.map(|v| v.map_or(Value::Null, |b| Value::Str(b.into()))),
        "setArray" => c.set_array(&s(0)?, &array(1)?, n_or(2, 0)?).await.map(Value::Bool),
        "increment" => c.increment(&s(0)?).await.map(Value::Int),
        "incrementBy" => c.increment_by(&s(0)?, n(1)?).await.map(Value::Int),
        "decrement" => c.decrement(&s(0)?).await.map(Value::Int),
        "ping" => Ok(Value::Bool(c.ping().await)),
        "close" => {
            c.close().await;
            Ok(Value::Null)
        }
        other => return Err(Fault::new(format!("unknown connection method {other}"))),
    };
    Ok(r)
}

/// A broker any operation may be given.
#[derive(Clone)]
enum Broker {
    Redis(Arc<Redis>),
    Pool(Arc<Pool<Redis>>),
    Memory(Arc<Memory>),
    Nats(Arc<Nats>),
}

impl Broker {
    fn consumer(&self) -> Arc<dyn Consumer> {
        match self {
            Broker::Redis(b) => b.clone(),
            Broker::Pool(b) => b.clone(),
            Broker::Memory(b) => b.clone(),
            Broker::Nats(b) => b.clone(),
        }
    }

    fn publisher(&self) -> Result<Arc<dyn Publisher>, Fault> {
        match self {
            Broker::Redis(b) => Ok(b.clone()),
            Broker::Pool(b) => Ok(b.clone()),
            Broker::Nats(b) => Ok(b.clone()),
            Broker::Memory(_) => Err(Fault::new("a memory consumer does not publish")),
        }
    }
}

fn delivered(messages: Vec<Message>, session: &mut Session) -> Json {
    let mut handles = Vec::new();
    let mut arrays = Vec::new();
    for m in messages {
        let mut a = m.to_array();
        a.set("receipt".into(), m.receipt().map_or(Value::Null, Value::from));
        a.set("size".into(), m.size().map_or(Value::Null, Value::Int));
        arrays.push(dump(&Value::Array(a)));
        handles.push(session.handle(m));
    }
    json!({"handles": handles, "messages": arrays})
}

fn message(a: &Args, session: &Session) -> Result<Message, Fault> {
    let m = session.get::<Message>(a.value("message")?)?.clone();
    if a.opt_bool("terminal")?.unwrap_or(false) {
        m.terminal(true);
    }
    Ok(m)
}

fn messages(a: &Args, session: &Session) -> Result<Vec<Message>, Fault> {
    a.array("messages")?.iter().map(|h| session.get::<Message>(h).cloned()).collect()
}

fn unit(r: Result<(), Error>) -> Outcome {
    match r {
        Ok(()) => Outcome::Ok(Json::Null),
        Err(e) => err(e),
    }
}

fn val<T: Into<Json>>(r: Result<T, Error>) -> Outcome {
    match r {
        Ok(v) => Outcome::Ok(v.into()),
        Err(e) => err(e),
    }
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    scope(&session.ns);
    if let Some(prefix) = a.opt_str("clock")? {
        activate(prefix);
    }
    let broker = |s: &Session| -> Result<Broker, Fault> { s.get::<Broker>(a.value("broker")?).cloned() };
    Ok(match op {
        "clock.set" => {
            let mut with = json!({"continue": true});
            if let (Some(target), Some(source)) = (with.as_object_mut(), args.as_object()) {
                target.extend(source.clone());
            }
            clock(&Args(&with))?;
            Outcome::Ok(Json::Null)
        }
        "queue.new" => match queue(args)? {
            Ok(q) => Outcome::ok(json!([q.name(), q.namespace(), q.job_ttl()])),
            Err(e) => err(e),
        },
        "message.new" => match Message::from_envelope(&decode_array(a.value("envelope")?)?) {
            Ok(m) => Outcome::Ok(dump(&Value::Array(m.to_array()))),
            Err(e) => err(e),
        },
        "message.set" => {
            let mut m = match Message::from_envelope(&decode_array(a.value("envelope")?)?) {
                Ok(m) => m,
                Err(e) => return Ok(err(e)),
            };
            if let Some(v) = a.opt("pid") {
                m.set_pid(arg_bytes(v)?);
            }
            if let Some(v) = a.opt("queue") {
                m.set_queue(arg_bytes(v)?);
            }
            if let Some(v) = a.opt_i64("timestamp")? {
                m.set_timestamp(v);
            }
            if let Some(v) = a.opt("payload") {
                m.set_payload(decode_array(v)?);
            }
            if let Some(v) = a.opt_i64("attempts")? {
                m.set_attempts(v);
            }
            if a.0.get("sequence").is_some() {
                m.set_sequence(a.opt_i64("sequence")?);
            }
            if let Some(v) = a.opt_str("receipt")? {
                m.set_receipt(v);
            }
            if let Some(v) = a.opt_bool("terminal")? {
                m.terminal(v);
            }
            if a.0.get("size").is_some() {
                m.set_size(a.opt_i64("size")?);
            }
            let mut out = Array::new();
            out.set("pid".into(), Value::Str(m.pid().clone()));
            out.set("queue".into(), Value::Str(m.queue().clone()));
            out.set("timestamp".into(), Value::Int(m.timestamp()));
            out.set("payload".into(), Value::Array(m.payload().clone()));
            out.set("attempts".into(), Value::Int(m.attempts()));
            out.set("sequence".into(), m.sequence().map_or(Value::Null, Value::Int));
            out.set("receipt".into(), m.receipt().map_or(Value::Null, Value::from));
            out.set("terminal".into(), Value::Bool(m.is_terminal()));
            out.set("size".into(), m.size().map_or(Value::Null, Value::Int));
            out.set("array".into(), Value::Array(m.to_array()));
            Outcome::Ok(dump(&Value::Array(out)))
        }

        "message.warn_if_large" => {
            let (queue, size) = (a.str("queue")?, a.i64("size")?);
            Message::warn_if_large(queue, size);
            Outcome::ok(Message::large_warning(queue, size))
        }

        "codec.encode" => match codec(a.str("codec")?)?.encode(&decode(a.value("value")?)?) {
            Ok(b) => Outcome::Ok(bytes_value(&b)),
            Err(e) => err(e),
        },
        "codec.decode" => match codec(a.str("codec")?)?.decode(&a.bytes("bytes")?) {
            Ok(v) => Outcome::Ok(dump(&v)),
            Err(e) => err(e),
        },
        "codec.content_type" => Outcome::ok(codec(a.str("codec")?)?.content_type()),
        "codec.round_trip" => {
            let c = codec(a.str("codec")?)?;
            match c.encode(&decode(a.value("value")?)?).and_then(|b| c.decode(&b).map(|v| (b, v))) {
                Ok((b, v)) => Outcome::ok(json!({"bytes": bytes_value(&b), "value": dump(&v)})),
                Err(e) => err(e),
            }
        }

        "connection.new" => match connection(&a, session)? {
            Ok(c) => Outcome::Ok(session.handle(c)),
            Err(e) => err(e),
        },
        "connection.call" => {
            let c = session.get::<Arc<dyn Connection>>(a.value("connection")?)?.clone();
            let empty = Vec::new();
            let list = a.opt("args").and_then(Json::as_array).unwrap_or(&empty);
            match call_connection(c.as_ref(), a.str("method")?, list).await? {
                Ok(v) => Outcome::Ok(dump(&v)),
                Err(e) => err(e),
            }
        }

        "redis.cmd" => {
            let (host, port) = host_port(session.service(a.opt_str("service")?.unwrap_or("redis"))?);
            let conn = RedisConnection::with(host, port, None, a.opt_str("auth")?.map(str::to_owned), -1.0, -1.0);
            let list = a.array("args")?;
            let mut cmd = redis::cmd(list.first().and_then(Json::as_str).ok_or_else(|| Fault::new("empty command"))?);
            for v in &list[1..] {
                cmd.arg(arg_bytes(v)?);
            }
            match conn.query(cmd, false).await {
                Ok(Some(Reply::Nil)) | Ok(None) => Outcome::Ok(Json::Null),
                Ok(Some(r)) => Outcome::Ok(dump(&reply(r))),
                Err(e) => err(e),
            }
        }

        "broker.redis" => {
            let clock = clock(&a)?;
            let receive: Arc<dyn Connection> = match a.opt("receive") {
                Some(h) => session.get::<Arc<dyn Connection>>(h)?.clone(),
                None => Arc::new(redis_connection(session)?),
            };
            let commands: Arc<dyn Connection> = match a.opt("commands") {
                Some(h) => session.get::<Arc<dyn Connection>>(h)?.clone(),
                None => Arc::new(Locking::new(redis_connection(session)?)),
            };
            let broker = Redis::new(receive, commands)
                .with_codec(codec(a.opt_str("codec")?.unwrap_or("json"))?)
                .with_reap_after(a.opt_i64("reap_after")?.unwrap_or(90_000))
                .with_clock(clock);
            Outcome::Ok(session.handle(Broker::Redis(Arc::new(broker))))
        }
        "broker.round_trip" => {
            let fixed = json!({"time": 1_700_000_000, "prefix": "fz"});
            let clock = clock(&Args(&fixed))?;
            let connection = Arc::new(redis_connection(session)?);
            let broker = Redis::new(connection.clone(), Arc::new(Locking::new(redis_connection(session)?)))
                .with_codec(codec(a.opt_str("codec")?.unwrap_or("json"))?)
                .with_clock(clock);
            let q = Queue::with("fuzz", session.ns.clone(), 0).map_err(fault)?;
            if let Err(e) = broker.publish(&q, decode_array(a.value("payload")?)?).await {
                return Ok(err(e));
            }
            let raw = match connection.list_range(q.key("queue").as_bytes(), 1, 0).await {
                Ok(mut items) if !items.is_empty() => bytes_value(&items.remove(0)),
                Ok(_) => Json::Null,
                Err(e) => return Ok(err(e)),
            };
            let received = match broker.receive(&q, 0, 1).await {
                Ok(m) => m.into_iter().next(),
                Err(e) => return Ok(err(e)),
            };
            if let Some(m) = &received
                && let Err(e) = broker.commit(&q, m).await
            {
                return Ok(err(e));
            }
            Outcome::ok(json!({"bytes": raw, "payload": received.map(|m| dump(&Value::Array(m.payload().clone())))}))
        }
        "broker.pool" => {
            let clock = clock(&a)?;
            let (host, port) = host_port(session.service("redis")?);
            let codec = codec(a.opt_str("codec")?.unwrap_or("json"))?;
            let reap_after = a.opt_i64("reap_after")?.unwrap_or(90_000);
            let resources = Arc::new(PoolResources::new(a.opt_i64("size")?.unwrap_or(1) as usize, move || {
                Redis::new(
                    Arc::new(RedisConnection::new(host.clone(), port)),
                    Arc::new(Locking::new(RedisConnection::new(host.clone(), port))),
                )
                .with_codec(codec.clone())
                .with_reap_after(reap_after)
                .with_clock(clock.clone())
            }));
            let publisher = a.opt_bool("publisher")?.unwrap_or(true).then(|| resources.clone());
            let consumer = a.opt_bool("consumer")?.unwrap_or(true).then(|| resources.clone());
            Outcome::Ok(session.handle(Broker::Pool(Arc::new(Pool::new(publisher, consumer)))))
        }
        "broker.publish" => {
            let q = q!(a);
            val(broker(session)?.publisher()?.publish(&q, decode_array(a.value("payload")?)?).await)
        }
        "broker.publish_many" => {
            let q = q!(a);
            let payloads = a.array("payloads")?.iter().map(decode_array).collect::<Result<Vec<_>, _>>()?;
            val(broker(session)?.publisher()?.publish_many(&q, payloads).await)
        }
        "broker.receive" => {
            let q = q!(a);
            let c = broker(session)?.consumer();
            match c.receive(&q, a.opt_i64("timeout")?.unwrap_or(0), a.opt_i64("n")?.unwrap_or(1)).await {
                Ok(m) => Outcome::Ok(delivered(m, session)),
                Err(e) => err(e),
            }
        }
        "broker.commit" => {
            let q = q!(a);
            let m = message(&a, session)?;
            unit(broker(session)?.consumer().commit(&q, &m).await)
        }
        "broker.commit_all" => {
            let q = q!(a);
            let m = messages(&a, session)?;
            let c = broker(session)?.consumer();
            let results = futures_util::future::join_all(m.iter().map(|m| c.commit(&q, m))).await;
            Outcome::ok(
                results
                    .into_iter()
                    .map(|r| match r {
                        Ok(()) => json!(true),
                        Err(e) => error_json(&e),
                    })
                    .collect::<Vec<_>>(),
            )
        }
        "broker.reject" => {
            let q = q!(a);
            let m = message(&a, session)?;
            unit(broker(session)?.consumer().reject(&q, &m).await)
        }
        "broker.release" => {
            let q = q!(a);
            let m = messages(&a, session)?;
            let c = broker(session)?.consumer();
            match c.release(&q, &m) {
                Some(f) => unit(f.await),
                None => return Err(Fault::new("the consumer cannot release")),
            }
        }
        "broker.extend" => {
            let q = q!(a);
            let m = messages(&a, session)?;
            unit(broker(session)?.consumer().extend(&q, &m).await)
        }
        "broker.extend_interval" => Outcome::ok(broker(session)?.consumer().extend_interval().await),
        "broker.maintain" => match broker(session)?.consumer().maintain() {
            Some(f) => unit(f.await),
            None => return Err(Fault::new("the consumer has no upkeep")),
        },
        "broker.retry" => {
            let q = q!(a);
            let (limit, max, newer) = (a.opt_i64("limit")?, a.opt_i64("max_attempts")?, a.opt_i64("newer_than")?);
            match broker(session)? {
                Broker::Redis(b) => unit(b.retry_with(&q, limit, max, newer).await),
                Broker::Pool(b) => unit(b.retry_with(&q, limit, max, newer).await),
                Broker::Memory(_) => return Err(Fault::new("a memory consumer cannot retry")),
                Broker::Nats(b) => unit(b.retry_dead(&q, limit).await),
            }
        }
        "broker.reap" => {
            let q = q!(a);
            let older = a.opt_i64("older_than")?.unwrap_or(90_000);
            let (limit, max, newer) = (a.opt_i64("limit")?, a.opt_i64("max_attempts")?, a.opt_i64("newer_than")?);
            match broker(session)? {
                Broker::Redis(b) => val(b.reap(&q, older, limit, max, newer, a.opt_i64("scan")?).await),
                Broker::Pool(b) => val(b.reap(&q, older, limit, max, newer).await),
                Broker::Memory(_) => return Err(Fault::new("a memory consumer cannot reap")),
                Broker::Nats(b) => Outcome::ok(b.reap()),
            }
        }
        "broker.queue_size" => {
            let q = q!(a);
            val(broker(session)?.publisher()?.queue_size(&q, a.opt_bool("failed")?.unwrap_or(false)).await)
        }
        "broker.failed_count" => {
            let q = q!(a);
            val(broker(session)?.publisher()?.failed_count(&q).await)
        }
        "broker.in_flight_ceiling" => Outcome::ok(broker(session)?.consumer().in_flight_ceiling().await),
        "broker.close" => {
            broker(session)?.consumer().close().await;
            Outcome::Ok(Json::Null)
        }
        "broker.reconnects" => {
            let clock = clock(&a)?;
            let q = q!(a);
            let events = Arc::new(Mutex::new(Vec::<Json>::new()));
            let (e1, e2) = (events.clone(), events.clone());
            let dead: Arc<dyn Connection> = Arc::new(RedisConnection::new("localhost", 1));
            let b = Redis::new(dead.clone(), dead)
                .with_clock(clock)
                .on_reconnect(Box::new(move |q: &Queue, e: &Error, attempt, sleep| {
                    if let Ok(mut v) = e1.lock() {
                        v.push(json!([q.name(), e.php_class(), attempt, sleep >= 0]));
                    }
                }))
                .on_reconnect_success(Box::new(move |q: &Queue, attempts| {
                    if let Ok(mut v) = e2.lock() {
                        v.push(json!([q.name(), "success", attempts]));
                    }
                }));
            let mut received = Vec::new();
            for _ in 0..a.opt_i64("times")?.unwrap_or(1) {
                match b.receive(&q, 0, 1).await {
                    Ok(m) => received.push(m.len()),
                    Err(e) => return Ok(err(e)),
                }
            }
            let events = events.lock().map(|v| v.clone()).unwrap_or_default();
            Outcome::ok(json!({"received": received, "events": events}))
        }

        "background.new" => {
            let publisher = match broker(session)? {
                Broker::Redis(b) => b,
                _ => return Err(Fault::new("background wraps a Redis broker here")),
            };
            let batch = match (a.opt_f64("batch_interval")?, a.opt_i64("batch_size")?) {
                (None, None) => None,
                (Some(i), Some(s)) => Some((i, s.max(0) as usize)),
                _ => {
                    return Ok(Outcome::err(
                        "InvalidArgumentException",
                        "maxBatchInterval and maxBatchSize must be configured together.",
                    ));
                }
            };
            match Background::new(
                publisher,
                a.opt_i64("capacity")?.unwrap_or(512).max(0) as usize,
                a.opt_i64("coroutines")?.unwrap_or(1).max(0) as usize,
                a.opt_f64("timeout")?.unwrap_or(-1.0),
                batch,
            ) {
                Ok(b) => Outcome::Ok(session.handle(Arc::new(b))),
                Err(e) => err(e),
            }
        }
        "background.run" => {
            let b = session.get::<Arc<Background<Redis>>>(a.value("background")?)?.clone();
            let q = q!(a);
            if a.opt_bool("start")?.unwrap_or(true)
                && let Err(e) = b.start()
            {
                return Ok(err(e));
            }
            for p in a.array("payloads")? {
                if let Err(e) = b.enqueue(&q, decode_array(p)?).await {
                    b.shutdown().await;
                    return Ok(err(e));
                }
            }
            b.shutdown().await;
            Outcome::Ok(Json::Null)
        }
        "background.size" => {
            let b = session.get::<Arc<Background<Redis>>>(a.value("background")?)?.clone();
            let q = q!(a);
            val(b.queue_size(&q, a.opt_bool("failed")?.unwrap_or(false)).await)
        }
        "background.failed_count" => {
            let b = session.get::<Arc<Background<Redis>>>(a.value("background")?)?.clone();
            let q = q!(a);
            val(b.failed_count(&q).await)
        }
        "background.retry" => {
            let b = session.get::<Arc<Background<Redis>>>(a.value("background")?)?.clone();
            let q = q!(a);
            unit(Publisher::retry(b.as_ref(), &q, a.opt_i64("limit")?).await)
        }
        "background.publish" => {
            let b = session.get::<Arc<Background<Redis>>>(a.value("background")?)?.clone();
            let q = q!(a);
            val(Publisher::publish(b.as_ref(), &q, decode_array(a.value("payload")?)?).await)
        }
        "background.publish_many" => {
            let b = session.get::<Arc<Background<Redis>>>(a.value("background")?)?.clone();
            let q = q!(a);
            let payloads = a.array("payloads")?.iter().map(decode_array).collect::<Result<Vec<_>, _>>()?;
            val(b.publish_many(&q, payloads).await)
        }

        "memory.new" => {
            let m = Memory::make(&a)?;
            Outcome::Ok(session.handle(Broker::Memory(Arc::new(m))))
        }
        "memory.log" => match session.get::<Broker>(a.value("consumer")?)? {
            Broker::Memory(m) => Outcome::ok(m.log.lock().map(|l| l.clone()).unwrap_or_default()),
            _ => return Err(Fault::new("not a memory consumer")),
        },

        "server.configure" => configure(&a, session).await?,
        "server.run" => run(&a, session).await?,
        "nats.broker" => nats_broker(&a, session).await?,
        "nats.provision" => {
            let q = q!(a);
            match broker(session)? {
                Broker::Nats(b) => unit(b.provision(&q).await),
                _ => return Err(Fault::new("not a NATS broker")),
            }
        }
        "nats.duplicates" => match broker(session)? {
            Broker::Nats(b) => Outcome::ok(b.duplicates()),
            _ => return Err(Fault::new("not a NATS broker")),
        },
        "nats.reconnect" => match broker(session)? {
            Broker::Nats(b) => Outcome::ok(b.reconnect().await),
            _ => return Err(Fault::new("not a NATS broker")),
        },
        "nats.reports" => match broker(session)? {
            Broker::Nats(b) => {
                let id = Arc::as_ptr(&b) as usize;
                Outcome::ok(reports_of(id))
            }
            _ => return Err(Fault::new("not a NATS broker")),
        },
        "nats.stream" => nats_stream(&a, session).await?,
        "nats.consumer" => nats_consumer(&a, session).await?,
        "nats.message" => nats_message(&a, session).await?,
        "nats.delete" => {
            let js = async_nats::jetstream::new(nats_client(session).await?);
            for s in a.array("queues")? {
                if let Some(queue) = s.as_str() {
                    let _ = js.delete_stream(stream_of(queue, false)).await;
                    let _ = js.delete_stream(stream_of(queue, true)).await;
                }
            }
            Outcome::Ok(Json::Null)
        }
        "adapter.consume" => consume(&a, session).await?,
        "adapter.maintain" => {
            let reports = Arc::new(Mutex::new(Vec::<Json>::new()));
            let fail = a.opt_bool("fail_report")?.unwrap_or(false);
            let report = a.opt_bool("report")?.unwrap_or(true);
            for h in a.array("consumers")? {
                let consumer = session.get::<Broker>(h)?.consumer();
                let adapter = Adapter::new(consumer, 1, Runtime::Drain);
                let sink = reports.clone();
                let callback: utopia_queue::adapter::ErrorCallback = Arc::new(move |m, e, _| {
                    let sink = sink.clone();
                    Box::pin(async move {
                        if let Ok(mut r) = sink.lock() {
                            let pid = m.map(|m| String::from_utf8_lossy(m.pid().as_bytes()).into_owned());
                            r.push(json!([pid, e.to_string()]));
                        }
                        if fail { Err(Error::Runtime("report failed".into())) } else { Ok(()) }
                    })
                });
                adapter.maintain(report.then_some(&callback)).await;
            }
            let reports = reports.lock().map(|r| r.clone()).unwrap_or_default();
            Outcome::ok(reports)
        }
        _ => return Err(Fault::new(format!("queue: unknown operation `{op}`"))),
    })
}

/// The memory consumer fixture (`tests/compat/queue/Memory.php`).
struct Memory {
    log: Mutex<Vec<String>>,
    pending: Mutex<Vec<Message>>,
    fail: Mutex<Vec<String>>,
    exclusive: bool,
    ceiling: Option<i64>,
    maintain: Option<String>,
}

impl Memory {
    fn make(a: &Args) -> Result<Self, Fault> {
        let mut pending = Vec::new();
        if let Some(list) = a.opt("messages").and_then(Json::as_array) {
            for envelope in list {
                pending.push(Message::from_envelope(&decode_array(envelope)?).map_err(fault)?);
            }
        }
        let fail = a
            .opt("fail")
            .and_then(Json::as_array)
            .map(|l| l.iter().filter_map(|v| v.as_str().map(str::to_owned)).collect())
            .unwrap_or_default();
        let exclusive = a.opt_bool("exclusive")?.unwrap_or(false);
        let ceiling = if exclusive { None } else { a.opt_i64("ceiling")? };
        let maintain = if exclusive || ceiling.is_some() { None } else { a.opt_str("maintain")?.map(str::to_owned) };
        Ok(Self {
            log: Mutex::new(Vec::new()),
            pending: Mutex::new(pending),
            fail: Mutex::new(fail),
            exclusive,
            ceiling,
            maintain,
        })
    }

    fn record(&self, line: String) {
        if let Ok(mut l) = self.log.lock() {
            l.push(line);
        }
    }

    fn fails(&self, what: &str) -> bool {
        self.fail.lock().map(|f| f.iter().any(|x| x == what)).unwrap_or(false)
    }
}

fn pid(m: &Message) -> String {
    String::from_utf8_lossy(m.pid().as_bytes()).into_owned()
}

impl Consumer for Memory {
    fn receive<'a>(&'a self, _: &'a Queue, _: i64, n: i64) -> BoxFuture<'a, Result<Vec<Message>, Error>> {
        Box::pin(async move {
            if self.fails("receive") {
                if let Ok(mut f) = self.fail.lock()
                    && let Some(i) = f.iter().position(|x| x == "receive")
                {
                    f.remove(i);
                }
                self.record("receive:failed".into());
                return Err(Error::Runtime("broker unreachable".into()));
            }
            let batch: Vec<Message> = {
                let mut p = self.pending.lock().unwrap_or_else(|e| e.into_inner());
                let take = (n.max(1) as usize).min(p.len());
                p.drain(..take).collect()
            };
            if batch.is_empty() {
                // As MemoryConsumer: an empty receive yields, like a broker wait.
                tokio::time::sleep(std::time::Duration::from_millis(1)).await;
                return Ok(batch);
            }
            self.record(format!("receive:{}", batch.len()));
            Ok(batch)
        })
    }

    fn commit<'a>(&'a self, _: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            self.record(format!("commit:{}", pid(message)));
            if self.fails("commit") { Err(Error::Runtime("commit failed".into())) } else { Ok(()) }
        })
    }

    fn reject<'a>(&'a self, _: &'a Queue, message: &'a Message) -> BoxFuture<'a, Result<(), Error>> {
        Box::pin(async move {
            let verdict = if message.is_terminal() { "terminal" } else { "retry" };
            self.record(format!("reject:{}:{verdict}", pid(message)));
            if self.fails("reject") { Err(Error::Runtime("reject failed".into())) } else { Ok(()) }
        })
    }

    fn close(&self) -> BoxFuture<'_, ()> {
        Box::pin(async move { self.record("close".into()) })
    }

    fn maintain(&self) -> Option<BoxFuture<'_, Result<(), Error>>> {
        let mode = self.maintain.clone()?;
        Some(Box::pin(async move {
            self.record("maintain".into());
            if mode == "fail" { Err(Error::Runtime("pool is unreachable".into())) } else { Ok(()) }
        }))
    }

    fn in_flight_ceiling(&self) -> BoxFuture<'_, Option<i64>> {
        let c = self.ceiling;
        Box::pin(async move { c })
    }

    fn is_exclusive(&self) -> bool {
        self.exclusive
    }

    fn name(&self) -> &str {
        if self.exclusive {
            "Tests\\Compat\\Queue\\MemoryExclusive"
        } else if self.ceiling.is_some() {
            "Tests\\Compat\\Queue\\MemoryBounded"
        } else if self.maintain.is_some() {
            "Tests\\Compat\\Queue\\MemoryMaintained"
        } else {
            "Tests\\Compat\\Queue\\Memory"
        }
    }
}

fn error_json(e: &Error) -> Json {
    json!({"$error": {"class": e.php_class(), "message": e.to_string()}})
}

fn adapter(a: &Args, session: &Session) -> Result<Adapter, Fault> {
    let consumer = match a.opt("consumer") {
        Some(h) => session.get::<Broker>(h)?.consumer(),
        None => Arc::new(Memory::make(&Args(&json!({})))?),
    };
    let namespace = a.opt_str("namespace")?.unwrap_or("utopia-queue").to_owned();
    Ok(if a.opt_bool("shared")?.unwrap_or(true) {
        Adapter::new(consumer, 1, Runtime::Drain)
            .with_namespace(namespace)
            .with_receive_timeout(i64::from(a.opt_bool("slow")?.unwrap_or(false)))
    } else {
        let factory: ConsumerFactory = Arc::new(move |_: &str| consumer.clone());
        Adapter::with_factory(factory, 1, Runtime::Drain)
            .with_namespace(namespace)
            .with_receive_timeout(i64::from(a.opt_bool("slow")?.unwrap_or(false)))
    })
}

async fn configure(a: &Args<'_>, session: &mut Session) -> Result<Outcome, Fault> {
    let mut server = Server::new(adapter(a, session)?);
    let mut jobs = Vec::new();
    for job in a.opt("jobs").and_then(Json::as_array).cloned().unwrap_or_default() {
        let j = Args(&job);
        let name = j.str("queue")?;
        let coroutines = j.opt_i64("coroutines")?.unwrap_or(1).max(0) as usize;
        let prefetch = j.opt_i64("prefetch")?.map(|p| p.max(0) as usize);
        match server.job(name, coroutines, prefetch) {
            Ok(_) => jobs.push(json!([name, server.coroutines(name), server.prefetch(name)])),
            Err(e) => jobs.push(error_json(&e)),
        }
    }
    let mut out = json!({
        "jobs": jobs,
        "registered": server.jobs(),
        "unregistered": [server.coroutines("none"), server.prefetch("none")],
    });
    if a.opt_bool("start")?.unwrap_or(false) {
        let server = Arc::new(server);
        out["start"] = match server.start().await {
            Ok(()) => json!("ok"),
            Err(e) => error_json(&e),
        };
    }
    Ok(Outcome::Ok(out))
}

type Events = Arc<Mutex<Vec<Json>>>;

fn declare(hook: &mut Hook, spec: &Json, name: String, events: &Events) -> Result<(), Fault> {
    let s = Args(spec);
    let groups: Vec<String> = s
        .opt("groups")
        .and_then(Json::as_array)
        .map(|g| g.iter().filter_map(|x| x.as_str().map(str::to_owned)).collect())
        .unwrap_or_else(|| vec!["*".into()]);
    hook.groups(&groups.iter().map(String::as_str).collect::<Vec<_>>());
    let mut keys = Vec::new();
    for param in s.opt("params").and_then(Json::as_array).cloned().unwrap_or_default() {
        let p = Args(&param);
        let key = p.str("key")?.to_owned();
        let validator: Check = match p.opt_str("validator")?.unwrap_or("text") {
            "integer" => Check::Validator(Arc::new(Integer::new(true, 32, false).map_err(fault)?)),
            "any" => Check::Validator(Arc::new(utopia_validators::Wildcard)),
            _ => Check::Validator(Arc::new(Text::with_min(p.opt_i64("length")?.unwrap_or(256) as usize, 0))),
        };
        hook.param_with(
            &key,
            Param {
                default: p.opt("default").map(decode).transpose()?.unwrap_or(Value::Null),
                validator,
                description: String::new(),
                optional: p.opt_bool("optional")?.unwrap_or(false),
                aliases: p
                    .opt("aliases")
                    .and_then(Json::as_array)
                    .map(|l| l.iter().filter_map(|x| x.as_str().map(str::to_owned)).collect())
                    .unwrap_or_default(),
            },
        );
        keys.push(key);
    }
    let injections: Vec<String> = s
        .opt("inject")
        .and_then(Json::as_array)
        .map(|l| l.iter().filter_map(|x| x.as_str().map(str::to_owned)).collect())
        .unwrap_or_default();
    for i in &injections {
        hook.inject(i);
    }
    let fail = s.opt("fail").cloned();
    let context = s.opt_str("context")?.map(str::to_owned);
    let when = s.opt_str("when")?.map(str::to_owned);
    let has = s.opt_str("has")?.map(str::to_owned);
    let events = events.clone();
    hook.action(move |scope| {
        let (keys, injections, fail, events, name) =
            (keys.clone(), injections.clone(), fail.clone(), events.clone(), name.clone());
        let (context, when, has) = (context.clone(), when.clone(), has.clone());
        async move {
            let mut seen = Vec::new();
            for k in &keys {
                seen.push(dump(scope.param(k)));
            }
            for i in &injections {
                if let Some(m) = scope.get::<Message>(i) {
                    seen.push(json!(format!("message:{}", pid(&m))));
                    if fail.as_ref().and_then(Json::as_str) == Some("terminal") {
                        m.terminal(true);
                    }
                    if let (Some(key), Some(target)) = (&context, scope.context())
                        && when.as_ref().is_none_or(|w| *w == pid(&m))
                    {
                        target.set(key, Arc::new(pid(&m)));
                    }
                } else if let Some(e) = scope.get::<Error>(i) {
                    seen.push(json!(format!("error:{}:{}", e.php_class(), e)));
                } else if let Some(s) = scope.get::<String>(i) {
                    seen.push(json!(s.as_str()));
                } else {
                    seen.push(Json::Null);
                }
            }
            if let Some(key) = &has {
                let value = scope.context().and_then(|c| c.get(key).ok()).and_then(|r| r.downcast::<String>().ok());
                seen.push(value.map_or(Json::Null, |v| json!(v.as_str())));
            }
            if let Ok(mut e) = events.lock() {
                e.push(json!([name, seen]));
            }
            match fail {
                Some(Json::String(t)) if t == "terminal" => Err(Error::Runtime("marked terminal".into())),
                Some(f @ Json::Object(_)) => {
                    let message = f.get("message").and_then(Json::as_str).unwrap_or("").to_owned();
                    Err(match f.get("class").and_then(Json::as_str).unwrap_or("") {
                        "PermanentFailure" => Error::PermanentFailure(message),
                        "TypeError" => Error::Type(message),
                        "ValueError" => Error::Value(message),
                        "Error" => Error::Other { class: "Error".into(), message },
                        "Exception" => Error::Other { class: "Exception".into(), message },
                        _ => Error::Runtime(message),
                    })
                }
                _ => Ok(()),
            }
        }
    });
    Ok(())
}

async fn run(a: &Args<'_>, session: &mut Session) -> Result<Outcome, Fault> {
    let events: Events = Arc::new(Mutex::new(Vec::new()));
    let mut server = Server::new(adapter(a, session)?);
    server.resources().set("greeting", Arc::new("hello".to_owned()));
    let telemetry = Arc::new(Recording::default());
    if a.opt_bool("telemetry")?.unwrap_or(false) {
        let publisher = match a.opt("consumer").map(|h| session.get::<Broker>(h)).transpose()? {
            Some(b) => b.publisher().ok(),
            None => None,
        };
        server.set_telemetry(telemetry.clone(), publisher);
    }
    for spec in a.array("jobs")? {
        let s = Args(spec);
        let name = s.str("queue")?;
        let job = match server.job(
            name,
            s.opt_i64("coroutines")?.unwrap_or(1) as usize,
            s.opt_i64("prefetch")?.map(|p| p as usize),
        ) {
            Ok(j) => j,
            Err(e) => return Ok(err(e)),
        };
        if let Some(h) = s.opt_bool("hook")? {
            job.hook(h);
        }
        declare(job, spec, format!("job:{name}"), &events)?;
        let groups: Vec<String> = s
            .opt("groups")
            .and_then(Json::as_array)
            .map(|g| g.iter().filter_map(|x| x.as_str().map(str::to_owned)).collect())
            .unwrap_or_default();
        job.groups(&groups.iter().map(String::as_str).collect::<Vec<_>>());
    }
    let hooks = a.opt("hooks").cloned().unwrap_or(json!({}));
    for kind in ["init", "shutdown", "error", "workerStart", "workerStop"] {
        for (index, spec) in hooks.get(kind).and_then(Json::as_array).cloned().unwrap_or_default().iter().enumerate() {
            let hook = match kind {
                "init" => server.init(),
                "shutdown" => server.shutdown(),
                "error" => server.error(),
                "workerStart" => server.worker_start(),
                _ => server.worker_stop(),
            };
            declare(hook, spec, format!("{kind}{index}"), &events)?;
        }
    }
    let server = Arc::new(server);
    if let Err(e) = server.start().await
        && let Ok(mut ev) = events.lock()
    {
        ev.push(json!(["start", [format!("error:{}:{}", e.php_class(), e)]]));
    }
    if a.opt_bool("telemetry")?.unwrap_or(false) {
        let report = telemetry.report().await;
        if let Ok(mut ev) = events.lock() {
            ev.push(json!(["telemetry", report]));
        }
    }
    let out = events.lock().map(|e| e.clone()).unwrap_or_default();
    Ok(Outcome::ok(out))
}

/// A telemetry sink that keeps what it was given (Utopia\Telemetry\Adapter\Test).
#[derive(Default)]
struct Recording {
    histograms: Mutex<std::collections::HashMap<String, Arc<Values>>>,
    gauges: Mutex<std::collections::HashMap<String, Vec<utopia_queue::telemetry::Observer>>>,
}

#[derive(Default)]
struct Values(Mutex<Vec<f64>>);

impl utopia_queue::telemetry::Histogram for Values {
    fn record(&self, value: f64) {
        if let Ok(mut v) = self.0.lock() {
            v.push(value);
        }
    }
}

impl utopia_queue::telemetry::Telemetry for Recording {
    fn histogram(
        &self,
        name: &str,
        _: &str,
        _: Option<&str>,
        _: &[f64],
    ) -> Arc<dyn utopia_queue::telemetry::Histogram> {
        let values = Arc::new(Values::default());
        if let Ok(mut h) = self.histograms.lock() {
            h.insert(name.to_owned(), values.clone());
        }
        values
    }

    fn observable_gauge(&self, name: &str, _: &str, _: &str, observer: utopia_queue::telemetry::Observer) {
        if let Ok(mut g) = self.gauges.lock() {
            g.entry(name.to_owned()).or_default().push(observer);
        }
    }
}

impl Recording {
    /// Each duration histogram's size and sign, the message sizes (sorted),
    /// each depth gauge observed twice.
    async fn report(&self) -> Json {
        let mut out = serde_json::Map::new();
        for name in ["messaging.process.wait.duration", "messaging.process.duration"] {
            let values = self.histograms.lock().ok().and_then(|h| h.get(name).cloned());
            let values = values.map(|v| v.0.lock().map(|v| v.clone()).unwrap_or_default()).unwrap_or_default();
            out.insert(name.into(), json!({"count": values.len(), "non_negative": values.iter().all(|v| *v >= 0.0)}));
        }
        let name = "messaging.process.message.size";
        let sizes = self.histograms.lock().ok().and_then(|h| h.get(name).cloned());
        let mut sizes: Vec<i64> = sizes
            .map(|v| v.0.lock().map(|v| v.iter().map(|s| *s as i64).collect()).unwrap_or_default())
            .unwrap_or_default();
        sizes.sort_unstable();
        out.insert(name.into(), json!(sizes));
        for name in ["messaging.queue.depth", "messaging.queue.failed.depth"] {
            let observers = self.gauges.lock().ok().and_then(|g| g.get(name).cloned()).unwrap_or_default();
            let mut observed = Vec::new();
            for _ in 0..2 {
                for observer in &observers {
                    for (value, attributes) in observer().await {
                        let attrs: serde_json::Map<String, Json> =
                            attributes.into_iter().map(|(k, v)| (k, Json::String(v))).collect();
                        observed.push(json!([value, attrs]));
                    }
                }
            }
            out.insert(name.into(), Json::Array(observed));
        }
        Json::Object(out)
    }
}

#[derive(Default)]
struct Consumed {
    handled: Vec<String>,
    succeeded: Vec<String>,
    errors: Vec<Json>,
    active: i64,
    max: i64,
}

/// The long-running loop (`Runtime::Workers`) over one queue until
/// `stop_after` messages were handled (or five seconds passed), as
/// `Adapter::consume()` in Adapter.php reports it.
async fn consume(a: &Args<'_>, session: &mut Session) -> Result<Outcome, Fault> {
    use utopia_queue::adapter::{ErrorCallback, MessageCallback, Spec, SuccessCallback};

    let consumer = session.get::<Broker>(a.value("consumer")?)?.consumer();
    let queue = match queue(a.value("queue")?)? {
        Ok(q) => q,
        Err(e) => return Ok(err(e)),
    };
    let adapter = Adapter::new(consumer.clone(), 1, Runtime::Workers).with_receive_timeout(1);
    let stopper = adapter.stopper();
    let state = Arc::new(Mutex::new(Consumed::default()));
    let stop_after = a.i64("stop_after")? as usize;
    let hold = a.opt_i64("hold_ms")?.unwrap_or(0);
    let fail: Vec<String> = a
        .opt("fail")
        .and_then(Json::as_array)
        .map(|l| l.iter().filter_map(|x| x.as_str().map(str::to_owned)).collect())
        .unwrap_or_default();
    let coroutines = a.opt_i64("coroutines")?.unwrap_or(1) as usize;
    let prefetch = a.opt_i64("prefetch")?.map_or(coroutines, |p| p as usize);

    let (st, stop) = (state.clone(), stopper.clone());
    let on_message: MessageCallback = Arc::new(move |m: Arc<Message>, _| {
        let (st, stop, fail) = (st.clone(), stop.clone(), fail.clone());
        Box::pin(async move {
            if let Ok(mut s) = st.lock() {
                s.active += 1;
                s.max = s.max.max(s.active);
            }
            if hold > 0 {
                tokio::time::sleep(std::time::Duration::from_millis(hold as u64)).await;
            }
            let pid = pid(&m);
            if let Ok(mut s) = st.lock() {
                s.active -= 1;
                s.handled.push(pid.clone());
                if s.handled.len() >= stop_after {
                    stop.stop();
                }
            }
            if fail.contains(&pid) { Err(Error::Runtime("boom".into())) } else { Ok(()) }
        })
    });
    let st = state.clone();
    let on_success: SuccessCallback = Arc::new(move |m: Arc<Message>, _| {
        if let Ok(mut s) = st.lock() {
            s.succeeded.push(pid(&m));
        }
        Box::pin(async { Ok(()) })
    });
    let st = state.clone();
    let on_error: ErrorCallback = Arc::new(move |m: Option<Arc<Message>>, e: Error, _| {
        if let Ok(mut s) = st.lock() {
            s.errors.push(json!([m.map(|m| pid(&m)), e.to_string()]));
        }
        Box::pin(async { Ok(()) })
    });
    let deadline = tokio::spawn(async move {
        tokio::time::sleep(std::time::Duration::from_secs(5)).await;
        stopper.stop();
    });
    let result =
        adapter.consume(on_message, on_success, on_error, vec![Spec { queue, coroutines, prefetch, consumer }]).await;
    deadline.abort();
    if let Err(e) = result {
        return Ok(err(e));
    }
    let mut s = std::mem::take(&mut *state.lock().unwrap_or_else(|e| e.into_inner()));
    if a.opt_bool("sort")?.unwrap_or(false) {
        s.handled.sort();
        s.succeeded.sort();
    }
    Ok(Outcome::ok(json!({"handled": s.handled, "succeeded": s.succeeded, "errors": s.errors, "max": s.max})))
}

type Sink = Arc<Mutex<Vec<Json>>>;

/// What each NATS broker reported through `on_error`, by broker address.
static REPORTS: Mutex<Vec<(usize, Sink)>> = Mutex::new(Vec::new());

fn reports_of(id: usize) -> Vec<Json> {
    REPORTS
        .lock()
        .ok()
        .and_then(|s| s.iter().rev().find(|(k, _)| *k == id).and_then(|(_, r)| r.lock().ok().map(|r| r.clone())))
        .unwrap_or_default()
}

/// The stream a queue name maps to (`Q_<NAME>`, `_DEAD` for the dead one),
/// so cases can name streams whose names embed the run's namespace.
fn stream_of(queue: &str, dead: bool) -> String {
    let token: String = queue
        .bytes()
        .map(|b| if b.is_ascii_alphanumeric() || b == b'_' || b == b'-' { b.to_ascii_uppercase() as char } else { '_' })
        .collect();
    format!("Q_{token}{}", if dead { "_DEAD" } else { "" })
}

fn stream_name(a: &Args) -> Result<String, Fault> {
    Ok(stream_of(a.str("queue")?, a.opt_bool("dead")?.unwrap_or(false)))
}

async fn nats_client(session: &Session) -> Result<async_nats::Client, Fault> {
    async_nats::connect(session.service("queue-nats")?).await.map_err(fault)
}

async fn nats_broker(a: &Args<'_>, session: &mut Session) -> Result<Outcome, Fault> {
    let clock = clock(a)?;
    let url = session.service("queue-nats")?.to_owned();
    let reports: Sink = Arc::new(Mutex::new(Vec::new()));
    let sink = reports.clone();
    let field = a.opt_str("message_id")?.map(str::to_owned);
    let message_id = field.map(|field| -> utopia_queue::broker::MessageId {
        Arc::new(move |payload: &Array| {
            payload
                .get_str(&field)
                .and_then(|v| v.to_php_string().ok())
                .map_or_else(String::new, |s| String::from_utf8_lossy(s.as_bytes()).into_owned())
        })
    });
    let config = NatsConfig {
        ack_wait: a.opt_f64("ack_wait")?.unwrap_or(30.0),
        max_deliver: a.opt_i64("max_deliver")?.unwrap_or(5),
        replicas: 1,
        backoff: a.opt("backoff").and_then(Json::as_array).map(|l| l.iter().filter_map(Json::as_f64).collect()),
        memory: a.opt_bool("memory")?.unwrap_or(false),
        dead_max_age: a.opt_f64("dead_max_age")?,
        duplicate_window: a.opt_f64("duplicate_window")?.unwrap_or(120.0),
        message_id,
        on_error: Some(Arc::new(move |e: &Error| {
            if let Ok(mut r) = sink.lock() {
                r.push(json!([e.php_class(), e.to_string()]));
            }
        })),
        max_age: a.opt_f64("max_age")?,
        max_msg_size: a.opt_i64("max_msg_size")?,
        max_msgs: a.opt_i64("max_msgs")?.unwrap_or(-1),
        max_bytes: a.opt_i64("max_bytes")?.unwrap_or(-1),
        discard_new: a.opt_bool("discard_new")?.unwrap_or(false),
        max_ack_pending: a.opt_i64("max_ack_pending")?,
        max_waiting: a.opt_i64("max_waiting")?,
        inactive_threshold: a.opt_f64("inactive_threshold")?,
        provisioning: if a.opt_bool("require")?.unwrap_or(false) {
            Provisioning::Require
        } else {
            Provisioning::Ensure
        },
        codec: codec(a.opt_str("codec")?.unwrap_or("json"))?,
        release_delay: a.opt_f64("release_delay")?.unwrap_or(5.0),
    };
    let built = if a.opt_bool("live")?.unwrap_or(false) {
        Nats::with_client(nats_client(session).await?, config)
    } else {
        Nats::new(url, config)
    };
    let broker = match built {
        Ok(b) => Arc::new(b.with_clock(clock)),
        Err(e) => return Ok(err(e)),
    };
    REPORTS.lock().unwrap_or_else(|e| e.into_inner()).push((Arc::as_ptr(&broker) as usize, reports));
    Ok(Outcome::Ok(session.handle(Broker::Nats(broker))))
}

async fn nats_stream(a: &Args<'_>, session: &Session) -> Result<Outcome, Fault> {
    use async_nats::jetstream::stream::{DiscardPolicy, RetentionPolicy, StorageType};
    let js = async_nats::jetstream::new(nats_client(session).await?);
    let Ok(mut stream) = js.get_stream(stream_name(a)?).await else { return Ok(Outcome::Ok(Json::Null)) };
    let info = stream.info().await.map_err(fault)?;
    let c = &info.config;
    Ok(Outcome::ok(json!({
        "subjects": c.subjects,
        "retention": match c.retention {
            RetentionPolicy::Limits => "limits",
            RetentionPolicy::Interest => "interest",
            RetentionPolicy::WorkQueue => "workqueue",
        },
        "max_msgs": c.max_messages,
        "max_bytes": c.max_bytes,
        "max_msg_size": c.max_message_size,
        "max_age": c.max_age.as_secs_f64(),
        "storage": match c.storage { StorageType::File => "file", StorageType::Memory => "memory" },
        "replicas": c.num_replicas,
        "discard": match c.discard { DiscardPolicy::Old => "old", DiscardPolicy::New => "new" },
        "duplicate_window": c.duplicate_window.as_secs_f64(),
        "identity": c.metadata.get("utopia_queue_identity"),
        "messages": info.state.messages,
    })))
}

async fn nats_consumer(a: &Args<'_>, session: &Session) -> Result<Outcome, Fault> {
    let js = async_nats::jetstream::new(nats_client(session).await?);
    let Ok(stream) = js.get_stream(stream_name(a)?).await else { return Ok(Outcome::Ok(Json::Null)) };
    let Ok(mut consumer) =
        stream.get_consumer::<async_nats::jetstream::consumer::pull::Config>(a.str("consumer")?).await
    else {
        return Ok(Outcome::Ok(Json::Null));
    };
    let info = consumer.info().await.map_err(fault)?;
    let c = &info.config;
    Ok(Outcome::ok(json!({
        "max_deliver": c.max_deliver,
        "ack_wait": c.ack_wait.as_secs_f64(),
        "max_ack_pending": c.max_ack_pending,
        "max_waiting": c.max_waiting,
        "backoff": c.backoff.iter().map(|d| d.as_secs_f64()).collect::<Vec<_>>(),
        "spare": c.metadata.contains_key("utopia_queue_spare_delivery"),
        "pending": info.num_pending,
    })))
}

async fn nats_message(a: &Args<'_>, session: &Session) -> Result<Outcome, Fault> {
    let js = async_nats::jetstream::new(nats_client(session).await?);
    let Ok(stream) = js.get_stream(stream_name(a)?).await else { return Ok(Outcome::Ok(Json::Null)) };
    let Ok(m) = stream.get_raw_message(a.i64("seq")? as u64).await else { return Ok(Outcome::Ok(Json::Null)) };
    let header = |k: &str| {
        m.headers
            .iter()
            .find(|(name, _)| name.to_string().eq_ignore_ascii_case(k))
            .and_then(|(_, v)| v.first())
            .map(|v| v.to_string())
    };
    Ok(Outcome::ok(json!({
        "subject": m.subject.to_string(),
        "data": bytes_value(&m.payload),
        "content_type": header("Content-Type"),
        "id": header("Nats-Msg-Id"),
    })))
}

/// A message as Appwrite's audits queue carries one.
const BENCH_PAYLOAD: &str = r#"{"project":{"$id":"6650c1f2a8e4d","name":"Demo","region":"fra"},"user":{"$id":"6650c20b3f1a2","name":"Ada Lovelace","email":"ada@example.com"},"event":"users.6650c20b3f1a2.create","resource":"user/6650c20b3f1a2","mode":"admin","userAgent":"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15","ip":"203.0.113.7","hostname":"cloud.appwrite.io","data":{"id":1,"tags":["a","b","c"],"active":true,"score":4.5,"note":"café ✓"},"time":1700000000}"#;

pub const BENCHES: &[(&str, &str)] = &[
    ("encode", "Encode an audits envelope to JSON bytes, as a broker publishes it"),
    ("decode", "Decode an audits envelope from JSON bytes into a Message, as a broker receives it"),
    ("igbinary", "Encode an audits envelope with igbinary and decode it back"),
    ("publish", "Publish an audits message onto a Redis queue: the envelope encoded and one LPUSH"),
    (
        "consume",
        "Publish, receive and commit one message on a Redis queue: an LPUSH and the reserve, claim and settle scripts",
    ),
];

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    let payload = match JsonCodec.decode(BENCH_PAYLOAD.as_bytes()).map_err(fault)? {
        Value::Array(a) => a,
        _ => return Err(Fault::new("bench payload is not an array")),
    };
    let mut envelope = Array::new();
    envelope.set("pid".into(), Value::from("6650c2a91b6f53.12345678"));
    envelope.set("queue".into(), Value::from("v1-audits"));
    envelope.set("timestamp".into(), Value::Int(1_700_000_000));
    envelope.set("payload".into(), Value::Array(payload.clone()));
    let envelope = Value::Array(envelope);
    let queue = Queue::with("bench", format!("{}.bench", session.ns), 0).map_err(fault)?;
    let broker = || -> Result<Redis, Fault> {
        Ok(Redis::new(Arc::new(redis_connection(session)?), Arc::new(Locking::new(redis_connection(session)?))))
    };
    Ok(match name {
        "encode" => Bench::sync(move || {
            black_box(JsonCodec.encode(black_box(&envelope)).ok());
        }),
        "decode" => {
            let bytes = JsonCodec.encode(&envelope).map_err(fault)?;
            Bench::sync(move || {
                let message = match JsonCodec.decode(black_box(&bytes)) {
                    Ok(Value::Array(a)) => Message::from_envelope(&a).ok(),
                    _ => None,
                };
                black_box(message);
            })
        }
        "igbinary" => Bench::sync(move || {
            let decoded = Igbinary.encode(black_box(&envelope)).and_then(|b| Igbinary.decode(&b));
            black_box(decoded.ok());
        }),
        "publish" => {
            let (broker, queue) = (Rc::new(broker()?), Rc::new(queue));
            Bench::future(move || {
                let (broker, queue, payload) = (broker.clone(), queue.clone(), payload.clone());
                async move {
                    black_box(broker.publish(&queue, payload).await.ok());
                }
            })
        }
        "consume" => {
            let (broker, queue) = (Rc::new(broker()?), Rc::new(queue));
            Bench::future(move || {
                let (broker, queue, payload) = (broker.clone(), queue.clone(), payload.clone());
                async move {
                    let _ = broker.publish(&queue, payload).await;
                    if let Ok(messages) = broker.receive(&queue, 0, 1).await {
                        for message in &messages {
                            black_box(broker.commit(&queue, message).await.ok());
                        }
                    }
                }
            })
        }
        other => return Err(Fault::new(format!("queue: unknown benchmark `{other}`"))),
    })
}
