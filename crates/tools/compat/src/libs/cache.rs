//! Compat adapter for `cache`: maps `tests/compat/cache/spec.json` operations onto `utopia-cache`.

use std::collections::BTreeMap;
use std::future::Future;
use std::sync::Arc;
use std::sync::atomic::{AtomicI64, Ordering};

use php_std::types::Value;
use serde_json::{Map, Value as Json, json};
use utopia_cache::telemetry::{Kind, MemoryTelemetry, Telemetry};
use utopia_cache::{
    Adapter, AnyCodec, Cache, CircuitBreaker, Codec, DynAdapter, Envelope, Error, Fields, Filesystem, Hazelcast,
    Memcached, MemcachedClient, Memory, Multiplexing, MultiplexingOptions, None as NoneAdapter, Pool, Redis,
    RedisCluster, Retry, Sharding, resp,
};

use crate::adapter::{Args, Fault, OpResult, Outcome, Session, bytes, bytes_value};
use crate::libs::php_std::typed;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &[
    "memory.new",
    "none.new",
    "filesystem.new",
    "filesystem.path",
    "filesystem.stream",
    "redis.new",
    "memcached.new",
    "hazelcast.new",
    "cluster.new",
    "cluster.info",
    "sharding.new",
    "pool.new",
    "circuit_breaker.new",
    "failing.new",
    "counting_failing.new",
    "counting_failing.loads",
    "probe.new",
    "probe.calls",
    "null_load.new",
    "telemetry.new",
    "telemetry.dump",
    "cache.new",
    "cache.set_case_sensitivity",
    "cache.case_sensitive",
    "cache.set_telemetry",
    "adapter.set_telemetry",
    "adapter.name",
    "adapter.retry",
    "adapter.grace",
    "multiplexing.run",
    "codec.encode",
    "codec.decode",
    "envelope.encode",
    "envelope.decode",
    "envelope.touch",
    "resp.encode",
    "resp.parse",
    "resp.unwrap",
    "noscript.matches",
    "noscript.from",
    "raw.hget",
    "raw.hset",
    "raw.hexists",
    "raw.ttl",
    "raw.script_flush",
    "raw.tomb",
    "raw.json_decodes",
    "sleep",
    "fuzz.roundtrip",
    "cache.load",
    "cache.save",
    "cache.touch",
    "cache.list",
    "cache.purge",
    "cache.flush",
    "cache.ping",
    "cache.size",
    "cache.load_many",
    "cache.save_many",
    "cache.generation",
    "cache.save_with_lease",
    "adapter.load",
    "adapter.save",
    "adapter.touch",
    "adapter.list",
    "adapter.purge",
    "adapter.flush",
    "adapter.ping",
    "adapter.size",
    "adapter.load_many",
    "adapter.save_many",
    "adapter.generation",
    "adapter.save_with_lease",
];

/// A user document as Appwrite caches it: system attributes, permissions,
/// nested preferences, targets and memberships (about 2 KB of JSON).
const DOCUMENT: &str = r#"{"$id":"6650f2b1c3a4e5d6f7a8","$sequence":48213,"$createdAt":"2026-05-24T10:15:30.123+00:00","$updatedAt":"2026-09-30T08:02:11.456+00:00","$permissions":["read(\"user:6650f2b1c3a4e5d6f7a8\")","update(\"user:6650f2b1c3a4e5d6f7a8\")","delete(\"user:6650f2b1c3a4e5d6f7a8\")"],"name":"Ada Lovelace","email":"ada.lovelace@example.com","phone":"+14155550123","status":true,"labels":["admin","beta"],"passwordUpdate":"2026-05-24T10:15:30.123+00:00","registration":"2026-05-24T10:15:30.123+00:00","emailVerification":true,"phoneVerification":false,"mfa":false,"accessedAt":"2026-10-10T19:44:02.000+00:00","prefs":{"theme":"dark","language":"en-GB","timezone":"Europe/London","notifications":{"email":true,"push":false,"digest":"weekly"},"dashboard":{"pinned":["functions","databases","storage"],"density":0.75}},"targets":[{"$id":"6650f2b1d4e5f6a7b8c9","$createdAt":"2026-05-24T10:15:30.123+00:00","$updatedAt":"2026-05-24T10:15:30.123+00:00","name":"","userId":"6650f2b1c3a4e5d6f7a8","providerId":null,"providerType":"email","identifier":"ada.lovelace@example.com","expired":false},{"$id":"6650f2b1e5f6a7b8c9d0","$createdAt":"2026-06-02T14:20:00.000+00:00","$updatedAt":"2026-06-02T14:20:00.000+00:00","name":"Pixel 9","userId":"6650f2b1c3a4e5d6f7a8","providerId":"fcm","providerType":"push","identifier":"dGhpcyBpcyBhIGZha2UgZmNtIHRva2VuIGZvciBiZW5jaG1hcmtz","expired":false}],"memberships":[{"teamId":"team_core","roles":["owner"],"confirm":true},{"teamId":"team_docs","roles":["developer","reviewer"],"confirm":true}],"quota":{"storage":1073741824,"used":52428800,"ratio":0.048828125}}"#;

/// The key the in-process benchmarks cache the document under.
const KEY: &str = "user:6650f2b1c3a4e5d6f7a8";

