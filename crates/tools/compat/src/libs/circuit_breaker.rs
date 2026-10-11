//! Compat adapter for `circuit-breaker`: maps
//! `tests/compat/circuit-breaker/spec.json` operations onto
//! `utopia-circuit-breaker`.

use std::borrow::Cow;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use redis::aio::ConnectionManager;
use serde_json::{Value as Json, json};
use utopia_circuit_breaker::{
    Adapter as Store, CircuitBreaker, Error, Failure, Options, Redis, SharedTable, State, Stored, Table,
};
use utopia_telemetry::{
    Attributes, BoxFuture, Counter, Gauge, Histogram, Kind, MemoryTelemetry, Number, ObservableGauge, Record,
    Telemetry, UpDownCounter, Value,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, float_value};

pub const OPS: &[&str] = &[
    "store.array",
    "store.writes",
    "store.table",
    "store.swoole_table",
    "store.redis",
    "store.get",
    "store.set",
    "store.increment",
    "store.delete",
    "telemetry.new",
    "telemetry.log",
    "telemetry.observe",
    "telemetry.callbacks",
    "breaker.new",
    "breaker.discarded",
    "breaker.set_telemetry",
    "breaker.call",
    "breaker.state",
    "breaker.is",
    "breaker.failures",
    "breaker.successes",
    "breaker.trip",
    "breaker.calls",
    "breaker.scenario",
    "sleep",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("call_closed", "A successful call through a closed in-process breaker"),
    ("call_open", "A call short-circuited by an open in-process breaker"),
    ("call_telemetry", "A successful call through a closed breaker recording telemetry"),
    ("state", "Read the state of an in-process breaker"),
];

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    let breaker = Rc::new(CircuitBreaker::new(Options::default()).map_err(|e| Fault::new(e.to_string()))?);
    let call = |breaker: Rc<CircuitBreaker>| {
        Bench::future(move || {
            let breaker = breaker.clone();
            async move {
                black_box(breaker.call(|| "fallback", async { Ok::<_, &str>("ok") }).await.ok());
            }
        })
    };
    Ok(match name {
        "call_closed" => call(breaker),
        "call_open" => {
            breaker.trip().await.map_err(|e| Fault::new(e.to_string()))?;
            call(breaker)
        }
        "call_telemetry" => {
            breaker.set_telemetry(Arc::new(MemoryTelemetry::new()));
            call(breaker)
        }
        "state" => Bench::future(move || {
            let breaker = breaker.clone();
            async move {
                black_box(breaker.state().await.ok());
            }
        }),
        _ => return Err(Fault::new(format!("circuit-breaker: no benchmark `{name}`"))),
    })
}

/// The tests' array adapter: values in memory, every write logged.
#[derive(Default)]
struct ArrayStore {
    values: Mutex<HashMap<String, Stored>>,
    writes: Mutex<Vec<Json>>,
}

fn stored_json(v: &Stored) -> Json {
    match v {
        Stored::Int(i) => json!(i),
        Stored::Str(s) => json!(s),
    }
}

fn stored(v: &Json) -> Result<Stored, Fault> {
    match v {
        Json::String(s) => Ok(Stored::Str(s.clone())),
        Json::Number(n) => n.as_i64().map(Stored::Int).ok_or_else(|| Fault::new("not an int")),
        _ => Err(Fault::new("int|string")),
    }
}

impl Store for ArrayStore {
    fn get<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<Option<Stored>, Error>> {
        let value = self.values.lock().unwrap_or_else(|e| e.into_inner()).get(key).cloned();
        Box::pin(async move { Ok(value) })
    }

    fn set<'a>(&'a self, key: &'a str, value: Stored) -> BoxFuture<'a, Result<(), Error>> {
        self.writes.lock().unwrap_or_else(|e| e.into_inner()).push(json!(["set", key, stored_json(&value)]));
        self.values.lock().unwrap_or_else(|e| e.into_inner()).insert(key.to_owned(), value);
        Box::pin(async { Ok(()) })
    }

