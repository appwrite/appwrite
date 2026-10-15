//! Compat adapter for `pools`: maps `tests/compat/pools/spec.json`
//! operations onto `utopia-pools`. Resources are made from the `init`
//! creation behaviours described in Adapter.php.

use std::sync::atomic::{AtomicI64, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use serde_json::{Value as Json, json};
use utopia_pools::{Adapter as PoolAdapter, BoxError, Channel, Connection, Error, Group, Pool, Resource, Stack};
use utopia_telemetry::{Kind, MemoryTelemetry, NoTelemetry, Telemetry, Value};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, float_value};

pub const OPS: &[&str] = &[
    "pool.new",
    "pool.info",
    "pool.pop",
    "pool.push",
    "pool.reclaim",
    "pool.destroy",
    "pool.release",
    "pool.maintain",
    "pool.counts",
    "pool.use",
    "connection.get",
    "connection.reclaim",
    "connection.destroy",
    "connection.orphan",
    "pool.dropped_frees",
    "adapter.new",
    "adapter.push",
    "adapter.pop",
    "adapter.count",
    "adapter.unblock",
    "adapter.synchronized",
    "group.new",
    "group.add",
    "group.get",
    "group.remove",
    "group.reclaim",
    "group.use",
    "telemetry.new",
    "telemetry.dump",
    "telemetry.observe",
    "pool.concurrency",
    "waiter.handoff",
    "waiter.discard",
    "waiter.creation",
    "waiter.deadline",
    "waiter.capacity",
    "pool.pop_elapsed",
    "pool.scenario",
];

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("pop_reclaim_stack", "Pop and reclaim a connection of a Stack pool of 8"),
    ("pop_reclaim_channel", "Pop and reclaim a connection of a waiting (Swoole) pool of 8, 100 times in one coroutine"),
    ("use_stack", "Run a callback with a connection of a Stack pool of 8"),
    ("pop_reclaim_telemetry", "Pop and reclaim a connection of a Stack pool recording telemetry"),
];

fn plain() -> impl Fn() -> std::future::Ready<Result<String, std::convert::Infallible>> + Send + Sync + 'static {
    || std::future::ready(Ok("x".to_owned()))
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;

    use crate::bench::Bench;

    let f = |e: Error| Fault::new(e.to_string());
    Ok(match name {
        "pop_reclaim_stack" => {
            let pool = Pool::new(Stack::new(), "bench", 8, plain(), 1.0).map_err(f)?;
            Bench::future(move || {
                let pool = pool.clone();
                async move {
                    if let Ok(c) = pool.pop().await {
                        pool.reclaim(black_box(&c));
                    }
                }
            })
        }
        "pop_reclaim_channel" => {
            let pool = Pool::new(Channel::new(), "bench", 8, plain(), 1.0).map_err(f)?;
            Bench::future(move || {
                let pool = pool.clone();
                async move {
                    for _ in 0..100 {
                        if let Ok(c) = pool.pop().await {
                            pool.reclaim(black_box(&c));
                        }
                    }
                }
            })
        }
        "use_stack" => {
            let pool = Pool::new(Stack::new(), "bench", 8, plain(), 1.0).map_err(f)?;
            Bench::future(move || {
                let pool = pool.clone();
                async move {
                    black_box(pool.with(async |r: &String| Ok::<_, Error>(r.len())).await.ok());
                }
            })
        }
        "pop_reclaim_telemetry" => {
            let pool =
                Pool::with_telemetry(Stack::new(), "bench", 8, plain(), 1.0, &MemoryTelemetry::new()).map_err(f)?;
            Bench::future(move || {
                let pool = pool.clone();
                async move {
                    if let Ok(c) = pool.pop().await {
                        pool.reclaim(black_box(&c));
                    }
                }
            })
        }
        _ => return Err(Fault::new(format!("pools: no benchmark `{name}`"))),
    })
}

/// A failure thrown by a fixture: the PHP class and message it stands for.
#[derive(Debug)]
struct Thrown {
    class: String,
    message: String,
}

impl std::fmt::Display for Thrown {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(&self.message)
    }
}

impl std::error::Error for Thrown {}

#[derive(Debug, Clone, Copy)]
enum Reconnect {
    Yes,
    No,
    Throw,
    Void,
}

/// The resources `init` makes (see Adapter.php).
enum Res {
    Plain(String),
    Object { name: String, reconnect: Option<Reconnect> },
    Ticking { serial: i64, ticks: AtomicI64, throws: bool },
    Stream(String),
    Tracked(Arc<AtomicUsize>),
}

