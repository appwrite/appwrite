//! What every `core` area shares: the session's databases, argument
//! decoding, result encoding, callbacks (`{"steps": [...]}`) and the e2e
//! suite's fixtures (filters, formats, listeners, transformations) with
//! their log. The twin of the `Core` class of `tests/compat/database/ops/core.php`;
//! `tests/compat/README.md` (`database`, the core vocabulary) documents the
//! wire forms.
//!
//! An operation handler is `async fn(op, &Json, &mut Session) -> R<Json>`:
//! [`Args`] decodes its arguments, the encoders below build its result,
//! and a library error (`?` on a `utopia_database::Result`) becomes the
//! operation's `{"$error": ...}` while a [`Fault`] stops the case.

use std::collections::HashMap;
use std::sync::{Arc, LazyLock, Mutex as StdMutex};

use async_trait::async_trait;
use base64::Engine as _;
use base64::engine::general_purpose::STANDARD as BASE64;
use futures_util::future::BoxFuture;
use php_std::datetime::{DateTime, TzInfo};
use serde_json::{Map, Value as Json, json};
use utopia_cache::{AnyCodec, Cache, Memory, Redis};
use utopia_database::adapter::Transform;
use utopia_database::adapter::mariadb::MariaDB;
use utopia_database::adapter::pool::{Pool as PoolAdapter, Pooled};
use utopia_database::adapter::postgres::Postgres;
use utopia_database::database::{Callback, Filter, Listener};
use utopia_database::document::Document;
use utopia_database::query::Query;
use utopia_database::value::{Array, ArrayKey, Str, Value, to_text};
use utopia_database::{Authorization, Database, Error, Kind};

use super::super::codec;
use crate::adapter::{Fault, OpResult, Outcome, Session};

// ----- Results -----

/// Why an operation did not return a value: the library raised an error
/// (the operation's result is `{"$error": ...}`), or the harness failed
/// (a [`Fault`]: malformed arguments, unknown handle, ...).
#[derive(Debug)]
pub enum Fail {
    /// A library error: the operation's result.
    Error(Error),
    /// A harness problem: stops the case.
    Fault(Fault),
}

impl From<Error> for Fail {
    fn from(e: Error) -> Self {
        Fail::Error(e)
    }
}

impl From<Fault> for Fail {
    fn from(f: Fault) -> Self {
        Fail::Fault(f)
    }
}

/// What an operation handler returns.
pub type R<T> = Result<T, Fail>;

/// A handler's result as the driver reports it.
pub fn outcome(r: R<Json>) -> OpResult {
    match r {
        Ok(json) => Ok(Outcome::Ok(json)),
        Err(Fail::Error(e)) => Ok(codec::error(&e)),
        Err(Fail::Fault(f)) => Err(f),
    }
}

/// A fault with a message.
pub fn fault<T>(message: impl Into<String>) -> R<T> {
    Err(Fail::Fault(Fault::new(message)))
}

// ----- Encoding (Core::result) -----

/// A library value (`Values::encode`).
pub fn encode(value: &Value) -> Json {
    codec::encode(value)
}

/// A document (`{"$document": {...}}`, plus `$class` for a custom type).
pub fn document(d: &Document) -> Json {
    codec::encode_document(d)
}

/// Documents, as a list.
pub fn documents(docs: &[Document]) -> Json {
    codec::encode_documents(docs)
}

/// Strings, as a list.
pub fn strings<S: AsRef<str>>(list: &[S]) -> Json {
    Json::Array(list.iter().map(|s| Json::String(s.as_ref().to_owned())).collect())
}

/// The database itself, which fluent setters return: `{"$database": true}`.
pub fn database_marker() -> Json {
    json!({"$database": true})
}

/// An adapter: `{"$adapter": "<PHP class short name>"}` (`Postgres`,
/// `Pool`, `Redis`, `Mirror`, `SQLite`, `Memory`, `MariaDB`, `MySQL`, `Mongo`).
pub fn adapter_marker(name: &str) -> Json {
    json!({"$adapter": name})
}

/// A cache: `{"$cache": true}`.
pub fn cache_marker() -> Json {
    json!({"$cache": true})
}

/// An authorization: `{"$authorization": true}`.
pub fn authorization_marker() -> Json {
    json!({"$authorization": true})
}

/// A `DateTime`: `{"$datetime": "Y-m-d\TH:i:s.uP"}`.
pub fn datetime(d: &DateTime) -> Json {
    json!({"$datetime": String::from_utf8_lossy(&d.format(b"Y-m-d\\TH:i:s.uP"))})
}

/// An exception passed as a value (`onError`'s argument):
/// `{"$error": {"class", "message"}}`.
pub fn error(e: &Error) -> Json {
    json!({"$error": {"class": e.php_class(), "message": e.message()}})
}

// ----- The session's databases (CoreState) -----