    fn increment<'a>(&'a self, key: &'a str, by: i64) -> BoxFuture<'a, Result<i64, Error>> {
        let mut values = self.values.lock().unwrap_or_else(|e| e.into_inner());
        let current = match values.get(key) {
            Some(Stored::Int(i)) => *i,
            Some(Stored::Str(s)) => php_std::format::Arg::Str(s.as_bytes()).to_int(),
            None => 0,
        };
        let next = current.wrapping_add(by);
        values.insert(key.to_owned(), Stored::Int(next));
        self.writes.lock().unwrap_or_else(|e| e.into_inner()).push(json!(["increment", key, by]));
        Box::pin(async move { Ok(next) })
    }

    fn delete<'a>(&'a self, key: &'a str) -> BoxFuture<'a, Result<(), Error>> {
        self.writes.lock().unwrap_or_else(|e| e.into_inner()).push(json!(["delete", key, null]));
        self.values.lock().unwrap_or_else(|e| e.into_inner()).remove(key);
        Box::pin(async { Ok(()) })
    }
}

/// The Test backend, also logging every counter and gauge data point
/// (gauge values, timestamps, as 0), as `RecordingTelemetry` in Adapter.php.
#[derive(Default)]
struct Recording {
    inner: MemoryTelemetry,
    log: Arc<Mutex<Vec<Json>>>,
}

struct Logged {
    kind: &'static str,
    name: String,
    inner: Arc<dyn Fn(Number, Attributes<'_>) + Send + Sync>,
    log: Arc<Mutex<Vec<Json>>>,
}

fn value_json(v: &Value<'_>) -> Json {
    match v {
        Value::Str(s) => json!(s),
        Value::Int(i) => json!(i),
        Value::Float(f) => float_value(*f),
        Value::Bool(b) => json!(b),
    }
}

fn number_json(n: Number) -> Json {
    match n {
        Number::Int(i) => json!(i),
        Number::Float(f) => float_value(f),
    }
}

impl Record for Logged {
    fn record(&self, value: Number, attributes: Attributes<'_>) {
        (self.inner)(value, attributes);
        let pairs: Vec<Json> = attributes.iter().map(|(k, v)| json!([k, value_json(v)])).collect();
        let value = if self.kind == "gauge" { json!(0) } else { number_json(value) };
        self.log.lock().unwrap_or_else(|e| e.into_inner()).push(json!([self.kind, self.name, value, pairs]));
    }
}

impl Telemetry for Recording {
    fn counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Counter {
        let inner = self.inner.counter(name, unit, description);
        Counter::new(Arc::new(Logged {
            kind: "counter",
            name: name.to_owned(),
            inner: Arc::new(move |v, a| inner.add(v, a)),
            log: self.log.clone(),
        }))
    }