impl Drop for Res {
    fn drop(&mut self) {
        if let Res::Tracked(freed) = self {
            freed.fetch_add(1, Ordering::SeqCst);
        }
    }
}

impl Resource for Res {
    async fn recover(&self) -> bool {
        match self {
            Res::Plain(_) => true,
            Res::Object { reconnect, .. } => matches!(reconnect, Some(Reconnect::Yes | Reconnect::Void)),
            _ => false,
        }
    }

    async fn tick(&self) -> Result<(), BoxError> {
        if let Res::Ticking { ticks, throws, .. } = self {
            ticks.fetch_add(1, Ordering::SeqCst);
            if *throws {
                return Err("server closed the connection".into());
            }
        }
        Ok(())
    }
}

fn describe(r: &Res) -> Json {
    match r {
        Res::Plain(s) => json!(s),
        Res::Object { name, .. } => json!({ "object": name }),
        Res::Ticking { serial, ticks, .. } => json!({ "serial": serial, "ticks": ticks.load(Ordering::SeqCst) }),
        Res::Stream(s) => json!({ "stream": s }),
        Res::Tracked(_) => json!({}),
    }
}

type Init = Arc<dyn Fn() -> utopia_telemetry::BoxFuture<'static, Result<Res, Thrown>> + Send + Sync>;

fn init(specs: Vec<Json>) -> Init {
    let created = Arc::new(AtomicUsize::new(0));
    Arc::new(move || {
        let n = created.fetch_add(1, Ordering::SeqCst) + 1;
        let spec = specs[n.min(specs.len()) - 1].clone();
        Box::pin(async move {
            if let Some(ms) = spec["delay_ms"].as_u64() {
                utopia_async::sleep(Duration::from_millis(ms)).await;
            }
            if let Some(message) = spec["throw"].as_str() {
                let class = spec["class"].as_str().unwrap_or("Exception").to_owned();
                return Err(Thrown { class, message: message.to_owned() });
            }
            if let Some(object) = spec["object"].as_str() {
                let reconnect = spec.get("reconnect").map(|r| match r {
                    Json::Bool(true) => Reconnect::Yes,
                    Json::String(s) if s == "throw" => Reconnect::Throw,
                    Json::String(s) if s == "void" => Reconnect::Void,
                    _ => Reconnect::No,
                });
                return Ok(Res::Object { name: format!("{object}-{n}"), reconnect });
            }
            if spec.get("ticking").is_some() {
                let throws = spec["tick_throws"].as_bool().unwrap_or(false);
                return Ok(Res::Ticking { serial: n as i64, ticks: AtomicI64::new(0), throws });
            }
            if let Some(text) = spec["stream"].as_str() {
                return Ok(Res::Stream(format!("{text}-{n}")));
            }
            Ok(Res::Plain(spec["value"].as_str().unwrap_or("").to_owned()))
        })
    })
}

#[derive(Clone)]
enum AnyPool {
    Stack(Pool<Res, Stack<Res>>),
    Channel(Pool<Res, Channel<Res>>),
}

macro_rules! each {
    ($pool:expr, $p:ident => $body:expr) => {
        match $pool {
            AnyPool::Stack($p) => $body,
            AnyPool::Channel($p) => $body,
        }
    };
}

fn make<A: PoolAdapter<Res>>(
    adapter: A,
    name: &str,
    size: usize,
    init: Init,
    timeout: f64,
    telemetry: &dyn Telemetry,
) -> Result<Pool<Res, A>, Error> {
    Pool::with_telemetry(adapter, name, size, move || init(), timeout, telemetry)
}

fn error(e: &Error) -> Outcome {
    match e {
        Error::Init(inner) => match inner.downcast_ref::<Thrown>() {
            Some(t) => Outcome::err(t.class.clone(), t.message.clone()),
            None => Outcome::err("Exception", inner.to_string()),
        },
        e => Outcome::err(e.php_class(), e.to_string()),
    }
}

/// A `use` callback's failure: a pool error or a thrown fixture.
enum Failed {
    Pool(Error),
    Thrown(Thrown),
}

impl From<Error> for Failed {
    fn from(e: Error) -> Self {
        Failed::Pool(e)
    }
}

fn failed(f: Failed) -> Outcome {
    match f {
        Failed::Pool(e) => error(&e),
        Failed::Thrown(t) => Outcome::err(t.class, t.message),
    }
}

fn counts(p: &AnyPool) -> Json {
    each!(p, p => json!({ "count": p.count(), "empty": p.is_empty(), "full": p.is_full() }))
}