/// The instant the envelope benchmark writes at, so both runtimes encode the same bytes.
const TIME: i64 = 1_790_000_000;

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = &[
    ("memory_load", "Load a cached user document from the in-memory adapter through the cache facade"),
    ("memory_save", "Save a user document to the in-memory adapter through the cache facade"),
    ("redis_load", "Load a cached user document from Redis: one HGET and the JSON envelope decoded"),
    ("redis_miss", "Load a key Redis does not hold: one HGET, nothing to decode"),
    ("redis_save", "Save a user document to Redis: the JSON envelope encoded and one HSET"),
    ("envelope", "Encode a user document into a JSON cache envelope and decode it back"),
];

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    use std::hint::black_box;
    use std::rc::Rc;

    use crate::bench::Bench;

    // The document as `json_decode($json, true)` returns it.
    let doc = php_std::json::decode(
        DOCUMENT.as_bytes(),
        Some(true),
        php_std::json::DEFAULT_DEPTH,
        php_std::json::Flags::NONE,
    )
    .map_err(fault)?;
    Ok(match name {
        "memory_load" => {
            let cache = Cache::new(Memory::new());
            cache.save(KEY, &doc, "", 0).await.map_err(fault)?;
            let cache = Rc::new(cache);
            Bench::future(move || {
                let cache = cache.clone();
                async move {
                    black_box(cache.load(black_box(KEY), 3600, "").await.ok());
                }
            })
        }
        "memory_save" => {
            let state = Rc::new((Cache::new(Memory::new()), doc));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    black_box(state.0.save(black_box(KEY), &state.1, "", 0).await.ok());
                }
            })
        }
        "redis_load" | "redis_miss" | "redis_save" => {
            // The `cache-redis` service as the cases use it, a key in this
            // run's namespace. The setup saves it with a TTL, which the
            // timed HSETs keep, so Redis drops it after the run.
            let client = redis::Client::open(redis_url(session, &Args(&json!({})))?).map_err(fault)?;
            let cache = Cache::new(Redis::new(client.get_connection_manager().await.map_err(fault)?));
            let key = format!("{}:bench:{name}", session.ns);
            if name != "redis_miss" {
                cache.save(&key, &doc, "", 600).await.map_err(fault)?;
            }
            let state = Rc::new((cache, key, doc));
            if name == "redis_save" {
                Bench::future(move || {
                    let state = state.clone();
                    async move {
                        black_box(state.0.save(&state.1, &state.2, "", 0).await.ok());
                    }
                })
            } else {
                Bench::future(move || {
                    let state = state.clone();
                    async move {
                        black_box(state.0.load(&state.1, 3600, "").await.ok());
                    }
                })
            }
        }
        "envelope" => {
            let envelope = Envelope::new(AnyCodec::Json);
            Bench::sync(move || {
                let stored = envelope.encode(black_box(&doc), TIME).unwrap_or_default();
                black_box(envelope.decode(&stored, 3600, TIME));
            })
        }
        _ => return Err(Fault::new(format!("cache: no benchmark `{name}`"))),
    })
}

/// An adapter handle: the adapter, and what the operations read back from
/// its concrete type.
#[derive(Clone)]
struct Handle {
    adapter: Arc<dyn DynAdapter>,
    retry: Option<Retry>,
    grace: Option<i64>,
    loads: Option<Arc<AtomicI64>>,
    probe: Option<Arc<ProbeState>>,
    cluster: Option<(Option<String>, Vec<String>)>,
}

impl Handle {
    fn new(adapter: Arc<dyn DynAdapter>) -> Self {
        Handle { adapter, retry: None, grace: None, loads: None, probe: None, cluster: None }
    }
}

fn err(e: Error) -> Outcome {
    Outcome::err(e.php_class(), e.to_string())
}

fn fault(e: impl std::fmt::Display) -> Fault {
    Fault::new(e.to_string())
}

fn codec(name: &str) -> Result<AnyCodec, Fault> {
    match name {
        "json" => Ok(AnyCodec::Json),
        "igbinary" => Ok(AnyCodec::Igbinary),
        other => Err(Fault::new(format!("unknown codec {other}"))),
    }
}

fn redis_url(session: &Session, a: &Args<'_>) -> Result<String, Fault> {
    let service = session.service(a.opt_str("service")?.unwrap_or("cache-redis"))?;
    let (base, db) = split_db(service);
    Ok(format!("{base}/{}", a.opt_i64("db")?.unwrap_or(db)))
}

/// A Redis URL without its database path, and the database (0 by default).
fn split_db(url: &str) -> (&str, i64) {
    let rest = url.strip_prefix("redis://").unwrap_or(url);
    match rest.split_once('/') {
        Some((_, db)) => (&url[..url.len() - db.len() - 1], db.parse().unwrap_or(0)),
        None => (url.trim_end_matches('/'), 0),
    }
}

async fn raw(session: &Session, a: &Args<'_>) -> Result<redis::aio::MultiplexedConnection, Fault> {
    redis::Client::open(redis_url(session, a)?).map_err(fault)?.get_multiplexed_async_connection().await.map_err(fault)
}

fn handle(session: &Session, value: &Json) -> Result<Handle, Fault> {
    session.get::<Handle>(value).cloned()
}

fn cache(session: &Session, a: &Args<'_>) -> Result<Arc<Cache>, Fault> {
    session.get::<Arc<Cache>>(a.value("cache")?).cloned()
}

fn telemetry(session: &Session, value: &Json) -> Result<MemoryTelemetry, Fault> {
    session.get::<MemoryTelemetry>(value).cloned()
}

fn fields(value: &Json) -> Result<Fields, Fault> {
    let mut out = Fields::new();
    for pair in value.as_array().ok_or_else(|| Fault::new("data must be a list of pairs"))? {
        let key = pair.get(0).and_then(Json::as_str).ok_or_else(|| Fault::new("a field name must be a string"))?;
        out.insert(key.to_owned(), typed::decode(pair.get(1).unwrap_or(&Json::Null))?);
    }
    Ok(out)
}