    fn up_down_counter(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> UpDownCounter {
        self.inner.up_down_counter(name, unit, description)
    }

    fn histogram(&self, name: &str, unit: Option<&str>, description: Option<&str>, b: Option<&[f64]>) -> Histogram {
        self.inner.histogram(name, unit, description, b)
    }

    fn gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> Gauge {
        let inner = self.inner.gauge(name, unit, description);
        Gauge::new(Arc::new(Logged {
            kind: "gauge",
            name: name.to_owned(),
            inner: Arc::new(move |v, a| inner.record(v, a)),
            log: self.log.clone(),
        }))
    }

    fn observable_gauge(&self, name: &str, unit: Option<&str>, description: Option<&str>) -> ObservableGauge {
        self.inner.observable_gauge(name, unit, description)
    }

    fn collect(&self) -> BoxFuture<'_, bool> {
        self.inner.collect()
    }
}

/// A callback's failure, reported as PHP's `RuntimeException`.
struct RuntimeException;

impl Failure for RuntimeException {
    fn exception_type(&self) -> Cow<'_, str> {
        Cow::Borrowed("RuntimeException")
    }
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn fault(e: Error) -> Fault {
    Fault::new(format!("{}: {e}", e.php_class()))
}

async fn observe(t: &Recording) -> Json {
    let mut out = Vec::new();
    for name in t.inner.names(Kind::ObservableGauge) {
        let observed = t.inner.observe(&name).await.unwrap_or_default();
        let series: Vec<Json> = observed
            .iter()
            .map(|o| {
                let breaker =
                    o.attributes.iter().find(|(k, _)| k == "circuit_breaker.name").map(|(_, v)| value_json(v));
                json!([breaker, number_json(o.value)])
            })
            .collect();
        out.push(json!([name, series]));
    }
    Json::Array(out)
}

fn options(a: &Args<'_>) -> Result<Options, Fault> {
    let mut o = Options::default();
    if let Some(v) = a.opt_i64("timeout")? {
        o.timeout = v;
    }
    if let Some(v) = a.opt_i64("success_threshold")? {
        o.success_threshold = v;
    }
    if let Some(v) = a.opt_str("key")? {
        o.key = v.to_owned();
    }
    if let Some(v) = a.opt_str("metric_prefix")? {
        o.metric_prefix = v.to_owned();
    }
    if let Some(v) = a.opt_i64("window")? {
        o.window = v;
    }
    if let Some(v) = a.opt_f64("failure_ratio")? {
        o.failure_ratio = v;
    }
    if let Some(v) = a.opt_i64("minimum_throughput")? {
        o.minimum_throughput = v;
    }
    if let Some(v) = a.opt_i64("half_open_permitted_calls")? {
        o.half_open_permitted_calls = v;
    }
    Ok(o)
}

fn build(a: &Args<'_>, session: &Session) -> Result<Result<CircuitBreaker, Error>, Fault> {
    let options = options(a)?;
    let breaker = match a.opt("store") {
        Some(h) => CircuitBreaker::with_cache(options, session.get::<Arc<dyn Store>>(h)?.clone()),
        None => CircuitBreaker::new(options),
    };
    if let (Ok(b), Some(t)) = (&breaker, a.opt("telemetry")) {
        b.set_telemetry(session.get::<Arc<Recording>>(t)?.clone());
    }
    Ok(breaker)
}

/// See `call()` in Adapter.php.
fn run_call<'a>(
    breaker: Arc<CircuitBreaker>,
    spec: Json,
    session: &'a Session,
) -> std::pin::Pin<Box<dyn std::future::Future<Output = Result<Json, Fault>> + 'a>> {
    Box::pin(async move {
        let during: Arc<Mutex<Vec<Json>>> = Arc::default();
        let callback = |spec: Json| {
            let (during, breaker) = (during.clone(), breaker.clone());
            async move {
                for action in spec["during"].as_array().cloned().unwrap_or_default() {
                    let a = Args(&action);
                    let value = match a.str("op").map_err(|_| RuntimeException)? {
                        "call" => {
                            let target = match a.opt("breaker") {
                                Some(h) => session.get::<Arc<CircuitBreaker>>(h).map_err(|_| RuntimeException)?.clone(),
                                None => breaker.clone(),
                            };
                            run_call(target, action.clone(), session).await.map_err(|_| RuntimeException)?
                        }
                        "state" => json!(breaker.state().await.map_err(|_| RuntimeException)?.as_str()),
                        "observe" => {
                            let t = session.get::<Arc<Recording>>(a.value("telemetry").map_err(|_| RuntimeException)?);
                            observe(t.map_err(|_| RuntimeException)?).await
                        }
                        _ => return Err(RuntimeException),
                    };
                    during.lock().unwrap_or_else(|e| e.into_inner()).push(value);
                }
                if spec.get("throw").is_some() {
                    return Err(RuntimeException);
                }
                Ok(spec.get("result").cloned().unwrap_or(Json::Null))
            }
        };
        let fallback = spec.get("fallback").cloned().unwrap_or_else(|| json!("fallback"));
        let close = callback(spec.get("close").cloned().unwrap_or_else(|| json!({"result": "closed"})));
        let result = match spec.get("half_open") {
            Some(probe) => breaker.call_with_probe(|| fallback, close, callback(probe.clone())).await,
            None => breaker.call(|| fallback, close).await,
        };
        let result = match result {
            Ok(v) => v,
            Err(e) => err(e).to_json(),
        };
        let during = during.lock().unwrap_or_else(|e| e.into_inner()).clone();
        Ok(if during.is_empty() { result } else { json!({ "result": result, "during": during }) })
    })
}