/// A database handle: what `db.open` returns and `db` arguments name.
#[derive(Clone)]
pub struct Db {
    /// The database.
    pub database: Database,
    /// Its PostgreSQL adapter (`db.sql` runs on its connection); `None` for
    /// none of its own.
    pub postgres: Option<Arc<Postgres>>,
    /// The short name of its adapter's PHP class (`{"$adapter": ...}`).
    pub adapter_name: &'static str,
    /// The pool of a Pool database and its Pool adapter (`db.pool`).
    pub pool: Option<(Arc<PoolFixture>, Arc<PoolAdapter>)>,
    /// The mirror a database opened with `mirror` is (its `database` is the
    /// mirror's own).
    pub mirror: Option<Arc<MirrorHandle>>,
    /// Its SQLite adapter (`db.sql` runs on its connection), for engine
    /// `sqlite`.
    pub sqlite: Option<Arc<utopia_database::adapter::sqlite::Sqlite>>,
    /// Its in-memory adapter (`db.memory_data` reads it), for engine
    /// `memory`.
    pub memory: Option<Arc<utopia_database::adapter::memory::Memory>>,
    /// Its MariaDB or MySQL adapter (`db.sql` runs on its connection), for
    /// `db.open` with `driver` `mariadb` or `mysql`.
    pub mariadb: Option<Arc<MariaDB>>,
    /// Its MongoDB adapter (`engine: "mongo"`), for the operations only
    /// that adapter has.
    pub mongo: Option<Arc<utopia_database::adapter::mongo::Mongo>>,
}

/// A mirror and the handles of its source and destination (`db.mirror`).
pub struct MirrorHandle {
    /// The mirror.
    pub mirror: utopia_database::mirror::Mirror,
    /// Its source, with its connection for `db.sql`.
    pub source: Db,
    /// Its destination, if any.
    pub destination: Option<Db>,
}

/// The connection pool of a `db.open` with `pool` (PHP's `PoolFixture`).
pub struct PoolFixture {
    /// The connections its `init` opened (attempts included).
    pub opened: Arc<std::sync::atomic::AtomicI64>,
    /// The pool.
    pub pool: utopia_pools::Pool<Pooled, utopia_pools::Stack<Pooled>>,
}

/// What a session's databases share (PHP's `CoreState`).
#[derive(Default)]
pub struct State {
    /// The current database (`db.open`, `db.select`).
    pub current: Option<Db>,
    /// What fixtures logged since the last `db.log`.
    pub log: Log,
    /// Whether the last operation took callbacks (its result is then
    /// `{"result", "calls"}`); `return: true` steps read it.
    pub called: bool,
}

/// The session's core state.
pub fn state(session: &mut Session) -> &mut State {
    session.state::<State>()
}

/// The log fixtures write to: the session whose operation runs now
/// (`Core::enter`).
/// A log fixtures append to.
pub type Log = Arc<StdMutex<Vec<Json>>>;

static ACTIVE_LOG: LazyLock<StdMutex<Option<Log>>> = LazyLock::new(|| StdMutex::new(None));

fn lock<T>(m: &StdMutex<T>) -> std::sync::MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|e| e.into_inner())
}

/// Makes `session` the one fixtures log into, and clears the callback flag.
/// The dispatcher calls it before every operation.
pub fn enter(session: &mut Session) {
    let s = state(session);
    s.called = false;
    *lock(&ACTIVE_LOG) = Some(s.log.clone());
}

/// Appends an entry to the log of the session running now (`Core::log`).
pub fn log(entry: Json) {
    if let Some(log) = lock(&ACTIVE_LOG).as_ref() {
        lock(log).push(entry);
    }
}

/// The log since the last call, cleared (`db.log`).
pub fn take_log(session: &mut Session) -> Vec<Json> {
    std::mem::take(&mut *lock(&state(session).log))
}

/// The database handle an operation acts on: `db` (a handle) or the
/// session's current one (`Core::db`).
pub fn db_handle(args: &Json, session: &mut Session) -> R<Db> {
    match args.get("db") {
        Some(h) if !h.is_null() => {
            Ok(session.get::<Db>(h).map_err(|_| Fault::new("`db` is not a database handle"))?.clone())
        }
        _ => match &state(session).current {
            Some(db) => Ok(db.clone()),
            None => fault("no database: run `db.open` first"),
        },
    }
}

/// The database an operation acts on (see [`db_handle`]).
pub fn db(args: &Json, session: &mut Session) -> R<Database> {
    Ok(db_handle(args, session)?.database)
}

// ----- Arguments (Core::argument, Core::tags) -----

/// Resolves the argument tags (`Core::tags`), recursively: `{"$now":
/// <seconds>, "format": "<DateTime::format>"}` is the string of now plus the
/// seconds, `{"$repeat": "<text>", "times": <n>}` the repeated text.
/// `{"$datetime": "<string>"}` stays a tag ([`Args::datetime`] reads it).
pub fn tags(json: &Json) -> R<Json> {
    Ok(match json {
        Json::Array(items) => Json::Array(items.iter().map(tags).collect::<R<_>>()?),
        Json::Object(o) => {
            if o.len() == 2 && o.contains_key("$repeat") && o.contains_key("times") {
                let text = to_text(&codec::decode(&o["$repeat"])?);
                let times = php_int(&codec::decode(&o["times"])?).max(0) as usize;
                return Ok(Json::String(text.repeat(times)));
            }
            if o.len() == 2 && o.contains_key("$now") && o.contains_key("format") {
                let seconds = php_int(&codec::decode(&o["$now"])?);
                let format = to_text(&codec::decode(&o["format"])?);
                return Ok(Json::String(now_plus(seconds, &format)?));
            }
            if o.len() == 1 && o.get("$datetime").is_some_and(Json::is_string) {
                return Ok(json.clone());
            }
            Json::Object(o.iter().map(|(k, v)| Ok((k.clone(), tags(v)?))).collect::<R<_>>()?)
        }
        other => other.clone(),
    })
}

