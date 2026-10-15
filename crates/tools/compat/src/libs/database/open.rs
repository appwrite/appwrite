//! Opening databases and adapters (`db.open`, `Core::connect`,
//! `Core::engine`, `Core::cache`, `Core::pool`, `Core::mongo`): the
//! services of `compat.json`, reached the way each runtime reaches them.
//!
//! PHP's adapters name their server by the host of their DSN, which cache
//! keys carry; this driver may reach the same server through a forwarded
//! port, so its DSNs name PHP's host for `getHostname()` and dial this
//! runtime's address (PostgreSQL's `hostaddr`; for MySQL, a `host ` entry
//! the connection's exact-name parser skips and `getHostname()`'s trimming
//! parser reads, as PHP's do).

use std::sync::Arc;

use serde_json::Value as Json;
use utopia_cache::{AnyCodec, Cache, Memory, Redis};
use utopia_database::adapter::Adapter;
use utopia_database::adapter::memory::Memory as MemoryAdapter;
use utopia_database::adapter::pool::{Pool as PoolAdapter, Pooled};
use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::validator::authorization::Authorization;
use utopia_database::{Database, Error, Kind, Value};

use super::args::Args;
use super::ctx::{Ctx, Db, Fail, R, fault};
use super::fixtures;
use super::obj::Obj;

/// A service URL's parts: user, password, host, port and path.
pub struct Url {
    /// The user (decoded).
    pub user: String,
    /// The password (decoded).
    pub pass: String,
    /// The host.
    pub host: String,
    /// The port, if given.
    pub port: Option<u16>,
    /// The path, without its leading `/`.
    pub path: String,
}