pub async fn call_op(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    let store = |s: &Session| -> Result<Arc<dyn Store>, Fault> { s.get::<Arc<dyn Store>>(a.value("store")?).cloned() };
    let breaker = |s: &Session| -> Result<Arc<CircuitBreaker>, Fault> {
        s.get::<Arc<CircuitBreaker>>(a.value("breaker")?).cloned()
    };
    let telemetry =
        |s: &Session| -> Result<Arc<Recording>, Fault> { s.get::<Arc<Recording>>(a.value("telemetry")?).cloned() };
    let result = match op {
        "store.array" => {
            let s = ArrayStore::default();
            if let Some(Json::Array(pairs)) = a.opt("values") {
                let mut values = s.values.lock().unwrap_or_else(|e| e.into_inner());
                for pair in pairs {
                    let (Some(k), Some(v)) = (pair[0].as_str(), pair.get(1)) else { continue };
                    let v =
                        if v == "$opened_ago_10" { Stored::Int(php_std::datetime::time() - 10) } else { stored(v)? };
                    values.insert(k.to_owned(), v);
                }
            }
            let s: Arc<ArrayStore> = Arc::new(s);
            session.handle(s.clone());
            Outcome::Ok(session.handle::<Arc<dyn Store>>(s))
        }
        "store.writes" => {
            // The array store was handled twice: as itself (one handle
            // before) and as a store; find it by the store handle.
            let h = a.value("store")?;
            let id = h["$handle"].as_str().unwrap_or_default();
            let prev = id
                .strip_prefix('h')
                .and_then(|n| n.parse::<u64>().ok())
                .map(|n| json!({"$handle": format!("h{}", n - 1)}));
            let s = session.get::<Arc<ArrayStore>>(&prev.unwrap_or(Json::Null))?;
            Outcome::Ok(Json::Array(s.writes.lock().unwrap_or_else(|e| e.into_inner()).clone()))
        }
        "store.table" => Outcome::Ok(session.handle(SharedTable::new(
            a.opt_i64("size")?.unwrap_or(1024).max(0) as usize,
            a.opt_i64("value_length")?.unwrap_or(255).max(0) as usize,
        ))),
        "store.swoole_table" => {
            let table = session.get::<SharedTable>(a.value("table")?)?.clone();
            let s: Arc<dyn Store> = Arc::new(Table::new(table, a.opt_str("prefix")?.unwrap_or("breaker:")));
            Outcome::Ok(session.handle(s))
        }
        "store.redis" => {
            let url = session.service("redis")?.to_owned();
            let c: ConnectionManager = redis::Client::open(url.as_str())
                .map_err(|e| Fault::new(e.to_string()))?
                .get_connection_manager()
                .await
                .map_err(|e| Fault::new(e.to_string()))?;
            let s: Arc<dyn Store> = Arc::new(Redis::new(c, a.opt_str("prefix")?.unwrap_or("breaker:")));
            Outcome::Ok(session.handle(s))
        }
        "store.get" => match store(session)?.get(a.str("key")?).await {
            Ok(v) => Outcome::Ok(v.as_ref().map_or(Json::Null, stored_json)),
            Err(e) => err(e),
        },
        "store.set" => match store(session)?.set(a.str("key")?, stored(a.value("value")?)?).await {
            Ok(()) => Outcome::Ok(Json::Null),
            Err(e) => err(e),
        },
        "store.increment" => match store(session)?.increment(a.str("key")?, a.opt_i64("by")?.unwrap_or(1)).await {
            Ok(v) => Outcome::ok(v),
            Err(e) => err(e),
        },
        "store.delete" => match store(session)?.delete(a.str("key")?).await {
            Ok(()) => Outcome::Ok(Json::Null),
            Err(e) => err(e),
        },
        "telemetry.new" => Outcome::Ok(session.handle(Arc::new(Recording::default()))),
        "telemetry.log" => {
            Outcome::Ok(Json::Array(telemetry(session)?.log.lock().unwrap_or_else(|e| e.into_inner()).clone()))
        }
        "telemetry.observe" => Outcome::Ok(observe(&*telemetry(session)?).await),
        "telemetry.callbacks" => {
            let t = telemetry(session)?;
            Outcome::Ok(Json::Array(
                t.inner
                    .names(Kind::ObservableGauge)
                    .into_iter()
                    .map(|n| json!([n, t.inner.callbacks(&n).unwrap_or(0)]))
                    .collect(),
            ))
        }
        "breaker.new" => match build(&a, session)? {
            Ok(b) => Outcome::Ok(session.handle(Arc::new(b))),
            Err(e) => err(e),
        },
        "breaker.discarded" => match build(&a, session)? {
            Ok(b) => {
                let b = Arc::new(b);
                let weak = Arc::downgrade(&b);
                drop(b);
                Outcome::ok(weak.upgrade().is_none())
            }
            Err(e) => err(e),
        },
        "breaker.set_telemetry" => {
            breaker(session)?.set_telemetry(telemetry(session)?);
            Outcome::Ok(Json::Null)
        }
        "breaker.call" => Outcome::Ok(run_call(breaker(session)?, args.clone(), session).await?),
        "breaker.state" => match breaker(session)?.state().await {
            Ok(s) => Outcome::ok(s.as_str()),
            Err(e) => err(e),
        },
        "breaker.is" => {
            let b = breaker(session)?;
            let (open, closed, half) = (b.state().await, b.state().await, b.state().await);
            match (open, closed, half) {
                (Ok(o), Ok(c), Ok(h)) => Outcome::Ok(json!({
                    "open": o == State::Open,
                    "closed": c == State::Closed,
                    "half_open": h == State::HalfOpen,
                })),
                (Err(e), _, _) | (_, Err(e), _) | (_, _, Err(e)) => err(e),
            }
        }
        "breaker.failures" => match breaker(session)?.failures().await {
            Ok(n) => Outcome::ok(n),
            Err(e) => err(e),
        },
        "breaker.successes" => match breaker(session)?.successes().await {
            Ok(n) => Outcome::ok(n),
            Err(e) => err(e),
        },
        "breaker.trip" => match breaker(session)?.trip().await {
            Ok(()) => Outcome::Ok(Json::Null),
            Err(e) => err(e),
        },
        "breaker.scenario" => {
            let b = match build(&a, session)? {
                Ok(b) => b,
                Err(e) => return Ok(err(e)),
            };
            let mut out = Vec::new();
            for step in a.array("steps")? {
                let result = match step.as_str().unwrap_or("") {
                    "trip" => b.trip().await.map(|()| Json::Null),
                    "probe" => {
                        b.call_with_probe(
                            || json!("fallback"),
                            async { Ok::<_, RuntimeException>(json!("closed")) },
                            async { Ok(json!("probe")) },
                        )
                        .await
                    }
                    "fail" => b.call(|| json!("fallback"), async { Err::<Json, _>(RuntimeException) }).await,
                    _ => b.call(|| json!("fallback"), async { Ok::<_, RuntimeException>(json!("ok")) }).await,
                }
                .map_err(fault)?;
                let state = b.state().await.map_err(fault)?;
                let failures = b.failures().await.map_err(fault)?;
                let successes = b.successes().await.map_err(fault)?;
                out.push(json!([result, state.as_str(), failures, successes]));
            }
            Outcome::Ok(Json::Array(out))
        }
        "breaker.calls" => {
            let b = breaker(session)?;
            let mut counts: Vec<(String, i64)> = Vec::new();
            for outcome in a.array("outcomes")? {
                let fail = outcome.as_str() == Some("fail");
                let value = b
                    .call(|| "fallback", async move { if fail { Err(RuntimeException) } else { Ok("ok") } })
                    .await
                    .map_err(fault)?;
                match counts.iter_mut().find(|(k, _)| k == value) {
                    Some((_, n)) => *n += 1,
                    None => counts.push((value.to_owned(), 1)),
                }
            }
            Outcome::Ok(Json::Object(counts.into_iter().map(|(k, n)| (k, json!(n))).collect()))
        }
        "sleep" => {
            tokio::time::sleep(std::time::Duration::from_secs_f64(a.f64("ms")? / 1000.0)).await;
            Outcome::Ok(Json::Null)
        }
        _ => return Err(Fault::new(format!("circuit-breaker: unknown operation `{op}`"))),
    };
    Ok(result)
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    call_op(op, args, session).await
}