/// `(new DateTime())->modify("+N seconds")->format($format)`.
fn now_plus(seconds: i64, format: &str) -> R<String> {
    let modifier = format!("{}{seconds} seconds", if seconds < 0 { "" } else { "+" });
    let mut now = DateTime::new(b"now", None, &TzInfo::utc()).map_err(|e| Fault::new(e.message()))?;
    now.modify(modifier.as_bytes()).map_err(|e| Fault::new(e.message()))?;
    Ok(String::from_utf8_lossy(&now.format(format.as_bytes())).into_owned())
}

/// `new DateTime($text)` (relative forms count from now).
pub fn parse_datetime(text: &str) -> R<DateTime> {
    DateTime::new(text.as_bytes(), None, &TzInfo::utc())
        .map_err(|e| Fail::Error(Error::new(Kind::Exception, e.message())))
}

/// `(int) $value`.
pub fn php_int(v: &Value) -> i64 {
    utopia_database::adapter::postgres::pdo::to_long(v)
}

/// An operation's named arguments, decoded for the parameter types of the
/// method they go to (`Core::argument`). Absent and `null` arguments are
/// the same to the `opt_*` / `*_or` getters (PHP passes `null` or uses the
/// default); required getters fault on them.
#[derive(Clone, Copy)]
pub struct Args<'a> {
    json: &'a Json,
}

impl<'a> Args<'a> {
    /// The arguments of an operation (a JSON object).
    pub fn new(json: &'a Json) -> Self {
        Self { json }
    }