fn dump_fields(fields: Fields) -> Json {
    Json::Object(fields.into_iter().map(|(k, v)| (k, typed::dump(&v))).collect())
}

fn dump_load(value: Option<Value>) -> Json {
    match value {
        Some(v) => typed::dump(&v),
        None => Json::Bool(false),
    }
}

/// One cache or adapter method with the case's arguments.
async fn method<A: Adapter + ?Sized>(target: Target<'_, A>, op: &str, a: &Args<'_>) -> Result<Outcome, Fault> {
    let key = || a.str("key");
    let hash = || -> Result<&str, Fault> { Ok(a.opt_str("hash")?.unwrap_or("")) };
    let result: Result<Json, Error> = match op {
        "load" => target.load(key()?, a.i64("ttl")?, hash()?).await.map(dump_load),
        "save" => {
            let data = typed::decode(a.value("data")?)?;
            target.save(key()?, &data, hash()?, a.opt_i64("ttl")?.unwrap_or(0)).await.map(Json::Bool)
        }
        "touch" => target.touch(key()?, hash()?).await.map(Json::Bool),
        "list" => target.list(key()?).await.map(|l| json!(l)),
        "purge" => target.purge(key()?, hash()?).await.map(Json::Bool),
        "flush" => target.flush().await.map(Json::Bool),
        "ping" => target.ping().await.map(Json::Bool),
        "size" => target.size().await.map(Json::from),
        "load_many" => {
            let names: Vec<&str> = match a.opt("fields") {
                Some(f) => f.as_array().map(|l| l.iter().filter_map(Json::as_str).collect()).unwrap_or_default(),
                None => Vec::new(),
            };
            target.load_many(key()?, &names, a.i64("ttl")?).await.map(dump_fields)
        }
        "save_many" => {
            let data = fields(a.value("data")?)?;
            target.save_many(key()?, &data, a.opt_i64("ttl")?.unwrap_or(0)).await.map(Json::Bool)
        }
        "generation" => target.generation(key()?).await.map(Json::String),
        "save_with_lease" => {
            let data = typed::decode(a.value("data")?)?;
            target.save_with_lease(key()?, &data, a.str("hash")?, a.str("generation")?).await.map(Json::Bool)
        }
        other => return Err(Fault::new(format!("unknown method {other}"))),
    };
    Ok(match result {
        Ok(v) => Outcome::Ok(v),
        Err(e) => err(e),
    })
}