/// See `work()` in Adapter.php.
async fn work(resources: Vec<&Res>, a: &Args<'_>, s: &Session) -> Result<Json, Failed> {
    let mut during = Vec::new();
    for action in a.opt("during").and_then(Json::as_array).cloned().unwrap_or_default() {
        during.push(match action["op"].as_str() {
            Some("counts") => match s.get::<AnyPool>(&action["pool"]) {
                Ok(p) => counts(p),
                Err(_) => Json::Null,
            },
            Some("sleep") => {
                utopia_async::sleep(Duration::from_millis(action["ms"].as_u64().unwrap_or(0))).await;
                Json::Null
            }
            // PoolTestScope's failing lock: a Rust pool's lock cannot fail.
            _ => Json::Null,
        });
    }
    if let Some(t) = a.opt("throw") {
        return Err(Failed::Thrown(Thrown {
            class: t["class"].as_str().unwrap_or("RuntimeException").to_owned(),
            message: t["message"].as_str().unwrap_or("").to_owned(),
        }));
    }
    Ok(json!({ "resources": resources.into_iter().map(describe).collect::<Vec<_>>(), "during": during }))
}

#[derive(Clone)]
enum AnyAdapter {
    Stack(Arc<Stack<Res>>),
    Channel(Arc<Channel<Res>>),
}

enum AnyGroup {
    Stack(Group<Res, Stack<Res>>),
    Channel(Group<Res, Channel<Res>>),
}