    /// The argument as sent (no tags resolved).
    pub fn raw(&self, key: &str) -> Option<&'a Json> {
        self.json.get(key)
    }

    /// The arguments as an object (`None` when they are not one).
    pub fn raw_object(&self) -> Option<&'a Map<String, Json>> {
        self.json.as_object()
    }

    /// Whether the argument is present (even as `null`).
    pub fn has(&self, key: &str) -> bool {
        self.json.get(key).is_some()
    }

    /// Whether the argument is present and not `null`.
    pub fn given(&self, key: &str) -> bool {
        self.json.get(key).is_some_and(|j| !j.is_null())
    }

    /// The argument with its tags resolved (`None`: absent or `null`).
    pub fn json(&self, key: &str) -> R<Option<Json>> {
        match self.json.get(key) {
            None | Some(Json::Null) => Ok(None),
            Some(j) => tags(j).map(Some),
        }
    }

    fn missing<T>(&self, key: &str) -> R<T> {
        fault(format!("missing argument `{key}`"))
    }

    /// A plain value (`Values::decode`); `null` when absent.
    pub fn value(&self, key: &str) -> R<Value> {
        match self.json(key)? {
            None => Ok(Value::Null),
            Some(j) => Ok(codec::decode(&j)?),
        }
    }

    /// A plain value, `None` when absent (`Some(Null)` when `null`).
    pub fn opt_value(&self, key: &str) -> R<Option<Value>> {
        if !self.has(key) {
            return Ok(None);
        }
        self.value(key).map(Some)
    }

    /// A required string (`(string)`-cast like a PHP `string` parameter).
    pub fn string(&self, key: &str) -> R<String> {
        match self.opt_string(key)? {
            Some(s) => Ok(s),
            None => self.missing(key),
        }
    }

    /// A string, `default` when absent or `null`.
    pub fn string_or(&self, key: &str, default: &str) -> R<String> {
        Ok(self.opt_string(key)?.unwrap_or_else(|| default.to_owned()))
    }

    /// A string, `None` when absent or `null`.
    pub fn opt_string(&self, key: &str) -> R<Option<String>> {
        match self.json(key)? {
            None => Ok(None),
            Some(j) => Ok(Some(to_text(&codec::decode(&j)?))),
        }
    }

    /// A required integer.
    pub fn int(&self, key: &str) -> R<i64> {
        match self.opt_int(key)? {
            Some(i) => Ok(i),
            None => self.missing(key),
        }
    }

    /// An integer, `default` when absent or `null`.
    pub fn int_or(&self, key: &str, default: i64) -> R<i64> {
        Ok(self.opt_int(key)?.unwrap_or(default))
    }

    /// An integer, `None` when absent or `null`.
    pub fn opt_int(&self, key: &str) -> R<Option<i64>> {
        match self.json(key)? {
            None => Ok(None),
            Some(j) => Ok(Some(php_int(&codec::decode(&j)?))),
        }
    }

    /// A required boolean (truthiness, like a PHP `bool` parameter).
    pub fn bool(&self, key: &str) -> R<bool> {
        match self.opt_bool(key)? {
            Some(b) => Ok(b),
            None => self.missing(key),
        }
    }

    /// A boolean, `default` when absent or `null`.
    pub fn bool_or(&self, key: &str, default: bool) -> R<bool> {
        Ok(self.opt_bool(key)?.unwrap_or(default))
    }

    /// A boolean, `None` when absent or `null`.
    pub fn opt_bool(&self, key: &str) -> R<Option<bool>> {
        match self.json(key)? {
            None => Ok(None),
            Some(j) => Ok(Some(codec::decode(&j)?.truthy())),
        }
    }

    /// A list of strings (empty when absent or `null`).
    pub fn strings(&self, key: &str) -> R<Vec<String>> {
        Ok(self.opt_strings(key)?.unwrap_or_default())
    }

    /// A list of strings, `None` when absent or `null`.
    pub fn opt_strings(&self, key: &str) -> R<Option<Vec<String>>> {
        match self.json(key)? {
            None => Ok(None),
            Some(j) => {
                Ok(Some(codec::list(&j)?.into_iter().map(|v| Ok(to_text(&codec::decode(v)?))).collect::<R<_>>()?))
            }
        }
    }

    /// An array (`Values::decodeArray`; empty when absent or `null`).
    pub fn array(&self, key: &str) -> R<Array> {
        match self.json(key)? {
            None => Ok(Array::new()),
            Some(j) => Ok(codec::decode_array(&j)?),
        }
    }

    /// A required document: a `$document` tag or a plain array
    /// (`Values::document`).
    pub fn document(&self, key: &str) -> R<Document> {
        match self.opt_document(key)? {
            Some(d) => Ok(d),
            None => self.missing(key),
        }
    }

    /// A document, `None` when absent or `null`.
    pub fn opt_document(&self, key: &str) -> R<Option<Document>> {
        match self.json(key)? {
            None => Ok(None),
            Some(j) => Ok(Some(codec::document(&j)?)),
        }
    }

    /// An `array<Document>` (`Values::documents`; empty when absent).
    pub fn documents(&self, key: &str) -> R<Vec<Document>> {
        match self.json(key)? {
            None => Ok(Vec::new()),
            Some(j) => Ok(codec::documents(&j)?),
        }
    }

    /// Queries (`Values::queries`: `$query` tags, arrays, strings; empty
    /// when absent). A parse error is the operation's error.
    pub fn queries(&self, key: &str) -> R<Vec<Query>> {
        match self.json(key)? {
            None => Ok(Vec::new()),
            Some(j) => Ok(codec::queries(&j)??),
        }
    }

    /// One query (a `Query` parameter).
    pub fn query(&self, key: &str) -> R<Query> {
        match self.json(key)? {
            None => self.missing(key),
            Some(j) => match codec::queries(&Json::Array(vec![j]))??.pop() {
                Some(q) => Ok(q),
                None => self.missing(key),
            },
        }
    }

    /// A `DateTime` parameter: a string for `new DateTime()` or a
    /// `{"$datetime": ...}` tag; `None` when absent or `null`.
    pub fn datetime(&self, key: &str) -> R<Option<DateTime>> {
        match self.json(key)? {
            None => Ok(None),
            Some(Json::Object(o)) if o.len() == 1 && o.contains_key("$datetime") => {
                parse_datetime(o["$datetime"].as_str().unwrap_or_default()).map(Some)
            }
            Some(j) => parse_datetime(&to_text(&codec::decode(&j)?)).map(Some),
        }
    }

    /// A `Cache` parameter: a cache kind (see [`cache`]).
    pub async fn cache(&self, key: &str, session: &mut Session) -> R<Arc<Cache>> {
        cache(&self.string_or(key, "memory")?, session, false).await
    }

    /// An `Authorization` parameter: `{"roles": [...]}` (a new one with
    /// those roles, besides `any`... as PHP's constructor gives) or a
    /// database handle (that database's own).
    pub fn authorization(&self, key: &str, session: &mut Session) -> R<Arc<Authorization>> {
        let Some(j) = self.raw(key) else {
            return self.missing(key);
        };
        if j.get("$handle").is_some() {
            let db = session
                .get::<Db>(j)
                .map_err(|_| Fault::new("an authorization is {\"roles\": [...]} or a database handle"))?;
            return Ok(db.database.authorization());
        }
        let authorization = Authorization::new();
        for role in Args::new(j).strings("roles")? {
            authorization.add_role(&role);
        }
        Ok(Arc::new(authorization))
    }
}

/// The Redis caches by kind and the compat service their entries live on
/// (`Core::REDIS_CACHES`): `redis` and `redis-mirror` are private to each
/// runtime, `redis-shared` is one both read and write.
const REDIS_CACHES: &[(&str, &str)] = &[
    ("redis", "database-redis"),
    ("redis-mirror", "database-redis-mirror"),
    ("redis-shared", "database-redis-shared"),
];

/// A cache by kind (`Core::cache`): `"memory"`, `"none"`, or a Redis cache
/// ([`REDIS_CACHES`]) with Json entries, igbinary ones with the `-igbinary`
/// suffix. `fresh` empties a private Redis cache's database first.
pub async fn cache(kind: &str, session: &mut Session, fresh: bool) -> R<Arc<Cache>> {
    match kind {
        "memory" => return Ok(Arc::new(Cache::from_dyn(Arc::new(Memory::new())))),
        "none" => return Ok(Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None)))),
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
    let connection = redis_connection(session, service).await?;
    if fresh && store != "redis-shared" {
        let _: () = redis::cmd("FLUSHDB")
            .query_async(&mut connection.clone())
            .await
            .map_err(|e| Fault::new(format!("{service}: {e}")))?;
    }
    Ok(Arc::new(Cache::from_dyn(Arc::new(Redis::with_codec(connection, codec)))))
}