/// What a method runs on: the facade, or an adapter directly.
enum Target<'a, A: ?Sized> {
    Cache(&'a Cache<A>),
    Adapter(&'a A),
}

impl<A: Adapter + ?Sized> Target<'_, A> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> utopia_cache::Result<Option<Value>> {
        match self {
            Target::Cache(c) => c.load(key, ttl, hash).await,
            Target::Adapter(x) => x.load(key, ttl, hash).await,
        }
    }
    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.save(key, data, hash, ttl).await,
            Target::Adapter(x) => x.save(key, data, hash, ttl).await,
        }
    }
    async fn touch(&self, key: &str, hash: &str) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.touch(key, hash).await,
            Target::Adapter(x) => x.touch(key, hash).await,
        }
    }
    async fn list(&self, key: &str) -> utopia_cache::Result<Vec<String>> {
        match self {
            Target::Cache(c) => c.list(key).await,
            Target::Adapter(x) => x.list(key).await,
        }
    }
    async fn purge(&self, key: &str, hash: &str) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.purge(key, hash).await,
            Target::Adapter(x) => x.purge(key, hash).await,
        }
    }
    async fn flush(&self) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.flush().await,
            Target::Adapter(x) => x.flush().await,
        }
    }
    async fn ping(&self) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.ping().await,
            Target::Adapter(x) => x.ping().await,
        }
    }
    async fn size(&self) -> utopia_cache::Result<i64> {
        match self {
            Target::Cache(c) => c.size().await,
            Target::Adapter(x) => x.size().await,
        }
    }
    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> utopia_cache::Result<Fields> {
        match self {
            Target::Cache(c) => c.load_many(key, ttl, fields).await,
            Target::Adapter(x) => x.load_many(key, fields, ttl).await,
        }
    }
    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.save_many(key, data, ttl).await,
            Target::Adapter(x) => x.save_many(key, data, ttl).await,
        }
    }
    async fn generation(&self, key: &str) -> utopia_cache::Result<String> {
        match self {
            Target::Cache(c) => c.generation(key).await,
            Target::Adapter(x) => x.generation(key).await,
        }
    }
    async fn save_with_lease(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        generation: &str,
    ) -> utopia_cache::Result<bool> {
        match self {
            Target::Cache(c) => c.save_with_lease(key, data, hash, generation).await,
            Target::Adapter(x) => x.save_with_lease(key, data, hash, generation).await,
        }
    }
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let a = Args(args);
    let new = |session: &mut Session, adapter: Arc<dyn DynAdapter>| Outcome::Ok(session.handle(Handle::new(adapter)));
    let result = match op {
        "memory.new" => new(session, Arc::new(Memory::new())),
        "none.new" => new(session, Arc::new(NoneAdapter)),
        "filesystem.new" => new(session, Arc::new(Filesystem::new(a.str("path")?))),
        "filesystem.path" => Outcome::ok(Filesystem::new(a.str("path")?).path(a.str("key")?).display().to_string()),
        "filesystem.stream" => {
            use tokio::io::AsyncReadExt;
            match Filesystem::new(a.str("path")?).open(a.str("key")?, a.i64("ttl")?).await {
                Ok(Some(mut file)) => {
                    let mut contents = Vec::new();
                    file.read_to_end(&mut contents).await.map_err(fault)?;
                    Outcome::Ok(bytes_value(&contents))
                }
                Ok(None) => Outcome::ok(false),
                Err(e) => err(e),
            }
        }
        "redis.new" => {
            let client = redis::Client::open(redis_url(session, &a)?).map_err(fault)?;
            let connection = client.get_connection_manager().await.map_err(fault)?;
            let mut adapter = Redis::with_codec(connection, codec(a.opt_str("codec")?.unwrap_or("json"))?);
            if let Some(retries) = a.opt_i64("retries")? {
                adapter = adapter.with_max_retries(retries);
            }
            if let Some(delay) = a.opt_i64("delay")? {
                adapter = adapter.with_retry_delay(delay);
            }
            if let Some(grace) = a.opt_i64("grace")? {
                adapter = adapter.with_lease_grace_window(grace);
            }
            let (retry, grace) = (adapter.retry(), adapter.lease_grace_window());
            let h = Handle { retry: Some(retry), grace: Some(grace), ..Handle::new(Arc::new(adapter)) };
            Outcome::Ok(session.handle(h))
        }
        "memcached.new" | "hazelcast.new" => {
            let default = if op == "memcached.new" { "cache-memcached" } else { "cache-hazelcast" };
            let service = session.service(a.opt_str("service")?.unwrap_or(default))?;
            let rest = service.split_once("://").map_or(service, |(_, r)| r).trim_end_matches('/');
            let (host, port) = rest.rsplit_once(':').ok_or_else(|| Fault::new("memcached service needs a port"))?;
            let client = MemcachedClient::new(host, port.parse().map_err(fault)?);
            let (retries, delay) = (a.opt_i64("retries")?, a.opt_i64("delay")?);
            let (adapter, retry): (Arc<dyn DynAdapter>, Retry) = if op == "memcached.new" {
                let mut m = Memcached::new(client);
                if let Some(r) = retries {
                    m = m.with_max_retries(r);
                }
                if let Some(d) = delay {
                    m = m.with_retry_delay(d);
                }
                let retry = m.retry();
                (Arc::new(m), retry)
            } else {
                let mut h = Hazelcast::with_codec(client, codec(a.opt_str("codec")?.unwrap_or("json"))?);
                if let Some(r) = retries {
                    h = h.with_max_retries(r);
                }
                if let Some(d) = delay {
                    h = h.with_retry_delay(d);
                }
                let retry = h.retry();
                (Arc::new(h), retry)
            };
            Outcome::Ok(session.handle(Handle { retry: Some(retry), ..Handle::new(adapter) }))
        }
        "cluster.new" => {
            let service = session.service("cache-cluster")?;
            let seeds: Vec<String> =
                service.trim_start_matches("redis-cluster://").split(',').map(str::to_owned).collect();
            let urls: Vec<String> = seeds.iter().map(|s| format!("redis://{s}")).collect();
            let client = redis::cluster::ClusterClient::new(urls).map_err(fault)?;
            let connection = client.get_async_connection().await.map_err(fault)?;
            let name = a.opt_str("name")?.map(str::to_owned);
            let mut adapter =
                RedisCluster::with_codec(connection, seeds, name, codec(a.opt_str("codec")?.unwrap_or("json"))?);
            if let Some(retries) = a.opt_i64("retries")? {
                adapter = adapter.with_max_retries(retries);
            }
            if let Some(delay) = a.opt_i64("delay")? {
                adapter = adapter.with_retry_delay(delay);
            }
            let cluster = Some((adapter.cluster_name().map(str::to_owned), adapter.seeds().to_vec()));
            let h = Handle { retry: Some(adapter.retry()), cluster, ..Handle::new(Arc::new(adapter)) };
            Outcome::Ok(session.handle(h))
        }
        "cluster.info" => {
            let (name, seeds) =
                handle(session, a.value("adapter")?)?.cluster.ok_or_else(|| Fault::new("not a cluster adapter"))?;
            Outcome::Ok(json!({ "name": name, "seeds": seeds }))
        }
        "sharding.new" => {
            let mut shards = Vec::new();
            for h in a.array("adapters")? {
                shards.push(handle(session, h)?.adapter);
            }
            match Sharding::new(shards) {
                Ok(s) => new(session, Arc::new(s)),
                Err(e) => err(e),
            }
        }
        "pool.new" => {
            let path = a.str("path")?.to_owned();
            let size = a.opt_i64("size")?.unwrap_or(10) as usize;
            let make = move || std::future::ready(Ok::<_, std::convert::Infallible>(Filesystem::new(path.clone())));
            let filesystems = utopia_pools::Pool::new(utopia_pools::Stack::new(), "compat", size, make, 0.0)
                .map_err(|e| Fault::new(e.to_string()))?;
            match Pool::new(filesystems).await {
                Ok(pool) => new(session, Arc::new(pool)),
                Err(e) => err(e),
            }
        }
        "circuit_breaker.new" => {
            let inner = handle(session, a.value("adapter")?)?.adapter;
            let mut options = utopia_circuit_breaker::Options::default();
            if let Some(m) = a.opt_i64("minimum_throughput")? {
                options.minimum_throughput = m;
            }
            match utopia_circuit_breaker::CircuitBreaker::new(options) {
                Ok(breaker) => new(session, Arc::new(CircuitBreaker::new(inner, breaker))),
                Err(e) => Outcome::err(e.php_class(), e.to_string()),
            }
        }
        "failing.new" => new(session, Arc::new(Failing { loads: None })),
        "counting_failing.new" => {
            let loads = Arc::new(AtomicI64::new(0));
            let h = Handle { loads: Some(loads.clone()), ..Handle::new(Arc::new(Failing { loads: Some(loads) })) };
            Outcome::Ok(session.handle(h))
        }
        "counting_failing.loads" => {
            let h = handle(session, a.value("adapter")?)?;
            Outcome::ok(h.loads.ok_or_else(|| Fault::new("not a counting adapter"))?.load(Ordering::SeqCst))
        }
        "probe.new" => {
            let state = Arc::new(ProbeState::default());
            let h =
                Handle { probe: Some(state.clone()), ..Handle::new(Arc::new(Probe { memory: Memory::new(), state })) };
            Outcome::Ok(session.handle(h))
        }
        "probe.calls" => {
            let h = handle(session, a.value("adapter")?)?;
            let state = h.probe.ok_or_else(|| Fault::new("not a probe adapter"))?;
            let expected = telemetry(session, a.value("telemetry")?)?;
            let got = state.telemetry.lock().unwrap_or_else(|e| e.into_inner()).clone();
            let same = got.is_some_and(|t| t.same(&expected));
            Outcome::Ok(json!({ "calls": state.calls.load(Ordering::SeqCst), "same": same }))
        }
        "null_load.new" => new(session, Arc::new(NullLoad(Memory::new()))),
        "telemetry.new" => Outcome::Ok(session.handle(MemoryTelemetry::new())),
        "telemetry.dump" => Outcome::Ok(dump_telemetry(&telemetry(session, a.value("telemetry")?)?)),
        "cache.new" => {
            let adapter = handle(session, a.value("adapter")?)?.adapter;
            Outcome::Ok(session.handle(Arc::new(Cache::from_dyn(adapter))))
        }
        "cache.set_case_sensitivity" => Outcome::ok(cache(session, &a)?.set_case_sensitivity(a.bool("value")?)),
        "cache.case_sensitive" => Outcome::ok(cache(session, &a)?.case_sensitive()),
        "cache.set_telemetry" => {
            let t = telemetry(session, a.value("telemetry")?)?;
            cache(session, &a)?.set_telemetry(Arc::new(t));
            Outcome::Ok(Json::Null)
        }
        "adapter.set_telemetry" => {
            let t = telemetry(session, a.value("telemetry")?)?;
            handle(session, a.value("adapter")?)?.adapter.set_telemetry(Arc::new(t));
            Outcome::Ok(Json::Null)
        }
        "adapter.name" => {
            let h = handle(session, a.value("adapter")?)?;
            Outcome::ok(h.adapter.name(a.opt_str("key")?))
        }
        "adapter.retry" => {
            let retry = handle(session, a.value("adapter")?)?.retry.ok_or_else(|| Fault::new("not retryable"))?;
            Outcome::Ok(json!({ "max": retry.max_retries(), "delay": retry.delay_ms() }))
        }
        "adapter.grace" => {
            Outcome::ok(handle(session, a.value("adapter")?)?.grace.ok_or_else(|| Fault::new("not leasable"))?)
        }
        "multiplexing.run" => Outcome::Ok(multiplexing(session, &a).await?),
        "codec.encode" => match codec(a.str("codec")?)?.encode(&typed::decode(a.value("value")?)?) {
            Ok(b) => Outcome::Ok(bytes_value(&b)),
            Err(e) => err(e),
        },
        "codec.decode" => match codec(a.str("codec")?)?.decode(&a.bytes("bytes")?) {
            Ok(v) => Outcome::Ok(typed::dump(&v)),
            Err(e) => err(e),
        },
        "envelope.encode" => {
            let envelope = Envelope::new(codec(a.opt_str("codec")?.unwrap_or("json"))?);
            match envelope.encode(&typed::decode(a.value("data")?)?, a.i64("time")?) {
                Ok(b) => Outcome::Ok(bytes_value(&b)),
                Err(e) => err(e),
            }
        }
        "envelope.decode" => {
            let envelope = Envelope::new(codec(a.opt_str("codec")?.unwrap_or("json"))?);
            Outcome::Ok(dump_load(envelope.decode(&a.bytes("value")?, a.i64("ttl")?, a.i64("now")?)))
        }
        "envelope.touch" => {
            let envelope = Envelope::new(codec(a.opt_str("codec")?.unwrap_or("json"))?);
            Outcome::Ok(match envelope.touch(&a.bytes("value")?, a.i64("time")?) {
                Some(b) => bytes_value(&b),
                None => Json::Bool(false),
            })
        }
        "resp.encode" => {
            let args: Vec<Vec<u8>> = a
                .array("args")?
                .iter()
                .map(|v| match v {
                    Json::Number(n) => Ok(n.to_string().into_bytes()),
                    other => bytes(other).ok_or_else(|| Fault::new("an argument is a string or an integer")),
                })
                .collect::<Result<_, _>>()?;
            Outcome::Ok(bytes_value(&resp::encode(&args)))
        }
        "resp.parse" => {
            let buffer = a.bytes("buffer")?;
            let mut offset = a.opt_i64("offset")?.unwrap_or(0) as usize;
            match resp::parse(&buffer, &mut offset) {
                Ok(Some(reply)) => Outcome::Ok(json!({ "value": reply_json(&reply), "offset": offset })),
                Ok(None) => Outcome::Ok(json!({ "value": { "$incomplete": true }, "offset": offset })),
                Err(e) => err(e),
            }
        }
        "resp.unwrap" => match frame(a.value("value")?, true)?.and_then(resp::unwrap) {
            Ok(reply) => Outcome::Ok(reply_json(&reply)),
            Err(e) => err(e),
        },
        "noscript.matches" => Outcome::ok(resp::is_noscript(a.str("error")?)),
        "noscript.from" => {
            let error = Error::NoScript(a.str("message")?.to_owned());
            Outcome::Ok(json!({ "class": error.php_class(), "message": error.to_string() }))
        }
        "raw.hget" => {
            let mut c = raw(session, &a).await?;
            let v: Option<Vec<u8>> =
                redis::cmd("HGET").arg(a.str("key")?).arg(a.str("field")?).query_async(&mut c).await.map_err(fault)?;
            Outcome::Ok(v.map_or(Json::Bool(false), |v| bytes_value(&v)))
        }
        "raw.hset" => {
            let mut c = raw(session, &a).await?;
            let v: i64 = redis::cmd("HSET")
                .arg(a.str("key")?)
                .arg(a.str("field")?)
                .arg(a.bytes("value")?)
                .query_async(&mut c)
                .await
                .map_err(fault)?;
            Outcome::ok(v)
        }
        "raw.hexists" => {
            let mut c = raw(session, &a).await?;
            let v: i64 = redis::cmd("HEXISTS")
                .arg(a.str("key")?)
                .arg(a.str("field")?)
                .query_async(&mut c)
                .await
                .map_err(fault)?;
            Outcome::ok(v != 0)
        }
        "raw.ttl" => {
            let mut c = raw(session, &a).await?;
            let ttl: i64 = redis::cmd("TTL").arg(a.str("key")?).query_async(&mut c).await.map_err(fault)?;
            let at_most = a.opt_i64("at_most")?.unwrap_or(i64::MAX);
            Outcome::Ok(if ttl > 0 && ttl <= at_most { json!("positive") } else { json!(ttl) })
        }
        "raw.script_flush" => {
            let mut c = raw(session, &a).await?;
            let _: () = redis::cmd("SCRIPT").arg("FLUSH").query_async(&mut c).await.map_err(fault)?;
            Outcome::ok(true)
        }
        "raw.tomb" => {
            let mut c = raw(session, &a).await?;
            let time: (String, String) = redis::cmd("TIME").query_async(&mut c).await.map_err(fault)?;
            let micros = (time.0.parse::<i64>().map_err(fault)? + a.i64("seconds")?) * 1_000_000;
            let v: i64 = redis::cmd("HSET")
                .arg(a.str("key")?)
                .arg("__utopia_tomb__")
                .arg(micros.to_string())
                .query_async(&mut c)
                .await
                .map_err(fault)?;
            Outcome::ok(v)
        }
        "raw.json_decodes" => {
            let b = a.bytes("bytes")?;
            Outcome::ok(!matches!(
                php_std::json::decode(&b, None, php_std::json::DEFAULT_DEPTH, php_std::json::Flags::NONE),
                Err(_) | Ok(Value::Null)
            ))
        }
        "fuzz.roundtrip" => {
            let adapter: Arc<dyn DynAdapter> = match a.str("adapter")? {
                "memory" => Arc::new(Memory::new()),
                "redis" | "redis-igbinary" => {
                    let client = redis::Client::open(redis_url(session, &Args(&json!({})))?).map_err(fault)?;
                    let connection = client.get_connection_manager().await.map_err(fault)?;
                    let name = if a.str("adapter")? == "redis" { "json" } else { "igbinary" };
                    Arc::new(Redis::with_codec(connection, codec(name)?))
                }
                "filesystem" => Arc::new(Filesystem::new(format!("/tmp/compat-fs/{}/fuzz", session.ns))),
                "memcached" | "hazelcast" => {
                    let service = session.service(if a.str("adapter")? == "memcached" {
                        "cache-memcached"
                    } else {
                        "cache-hazelcast"
                    })?;
                    let rest = service.split_once("://").map_or(service, |(_, r)| r).trim_end_matches('/');
                    let (host, port) =
                        rest.rsplit_once(':').ok_or_else(|| Fault::new("memcached service needs a port"))?;
                    let client = MemcachedClient::new(host, port.parse().map_err(fault)?);
                    if a.str("adapter")? == "memcached" {
                        Arc::new(Memcached::new(client))
                    } else {
                        Arc::new(Hazelcast::new(client))
                    }
                }
                other => return Err(Fault::new(format!("unknown adapter {other}"))),
            };
            let cache = Cache::from_dyn(adapter);
            let key = format!("{}:{}", session.ns, a.str("key")?);
            let hash = a.str("hash")?;
            let data = typed::decode(a.value("data")?)?;
            let out = |r: utopia_cache::Result<Json>| match r {
                Ok(v) => v,
                Err(e) => err(e).to_json(),
            };
            let results = vec![
                out(cache.save(&key, &data, hash, 0).await.map(Json::Bool)),
                out(cache.load(&key, 60, hash).await.map(dump_load)),
                out(cache.touch(&key, hash).await.map(Json::Bool)),
                out(cache.load(&key, 60, hash).await.map(dump_load)),
                out(cache.list(&key).await.map(|l| json!(l))),
                out(cache.purge(&key, hash).await.map(Json::Bool)),
                out(cache.load(&key, 60, hash).await.map(dump_load)),
            ];
            Outcome::Ok(Json::Array(results))
        }
        "sleep" => {
            tokio::time::sleep(std::time::Duration::from_secs_f64(a.f64("seconds")?)).await;
            Outcome::Ok(Json::Null)
        }
        other => {
            if let Some(name) = other.strip_prefix("cache.") {
                let c = cache(session, &a)?;
                return method(Target::Cache(c.as_ref()), name, &a).await;
            }
            if let Some(name) = other.strip_prefix("adapter.") {
                let h = handle(session, a.value("adapter")?)?;
                return method(Target::Adapter(h.adapter.as_ref()), name, &a).await;
            }
            return Err(Fault::new(format!("cache: unknown operation `{other}`")));
        }
    };
    Ok(result)
}

