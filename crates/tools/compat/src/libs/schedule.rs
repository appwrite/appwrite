//! Compat adapter for `schedule`: maps `tests/compat/schedule/spec.json` operations onto `utopia-schedule`.
//!
//! Moments, trigger specs and scripted sources are decoded exactly as
//! `tests/compat/schedule/Adapter.php` decodes them.

use std::future::Future;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use php_std::datetime::{DateTime, TimeZone, Timestamp, TzInfo};
use redis::aio::ConnectionManager;
use serde_json::{Map, Value, json};
use utopia_schedule::{
    At, BoxError, Claim, Clock, Cron, Entry, Error, Interval, MemoryStore, Occurrence, Options, RedisStore, Row,
    Scheduler, Shifted, Source, Stop, Store, TestClock, Trigger,
};
use utopia_telemetry::{Kind, MemoryTelemetry, Number};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, float_value};

pub const OPS: &[&str] = &[
    "trigger.new",
    "trigger.occurrences",
    "trigger.recurring",
    "clock.new",
    "clock.now",
    "clock.advance",
    "clock.sleep",
    "store.memory",
    "store.redis",
    "store.load",
    "store.swap",
    "store.race",
    "redis.ttl",
    "redis.kill",
    "source.new",
    "source.set",
    "source.made",
    "scheduler.new",
    "scheduler.reconcile",
    "scheduler.tick",
    "scheduler.commit",
    "scheduler.count",
    "scheduler.is_current",
    "scheduler.run",
    "scheduler.stop",
    "scheduler.errors",
    "scheduler.telemetry",
    "occurrence.key",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("cron_parse", "Parse a cron expression with lists, ranges, steps and names"),
    ("cron_day", "List a day of */5 cron occurrences (288)"),
    ("interval_day", "List a day of one-minute interval occurrences (1440)"),
    ("tick", "Tick and commit a minute over 1000 minutely schedules (1000 occurrences)"),
];

const FORMAT: &[u8] = b"Y-m-d H:i:s.u T";