/// A connection to a compat Redis service, its database selected.
pub async fn redis_connection(session: &mut Session, service: &str) -> R<redis::aio::ConnectionManager> {
    let url = session.service(service)?.to_owned();
    let client = redis::Client::open(url.as_str()).map_err(|e| Fault::new(format!("{service}: {e}")))?;
    Ok(client.get_connection_manager().await.map_err(|e| Fault::new(format!("{service}: {e}")))?)
}

/// `db.cache`: a method of the database's cache (`Core::cacheCall`). Saves
/// report whether they stored.
pub async fn cache_call(cache: &Cache, method: &str, a: &Args<'_>) -> R<Json> {
    let key = a.string_or("key", "")?;
    let hash = a.string_or("hash", "")?;
    let failed = |e: utopia_cache::Error| Fail::Error(Error::new(Kind::Exception, e.to_string()));
    Ok(match method {
        "load" => {
            let ttl = a.int_or("ttl", Database::TTL)?;
            match cache.load(&key, ttl, &hash).await.map_err(failed)? {
                Some(v) => encode(&utopia_database::value::from_plain(v)),
                None => Json::Bool(false),
            }
        }
        "save" => {
            let data = utopia_database::value::to_plain(&a.value("data")?);
            Json::Bool(cache.save(&key, &data, &hash, a.int_or("ttl", 0)?).await.map_err(failed)?)
        }
        "save_with_lease" => {
            let data = utopia_database::value::to_plain(&a.value("data")?);
            let generation = a.string_or("generation", "")?;
            Json::Bool(cache.save_with_lease(&key, &data, &hash, &generation).await.map_err(failed)?)
        }
        "get_generation" => Json::String(cache.generation(&key).await.map_err(failed)?),
        "touch" => Json::Bool(cache.touch(&key, &hash).await.map_err(failed)?),
        "list" => strings(&cache.list(&key).await.map_err(failed)?),
        "purge" => Json::Bool(cache.purge(&key, &hash).await.map_err(failed)?),
        _ => return fault(format!("db.cache: no method `{method}`")),
    })
}

// ----- Callbacks (Core::callback, Core::run) -----

/// The callbacks one operation passes to the library, and what they
/// recorded. Create it from the session once the operation's other
/// arguments are decoded (it holds the session: steps run operations on
/// it), make each callable parameter with [`Calls::callback`], call the
/// library, then [`Calls::finish`] the result.
///
/// ```ignore
/// let db = args::db(json, session)?;
/// let calls = Calls::new(session);
/// let cb = calls.callback("callback", a.raw("callback"))?;
/// let result = match &cb {
///     Some(cb) => db.skip_relationships(|| cb.invoke(Vec::new())).await,
///     None => Ok(None),
/// };
/// calls.finish(result.map(|v| args::encode(&v.unwrap_or(Value::Null))).map_err(Fail::from))
/// ```
pub struct Calls<'s> {
    session: tokio::sync::Mutex<&'s mut Session>,
    record: StdMutex<Vec<(String, Vec<Invocation>)>>,
    fault: StdMutex<Option<Fault>>,
}

/// One call of a callback: its arguments (encoded) and its steps' results.
struct Invocation {
    args: Vec<Json>,
    steps: Vec<Json>,
}

impl<'s> Calls<'s> {
    /// Callbacks running their steps on `session`.
    pub fn new(session: &'s mut Session) -> Self {
        Self {
            session: tokio::sync::Mutex::new(session),
            record: StdMutex::new(Vec::new()),
            fault: StdMutex::new(None),
        }
    }

    /// The callback for parameter `name` from its argument: `None` for an
    /// absent or `null` one (pass `null`), else `true` / `{}` (records its
    /// calls) or `{"steps": [...]}` (also runs the steps at each call).
    pub fn callback<'c>(&'c self, name: &str, spec: Option<&Json>) -> R<Option<Cb<'c, 's>>> {
        let steps: Vec<Json> = match spec {
            None | Some(Json::Null) => return Ok(None),
            Some(Json::Bool(true)) => Vec::new(),
            Some(Json::Object(o)) if o.is_empty() => Vec::new(),
            Some(Json::Object(o)) => match o.get("steps") {
                Some(Json::Array(steps)) => steps.clone(),
                _ => return fault("a callback is null, true or {\"steps\": [...]}"),
            },
            _ => return fault("a callback is null, true or {\"steps\": [...]}"),
        };
        let mut record = lock(&self.record);
        record.push((name.to_owned(), Vec::new()));
        Ok(Some(Cb { calls: self, slot: record.len() - 1, steps: steps.into() }))
    }

    /// The operation's result: an error as is; with callbacks, `{"result":
    /// <result>, "calls": {"<param>": [{"args", "steps"}, ...]}}`. A fault
    /// a step raised wins over everything.
    pub fn finish(self, result: R<Json>) -> R<Json> {
        if let Some(f) = lock(&self.fault).take() {
            return Err(Fail::Fault(f));
        }
        let record = std::mem::take(&mut *lock(&self.record));
        let session = self.session.into_inner();
        state(session).called = !record.is_empty();
        let result = result?;
        if record.is_empty() {
            return Ok(result);
        }
        let mut calls = Map::new();
        for (name, invocations) in record {
            let list = invocations.into_iter().map(|i| json!({"args": i.args, "steps": i.steps})).collect();
            calls.insert(name, Json::Array(list));
        }
        Ok(json!({"result": result, "calls": calls}))
    }

    fn fail(&self, f: Fault) -> Error {
        let message = f.0.clone();
        lock(&self.fault).get_or_insert(f);
        Error::new(Kind::Exception, format!("compat fault: {message}"))
    }
}