fn reply_json(reply: &resp::Reply) -> Json {
    match reply {
        resp::Reply::Nil => Json::Null,
        resp::Reply::Int(i) => json!(i),
        resp::Reply::Str(s) => bytes_value(s),
        resp::Reply::Array(items) => Json::Array(items.iter().map(reply_json).collect()),
        resp::Reply::Error(e) => json!({ "$redis_error": String::from_utf8_lossy(e) }),
    }
}

/// A reply from its wire form; a top-level `{"$connection_error"}` is the
/// connection failure the multiplexer hands a waiting caller.
fn frame(value: &Json, top: bool) -> Result<utopia_cache::Result<resp::Reply>, Fault> {
    Ok(Ok(match value {
        Json::Null => resp::Reply::Nil,
        Json::Number(n) => resp::Reply::Int(n.as_i64().ok_or_else(|| Fault::new("integer replies only"))?),
        Json::Array(items) => {
            let mut out = Vec::new();
            for item in items {
                match frame(item, false)? {
                    Ok(r) => out.push(r),
                    Err(e) => return Ok(Err(e)),
                }
            }
            resp::Reply::Array(out)
        }
        Json::Object(o) if o.contains_key("$redis_error") => {
            resp::Reply::Error(o["$redis_error"].as_str().unwrap_or("").as_bytes().to_vec())
        }
        Json::Object(o) if o.contains_key("$connection_error") && top => {
            return Ok(Err(Error::Connection(o["$connection_error"].as_str().unwrap_or("").to_owned())));
        }
        other => resp::Reply::Str(bytes(other).ok_or_else(|| Fault::new("unsupported reply"))?),
    }))
}