/// What fills the fields a moment string leaves out (cases give them all).
const NOW: Timestamp = Timestamp { sec: 1_787_000_000, usec: 0 };

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    let at = |s: &str| moment(&Value::String(s.to_owned())).map_err(|e| Fault::new(e.to_string()));
    Ok(match name {
        "cron_parse" => Bench::sync(|| {
            black_box(Cron::new(black_box("*/15 9-17 * JAN-OCT MON-FRI")).ok());
        }),
        "cron_day" => {
            let cron = Cron::new("*/5 * * * *").map_err(|e| Fault::new(e.to_string()))?;
            let (start, end) = (at("2026-08-17 00:00:00")?, at("2026-08-18 00:00:00")?);
            Bench::sync(move || {
                black_box(cron.occurrences_between(&start, &end));
            })
        }
        "interval_day" => {
            let interval = Interval::new(60, None).map_err(|e| Fault::new(e.to_string()))?;
            let (start, end) = (at("2026-08-17 00:00:00")?, at("2026-08-18 00:00:00")?);
            Bench::sync(move || {
                black_box(interval.occurrences_between(&start, &end));
            })
        }
        "tick" => {
            let clock = Arc::new(TestClock::new(&at("2026-08-18 03:00:30")?));
            let spec = json!({"rows": [{"prefix": "s", "count": 1000, "version": "v1"}], "trigger": {"interval": 60}});
            let source = Arc::new(Script::new(&spec, None, None));
            let store = AnyStore::Memory(Arc::new(MemoryStore::new()));
            let options = Options {
                tick_seconds: 60,
                lease_seconds: Some(600),
                token: Some("bench".into()),
                ..Options::default()
            };
            let mut scheduler =
                Scheduler::new(source, store, clock.clone(), options).map_err(|e| Fault::new(e.to_string()))?;
            let prepare = async {
                scheduler.reconcile(false).await?;
                scheduler.tick().await?;
                scheduler.commit().await
            };
            prepare.await.map_err(|e| Fault::new(e.to_string()))?;
            let _ = session;
            let scheduler = Rc::new(tokio::sync::Mutex::new(scheduler));
            Bench::future(move || {
                let (scheduler, clock) = (scheduler.clone(), clock.clone());
                async move {
                    let mut scheduler = scheduler.lock().await;
                    clock.advance(60.0);
                    black_box(scheduler.tick().await.ok());
                    black_box(scheduler.commit().await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("schedule: no benchmark `{name}`"))),
    })
}

/// A failure the case scripted: `{"class": ..., "message": ...}`.
#[derive(Debug)]
struct Scripted {
    class: String,
    message: String,
}

impl std::fmt::Display for Scripted {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for Scripted {}

/// Arguments of the wrong shape, found while building something.
#[derive(Debug)]
struct Shape(String);

impl std::fmt::Display for Shape {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for Shape {}

fn scripted(e: &Value) -> BoxError {
    // The PHP adapter raises InvalidArgumentException or RuntimeException.
    let class = match e.get("class").and_then(Value::as_str) {
        Some("InvalidArgumentException") => "InvalidArgumentException",
        _ => "RuntimeException",
    };
    let message = e.get("message").and_then(Value::as_str).unwrap_or_default();
    Box::new(Scripted { class: class.into(), message: message.into() })
}

/// The PHP class and message of an error a source or handler raised.
fn boxed(e: &(dyn std::error::Error + 'static)) -> Result<(String, Value), Fault> {
    if let Some(s) = e.downcast_ref::<Scripted>() {
        return Ok((s.class.clone(), Value::String(s.message.clone())));
    }
    if let Some(s) = e.downcast_ref::<Shape>() {
        return Err(Fault::new(s.0.clone()));
    }
    if let Some(e) = e.downcast_ref::<Error>() {
        return class_of(e);
    }
    if let Some(e) = e.downcast_ref::<php_std::datetime::Error>() {
        return Ok((e.php_class().into(), crate::adapter::bytes_value(e.message_bytes())));
    }
    Err(Fault::new(format!("unexpected error: {e}")))
}

fn class_of(e: &Error) -> Result<(String, Value), Fault> {
    match e {
        Error::Source(inner) | Error::Handler(inner) => boxed(inner.as_ref()),
        other => Ok((other.php_class().into(), Value::String(other.to_string()))),
    }
}

/// Why an operation stopped: a harness problem, or a result to report.
enum Fail {
    Fault(Fault),
    Out(Outcome),
}

impl From<Fault> for Fail {
    fn from(f: Fault) -> Self {
        Fail::Fault(f)
    }
}

impl From<Error> for Fail {
    fn from(e: Error) -> Self {
        match class_of(&e) {
            Ok((class, message)) => Fail::Out(Outcome::Err { class, message }),
            Err(f) => Fail::Fault(f),
        }
    }
}

impl From<BoxError> for Fail {
    fn from(e: BoxError) -> Self {
        match boxed(e.as_ref()) {
            Ok((class, message)) => Fail::Out(Outcome::Err { class, message }),
            Err(f) => Fail::Fault(f),
        }
    }
}

/// `new DateTimeImmutable($s)` (UTC), or `{"time": ..., "tz": ...}`.
fn moment(v: &Value) -> Result<DateTime, BoxError> {
    let utc = TzInfo::utc();
    match v {
        Value::String(s) => Ok(DateTime::parse(s.as_bytes(), None, &utc, NOW)?),
        Value::Object(o) => {
            let time =
                o.get("time").and_then(Value::as_str).ok_or_else(|| Box::new(Shape("moment without time".into())))?;
            let zone = match o.get("tz").and_then(Value::as_str) {
                Some(tz) => Some(TimeZone::new(tz)?),
                None => None,
            };
            Ok(DateTime::parse(time.as_bytes(), zone.as_ref(), &utc, NOW)?)
        }
        other => Err(Box::new(Shape(format!("not a moment: {other}")))),
    }
}

fn int(v: &Value, key: &str) -> Result<i64, BoxError> {
    v.get(key).and_then(Value::as_i64).ok_or_else(|| Box::new(Shape(format!("`{key}` must be an integer"))) as BoxError)
}

/// A trigger spec, built as PHP builds it.
fn trigger(t: &Value) -> Result<Arc<dyn Trigger>, BoxError> {
    if let Some(expression) = t.get("cron").and_then(Value::as_str) {
        return Ok(Arc::new(Cron::new(expression)?));
    }
    if t.get("interval").is_some_and(|v| !v.is_null()) {
        let anchor = match t.get("anchor").filter(|v| !v.is_null()) {
            Some(a) => Some(moment(a)?),
            None => None,
        };
        return Ok(Arc::new(Interval::new(int(t, "interval")?, anchor.as_ref())?));
    }
    if let Some(at) = t.get("at").filter(|v| !v.is_null()) {
        return Ok(Arc::new(At::new(moment(at)?)));
    }
    if t.get("in").is_some_and(|v| !v.is_null()) {
        let from = moment(t.get("from").unwrap_or(&Value::Null))?;
        return Ok(Arc::new(At::after(int(t, "in")?, &from)));
    }
    if let Some(inner) = t.get("shifted").filter(|v| !v.is_null()) {
        return Ok(Arc::new(Shifted::new(trigger(inner)?, int(t, "seconds")?)));
    }
    Err(Box::new(Shape(format!("unknown trigger {t}"))))
}

fn text(bytes: Vec<u8>) -> Value {
    crate::adapter::bytes_value(&bytes)
}

fn claim_value(claim: Option<Claim>) -> Value {
    let opt = |f: Option<f64>| f.map_or(Value::Null, float_value);
    match claim {
        None => Value::Null,
        Some(c) => json!({
            "token": c.token,
            "expiresAt": float_value(c.expires_at),
            "coveredUntil": opt(c.covered_until),
            "syncedUntil": opt(c.synced_until),
        }),
    }
}

fn md5(text: &str) -> String {
    php_std::string::hash(b"md5", text.as_bytes()).unwrap_or_default()
}

fn occurrences(list: &[Occurrence<Value>], summary: bool) -> Value {
    if summary {
        let keys: Vec<String> = list.iter().map(Occurrence::key).collect();
        let distinct = keys.iter().collect::<std::collections::HashSet<_>>().len();
        return json!({"count": keys.len(), "distinct": distinct, "digest": md5(&keys.join("\n"))});
    }
    Value::Array(
        list.iter()
            .map(|o| {
                json!({
                    "id": o.id,
                    "due": text(o.due.format(FORMAT)),
                    "payload": o.payload,
                    "version": o.version,
                    "key": o.key(),
                })
            })
            .collect(),
    )
}

/// A claim store of either kind.
#[derive(Clone)]
enum AnyStore {
    Memory(Arc<MemoryStore>),
    Redis(Arc<RedisStore>),
}

impl Store for AnyStore {
    fn load(&self) -> impl Future<Output = Result<Option<Claim>, Error>> + Send {
        let store = self.clone();
        async move {
            match store {
                AnyStore::Memory(m) => m.load().await,
                AnyStore::Redis(r) => r.load().await,
            }
        }
    }

    fn swap(&self, expected: Option<&str>, next: &Claim) -> impl Future<Output = Result<bool, Error>> + Send {
        let (store, expected, next) = (self.clone(), expected.map(str::to_owned), next.clone());
        async move {
            match store {
                AnyStore::Memory(m) => m.swap(expected.as_deref(), &next).await,
                AnyStore::Redis(r) => r.swap(expected.as_deref(), &next).await,
            }
        }
    }
}

/// The scripted source of `Adapter.php` (`Script`, `ChangesScript`).
struct Script {
    spec: Mutex<Value>,
    made: AtomicUsize,
    clock: Option<Arc<TestClock>>,
    store: Option<AnyStore>,
    stop: Mutex<Option<Stop>>,
}

impl Script {
    fn new(spec: &Value, clock: Option<Arc<TestClock>>, store: Option<AnyStore>) -> Self {
        Self { spec: Mutex::new(spec.clone()), made: AtomicUsize::new(0), clock, store, stop: Mutex::new(None) }
    }

    fn spec(&self) -> std::sync::MutexGuard<'_, Value> {
        self.spec.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn now(&self) -> Result<DateTime, BoxError> {
        Ok(self.clock.as_ref().ok_or_else(|| Box::new(Shape("the source has no clock".into())))?.now()?)
    }

    async fn steal(&self, steal: &Value) -> Result<(), BoxError> {
        let store = self.store.as_ref().ok_or_else(|| Box::new(Shape("the source has no store".into())))?;
        let now = self.now()?;
        let at = moment(steal.get("at").unwrap_or(&Value::Null))?;
        let from = steal.get("from").and_then(Value::as_str).unwrap_or_default();
        if now >= at && store.load().await?.is_some_and(|c| c.token == from) {
            let token = steal.get("token").and_then(Value::as_str).unwrap_or_default();
            let expires =
                php_std::format::str_to_float(format!("{}.{:06}", now.timestamp(), now.microsecond()).as_bytes())
                    + int(steal, "lease")? as f64;
            store.swap(Some(from), &Claim::new(token, expires, None, None)).await?;
            if let Some(stop) = self.stop.lock().unwrap_or_else(|e| e.into_inner()).as_ref() {
                stop.stop();
            }
        }
        Ok(())
    }
}

fn rows(list: &Value) -> Result<Vec<Row<Value>>, BoxError> {
    let mut out = Vec::new();
    for r in list.as_array().map(Vec::as_slice).unwrap_or_default() {
        if let Some(prefix) = r.get("prefix").and_then(Value::as_str) {
            let version = r.get("version").and_then(Value::as_str).unwrap_or_default();
            let data = r.get("data").cloned().unwrap_or(Value::Null);
            let from = r.get("from").and_then(Value::as_i64).unwrap_or(0);
            for n in from..int(r, "count")? {
                out.push(Row::new(format!("{prefix}{n}"), version, data.clone()));
            }
            continue;
        }
        let id = r.get("id").and_then(Value::as_str).ok_or_else(|| Box::new(Shape("row without id".into())))?;
        let version = r.get("version").and_then(Value::as_str).unwrap_or_default();
        let mut row = Row::new(id, version, r.get("data").cloned().unwrap_or(Value::Null))
            .active(r.get("active").and_then(Value::as_bool).unwrap_or(true));
        if let Some(from) = r.get("activeFrom").filter(|v| !v.is_null()) {
            row = row.active_from(moment(from)?);
        }
        out.push(row);
    }
    Ok(out)
}

impl Source for Script {
    type Data = Value;
    type Payload = Value;

    async fn snapshot(&self) -> Result<Vec<Row<Value>>, BoxError> {
        let steal = self.spec().get("steal").cloned();
        if let Some(steal) = steal {
            self.steal(&steal).await?;
        }
        let (mut list, phases, fail) = {
            let spec = self.spec();
            (
                spec.get("rows").cloned().unwrap_or(Value::Null),
                spec.get("phases").cloned().unwrap_or(Value::Null),
                spec.get("fail").cloned().filter(|v| !v.is_null()),
            )
        };
        for phase in phases.as_array().map(Vec::as_slice).unwrap_or_default() {
            let from = phase.get("from").filter(|v| !v.is_null());
            let reached = match from {
                None => true,
                Some(from) => self.now()? >= moment(from)?,
            };
            if reached {
                list = phase.get("rows").cloned().unwrap_or(Value::Null);
            }
        }
        let rows = rows(&list)?;
        match fail {
            Some(fail) => Err(scripted(&fail)),
            None => Ok(rows),
        }
    }

    async fn make(&self, row: &Row<Value>) -> Result<Entry<Value>, BoxError> {
        self.made.fetch_add(1, Ordering::SeqCst);
        let (poison, spec, payload) = {
            let spec = self.spec();
            let poison = spec.get("poison").and_then(|p| p.get(&row.id)).cloned();
            let trigger = spec
                .get("triggers")
                .and_then(|t| t.get(&row.id))
                .or_else(|| spec.get("trigger"))
                .filter(|v| !v.is_null())
                .cloned()
                .unwrap_or_else(|| row.data.clone());
            (poison, trigger, spec.get("payload").and_then(Value::as_str).map(str::to_owned))
        };
        if let Some(poison) = poison {
            return Err(scripted(&poison));
        }
        let trigger = trigger(&spec)?;
        let payload = match payload.as_deref() {
            Some("id") => Value::String(row.id.clone()),
            Some("data") => row.data.clone(),
            _ => Value::Null,
        };
        Ok(Entry::shared(trigger, payload))
    }

    async fn since(&self, _moment: &DateTime) -> Option<Result<Vec<Row<Value>>, BoxError>> {
        let since = self.spec().get("since").cloned().filter(|v| !v.is_null())?;
        Some(rows(&since))
    }
}

/// A scheduler with what the case observes of it.
struct Instance {
    scheduler: Scheduler<Arc<Script>, AnyStore, Arc<TestClock>>,
    store: AnyStore,
    clock: Arc<TestClock>,
    telemetry: Option<MemoryTelemetry>,
    errors: Arc<Mutex<Vec<Value>>>,
}

fn numbers(values: Vec<Number>) -> Value {
    Value::Array(
        values
            .into_iter()
            .map(|n| match n {
                Number::Int(i) => Value::from(i),
                Number::Float(f) => float_value(f),
            })
            .collect(),
    )
}

/// What the telemetry recorded, as the PHP Test backend shows it.
fn telemetry(t: Option<&MemoryTelemetry>) -> Value {
    let Some(t) = t else {
        return json!([]);
    };
    let section = |kind: Kind| {
        let mut m = Map::new();
        for name in t.names(kind) {
            let values = t.values(kind, &name).unwrap_or_default();
            let value = if kind == Kind::Histogram && name.ends_with(".duration") {
                Value::from(values.len())
            } else {
                numbers(values)
            };
            m.insert(name, value);
        }
        if m.is_empty() { json!([]) } else { Value::Object(m) }
    };
    json!({
        "counters": section(Kind::Counter),
        "histograms": section(Kind::Histogram),
        "gauges": section(Kind::Gauge),
    })
}

async fn connection(session: &Session, name: &str) -> Result<ConnectionManager, Fault> {
    let url = session.service("redis")?.to_owned();
    let mut connection = redis::Client::open(url.as_str())
        .map_err(|e| Fault::new(e.to_string()))?
        .get_connection_manager()
        .await
        .map_err(|e| Fault::new(e.to_string()))?;
    if !name.is_empty() {
        let _: () = redis::cmd("CLIENT")
            .arg("SETNAME")
            .arg(name)
            .query_async(&mut connection)
            .await
            .map_err(|e| Fault::new(e.to_string()))?;
    }
    Ok(connection)
}

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    match run_op(op, args, session).await {
        Ok(v) => Ok(Outcome::Ok(v)),
        Err(Fail::Out(o)) => Ok(o),
        Err(Fail::Fault(f)) => Err(f),
    }
}

async fn run_op(op: &str, args: &Value, session: &mut Session) -> Result<Value, Fail> {
    let a = Args(args);
    Ok(match op {
        "trigger.new" => {
            trigger(a.value("trigger")?)?;
            Value::Bool(true)
        }
        "trigger.occurrences" => {
            let trigger = trigger(a.value("trigger")?)?;
            let start = moment(a.value("start")?)?;
            let end = match a.opt_str("window")? {
                Some(window) => start.modified(window.as_bytes()).map_err(|e| Box::new(e) as BoxError)?,
                None => moment(a.value("end")?)?,
            };
            let format = a.opt_str("format")?.map_or(FORMAT.to_vec(), |f| f.as_bytes().to_vec());
            let dues: Vec<Value> =
                trigger.occurrences_between(&start, &end).iter().map(|d| text(d.format(&format))).collect();
            match a.opt_i64("limit")? {
                Some(limit) => {
                    let joined: Vec<&str> = dues.iter().filter_map(Value::as_str).collect();
                    json!({
                        "count": dues.len(),
                        "head": dues.iter().take(limit.max(0) as usize).cloned().collect::<Vec<_>>(),
                        "digest": md5(&joined.join("\n")),
                    })
                }
                None => Value::Array(dues),
            }
        }
        "trigger.recurring" => Value::Bool(trigger(a.value("trigger")?)?.recurring()),

        "clock.new" => session.handle(Arc::new(TestClock::new(&moment(a.value("start")?)?))),
        "clock.now" => {
            let format = a.opt_str("format")?.map_or(FORMAT.to_vec(), |f| f.as_bytes().to_vec());
            text(session.get::<Arc<TestClock>>(a.value("clock")?)?.now()?.format(&format))
        }
        "clock.advance" => {
            session.get::<Arc<TestClock>>(a.value("clock")?)?.advance(a.f64("seconds")?);
            Value::Null
        }
        "clock.sleep" => {
            session.get::<Arc<TestClock>>(a.value("clock")?)?.sleep(a.f64("seconds")?).await;
            Value::Null
        }

        "store.memory" => session.handle(AnyStore::Memory(Arc::new(MemoryStore::new()))),
        "store.redis" => {
            let connection = connection(session, a.opt_str("name")?.unwrap_or_default()).await?;
            session.handle(AnyStore::Redis(Arc::new(RedisStore::new(connection, a.str("key")?))))
        }
        "store.load" => claim_value(session.get::<AnyStore>(a.value("store")?)?.clone().load().await?),
        "store.swap" => {
            let store = session.get::<AnyStore>(a.value("store")?)?.clone();
            let c = Args(a.value("claim")?);
            let claim =
                Claim::new(c.str("token")?, c.f64("expiresAt")?, c.opt_f64("coveredUntil")?, c.opt_f64("syncedUntil")?);
            Value::Bool(store.swap(a.opt_str("expected")?, &claim).await?)
        }
        "store.race" => {
            let (rounds, contenders, key) = (a.i64("rounds")?, a.i64("contenders")?, a.str("key")?);
            let mut winners = 0;
            for round in 0..rounds {
                let mut stores = Vec::new();
                for _ in 0..contenders {
                    stores.push(RedisStore::new(connection(session, "").await?, format!("{key}:{round}")));
                }
                let swaps = stores.iter().enumerate().map(|(contender, store)| async move {
                    store.swap(None, &Claim::new(format!("token-{round}-{contender}"), 100.0, None, None)).await
                });
                for won in futures_util::future::join_all(swaps).await {
                    if won? {
                        winners += 1;
                    }
                }
            }
            Value::from(winners)
        }
        "redis.ttl" => {
            let mut c = connection(session, "").await?;
            let ttl: i64 = redis::cmd("TTL")
                .arg(a.str("key")?)
                .query_async(&mut c)
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            Value::from(ttl)
        }
        "redis.kill" => {
            let name = a.str("name")?;
            let mut c = connection(session, "").await?;
            let list: String =
                redis::cmd("CLIENT").arg("LIST").query_async(&mut c).await.map_err(|e| Fault::new(e.to_string()))?;
            let mut killed = 0;
            for line in list.lines() {
                let field = |key: &str| {
                    line.split(' ').find_map(|kv| kv.strip_prefix(key).and_then(|rest| rest.strip_prefix('=')))
                };
                if field("name") == Some(name)
                    && let Some(addr) = field("addr")
                {
                    let _: redis::Value = redis::cmd("CLIENT")
                        .arg("KILL")
                        .arg(addr)
                        .query_async(&mut c)
                        .await
                        .map_err(|e| Fault::new(e.to_string()))?;
                    killed += 1;
                }
            }
            Value::from(killed)
        }

        "source.new" => {
            let clock = match a.opt("clock") {
                Some(h) => Some(session.get::<Arc<TestClock>>(h)?.clone()),
                None => None,
            };
            let store = match a.opt("store") {
                Some(h) => Some(session.get::<AnyStore>(h)?.clone()),
                None => None,
            };
            session.handle(Arc::new(Script::new(args, clock, store)))
        }
        "source.set" => {
            let source = session.get::<Arc<Script>>(a.value("source")?)?;
            let mut spec = source.spec();
            for key in ["rows", "since", "fail", "phases"] {
                if let Some(v) = args.get(key)
                    && let Some(o) = spec.as_object_mut()
                {
                    o.insert(key.into(), v.clone());
                }
            }
            Value::Null
        }
        "source.made" => Value::from(session.get::<Arc<Script>>(a.value("source")?)?.made.load(Ordering::SeqCst)),

        "scheduler.new" => {
            let source = session.get::<Arc<Script>>(a.value("source")?)?.clone();
            let store = match a.opt("store") {
                Some(h) => session.get::<AnyStore>(h)?.clone(),
                None => AnyStore::Memory(Arc::new(MemoryStore::new())),
            };
            let clock = session.get::<Arc<TestClock>>(a.value("clock")?)?.clone();
            let options = Options {
                tick_seconds: a.opt_i64("tick")?.unwrap_or(1),
                sync_seconds: a.opt_i64("sync")?.unwrap_or(10),
                lead_seconds: a.opt_i64("lead")?.unwrap_or(0),
                snapshot_seconds: a.opt_i64("snapshot")?,
                recover_seconds: a.opt_i64("recover")?,
                lease_seconds: a.opt_i64("lease")?,
                token: a.opt_str("token")?.map(str::to_owned),
            };
            let mut scheduler = Scheduler::new(source.clone(), store.clone(), clock.clone(), options)?;
            let telemetry = a.opt_bool("telemetry")?.unwrap_or(false).then(MemoryTelemetry::new);
            if let Some(t) = &telemetry {
                scheduler = scheduler.with_telemetry(t);
            }
            let errors = Arc::new(Mutex::new(Vec::new()));
            if a.opt_bool("errors")?.unwrap_or(false) {
                let seen = errors.clone();
                scheduler = scheduler.on_error(move |error, row| {
                    let (class, message) = class_of(error).unwrap_or_else(|f| ("Fault".into(), Value::String(f.0)));
                    let entry = json!([class, message, row.map(|r| r.id.clone()), row.map(|r| r.data.clone())]);
                    seen.lock().unwrap_or_else(|e| e.into_inner()).push(entry);
                });
            }
            *source.stop.lock().unwrap_or_else(|e| e.into_inner()) = Some(scheduler.stopper());
            session.handle(Instance { scheduler, store, clock, telemetry, errors })
        }
        "scheduler.reconcile" => {
            let full = a.opt_bool("full")?.unwrap_or(false);
            session.get_mut::<Instance>(a.value("scheduler")?)?.scheduler.reconcile(full).await?;
            Value::Null
        }
        "scheduler.tick" => {
            let summary = a.opt_bool("summary")?.unwrap_or(false);
            let due = session.get_mut::<Instance>(a.value("scheduler")?)?.scheduler.tick().await?;
            occurrences(&due, summary)
        }
        "scheduler.commit" => {
            Value::Bool(session.get_mut::<Instance>(a.value("scheduler")?)?.scheduler.commit().await?)
        }
        "scheduler.count" => Value::from(session.get::<Instance>(a.value("scheduler")?)?.scheduler.count()),
        "scheduler.is_current" => {
            let occurrence = Occurrence::new(a.str("id")?, moment(a.value("due")?)?, Value::Null)
                .with_version(a.opt_str("version")?.unwrap_or_default());
            Value::Bool(session.get::<Instance>(a.value("scheduler")?)?.scheduler.is_current(&occurrence))
        }
        "scheduler.run" => {
            let instance = session.get_mut::<Instance>(a.value("scheduler")?)?;
            let (clock, store, stop) = (instance.clock.clone(), instance.store.clone(), instance.scheduler.stopper());
            let batches = Arc::new(Mutex::new(Vec::new()));
            let seen = Arc::new(AtomicUsize::new(0));
            let throw = a.opt("throw").cloned();
            let never = a.opt_bool("never")?.unwrap_or(false);
            let (stop_after, stop_batches) = (a.opt_i64("stop_after")?, a.opt_i64("stop_batches")?);
            let result = instance
                .scheduler
                .run(|due: Vec<Occurrence<Value>>| {
                    let (clock, store, stop, batches, seen, throw) =
                        (clock.clone(), store.clone(), stop.clone(), batches.clone(), seen.clone(), throw.clone());
                    async move {
                        let at = text(clock.now()?.format(FORMAT));
                        let covered =
                            store.load().await?.and_then(|c| c.covered_until).map_or(Value::Null, float_value);
                        let count = {
                            let mut batches = batches.lock().unwrap_or_else(|e| e.into_inner());
                            batches
                                .push(json!({"at": at, "covered": covered, "occurrences": occurrences(&due, false)}));
                            batches.len() as i64
                        };
                        let total = (seen.fetch_add(due.len(), Ordering::SeqCst) + due.len()) as i64;
                        if let Some(throw) = throw {
                            return Err(scripted(&throw));
                        }
                        if never {
                            return Ok(());
                        }
                        if stop_after.is_some_and(|n| total >= n)
                            || stop_batches.is_some_and(|n| count >= n)
                            || (stop_after.is_none() && stop_batches.is_none())
                        {
                            stop.stop();
                        }
                        Ok::<(), BoxError>(())
                    }
                })
                .await;
            result?;
            Value::Array(batches.lock().unwrap_or_else(|e| e.into_inner()).clone())
        }
        "scheduler.stop" => {
            session.get::<Instance>(a.value("scheduler")?)?.scheduler.stop();
            Value::Null
        }
        "scheduler.errors" => Value::Array(
            session.get::<Instance>(a.value("scheduler")?)?.errors.lock().unwrap_or_else(|e| e.into_inner()).clone(),
        ),
        "scheduler.telemetry" => telemetry(session.get::<Instance>(a.value("scheduler")?)?.telemetry.as_ref()),

        "occurrence.key" => Value::String(Occurrence::new(a.str("id")?, moment(a.value("due")?)?, Value::Null).key()),
        _ => return Err(Fail::Fault(Fault::new(format!("schedule: unknown operation `{op}`")))),
    })
}