fn decode_percent(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%'
            && i + 2 < bytes.len()
            && let Ok(b) = u8::from_str_radix(&s[i + 1..i + 3], 16)
        {
            out.push(b);
            i += 3;
            continue;
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `parse_url()` of a service URL.
pub fn parse_url(url: &str) -> Url {
    let rest = url.split_once("://").map_or(url, |(_, r)| r);
    let (authority, path) = rest.split_once('/').unwrap_or((rest, ""));
    let (credentials, host_port) = authority.rsplit_once('@').map_or(("", authority), |(c, h)| (c, h));
    let (user, pass) = credentials.split_once(':').unwrap_or((credentials, ""));
    let (host, port) = match host_port.rsplit_once(':') {
        Some((h, p)) => (h, p.parse().ok()),
        None => (host_port, None),
    };
    Url { user: decode_percent(user), pass: decode_percent(pass), host: host.to_owned(), port, path: path.to_owned() }
}

/// This runtime's URL of a service, and PHP's host of it.
fn endpoints(cx: &Ctx, service: &str) -> R<(Url, String)> {
    let url = parse_url(&cx.service(service)?);
    let php_host =
        cx.service(&format!("{service}@php")).map(|u| parse_url(&u).host).unwrap_or_else(|_| url.host.clone());
    Ok((url, php_host))
}

/// The PDO DSN of a PostgreSQL service (`Core::pdo`).
fn pgsql_dsn(cx: &Ctx, service: &str) -> R<(String, Url)> {
    let (url, php_host) = endpoints(cx, service)?;
    let dsn =
        format!("pgsql:host={php_host};hostaddr={};port={};dbname={}", url.host, url.port.unwrap_or(5432), url.path);
    Ok((dsn, url))
}

/// The PDO DSN of a MariaDB/MySQL service (`Core::connect`).
fn mysql_dsn(cx: &Ctx, service: &str) -> R<(String, Url)> {
    let (url, php_host) = endpoints(cx, service)?;
    let dsn = format!("mysql:host={};port={};host ={php_host};charset=utf8mb4", url.host, url.port.unwrap_or(3306));
    Ok((dsn, url))
}

/// An adapter of `driver` (`postgres`, `mariadb`, `mysql`) on its compat
/// service.
pub async fn connect(cx: &Ctx, driver: &str) -> R<Arc<Sql>> {
    match driver {
        "postgres" => postgres(cx, "database-postgres").await,
        "mariadb" | "mysql" => {
            let (service, engine) = if driver == "mariadb" {
                ("database-mariadb", Engine::MariaDb)
            } else {
                ("database-mysql", Engine::MySql)
            };
            let (dsn, url) = mysql_dsn(cx, service)?;
            Ok(Arc::new(Sql::connect(engine, &dsn, Some(&url.user), Some(&url.pass)).await?))
        }
        other => fault(format!("db.open: unknown driver `{other}`")),
    }
}

/// A PostgreSQL adapter on a service.
pub async fn postgres(cx: &Ctx, service: &str) -> R<Arc<Sql>> {
    let (dsn, url) = pgsql_dsn(cx, service)?;
    Ok(Arc::new(Sql::connect(Engine::Postgres, &dsn, Some(&url.user), Some(&url.pass)).await?))
}

/// A SQLite adapter on file `/tmp/compat-fs/${ns}/<file>`.
pub async fn sqlite(cx: &Ctx, file: &str) -> R<Arc<Sql>> {
    let dir = format!("/tmp/compat-fs/{}", cx.ns);
    std::fs::create_dir_all(&dir).map_err(|e| Fail::Fault(crate::adapter::Fault::new(format!("sqlite: {e}"))))?;
    if file.is_empty() || file.contains('/') {
        return fault("`file` is a file name");
    }
    Ok(Arc::new(Sql::connect(Engine::Sqlite, &format!("sqlite:{dir}/{file}"), None, None).await?))
}

/// A MongoDB adapter on service `database-mongo`, its client on database
/// `database` (`Core::mongo`).
pub async fn mongo(cx: &Ctx, database: &str) -> R<Arc<utopia_database::adapter::mongo::Mongo>> {
    let (url, php_host) = endpoints(cx, "database-mongo")?;
    let php_port = cx.service("database-mongo@php").ok().and_then(|u| parse_url(&u).port).or(url.port).unwrap_or(27017);
    let mut config =
        utopia_mongo::Config::new(database, php_host, i64::from(php_port), url.user.clone(), url.pass.clone());
    config.address = Some((url.host.clone(), url.port.unwrap_or(27017)));
    let client = utopia_mongo::Client::new(config).map_err(utopia_database::adapter::mongo::from_client)?;
    Ok(Arc::new(utopia_database::adapter::mongo::Mongo::new(Arc::new(client)).await?))
}

/// The Redis caches by kind and the compat service their entries live on
/// (`Core::REDIS_CACHES`).
const REDIS_CACHES: &[(&str, &str)] = &[
    ("redis", "database-redis"),
    ("redis-mirror", "database-redis-mirror"),
    ("redis-shared", "database-redis-shared"),
];

/// A connection to a compat Redis service, its database selected.
pub async fn redis(cx: &Ctx, service: &str) -> R<redis::aio::ConnectionManager> {
    let url = cx.service(service)?;
    let client =
        redis::Client::open(url.as_str()).map_err(|e| crate::adapter::Fault::new(format!("{service}: {e}")))?;
    Ok(client.get_connection_manager().await.map_err(|e| crate::adapter::Fault::new(format!("{service}: {e}")))?)
}

/// A cache by kind (`Core::cache`): `memory`, `none`, or a Redis cache with
/// Json entries (igbinary ones with the `-igbinary` suffix). `fresh`
/// empties a private Redis cache's database first.
pub async fn cache(cx: &Ctx, kind: &str, fresh: bool) -> R<Arc<Cache>> {
    match kind {
        "memory" => return Ok(Arc::new(Cache::from_dyn(Arc::new(Memory::new())))),
        "none" => return Ok(Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None)))),
        "unreachable" => return Ok(Arc::new(Cache::from_dyn(Arc::new(super::unreachable::Unreachable)))),
        _ => {}
    }
    let (store, codec) = match kind.strip_suffix("-igbinary") {
        Some(store) => (store, AnyCodec::Igbinary),
        None => (kind, AnyCodec::Json),
    };
    let Some((_, service)) = REDIS_CACHES.iter().find(|(name, _)| *name == store) else {
        return fault(
            "cache must be \"memory\", \"none\" or one of \"redis\", \"redis-mirror\", \"redis-shared\" (with \"-igbinary\" for igbinary entries)",
        );
    };
    let connection = redis(cx, service).await?;
    if fresh && store != "redis-shared" {
        let _: () = redis::cmd("FLUSHDB")
            .query_async(&mut connection.clone())
            .await
            .map_err(|e| crate::adapter::Fault::new(format!("{service}: {e}")))?;
    }
    Ok(Arc::new(Cache::from_dyn(Arc::new(Redis::with_codec(connection, codec)))))
}