fn dump_telemetry(t: &MemoryTelemetry) -> Json {
    let mut out: BTreeMap<String, Json> = BTreeMap::new();
    for (kind, prefix) in
        [(Kind::Counter, "counter"), (Kind::UpDownCounter, "upDownCounter"), (Kind::Histogram, "histogram")]
    {
        for name in t.names(kind) {
            let points = t.points(kind, &name).unwrap_or_default();
            let list: Vec<Json> = points
                .into_iter()
                .map(|p| {
                    let value = if kind == Kind::Histogram { json!(0) } else { number(p.value) };
                    let attributes: Map<String, Json> =
                        p.attributes.into_iter().map(|(k, v)| (k, attribute(&v))).collect();
                    json!({ "value": value, "attributes": attributes })
                })
                .collect();
            out.insert(format!("{prefix}:{name}"), Json::Array(list));
        }
    }
    Json::Object(out.into_iter().collect())
}

fn number(n: utopia_telemetry::Number) -> Json {
    match n {
        utopia_telemetry::Number::Int(i) => json!(i),
        utopia_telemetry::Number::Float(f) => crate::adapter::float_value(f),
    }
}

fn attribute(v: &utopia_telemetry::Value<'_>) -> Json {
    match v {
        utopia_telemetry::Value::Str(s) => json!(s),
        utopia_telemetry::Value::Int(i) => json!(i),
        utopia_telemetry::Value::Float(f) => crate::adapter::float_value(*f),
        utopia_telemetry::Value::Bool(b) => json!(b),
    }
}