fn new_pool(a: &Args<'_>, s: &Session) -> Result<Result<AnyPool, Error>, Fault> {
    let specs = a
        .opt("init")
        .and_then(Json::as_array)
        .filter(|s| !s.is_empty())
        .cloned()
        .unwrap_or_else(|| vec![json!({"value": "x"})]);
    let name = a.opt_str("name")?.unwrap_or("test");
    let size = a.opt_i64("size")?.unwrap_or(5).max(0) as usize;
    let timeout = a.opt_f64("timeout")?.unwrap_or(0.0);
    let memory;
    let telemetry: &dyn Telemetry = match a.opt("telemetry") {
        Some(h) => {
            memory = s.get::<MemoryTelemetry>(h)?.clone();
            &memory
        }
        None => &NoTelemetry,
    };
    let init = init(specs);
    Ok(match a.opt_str("adapter")?.unwrap_or("stack") {
        "swoole" => make(Channel::new(), name, size, init, timeout, telemetry).map(AnyPool::Channel),
        _ => make(Stack::new(), name, size, init, timeout, telemetry).map(AnyPool::Stack),
    })
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    let pool = |s: &Session| -> Result<AnyPool, Fault> { s.get::<AnyPool>(a.value("pool")?).cloned() };
    let connection =
        |s: &Session| -> Result<Connection<Res>, Fault> { s.get::<Connection<Res>>(a.value("connection")?).cloned() };
    let result = match op {
        "pool.new" => match new_pool(&a, session)? {
            Ok(p) => Outcome::Ok(session.handle(p)),
            Err(e) => error(&e),
        },
        "pool.info" => {
            let p = pool(session)?;
            each!(&p, p => Outcome::Ok(json!({ "name": p.name(), "size": p.size(), "timeout": float_value(p.timeout()) })))
        }
        "pool.pop" => match each!(&pool(session)?, p => p.pop().await) {
            Ok(c) => Outcome::Ok(session.handle(c)),
            Err(e) => error(&e),
        },
        "pool.push" => {
            let c = connection(session)?;
            each!(&pool(session)?, p => { p.push(&c); });
            Outcome::Ok(Json::Null)
        }
        "pool.reclaim" => {
            let c = a.opt("connection").map(|_| connection(session)).transpose()?;
            each!(&pool(session)?, p => match &c { Some(c) => { p.reclaim(c); } None => { p.reclaim_all(); } });
            Outcome::Ok(Json::Null)
        }
        "pool.destroy" => {
            let c = a.opt("connection").map(|_| connection(session)).transpose()?;
            each!(&pool(session)?, p => match &c { Some(c) => { p.destroy(c); } None => { p.destroy_all(); } });
            Outcome::Ok(Json::Null)
        }
        "pool.release" => {
            let c = connection(session)?;
            let failed = a.opt_bool("failed")?.unwrap_or(false);
            each!(&pool(session)?, p => { p.release(&c, failed).await; });
            Outcome::Ok(Json::Null)
        }
        "pool.maintain" => {
            each!(&pool(session)?, p => { p.maintain().await; });
            Outcome::Ok(Json::Null)
        }
        "pool.counts" => Outcome::Ok(counts(&pool(session)?)),
        "pool.use" => {
            let p = pool(session)?;
            let s: &Session = session;
            let result = each!(&p, p => p.with(async |r: &Res| work(vec![r], &a, s).await).await);
            match result {
                Ok(v) => Outcome::Ok(v),
                Err(f) => failed(f),
            }
        }
        "connection.get" => {
            let c = connection(session)?;
            let name = a.opt_str("pool_name")?.unwrap_or("");
            let id = c.id();
            Outcome::Ok(json!({
                "resource": describe(c.resource()),
                "id": { "prefixed": id.starts_with(&format!("{name}-")), "length": id.len() as i64 - name.len() as i64 - 1 },
            }))
        }
        "connection.reclaim" => {
            connection(session)?.reclaim();
            Outcome::Ok(Json::Null)
        }
        "connection.destroy" => {
            connection(session)?.destroy();
            Outcome::Ok(Json::Null)
        }
        "connection.orphan" => {
            let p = match new_pool(&a, session)? {
                Ok(p) => p,
                Err(e) => return Ok(error(&e)),
            };
            let c = match each!(&p, p => p.pop().await) {
                Ok(c) => c,
                Err(e) => return Ok(error(&e)),
            };
            drop(p);
            c.reclaim();
            c.destroy();
            Outcome::Ok(describe(c.resource()))
        }
        "pool.dropped_frees" => {
            let freed = Arc::new(AtomicUsize::new(0));
            {
                let f = freed.clone();
                let make = move || {
                    let f = f.clone();
                    async move { Ok::<_, std::convert::Infallible>(Res::Tracked(f)) }
                };
                let p = match a.str("adapter")? {
                    "swoole" => AnyPool::Channel(
                        Pool::new(Channel::new(), "lifetime", 3, make, 1.0).map_err(|e| Fault::new(e.to_string()))?,
                    ),
                    _ => AnyPool::Stack(
                        Pool::new(Stack::new(), "lifetime", 3, make, 1.0).map_err(|e| Fault::new(e.to_string()))?,
                    ),
                };
                each!(&p, p => {
                    let mut held = Vec::new();
                    for _ in 0..3 {
                        held.push(p.pop().await.map_err(|e| Fault::new(e.to_string()))?);
                    }
                    for c in &held {
                        c.reclaim();
                    }
                });
            }
            Outcome::ok(freed.load(Ordering::SeqCst))
        }
        "adapter.new" => {
            let size = a.opt_i64("size")?.unwrap_or(1).max(0) as usize;
            let adapter = match a.str("adapter")? {
                "swoole" => {
                    let mut c = Channel::new();
                    c.initialize(size);
                    AnyAdapter::Channel(Arc::new(c))
                }
                _ => {
                    let mut s = Stack::new();
                    s.initialize(size);
                    AnyAdapter::Stack(Arc::new(s))
                }
            };
            Outcome::Ok(session.handle(adapter))
        }
        "adapter.push" | "adapter.pop" | "adapter.count" | "adapter.unblock" | "adapter.synchronized" => {
            let adapter = session.get::<AnyAdapter>(a.value("adapter")?)?.clone();
            macro_rules! on {
                ($x:ident => $body:expr) => {
                    match &adapter {
                        AnyAdapter::Stack($x) => $body,
                        AnyAdapter::Channel($x) => $body,
                    }
                };
            }
            match op {
                "adapter.push" => {
                    let c = connection(session)?;
                    on!(x => x.push(c));
                    Outcome::Ok(Json::Null)
                }
                "adapter.pop" => {
                    let timeout = Duration::from_secs_f64(a.opt_f64("timeout")?.unwrap_or(0.0).max(0.0));
                    let c = on!(x => x.pop(timeout).await);
                    Outcome::Ok(c.map_or(Json::Null, |c| describe(c.resource())))
                }
                "adapter.count" => Outcome::ok(on!(x => x.count())),
                "adapter.unblock" => {
                    on!(x => x.unblock());
                    Outcome::Ok(Json::Null)
                }
                _ => {
                    let value = a.opt("value").cloned().unwrap_or(Json::Null);
                    Outcome::Ok(on!(x => x.synchronized(|| value)))
                }
            }
        }
        "group.new" => {
            Outcome::Ok(session.handle(Arc::new(Mutex::new(match a.opt_str("adapter")?.unwrap_or("stack") {
                "swoole" => AnyGroup::Channel(Group::new()),
                _ => AnyGroup::Stack(Group::new()),
            }))))
        }
        "group.add" | "group.get" | "group.remove" | "group.reclaim" | "group.use" => {
            let group = session.get::<Arc<Mutex<AnyGroup>>>(a.value("group")?)?.clone();
            match op {
                "group.add" => {
                    let p = pool(session)?;
                    match (&mut *group.lock().unwrap_or_else(|e| e.into_inner()), p) {
                        (AnyGroup::Stack(g), AnyPool::Stack(p)) => {
                            g.add(p);
                        }
                        (AnyGroup::Channel(g), AnyPool::Channel(p)) => {
                            g.add(p);
                        }
                        _ => return Err(Fault::new("a group holds pools of one adapter")),
                    }
                    Outcome::Ok(Json::Null)
                }
                "group.get" => {
                    let g = group.lock().unwrap_or_else(|e| e.into_inner());
                    let name = a.str("name")?;
                    let got = match &*g {
                        AnyGroup::Stack(g) => g.get(name).map(|p| p.name().to_owned()),
                        AnyGroup::Channel(g) => g.get(name).map(|p| p.name().to_owned()),
                    };
                    match got {
                        Ok(n) => Outcome::ok(n),
                        Err(e) => error(&e),
                    }
                }
                "group.remove" => {
                    match &mut *group.lock().unwrap_or_else(|e| e.into_inner()) {
                        AnyGroup::Stack(g) => {
                            g.remove(a.str("name")?);
                        }
                        AnyGroup::Channel(g) => {
                            g.remove(a.str("name")?);
                        }
                    }
                    Outcome::Ok(Json::Null)
                }
                "group.reclaim" => {
                    match &*group.lock().unwrap_or_else(|e| e.into_inner()) {
                        AnyGroup::Stack(g) => {
                            g.reclaim();
                        }
                        AnyGroup::Channel(g) => {
                            g.reclaim();
                        }
                    }
                    Outcome::Ok(Json::Null)
                }
                _ => {
                    let names: Vec<String> =
                        a.array("names")?.iter().filter_map(|n| n.as_str().map(str::to_owned)).collect();
                    let names: Vec<&str> = names.iter().map(String::as_str).collect();
                    let s: &Session = session;
                    // The group is used without holding its lock across the
                    // await: clone its pools into a fresh group.
                    let result = match &*group.lock().unwrap_or_else(|e| e.into_inner()) {
                        AnyGroup::Stack(g) => Err(clone_stack(g, &names)),
                        AnyGroup::Channel(g) => Ok(clone_channel(g, &names)),
                    };
                    let outcome = match result {
                        Err(g) => g.with(&names, async |r: Vec<&Res>| work(r, &a, s).await).await,
                        Ok(g) => g.with(&names, async |r: Vec<&Res>| work(r, &a, s).await).await,
                    };
                    match outcome {
                        Ok(v) => Outcome::Ok(v),
                        Err(f) => failed(f),
                    }
                }
            }
        }
        "telemetry.new" => Outcome::Ok(session.handle(MemoryTelemetry::new())),
        "telemetry.dump" => {
            let t = session.get::<MemoryTelemetry>(a.value("telemetry")?)?;
            Outcome::Ok(json!({
                "histograms": t.names(Kind::Histogram).into_iter().map(|n| { let c = t.values(Kind::Histogram, &n).map_or(0, |v| v.len()); json!([n, c]) }).collect::<Vec<_>>(),
                "observableGauges": t.names(Kind::ObservableGauge).into_iter().map(|n| { let c = t.callbacks(&n).unwrap_or(0); json!([n, c]) }).collect::<Vec<_>>(),
            }))
        }
        "telemetry.observe" => {
            let t = session.get::<MemoryTelemetry>(a.value("telemetry")?)?.clone();
            let observed = t.observe(a.str("gauge")?).await.unwrap_or_default();
            Outcome::Ok(Json::Array(
                observed
                    .iter()
                    .map(|o| {
                        let pairs: Vec<Json> = o
                            .attributes
                            .iter()
                            .map(|(k, v)| match v {
                                Value::Str(s) => json!([k, s]),
                                Value::Int(i) => json!([k, i]),
                                Value::Float(f) => json!([k, float_value(*f)]),
                                Value::Bool(b) => json!([k, b]),
                            })
                            .collect();
                        let value = match o.value {
                            utopia_telemetry::Number::Int(i) => json!(i),
                            utopia_telemetry::Number::Float(f) => float_value(f),
                        };
                        json!([value, pairs])
                    })
                    .collect(),
            ))
        }
        "pool.concurrency" => Outcome::Ok(concurrency(&a).await?),
        "waiter.handoff" => Outcome::Ok(waiters::handoff().await),
        "waiter.discard" => Outcome::Ok(waiters::discard().await),
        "waiter.creation" => Outcome::Ok(waiters::creation().await),
        "waiter.deadline" => Outcome::Ok(waiters::deadline().await),
        "waiter.capacity" => Outcome::Ok(waiters::capacity().await),
        "pool.scenario" => {
            let p = match new_pool(&a, session)? {
                Ok(p) => p,
                Err(e) => return Ok(error(&e)),
            };
            let mut held: Vec<Connection<Res>> = Vec::new();
            let mut out = Vec::new();
            for step in a.array("steps")? {
                let result = each!(&p, p => match step.as_str().unwrap_or("") {
                    "pop" => match p.pop().await {
                        Ok(c) => {
                            let d = describe(c.resource());
                            held.push(c);
                            d
                        }
                        Err(e) => error(&e).to_json(),
                    },
                    "reclaim" => { if let Some(c) = held.pop() { p.reclaim(&c); } Json::Null }
                    "destroy" => { if let Some(c) = held.pop() { p.destroy(&c); } Json::Null }
                    "release_ok" => { if let Some(c) = held.pop() { p.release(&c, false).await; } Json::Null }
                    "release_failed" => { if let Some(c) = held.pop() { p.release(&c, true).await; } Json::Null }
                    "reclaim_all" => { p.reclaim_all(); held.clear(); Json::Null }
                    "destroy_all" => { p.destroy_all(); held.clear(); Json::Null }
                    "maintain" => { p.maintain().await; Json::Null }
                    "use_ok" => match p.with(async |r: &Res| Ok::<_, Error>(describe(r))).await {
                        Ok(v) => v,
                        Err(e) => error(&e).to_json(),
                    },
                    _ => match p.with(async |_: &Res| Err::<Json, _>(Failed::Thrown(Thrown { class: "RuntimeException".into(), message: "failed".into() }))).await {
                        Ok(v) => v,
                        Err(f) => failed(f).to_json(),
                    },
                });
                out.push(json!([result, each!(&p, p => p.count())]));
            }
            Outcome::Ok(Json::Array(out))
        }
        "pool.pop_elapsed" => {
            let start = Instant::now();
            let result = each!(&pool(session)?, p => p.pop().await);
            let elapsed = start.elapsed().as_secs_f64();
            let error = match result {
                Ok(_) => Json::Null,
                Err(e) => error(&e).to_json(),
            };
            Outcome::Ok(json!({ "error": error, "within": elapsed >= a.f64("at_least")? && elapsed < a.f64("below")? }))
        }
        _ => return Err(Fault::new(format!("pools: unknown operation `{op}`"))),
    };
    Ok(result)
}