/// A callback of [`Calls`]: call [`Cb::invoke`] from the closure the
/// library takes, or pass it as a [`Callback`] (`&mut cb.clone()`).
#[derive(Clone)]
pub struct Cb<'c, 's> {
    calls: &'c Calls<'s>,
    slot: usize,
    steps: Arc<[Json]>,
}

impl Cb<'_, '_> {
    /// Calls the callback with `args` (encoded, as the steps' `$arg` see
    /// them): records the call, runs the steps, and returns what the
    /// callback returns (`$return`, or a `return: true` step's result;
    /// `None` without one). A step's uncaught error is returned as the
    /// callback's exception.
    pub async fn invoke(&self, args: Vec<Json>) -> Result<Option<Value>, Error> {
        let index = {
            let mut record = lock(&self.calls.record);
            let list = &mut record[self.slot].1;
            list.push(Invocation { args: args.clone(), steps: Vec::new() });
            list.len() - 1
        };
        let push = |result: Json| lock(&self.calls.record)[self.slot].1[index].steps.push(result);
        let mut locals: HashMap<String, Json> = HashMap::new();
        let mut returned = None;
        for step in self.steps.iter() {
            let Some(op) = step.get("op").and_then(Json::as_str) else {
                return Err(self.calls.fail(Fault::new("a step is {\"op\": ..., \"args\": {...}}")));
            };
            let step_args = match resolve(step.get("args").unwrap_or(&Json::Null), &locals, &args) {
                Ok(Json::Null) => Json::Object(Map::new()),
                Ok(a) => a,
                Err(f) => return Err(self.calls.fail(f)),
            };
            if op == "$throw" {
                push(Json::Null);
                let a = Args::new(&step_args);
                let class = a.string_or("class", "Exception").unwrap_or_default();
                let message = a.string_or("message", "").unwrap_or_default();
                return Err(match Kind::from_php_class(&class) {
                    Some(kind) => Error::new(kind, message),
                    None => self.calls.fail(Fault::new(format!("$throw: no error kind for {class}"))),
                });
            }
            if op == "$return" {
                push(Json::Null);
                let value = tags(step_args.get("value").unwrap_or(&Json::Null))
                    .and_then(|j| codec::decode(&j).map_err(Fail::Fault));
                match value {
                    Ok(v) => returned = Some(v),
                    Err(Fail::Fault(f)) => return Err(self.calls.fail(f)),
                    Err(Fail::Error(e)) => return Err(e),
                }
                continue;
            }
            let (result, called) = {
                let mut guard = self.calls.session.lock().await;
                let session: &mut Session = &mut guard;
                let result = call_boxed(op, &step_args, session).await;
                (result, state(session).called)
            };
            match result {
                Err(f) => return Err(self.calls.fail(f)),
                Ok(Outcome::Err { class, message }) => {
                    let text = match &message {
                        Json::String(s) => s.clone(),
                        other => crate::adapter::bytes(other)
                            .map(|b| String::from_utf8_lossy(&b).into_owned())
                            .unwrap_or_default(),
                    };
                    push(json!({"$error": {"class": class, "message": message}}));
                    if !step.get("catch").and_then(Json::as_bool).unwrap_or(false) {
                        return Err(match Kind::from_php_class(&class) {
                            Some(kind) => Error::new(kind, text),
                            None => self
                                .calls
                                .fail(Fault::new(format!("a step raised {class}, which no error kind stands for"))),
                        });
                    }
                }
                Ok(Outcome::Ok(json)) => {
                    push(json.clone());
                    if step.get("return").and_then(Json::as_bool).unwrap_or(false) {
                        let raw = if called { json.get("result").cloned().unwrap_or(Json::Null) } else { json.clone() };
                        match codec::decode(&raw) {
                            Ok(v) => returned = Some(v),
                            Err(f) => return Err(self.calls.fail(f)),
                        }
                    }
                    if let Some(bind) = step.get("bind").and_then(Json::as_str) {
                        locals.insert(bind.to_owned(), json);
                    }
                }
            }
        }
        Ok(returned)
    }
}

/// The arguments a library passes to a [`Callback`], encoded as the steps'
/// `$arg` and the recorded `args` see them.
pub trait CallArgs: Send + 'static {
    /// The arguments, in order.
    fn encode(&self) -> Vec<Json>;
}

impl CallArgs for Document {
    fn encode(&self) -> Vec<Json> {
        vec![document(self)]
    }
}

impl CallArgs for Error {
    fn encode(&self) -> Vec<Json> {
        vec![error(self)]
    }
}