async fn multiplexing(session: &Session, a: &Args<'_>) -> Result<Json, Fault> {
    let service = session.service(a.opt_str("service")?.unwrap_or("cache-redis"))?;
    let (base, db) = split_db(service);
    let rest = base.strip_prefix("redis://").unwrap_or(base);
    let (host, port) = rest.rsplit_once(':').unwrap_or((rest, "6379"));
    let empty = Json::Object(Map::new());
    let o = Args(a.opt("options").unwrap_or(&empty));
    let mut options = MultiplexingOptions::new(host, port.parse().map_err(fault)?);
    options.db = db;
    if let Some(v) = o.opt_f64("timeout")? {
        options.timeout = v;
    }
    if let Some(v) = o.opt_f64("read_timeout")? {
        options.read_timeout = v;
    }
    if let Some(v) = o.opt_f64("liveness_timeout")? {
        options.liveness_timeout = v;
    }
    if let Some(v) = o.opt_f64("idle_grace")? {
        options.idle_grace = v;
    }
    if let Some(v) = o.opt_i64("db")? {
        options.db = v;
    }
    let mut results = Vec::new();
    let adapter = match Multiplexing::connect_with_codec(options, codec(o.opt_str("codec")?.unwrap_or("json"))?).await {
        Ok(adapter) => adapter,
        Err(e) => return Ok(Json::Array(vec![err(e).to_json()])),
    };
    let adapter = match o.opt_i64("grace")? {
        Some(g) => adapter.with_lease_grace_window(g),
        None => adapter,
    };
    let cache = Arc::new(Cache::new(adapter));
    if o.opt_bool("case_sensitive")?.unwrap_or(false) {
        cache.set_case_sensitivity(true);
    }
    for step in a.array("steps")? {
        let op = step.get("op").and_then(Json::as_str).ok_or_else(|| Fault::new("a step needs an op"))?;
        let args = step.get("args").cloned().unwrap_or(Json::Object(Map::new()));
        let sa = Args(&args);
        match op {
            "sleep" => {
                tokio::time::sleep(std::time::Duration::from_secs_f64(sa.f64("seconds")?)).await;
                results.push(Json::Null);
            }
            "load_equals" => {
                let expected = typed::decode(sa.value("data")?)?;
                let loaded = cache.load(sa.str("key")?, sa.i64("ttl")?, sa.str("hash")?).await;
                results.push(match loaded {
                    Ok(value) => Json::Bool(value.unwrap_or(Value::Bool(false)).loose_eq(&expected)),
                    Err(e) => err(e).to_json(),
                });
            }
            "concurrent" => {
                let mut tasks = Vec::new();
                for i in 0..sa.i64("count")? {
                    let cache = cache.clone();
                    let key = format!("{}{i}", sa.str("prefix")?);
                    tasks.push(tokio::spawn(async move {
                        let mut value = php_std::types::Array::new();
                        value.set("i".into(), Value::Int(i));
                        value.set("text".into(), Value::from(format!("café 🚀 row {i}").as_str()));
                        let value = Value::Array(value);
                        matches!(cache.save(&key, &value, &key, 0).await, Ok(true))
                            && cache.load(&key, 60, &key).await.ok().flatten().is_some_and(|v| v.loose_eq(&value))
                    }));
                }
                let mut ok = 0;
                for t in tasks {
                    if t.await.unwrap_or(false) {
                        ok += 1;
                    }
                }
                results.push(json!(ok));
            }
            name => results.push(method(Target::Cache(cache.as_ref()), name, &sa).await?.to_json()),
        }
    }
    cache.adapter().disconnect();
    Ok(Json::Array(results))
}