fn clone_stack(g: &Group<Res, Stack<Res>>, names: &[&str]) -> Group<Res, Stack<Res>> {
    let mut out = Group::new();
    for name in names {
        if let Ok(p) = g.get(name) {
            out.add(p.clone());
        }
    }
    out
}

fn clone_channel(g: &Group<Res, Channel<Res>>, names: &[&str]) -> Group<Res, Channel<Res>> {
    let mut out = Group::new();
    for name in names {
        if let Ok(p) = g.get(name) {
            out.add(p.clone());
        }
    }
    out
}

/// See `concurrency()` in Adapter.php.
async fn concurrency(a: &Args<'_>) -> Result<Json, Fault> {
    let created = Arc::new(AtomicUsize::new(0));
    let c = created.clone();
    let make = move || {
        let n = c.fetch_add(1, Ordering::SeqCst) + 1;
        async move { Ok::<_, std::convert::Infallible>(Res::Plain(format!("connection-{n}"))) }
    };
    let (name, size, timeout) =
        (a.opt_str("name")?.unwrap_or("concurrent"), a.i64("size")?.max(0) as usize, a.f64("timeout")?);
    let p = match a.str("adapter")? {
        "swoole" => AnyPool::Channel(
            Pool::new(Channel::new(), name, size, make, timeout).map_err(|e| Fault::new(e.to_string()))?,
        ),
        _ => AnyPool::Stack(Pool::new(Stack::new(), name, size, make, timeout).map_err(|e| Fault::new(e.to_string()))?),
    };
    let mode = a.str("mode")?.to_owned();
    let hold_ms = a.opt_f64("hold_ms")?.unwrap_or(0.0);
    let random = a.opt_bool("random_hold")?.unwrap_or(false);
    let (success, errors) = (AtomicUsize::new(0), AtomicUsize::new(0));
    let seen: Mutex<Vec<String>> = Mutex::new(Vec::new());
    let duplicates = AtomicUsize::new(0);
    let tasks = a.i64("tasks")?.max(0) as usize;
    let hold = |i: usize| {
        let ms = if random { (1 + (i * 7919) % 5) as f64 } else { hold_ms };
        utopia_async::sleep(Duration::from_secs_f64(ms / 1000.0))
    };
    let task = |i: usize| {
        let (p, mode) = (p.clone(), mode.clone());
        let (success, errors, seen, duplicates) = (&success, &errors, &seen, &duplicates);
        async move {
            let ok = each!(&p, p => {
                if mode == "use" {
                    p.with(async |_: &Res| { hold(i).await; Ok::<_, Error>(()) }).await.is_ok()
                } else {
                    match p.pop().await {
                        Ok(c) => {
                            let r = match c.resource() { Res::Plain(s) => s.clone(), _ => String::new() };
                            {
                                let mut s = seen.lock().unwrap_or_else(|e| e.into_inner());
                                if s.contains(&r) { duplicates.fetch_add(1, Ordering::SeqCst); } else { s.push(r); }
                            }
                            hold(i).await;
                            if mode == "pop_reclaim" { p.reclaim(&c); }
                            true
                        }
                        Err(_) => false,
                    }
                }
            });
            if ok {
                success.fetch_add(1, Ordering::SeqCst)
            } else {
                errors.fetch_add(1, Ordering::SeqCst)
            };
        }
    };
    futures_util::future::join_all((0..tasks).map(task)).await;
    let count = each!(&p, p => p.count());
    let distinct = seen.lock().unwrap_or_else(|e| e.into_inner()).len();
    Ok(json!({
        "success": success.load(Ordering::SeqCst),
        "errors": errors.load(Ordering::SeqCst),
        "created": created.load(Ordering::SeqCst),
        "count": count,
        "distinct": distinct,
        "duplicates": duplicates.load(Ordering::SeqCst),
    }))
}