impl CallArgs for (Document, Document) {
    fn encode(&self) -> Vec<Json> {
        vec![document(&self.0), document(&self.1)]
    }
}

impl CallArgs for (Document, Option<Document>) {
    fn encode(&self) -> Vec<Json> {
        vec![document(&self.0), self.1.as_ref().map(document).unwrap_or(Json::Null)]
    }
}

#[async_trait]
impl<A: CallArgs> Callback<A> for Cb<'_, '_> {
    async fn call(&mut self, args: A) -> utopia_database::Result<()> {
        self.invoke(args.encode()).await.map(|_| ())
    }
}

/// Runs a step's operation (any `core` operation), boxed: steps recurse
/// into the dispatcher.
pub fn call_boxed<'a>(op: &'a str, args: &'a Json, session: &'a mut Session) -> BoxFuture<'a, OpResult> {
    Box::pin(super::call(op, args, session))
}

/// `Core::locals`: `{"$local": <bind>, "path"?}` is an earlier step's
/// result, `{"$arg": <n>, "path"?}` the callback's n-th argument, anywhere
/// in a step's arguments.
fn resolve(value: &Json, locals: &HashMap<String, Json>, args: &[Json]) -> Result<Json, Fault> {
    match value {
        Json::Object(o) => {
            let path = o.get("path").and_then(Json::as_str).unwrap_or("");
            if let Some(Json::String(name)) = o.get("$local") {
                let bound = locals.get(name).ok_or_else(|| Fault::new(format!("`$local` to unknown step `{name}`")))?;
                return pointer(bound, path);
            }
            if let Some(n) = o.get("$arg").and_then(Json::as_u64) {
                let arg =
                    args.get(n as usize).ok_or_else(|| Fault::new(format!("the callback has no argument {n}")))?;
                return pointer(arg, path);
            }
            Ok(Json::Object(
                o.iter().map(|(k, v)| Ok((k.clone(), resolve(v, locals, args)?))).collect::<Result<_, Fault>>()?,
            ))
        }
        Json::Array(items) => {
            Ok(Json::Array(items.iter().map(|v| resolve(v, locals, args)).collect::<Result<_, _>>()?))
        }
        other => Ok(other.clone()),
    }
}

/// A JSON pointer into an encoded value (`Core::pointer`).
fn pointer(value: &Json, path: &str) -> Result<Json, Fault> {
    if path.is_empty() {
        return Ok(value.clone());
    }
    let mut current = value;
    for segment in path.split('/').skip(1) {
        let segment = segment.replace("~1", "/").replace("~0", "~");
        current = match current {
            Json::Object(o) => o.get(&segment),
            Json::Array(items) => segment.parse::<usize>().ok().and_then(|i| items.get(i)),
            _ => None,
        }
        .ok_or_else(|| Fault::new(format!("nothing at `{path}`")))?;
    }
    Ok(current.clone())
}

// ----- Fixtures (Core::filter, Core::format, Core::listener, Core::transform) -----

/// The PHP class whose static methods the fixture filters are
/// (`FilterFixtures` in `ops/core.php`): their signatures are
/// `Class::method`, which cache keys carry.
const FIXTURES_PHP: &str = "Tests\\Compat\\Database\\FilterFixtures";

/// The filter fixtures, which every `db.open` registers (PHP's
/// `Core::FILTERS`).
pub const FILTERS: [&str; 3] = ["encrypt", "subQueryProbe", "operator_double_decode"];

/// A filter fixture of the e2e suite (`Core::filter`): `encrypt`,
/// `subQueryProbe` or `operator_double_decode`, logging each call
/// (`{"filter", "call"}`).
pub fn filter(name: &str) -> R<Arc<dyn Filter>> {
    let kind = match name {
        "encrypt" => FixtureKind::Encrypt,
        "subQueryProbe" => FixtureKind::SubQueryProbe,
        "operator_double_decode" => FixtureKind::OperatorDoubleDecode,
        _ => return fault(format!("no filter fixture `{name}`")),
    };
    Ok(Arc::new(FixtureFilter { name: name.to_owned(), kind }))
}

#[derive(Clone, Copy)]
enum FixtureKind {
    Encrypt,
    SubQueryProbe,
    OperatorDoubleDecode,
}

impl FixtureKind {
    /// The prefix of its `FilterFixtures` methods (`<prefix>Encode`,
    /// `<prefix>Decode`).
    fn method_prefix(self) -> &'static str {
        match self {
            FixtureKind::Encrypt => "encrypt",
            FixtureKind::SubQueryProbe => "subQueryProbe",
            FixtureKind::OperatorDoubleDecode => "operatorDoubleDecode",
        }
    }
}

struct FixtureFilter {
    name: String,
    kind: FixtureKind,
}

fn json_encode(value: &Value) -> Value {
    match php_std::json::encode(value, php_std::json::Flags::NONE, 512) {
        Ok(s) => Value::Str(Str::from(s)),
        Err(_) => Value::Bool(false),
    }
}