/// CircuitBreakerTest's failing adapters: every operation fails; the
/// counting one counts the loads that reached it.
struct Failing {
    loads: Option<Arc<AtomicI64>>,
}

fn failed<T>() -> utopia_cache::Result<T> {
    Err(Error::Runtime("Cache failed.".into()))
}

impl Adapter for Failing {
    async fn load(&self, _: &str, _: i64, _: &str) -> utopia_cache::Result<Option<Value>> {
        if let Some(loads) = &self.loads {
            loads.fetch_add(1, Ordering::SeqCst);
        }
        failed()
    }
    async fn save(&self, _: &str, _: &Value, _: &str, _: i64) -> utopia_cache::Result<bool> {
        failed()
    }
    async fn touch(&self, _: &str, _: &str) -> utopia_cache::Result<bool> {
        failed()
    }
    async fn list(&self, _: &str) -> utopia_cache::Result<Vec<String>> {
        failed()
    }
    async fn purge(&self, _: &str, _: &str) -> utopia_cache::Result<bool> {
        failed()
    }
    async fn flush(&self) -> utopia_cache::Result<bool> {
        failed()
    }
    async fn ping(&self) -> utopia_cache::Result<bool> {
        failed()
    }
    async fn size(&self) -> utopia_cache::Result<i64> {
        failed()
    }
    fn name(&self, _: Option<&str>) -> &str {
        "failing"
    }
}

#[derive(Default)]
struct ProbeState {
    calls: AtomicI64,
    telemetry: std::sync::Mutex<Option<Probed>>,
}

/// The telemetry a probe was handed, compared by identity with a handle's.
#[derive(Clone)]
struct Probed(Arc<dyn Telemetry>);

impl Probed {
    fn same(&self, other: &MemoryTelemetry) -> bool {
        // Handles share one recorder; a fresh point shows up in both.
        let marker = format!("probe-identity-{:p}", Arc::as_ptr(&self.0));
        self.0.counter(&marker, None, None).add(1.0, &[]);
        other.points(Kind::Counter, &marker).is_some()
    }
}

/// TelemetryTest's adapter: Memory, recording the telemetry it is handed.
struct Probe {
    memory: Memory,
    state: Arc<ProbeState>,
}

impl Probe {
    fn record(&self, telemetry: Arc<dyn Telemetry>) {
        self.state.calls.fetch_add(1, Ordering::SeqCst);
        *self.state.telemetry.lock().unwrap_or_else(|e| e.into_inner()) = Some(Probed(telemetry));
    }
}

/// LoadResultsTelemetryTest's adapter: every load returns null.
struct NullLoad(Memory);

impl Adapter for Probe {
    fn load(
        &self,
        key: &str,
        ttl: i64,
        hash: &str,
    ) -> impl Future<Output = utopia_cache::Result<Option<Value>>> + Send {
        self.memory.load(key, ttl, hash)
    }
    fn save(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        ttl: i64,
    ) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.memory.save(key, data, hash, ttl)
    }
    fn touch(&self, key: &str, hash: &str) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.memory.touch(key, hash)
    }
    fn list(&self, key: &str) -> impl Future<Output = utopia_cache::Result<Vec<String>>> + Send {
        self.memory.list(key)
    }
    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.memory.purge(key, hash)
    }
    fn flush(&self) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.memory.flush()
    }
    fn ping(&self) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.memory.ping()
    }
    fn size(&self) -> impl Future<Output = utopia_cache::Result<i64>> + Send {
        self.memory.size()
    }
    fn name(&self, key: Option<&str>) -> &str {
        self.memory.name(key)
    }
    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        self.record(telemetry);
    }
}

impl Adapter for NullLoad {
    async fn load(&self, _: &str, _: i64, _: &str) -> utopia_cache::Result<Option<Value>> {
        Ok(Some(Value::Null))
    }
    fn save(
        &self,
        key: &str,
        data: &Value,
        hash: &str,
        ttl: i64,
    ) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.0.save(key, data, hash, ttl)
    }
    fn touch(&self, key: &str, hash: &str) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.0.touch(key, hash)
    }
    fn list(&self, key: &str) -> impl Future<Output = utopia_cache::Result<Vec<String>>> + Send {
        self.0.list(key)
    }
    fn purge(&self, key: &str, hash: &str) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.0.purge(key, hash)
    }
    fn flush(&self) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.0.flush()
    }
    fn ping(&self) -> impl Future<Output = utopia_cache::Result<bool>> + Send {
        self.0.ping()
    }
    fn size(&self) -> impl Future<Output = utopia_cache::Result<i64>> + Send {
        self.0.size()
    }
    fn name(&self, key: Option<&str>) -> &str {
        self.0.name(key)
    }
}