/// Ports of WaiterTest (see `Waiters` in Adapter.php).
mod waiters {
    use std::sync::Arc;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::time::{Duration, Instant};

    use serde_json::{Value as Json, json};
    use utopia_pools::{Adapter as _, Channel, Error, Pool};
    use utopia_telemetry::{MemoryTelemetry, Number};

    /// A failure as `{class, message}`; results sorted by their JSON.
    fn results(mut out: Vec<Json>) -> Json {
        out.sort_by_key(|v| v.to_string());
        Json::Array(out)
    }

    fn runtime(message: &str) -> Json {
        json!({ "class": "RuntimeException", "message": message })
    }

    #[derive(Debug)]
    struct Failure(&'static str);

    impl From<Error> for Failure {
        fn from(_: Error) -> Self {
            Failure("pool")
        }
    }

    /// An object without recovery hooks (a `stdClass`): discarded after a failure.
    #[derive(Debug, PartialEq, Clone, Copy)]
    struct Obj(usize);

    impl utopia_pools::Resource for Obj {}

    fn counter() -> (
        Arc<AtomicUsize>,
        impl Fn() -> std::future::Ready<Result<Obj, std::convert::Infallible>> + Send + Sync + 'static,
    ) {
        let created = Arc::new(AtomicUsize::new(0));
        let c = created.clone();
        (created, move || std::future::ready(Ok(Obj(c.fetch_add(1, Ordering::SeqCst) + 1))))
    }

    pub async fn handoff() -> Json {
        let telemetry = MemoryTelemetry::new();
        let (created, make) = counter();
        let pool = Pool::with_telemetry(Channel::new(), "handoff", 1, make, 0.1, &telemetry).expect("pool");
        let identities = std::sync::Mutex::new(Vec::new());
        let sample = async || {
            let active =
                telemetry.observe("pool.connection.active.count").await.unwrap_or_default().last().map(|o| o.value);
            let active = match active {
                Some(Number::Int(i)) => json!(i),
                _ => Json::Null,
            };
            json!([active, pool.adapter().count(), pool.count()])
        };
        let first = pool.pop().await.expect("first");
        identities.lock().unwrap().push(*first.resource());
        let (entered_tx, entered_rx) = utopia_async::sync::oneshot::channel::<()>();
        let mut entered_tx = Some(entered_tx);
        let failing = async {
            pool.with(async |r: &Obj| {
                identities.lock().unwrap().push(*r);
                if let Some(tx) = entered_tx.take() {
                    let _ = tx.send(());
                }
                utopia_async::sleep(Duration::from_millis(20)).await;
                Err::<Json, _>(Failure("successor failed"))
            })
            .await
        };
        let replacement = async {
            pool.with(async |r: &Obj| {
                identities.lock().unwrap().push(*r);
                Ok::<_, Failure>(json!("replacement"))
            })
            .await
        };
        let main = async {
            utopia_async::yield_now().await;
            pool.reclaim(&first);
            let _ = utopia_async::timeout(Duration::from_secs(1), entered_rx).await;
            sample().await
        };
        let (a, b, snapshot) = utopia_async::join!(failing, replacement, main);
        let snapshots = vec![snapshot, sample().await];
        let out: Vec<Json> = [a, b]
            .into_iter()
            .map(|r| match r {
                Ok(v) => v,
                Err(Failure(m)) => runtime(m),
            })
            .collect();
        let ids = identities.lock().unwrap().clone();
        json!({
            "snapshots": snapshots,
            "results": results(out),
            "created": created.load(Ordering::SeqCst),
            "identities": ids.len(),
            "first_reused": ids.first() == ids.get(1),
            "replacement_new": ids.get(1) != ids.get(2),
        })
    }

    pub async fn discard() -> Json {
        let (created, make) = counter();
        let pool = Pool::new(Channel::new(), "discard", 1, make, 0.5).expect("pool");
        let failing = pool.with(async |_: &Obj| {
            utopia_async::sleep(Duration::from_millis(20)).await;
            Err::<Json, _>(Failure("request failed"))
        });
        let replacement = pool.with(async |_: &Obj| Ok::<_, Failure>(json!("replacement")));
        let (a, b) = utopia_async::join!(failing, replacement);
        let out = [a, b].into_iter().map(|r| r.unwrap_or_else(|Failure(m)| runtime(m))).collect();
        json!({ "results": results(out), "created": created.load(Ordering::SeqCst), "full": pool.is_full() })
    }

    pub async fn creation() -> Json {
        let created = Arc::new(AtomicUsize::new(0));
        let c = created.clone();
        let make = move || {
            let n = c.fetch_add(1, Ordering::SeqCst) + 1;
            async move {
                if n == 1 {
                    utopia_async::sleep(Duration::from_millis(20)).await;
                    return Err(super::Thrown {
                        class: "RuntimeException".into(),
                        message: "connection failed".into(),
                    });
                }
                Ok("replacement".to_owned())
            }
        };
        let pool = Pool::new(Channel::new(), "creation", 1, make, 0.5).expect("pool");
        let one = || async {
            match pool.with(async |v: &String| Ok::<_, Error>(json!(v))).await {
                Ok(v) => v,
                Err(Error::Init(e)) => runtime(&e.to_string()),
                Err(e) => json!({ "class": e.php_class(), "message": e.to_string() }),
            }
        };
        let (a, b) = utopia_async::join!(one(), one());
        json!({ "results": results(vec![a, b]), "created": created.load(Ordering::SeqCst), "full": pool.is_full() })
    }

    pub async fn deadline() -> Json {
        let pool =
            Pool::new(Channel::new(), "deadline", 1, || async { Ok::<_, std::convert::Infallible>("occupied") }, 0.04)
                .expect("pool");
        let connection = pool.pop().await.expect("first");
        let started = Instant::now();
        let unblocker = async {
            for _ in 0..10 {
                utopia_async::sleep(Duration::from_millis(10)).await;
                pool.adapter().unblock();
            }
        };
        let waiter = async {
            let r = pool.pop().await;
            (r, started.elapsed().as_secs_f64())
        };
        let ((result, elapsed), ()) = utopia_async::join!(waiter, unblocker);
        pool.reclaim(&connection);
        let failure = match result {
            Ok(_) => Json::Null,
            Err(e) => json!({ "class": e.php_class(), "message": e.to_string() }),
        };
        json!({ "failure": results(vec![failure]), "quick": elapsed < 0.1, "full": pool.is_full() })
    }

    pub async fn capacity() -> Json {
        let pool =
            Pool::new(Channel::new(), "capacity", 2, || async { Ok::<_, std::convert::Infallible>(Obj(0)) }, 0.5)
                .expect("pool");
        let active = AtomicUsize::new(0);
        let maximum = AtomicUsize::new(0);
        let task = || async {
            pool.with(async |_: &Obj| {
                let now = active.fetch_add(1, Ordering::SeqCst) + 1;
                maximum.fetch_max(now, Ordering::SeqCst);
                utopia_async::sleep(Duration::from_millis(2)).await;
                active.fetch_sub(1, Ordering::SeqCst);
                Err::<(), _>(Failure("discard"))
            })
            .await
        };
        let out: Vec<Json> = futures_util::future::join_all((0..8).map(|_| task()))
            .await
            .into_iter()
            .map(|r| match r {
                Ok(()) => Json::Null,
                Err(Failure(m)) => runtime(m),
            })
            .collect();
        json!({
            "results": results(out),
            "maximum": maximum.load(Ordering::SeqCst),
            "active": active.load(Ordering::SeqCst),
            "full": pool.is_full(),
        })
    }
}