/// A cache as an object (`{"$cache": true}`).
#[derive(Clone)]
pub struct CacheObj(pub Arc<Cache>);
crate::php_object!(CacheObj, "Cache", |_c| serde_json::json!({"$cache": true}));

/// The connection pool of a `db.open` with `pool` (`PoolFixture`).
pub struct PoolFixture {
    /// The pool.
    pub pool: utopia_pools::Pool<Pooled, utopia_pools::Stack<Pooled>>,
}

/// The pool of a `db.open` with `pool` (`Core::pool`): `{"size": n}`
/// (default 1) new connections of `driver` on demand, from a Stack, waiting
/// 0 s; with `"unreachable": true` every attempt fails as a refused
/// connection does; `{"of": <database handle>}` the pool of another Pool
/// database.
fn pool(cx: &Arc<Ctx>, spec: &Args, driver: &str) -> R<Arc<PoolFixture>> {
    if let Some(of) = spec.raw("of") {
        return match cx.db_of(of)?.pool {
            Some(fixture) => Ok(fixture),
            None => fault("db.open: `pool.of` is not a Pool database"),
        };
    }
    let unreachable = spec.bool_or("unreachable", false)?;
    let size = usize::try_from(spec.int_or("size", 1)?).unwrap_or(0);
    let (cx, driver) = (cx.clone(), driver.to_owned());
    let pool = utopia_pools::Pool::new(
        utopia_pools::Stack::new(),
        &driver.clone(),
        size,
        move || {
            let (cx, driver) = (cx.clone(), driver.clone());
            async move {
                if unreachable {
                    return Err(Error::new(Kind::Runtime, "SQLSTATE[HY000] [2002] Connection refused"));
                }
                let adapter: Arc<dyn Adapter> = match connect(&cx, &driver).await {
                    Ok(a) => a,
                    Err(Fail::Error(e)) => return Err(e),
                    Err(other) => return Err(Error::new(Kind::Exception, format!("{other:?}"))),
                };
                Ok(Pooled(adapter))
            }
        },
        0.0,
    )
    .map_err(|e| crate::adapter::Fault::new(e.to_string()))?;
    Ok(Arc::new(PoolFixture { pool }))
}

/// The class `{"$adapter": ...}` names an adapter by (`Core::libraryClass`).
pub fn adapter_class(adapter: &dyn Adapter) -> &'static str {
    let any = adapter.as_any();
    if let Some(sql) = any.downcast_ref::<Sql>() {
        return match sql.engine() {
            Engine::Postgres => "Postgres",
            Engine::MariaDb => "MariaDB",
            Engine::MySql => "MySQL",
            Engine::Sqlite => "SQLite",
        };
    }
    if any.is::<MemoryAdapter>() {
        return "Memory";
    }
    if any.is::<utopia_database::adapter::mongo::Mongo>() {
        return "Mongo";
    }
    if any.is::<utopia_database::adapter::redis::Redis<redis::aio::ConnectionManager>>() {
        return "Redis";
    }
    if let Some(pool) = any.downcast_ref::<PoolAdapter>() {
        return if format!("{pool:?}").contains("read_write: true") { "ReadWritePool" } else { "Pool" };
    }
    "Adapter"
}

/// An adapter held as an object (`{"$adapter": "<class>"}`).
#[derive(Clone)]
pub struct AdapterObj(pub Arc<dyn Adapter>);
crate::php_object!(AdapterObj, "Adapter", |a| serde_json::json!({"$adapter": adapter_class(&*a.0)}));