/// `base64_decode(json_decode($value, true)['data'])`.
fn data_of(value: &Value) -> Value {
    let text = match value {
        Value::Str(s) => s.as_bytes().to_vec(),
        other => to_text(other).into_bytes(),
    };
    let decoded = php_std::json::decode(&text, Some(true), 512, php_std::json::Flags::NONE)
        .map(utopia_database::value::from_plain)
        .unwrap_or(Value::Null);
    let data = match &decoded {
        Value::Array(a) => a.get_str("data").map(to_text).unwrap_or_default(),
        _ => String::new(),
    };
    match BASE64.decode(data.as_bytes()) {
        Ok(bytes) => Value::Str(Str::from(bytes)),
        Err(_) => Value::Bool(false),
    }
}

#[async_trait]
impl Filter for FixtureFilter {
    async fn encode(&self, value: Value, _document: &Document, _database: &Database) -> utopia_database::Result<Value> {
        log(json!({"filter": self.name, "call": "encode"}));
        Ok(match self.kind {
            FixtureKind::Encrypt => {
                let mut a = Array::new();
                a.set(ArrayKey::from("data"), Value::Str(Str::from(BASE64.encode(to_text(&value)))));
                a.set(ArrayKey::from("method"), Value::Str(Str::from("base64")));
                a.set(ArrayKey::from("version"), Value::Str(Str::from("v1")));
                json_encode(&Value::Array(a))
            }
            FixtureKind::SubQueryProbe => Value::Null,
            FixtureKind::OperatorDoubleDecode => {
                let mut a = Array::new();
                a.set(ArrayKey::from("data"), Value::Str(Str::from(BASE64.encode(to_text(&value)))));
                json_encode(&Value::Array(a))
            }
        })
    }

    async fn decode(&self, value: Value, _document: &Document, _database: &Database) -> utopia_database::Result<Value> {
        log(json!({"filter": self.name, "call": "decode"}));
        Ok(match self.kind {
            FixtureKind::SubQueryProbe => {
                Value::Array([Value::Str(Str::from("fanned")), Value::Str(Str::from("out"))].into_iter().collect())
            }
            _ if matches!(value, Value::Null) => Value::Null,
            _ => data_of(&value),
        })
    }

    fn signature(&self) -> String {
        let prefix = self.kind.method_prefix();
        format!("{FIXTURES_PHP}::{prefix}Encode:{FIXTURES_PHP}::{prefix}Decode")
    }
}

/// Registers a Structure format fixture (`Core::format`): `priceRange` or
/// `priceRangeNew`, a `Range` from the attribute's `formatOptions`
/// (integer).
pub fn add_format(name: &str) -> R<()> {
    if name != "priceRange" && name != "priceRangeNew" {
        return fault(format!("no format fixture `{name}`"));
    }
    utopia_database::validator::structure::formats::add(
        name,
        Arc::new(|attribute: &Document| {
            let options = match attribute.get("formatOptions") {
                Some(Value::Array(a)) => a.clone(),
                _ => Array::new(),
            };
            let bound = |k: &str| options.get_str(k).map(php_int).unwrap_or(0);
            Box::new(utopia_validators::Range::new(bound("min"), bound("max")))
        }),
        Database::VAR_INTEGER,
    );
    Ok(())
}

/// A listener fixture (`Core::listener`): logs each event
/// (`{"event", "listener", "data"}`); `None` for a `null` / `false` spec.
pub fn listener(name: &str, spec: Option<&Json>) -> Option<Listener> {
    match spec {
        None | Some(Json::Null) | Some(Json::Bool(false)) => None,
        Some(_) => {
            let name = name.to_owned();
            Some(Arc::new(move |event: &str, data: &Value| {
                log(json!({"event": event, "listener": name, "data": encode(data)}));
                Ok(())
            }))
        }
    }
}

/// A query transformation fixture (`Core::transform`): `{"append":
/// "<sql>"}` appends to the statement and logs the result (`{"transform",
/// "name", "sql"}`); `None` for `null`.
pub fn transform(event: &str, name: &str, spec: Option<&Json>) -> R<Option<Transform>> {
    let spec = match spec {
        None | Some(Json::Null) => return Ok(None),
        Some(s @ Json::Object(_)) => s,
        Some(_) => return fault("a transformation is null or {\"append\": \"...\"}"),
    };
    let append = Args::new(spec).string_or("append", "")?;
    let (event, name) = (event.to_owned(), name.to_owned());
    Ok(Some(Arc::new(move |sql: String| {
        let sql = sql + &append;
        log(json!({"transform": event, "name": name, "sql": sql}));
        sql
    })))
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Fixture filters' signatures name the PHP driver's `FilterFixtures`
    /// methods; cache keys carry them, so the methods must exist there.
    #[test]
    fn fixture_signatures_name_core_php_methods() {
        let path = concat!(env!("CARGO_MANIFEST_DIR"), "/../../../tests/compat/database/ops/core.php");
        let source = std::fs::read_to_string(path).expect("ops/core.php");
        assert!(source.contains("namespace Tests\\Compat\\Database;"));
        assert!(source.contains("final class FilterFixtures"));
        for name in FILTERS {
            let Ok(fixture) = filter(name) else { panic!("no fixture {name}") };
            for part in fixture.signature().split(':').filter(|p| !p.is_empty() && !p.starts_with("Tests")) {
                assert!(
                    source.contains(&format!("public static function {part}(")),
                    "{path} has no FilterFixtures::{part}"
                );
            }
        }
    }
}