/// `db.open`: see the README (`database`: the core vocabulary).
pub async fn open(a: &Args) -> R<Obj> {
    const KNOWN: &[&str] = &[
        "namespace",
        "database",
        "shared_tables",
        "tenant",
        "tenant_per_document",
        "cache",
        "create",
        "roles",
        "adapter",
        "filters",
        "pool",
        "mirror",
        "redis",
        "engine",
        "file",
        "emulate_mysql",
        "detached",
        "driver",
        "schemaless",
        "hooks",
    ];
    for key in a.names() {
        if !KNOWN.contains(&key) {
            return fault(format!("db.open: unknown argument `{key}`"));
        }
    }
    let cx = &a.cx;
    fixtures::register_canonical();
    if a.given("mirror") {
        return fault("db.open: `mirror`: Mirror is not ported to the Rust database 8 crate (src/legacy)");
    }
    let mut sql = None;
    let mut pool_fixture = None;
    let adapter: Arc<dyn Adapter> = if let Some(peer) = a.raw("adapter").filter(|j| !j.is_null()) {
        let peer =
            cx.db_of(peer).map_err(|_| crate::adapter::Fault::new("db.open: `adapter` is not a database handle"))?;
        sql = peer.sql.clone();
        pool_fixture = peer.pool.clone();
        peer.database.adapter().clone()
    } else if let Some(spec) = a.raw("pool").filter(|j| !j.is_null()) {
        let spec = Args::new(cx, spec)?;
        let driver = a.string_or("driver", "postgres")?;
        let fixture = pool(cx, &spec, &driver)?;
        let adapter: Arc<dyn Adapter> = match spec.raw("read").filter(|j| !j.is_null()) {
            Some(read) => {
                let read = pool(cx, &Args::new(cx, read)?, &driver)?;
                Arc::new(PoolAdapter::read_write(fixture.pool.clone(), read.pool.clone()))
            }
            None => Arc::new(PoolAdapter::new(fixture.pool.clone())),
        };
        if !spec.bool_or("unreachable", false)? {
            sql = Some(connect(cx, &driver).await?);
        }
        pool_fixture = Some(fixture);
        adapter
    } else if a.bool_or("redis", false)? {
        let connection = redis(cx, "database-redis-adapter").await?;
        Arc::new(utopia_database::adapter::redis::Redis::new(connection))
    } else {
        match a.string_or("engine", "postgres")?.as_str() {
            "mongo" => mongo(cx, &a.string_or("database", &cx.ns)?).await?,
            "postgres" => {
                let adapter = connect(cx, &a.string_or("driver", "postgres")?).await?;
                sql = Some(adapter.clone());
                adapter
            }
            "sqlite" => {
                let adapter = sqlite(cx, &a.string_or("file", "database.sqlite")?).await?;
                adapter.set_emulate_mysql(a.bool_or("emulate_mysql", false)?);
                sql = Some(adapter.clone());
                adapter
            }
            "memory" => Arc::new(MemoryAdapter::new()),
            _ => return fault("db.open: `engine` must be \"postgres\", \"sqlite\", \"memory\" or \"mongo\""),
        }
    };
    let detached = a.bool_or("detached", false)?;
    let peer = a.given("adapter") || detached;
    let cache = cache(cx, &a.string_or("cache", "memory")?, !peer).await?;
    let codecs = match a.raw("filters") {
        None | Some(Json::Null) => Vec::new(),
        Some(list) => super::codec::list(list)?
            .into_iter()
            .map(|c| fixtures::make_codec(c).map(|c| c.0))
            .collect::<R<Vec<_>>>()?,
    };
    let db =
        if codecs.is_empty() { Database::new(adapter, cache) } else { Database::with_filters(adapter, cache, codecs)? };
    let authorization = Authorization::new(true);
    for role in a.opt_strings("roles")?.unwrap_or_else(|| vec!["any".to_owned()]) {
        authorization.add_role(&role);
    }
    db.set_authorization(Arc::new(authorization));
    if a.bool_or("schemaless", false)? {
        db.set_schemaless(true)?;
    }
    let hooks = match a.raw("hooks") {
        None | Some(Json::Null) => vec![Json::from("Relationships"), Json::from("Permissions")],
        Some(list) => super::codec::list(list)?.into_iter().cloned().collect(),
    };
    for hook in hooks {
        db.add_hook(fixtures::make_hook(&hook)?.hook)?;
    }
    if !peer {
        db.set_database(&a.string_or("database", &cx.ns)?);
    }
    if a.has("shared_tables") {
        db.set_shared_tables(a.bool_or("shared_tables", false)?);
    }
    if a.has("tenant") {
        db.set_tenant(a.value("tenant")?);
    }
    if a.has("tenant_per_document") {
        db.set_tenant_per_document(a.bool_or("tenant_per_document", false)?);
    }
    if !peer || a.has("namespace") {
        db.set_namespace(&a.string_or("namespace", "compat")?);
    }
    let handle = Db { database: db.clone(), sql, pool: pool_fixture };
    if !peer {
        cx.set_current(handle.clone());
    }
    if !peer && a.bool_or("create", true)? {
        db.create(None).await?;
    }
    let _ = Value::Null;
    Ok(Obj::Value(super::codec::decode(&cx.handle_db(handle))?))
}
