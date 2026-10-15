//! [`Redis`]: documents stored in Redis, with the keyspace of PHP's
//! `Utopia\Database\Adapter\Redis`, so PHP and Rust services share one
//! dataset.
//!
//! Every key starts with `utopia:`:
//!
//! | Key | Type | Holds |
//! |---|---|---|
//! | `utopia:{ns}:dbs` | SET | database names |
//! | `utopia:{ns}:{db}:cols` | SET | collection ids |
//! | `utopia:{ns}:{db}:meta:{col}` | HASH | `schema`, `attrs`, `indexes`, `docCount`, `sizeBytes` |
//! | `utopia:{ns}:{db}:doc:{col}:{id}` | STRING | the document as JSON |
//! | `utopia:{ns}:{db}:idx:{col}` | SET | the (lower-cased) ids of the collection |
//! | `utopia:{ns}:{db}:seq:{col}` | STRING | the last `$sequence` given |
//! | `utopia:{ns}:{db}:perm:{col}:{letter}:{role}` | SET | ids a role may act on |
//! | `utopia:{ns}:{db}:perm:doc:{col}:{id}` | HASH | role => its letters (`c,d,r,u`) |
//! | `utopia:{ns}:{db}:grants:{col}` | SET | the permission keys written for the collection |
//!
//! With shared tables, documents, id sets, sequences and permissions are
//! bucketed by tenant under `t:{tenant}` (`_` for none). Reads load the
//! visible documents and filter, order and page them here, with PHP's
//! comparisons.
//!
//! A transaction keeps a journal of the writes made in it and undoes them,
//! newest first, on rollback; nested transactions keep a frame each. A
//! Redis failure inside a write is a [`Kind::Transaction`] error (`Redis
//! transaction failed: …`); outside one, a [`Kind::Redis`] error (phpredis's
//! `RedisException`). A server error reply (`WRONGTYPE …`) is phpredis's
//! `false`, not an error.
//!
//! The adapter runs over any redis-rs async connection
//! ([`ConnectionLike`]); [`Redis::connect`] opens a [`ConnectionManager`].
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::Adapter;
//! use utopia_database::adapter::redis::Redis;
//!
//! let adapter = Redis::connect("redis://localhost:6379/3").await?;
//! adapter.set_database("appwrite");
//! adapter.set_namespace("_1");
//! adapter.create("appwrite").await?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Redis` (over phpredis) and
//! `Adapter\Redis\Write`.

use std::any::Any;
use std::sync::{Arc, Mutex, MutexGuard};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};
use redis::aio::{ConnectionLike, ConnectionManager};
use redis::{FromRedisValue, RedisError};
use utopia_query::schema::{ColumnType, IndexType};

use super::{Adapter, Connection, Find, Limits, Relationships, State, Upserts};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::change::Change;
use crate::collection::Collection;
use crate::database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::index::Index;
use crate::query::Query;
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::schema;
use crate::value::{Array, ArrayKey, Str, Value};

mod documents;
mod operators;
mod query;
mod schema_ops;
mod unique;

/// The prefix of every key (`KEY_PREFIX`).
pub const KEY_PREFIX: &str = "utopia";

/// The separator of key segments (`SEP`).
pub const SEP: &str = ":";

/// The `SCAN` batch, and the chunk of a variadic `DEL` (`SCAN_BATCH_SIZE`).
const SCAN_BATCH_SIZE: usize = 500;

/// The `json_decode` depth of stored JSON (`JSON_DECODE_DEPTH`).
const JSON_DECODE_DEPTH: i64 = 512;

/// A write recorded in a transaction (`$journalStack` entries): the
/// operation and its payload, undone on rollback.
#[derive(Clone, Debug)]
struct Entry {
    op: String,
    payload: Vec<(&'static str, Vec<u8>)>,
}

impl Entry {
    /// `payloadString($payload, $key)`.
    fn get(&self, key: &str) -> Option<&[u8]> {
        self.payload.iter().find(|(k, _)| *k == key).map(|(_, v)| v.as_slice())
    }

    /// `payloadString()` as text.
    fn text(&self, key: &str) -> Option<String> {
        self.get(key).map(|v| String::from_utf8_lossy(v).into_owned())
    }

    /// `payloadStringOr($payload, $key, '')`.
    fn text_or_empty(&self, key: &str) -> String {
        self.text(key).unwrap_or_default()
    }
}

/// The Redis adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Redis`.
pub struct Redis<C = ConnectionManager> {
    state: State,
    client: C,
    /// `$journalStack`: one frame per open transaction.
    journal: Mutex<Vec<Vec<Entry>>>,
}

/// What a Redis error says, as phpredis reports it: a server error is its
/// reply (`WRONGTYPE Operation against …`), an I/O failure its message.
fn redis_message(e: &RedisError) -> String {
    match (e.code(), e.detail()) {
        (Some(code), Some(detail)) => format!("{code} {detail}"),
        (Some(code), None) => code.to_owned(),
        _ => match e.detail() {
            Some(detail) => detail.to_owned(),
            None => e.to_string(),
        },
    }
}

/// Whether the server replied with an error (rather than the connection
/// failing).
fn replied(e: &RedisError) -> bool {
    !e.is_io_error() && !e.is_connection_dropped() && !e.is_connection_refusal() && e.code().is_some()
}

/// phpredis's `false` read as `T`: `nil`, else `0`.
fn falsy<T: FromRedisValue>() -> std::result::Result<T, RedisError> {
    T::from_redis_value(&redis::Value::Nil).or_else(|_| T::from_redis_value(&redis::Value::Int(0)))
}

/// A failed Redis command: phpredis's `RedisException`.
fn raw_error(e: RedisError) -> Error {
    Error::new(Kind::Redis, redis_message(&e))
}

/// `strtolower()`: ASCII only.
fn lower(s: &str) -> String {
    s.to_ascii_lowercase()
}

/// The text of bytes read from Redis.
fn utf8(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}

/// A string value of Rust text.
fn string(s: impl Into<Str>) -> Value {
    Value::Str(s.into())
}

/// A PHP array of `(key, value)` pairs, in order.
fn assoc<'a>(pairs: impl IntoIterator<Item = (&'a str, Value)>) -> Array {
    pairs.into_iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k)), v)).collect()
}

/// `$array[$key]` with a string key (normalized as PHP does).
fn at<'a>(array: &'a Array, key: &str) -> Option<&'a Value> {
    array.get(&ArrayKey::normalize(Str::from(key)))
}

/// `$array[$key] ?? null` of a value that may not be an array.
fn field<'a>(value: &'a Value, key: &str) -> Option<&'a Value> {
    match value {
        Value::Array(a) => at(a, key).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// `is_string($value) ? $value : ''` (`stringOrEmpty()`), as bytes.
fn string_or_empty(value: &Value) -> Vec<u8> {
    match value {
        Value::Str(s) => s.as_bytes().to_vec(),
        _ => Vec::new(),
    }
}

/// `json_encode($value, JSON_THROW_ON_ERROR | $flags)`.
fn json_encode(value: &Value, flags: php_std::json::Flags) -> Result<Vec<u8>> {
    php_std::json::encode(&crate::value::json_value(value), flags | php_std::json::Flags::THROW_ON_ERROR, 512)
        .map(String::into_bytes)
        .map_err(|e| json_exception(&e))
}

/// The `JsonException` of a JSON error.
fn json_exception(e: &php_std::json::Error) -> Error {
    let code = match e {
        php_std::json::Error::Json(code) => code.code(),
        _ => 0,
    };
    Error::new(Kind::Json, e.message().to_owned()).with_code(code)
}

/// `json_decode($payload, true, 512, JSON_THROW_ON_ERROR)`, as plain PHP
/// values.
fn json_decode_plain(payload: &[u8]) -> Result<php_std::Value> {
    php_std::json::decode(payload, Some(true), JSON_DECODE_DEPTH, php_std::json::Flags::THROW_ON_ERROR)
        .map_err(|e| json_exception(&e))
}

/// `json_decode($payload, true, 512, JSON_THROW_ON_ERROR)`.
fn json_decode(payload: &[u8]) -> Result<Value> {
    json_decode_plain(payload).map(crate::value::from_plain)
}

/// `json_decode($value, true)`: `None` where it fails (PHP's `null`).
fn json_decode_lenient(payload: &[u8]) -> Option<Value> {
    php_std::json::decode(payload, Some(true), JSON_DECODE_DEPTH, php_std::json::Flags::NONE)
        .ok()
        .map(crate::value::from_plain)
}

/// The flags of document payloads: `JSON_UNESCAPED_UNICODE |
/// JSON_PRESERVE_ZERO_FRACTION`.
fn payload_flags() -> php_std::json::Flags {
    php_std::json::Flags::UNESCAPED_UNICODE | php_std::json::Flags::PRESERVE_ZERO_FRACTION
}

/// The flags of the meta hash fields: `JSON_UNESCAPED_UNICODE`.
fn meta_flags() -> php_std::json::Flags {
    php_std::json::Flags::UNESCAPED_UNICODE
}

/// A redis-rs reply as bytes, `None` for anything but a string (phpredis's
/// `false` or a non-string).
fn reply_bytes(value: &redis::Value) -> Option<Vec<u8>> {
    match value {
        redis::Value::BulkString(b) => Some(b.clone()),
        redis::Value::SimpleString(s) => Some(s.clone().into_bytes()),
        redis::Value::VerbatimString { text, .. } => Some(text.clone().into_bytes()),
        _ => None,
    }
}

/// A non-empty string reply (`is_string($payload) && $payload !== ''`).
fn payload_of(value: Option<&redis::Value>) -> Option<Vec<u8>> {
    value.and_then(reply_bytes).filter(|b| !b.is_empty())
}

/// A hash reply as its `(field, value)` pairs, in reply order; empty for
/// anything else.
fn reply_pairs(value: &redis::Value) -> Vec<(String, String)> {
    match value {
        redis::Value::Array(items) => items
            .chunks(2)
            .filter_map(|pair| match pair {
                [k, v] => Some((utf8(&reply_bytes(k)?), reply_bytes(v).map(|b| utf8(&b)).unwrap_or_default())),
                _ => None,
            })
            .collect(),
        redis::Value::Map(items) => items
            .iter()
            .filter_map(|(k, v)| Some((utf8(&reply_bytes(k)?), reply_bytes(v).map(|b| utf8(&b)).unwrap_or_default())))
            .collect(),
        _ => Vec::new(),
    }
}

impl Redis<ConnectionManager> {
    /// Connects to `url` (`redis://host:port/db`) with a
    /// [`ConnectionManager`].
    pub async fn connect(url: &str) -> Result<Self> {
        let client = redis::Client::open(url).map_err(raw_error)?;
        let manager = client.get_connection_manager().await.map_err(raw_error)?;
        Ok(Self::new(manager))
    }
}

impl<C: ConnectionLike + Clone + Send + Sync + 'static> Redis<C> {
    /// An adapter over `client`.
    ///
    /// PHP: `new Redis($client)`.
    pub fn new(client: C) -> Self {
        Redis { state: State::default(), client, journal: Mutex::new(Vec::new()) }
    }

    /// The connection (`getDriver()`).
    pub fn client(&self) -> &C {
        &self.client
    }

    fn frames(&self) -> MutexGuard<'_, Vec<Vec<Entry>>> {
        self.journal.lock().unwrap_or_else(|e| e.into_inner())
    }

    // ----- Commands -----

    /// Runs a command. A server error reply (`WRONGTYPE …`) is phpredis's
    /// `false`: the reply read as `T` from `nil` (or `0`); only a failure to
    /// talk to the server is an error ([`Kind::Redis`]).
    async fn run<T: FromRedisValue>(&self, cmd: &redis::Cmd) -> Result<T> {
        let mut connection = self.client.clone();
        match cmd.query_async::<redis::Value>(&mut connection).await {
            Ok(redis::Value::ServerError(_)) => falsy().map_err(raw_error),
            Ok(value) => T::from_owned_redis_value(value).or_else(|_| falsy()).map_err(raw_error),
            Err(e) if replied(&e) => falsy().map_err(raw_error),
            Err(e) => Err(raw_error(e)),
        }
    }

    /// Runs a pipeline (`multi(Redis::PIPELINE)` … `exec()`): each reply in
    /// order, a server error as `nil` (phpredis's `false`). Every pipeline
    /// of the adapter is idempotent, so one a server error interrupts runs
    /// again command by command.
    async fn pipe(&self, pipe: &redis::Pipeline) -> Result<Vec<redis::Value>> {
        let mut connection = self.client.clone();
        match pipe.query_async::<Vec<redis::Value>>(&mut connection).await {
            Ok(values) => Ok(values
                .into_iter()
                .map(|value| match value {
                    redis::Value::ServerError(_) => redis::Value::Nil,
                    value => value,
                })
                .collect()),
            Err(e) if replied(&e) => {
                let mut out = Vec::with_capacity(pipe.len());
                for cmd in pipe.cmd_iter() {
                    out.push(self.run::<redis::Value>(cmd).await?);
                }
                Ok(out)
            }
            Err(e) => Err(raw_error(e)),
        }
    }

    /// `transaction()`: runs `body`, a Redis failure in it becoming a
    /// [`Kind::Transaction`] error (`Redis transaction failed: …`).
    async fn tx<T>(&self, body: impl std::future::Future<Output = Result<T>>) -> Result<T> {
        body.await.map_err(|e| {
            if e.kind() == Kind::Redis {
                Error::transaction(format!("Redis transaction failed: {}", e.message())).with_previous(e)
            } else {
                e
            }
        })
    }

    /// `(bool) $client->exists($key)`.
    async fn exists_key(&self, key: &str) -> Result<bool> {
        Ok(self.run::<i64>(redis::cmd("EXISTS").arg(key)).await? > 0)
    }

    /// `GET`, `None` for a miss or an empty string.
    async fn get_payload(&self, key: &str) -> Result<Option<Vec<u8>>> {
        let reply: redis::Value = self.run(redis::cmd("GET").arg(key)).await?;
        Ok(payload_of(Some(&reply)))
    }

    /// `SMEMBERS`, as text.
    async fn members(&self, key: &str) -> Result<Vec<String>> {
        let members: Vec<Vec<u8>> = self.run(redis::cmd("SMEMBERS").arg(key)).await?;
        Ok(members.iter().map(|m| utf8(m)).collect())
    }

    /// `MGET` of `keys`: each reply, `nil` for a miss.
    async fn mget(&self, keys: &[String]) -> Result<Vec<redis::Value>> {
        if keys.is_empty() {
            return Ok(Vec::new());
        }
        let reply: redis::Value = self.run(redis::cmd("MGET").arg(keys)).await?;
        Ok(match reply {
            redis::Value::Array(items) => items,
            _ => Vec::new(),
        })
    }

    /// `SCAN` over `pattern` until the cursor is back at 0.
    async fn scan(&self, pattern: &str) -> Result<Vec<String>> {
        let mut keys = Vec::new();
        let mut cursor: u64 = 0;
        loop {
            let (next, batch): (u64, Vec<Vec<u8>>) = self
                .run(redis::cmd("SCAN").arg(cursor).arg("MATCH").arg(pattern).arg("COUNT").arg(SCAN_BATCH_SIZE))
                .await?;
            keys.extend(batch.iter().map(|k| utf8(k)));
            cursor = next;
            if cursor == 0 {
                break;
            }
        }
        Ok(keys)
    }

    /// `scanKeys()`: the keys `pattern` matches, each once, in the order
    /// found.
    async fn scan_keys(&self, pattern: &str) -> Result<Vec<String>> {
        let mut keys: Vec<String> = Vec::new();
        for key in self.scan(pattern).await? {
            if !keys.contains(&key) {
                keys.push(key);
            }
        }
        Ok(keys)
    }

    // ----- Keys -----

    /// `KEY_PREFIX:{namespace}:{database}` (`ns()`).
    fn ns(&self) -> String {
        Self::ns_for(&self.namespace(), &self.database())
    }

    /// `KEY_PREFIX:{namespace}:{database}` for a database (`nsFor()`).
    fn ns_for(namespace: &str, database: &str) -> String {
        format!("{KEY_PREFIX}{SEP}{namespace}{SEP}{database}")
    }

    /// `KEY_PREFIX:{namespace}` (`nsBase()`).
    fn ns_base(&self) -> String {
        format!("{KEY_PREFIX}{SEP}{}", self.namespace())
    }

    /// `key(...$parts)`: the parts joined with `:`.
    fn key(parts: &[&str]) -> String {
        parts.join(SEP)
    }

    /// `key($this->ns(), 'meta', $collection)`.
    fn meta_key(&self, collection: &str) -> String {
        Self::key(&[&self.ns(), "meta", collection])
    }

    /// `key($this->nsBase(), 'dbs')`.
    fn dbs_key(&self) -> String {
        Self::key(&[&self.ns_base(), "dbs"])
    }

    /// The tenant bucket of shared-tables keys: the tenant given, else the
    /// adapter's, `_` for none (`bucketFor()`).
    fn bucket_for(&self, tenant: Option<&Value>) -> String {
        let tenant = match tenant {
            Some(t) if !matches!(t, Value::Null) => t.clone(),
            _ => self.tenant(),
        };
        if matches!(tenant, Value::Null) { "_".to_owned() } else { crate::value::to_text(&tenant) }
    }

    /// `tenantBucket()`: the bucket under shared tables, else `None`.
    fn tenant_bucket(&self, tenant: Option<&Value>) -> Option<String> {
        self.shared_tables().then(|| self.bucket_for(tenant))
    }

    /// A document's key (`docKey()`).
    fn doc_key(&self, collection: &str, id: &str, tenant: Option<&Value>) -> String {
        let id = lower(id);
        if !self.shared_tables() {
            return Self::key(&[&self.ns(), "doc", collection, &id]);
        }
        Self::key(&[&self.ns(), "doc", "t", &self.bucket_for(tenant), collection, &id])
    }

    /// A collection's id set (`idxKey()`).
    fn idx_key(&self, collection: &str, tenant: Option<&Value>) -> String {
        if !self.shared_tables() {
            return Self::key(&[&self.ns(), "idx", collection]);
        }
        Self::key(&[&self.ns(), "idx", "t", &self.bucket_for(tenant), collection])
    }

    /// A collection's sequence counter (`seqKey()`).
    fn seq_key(&self, collection: &str, tenant: Option<&Value>) -> String {
        if !self.shared_tables() {
            return Self::key(&[&self.ns(), "seq", collection]);
        }
        Self::key(&[&self.ns(), "seq", "t", &self.bucket_for(tenant), collection])
    }

    /// The set of ids `role` may act on with `letter` (`permKey()`).
    fn perm_key(&self, collection: &str, letter: &str, role: &str, tenant: Option<&Value>) -> String {
        match self.tenant_bucket(tenant) {
            Some(bucket) => Self::key(&[&self.ns(), "perm", "t", &bucket, collection, letter, role]),
            None => Self::key(&[&self.ns(), "perm", collection, letter, role]),
        }
    }

    /// A document's role => letters hash (`permDocKey()`).
    fn perm_doc_key(&self, collection: &str, id: &str, tenant: Option<&Value>) -> String {
        let id = lower(id);
        match self.tenant_bucket(tenant) {
            Some(bucket) => Self::key(&[&self.ns(), "perm", "t", &bucket, "doc", collection, &id]),
            None => Self::key(&[&self.ns(), "perm", "doc", collection, &id]),
        }
    }

    /// The set of permission keys written for a collection (`grantsKey()`).
    fn grants_key(prefix: &str, collection: &str) -> String {
        Self::key(&[prefix, "grants", collection])
    }

    /// `scopedKey()`: `{prefix}:{family}(:t:{bucket}):{parts}`.
    fn scoped_key(prefix: &str, family: &str, bucket: Option<&str>, parts: &[&str]) -> String {
        let mut all: Vec<&str> = vec![prefix, family];
        if let Some(bucket) = bucket {
            all.push("t");
            all.push(bucket);
        }
        all.extend_from_slice(parts);
        Self::key(&all)
    }

    // ----- Payloads -----

    /// A document as stored: `json_encode($document->getArrayCopy(), …)`
    /// (`encode()`).
    fn encode(document: &Document) -> Result<Vec<u8>> {
        json_encode(&Value::Array(document.array_copy()), payload_flags())
    }

    /// A stored document (`decode()`): `Document::fromStorage()` of the
    /// decoded JSON.
    fn decode(payload: &[u8]) -> Result<Document> {
        let data = json_decode(payload).map_err(|e| {
            Error::database(format!("Document decode failed: {}", e.message())).with_previous(e.clone())
        })?;
        match data {
            Value::Array(a) => Document::from_storage(a),
            other => Err(Error::new(
                Kind::TypeError,
                format!(
                    "Utopia\\Database\\Document::fromStorage(): Argument #1 ($data) must be of type array, {} given",
                    other.debug_type()
                ),
            )),
        }
    }

    // ----- Journal -----

    /// Records a write in the innermost open transaction; outside one,
    /// nothing (`journal()`).
    ///
    /// Public for tests that record an entry the adapter never writes (PHP
    /// reaches the protected method through a subclass).
    #[doc(hidden)]
    pub fn journal(&self, op: &str, payload: Vec<(&'static str, Vec<u8>)>) {
        if self.state.lock().in_transaction == 0 {
            return;
        }
        if let Some(frame) = self.frames().last_mut() {
            frame.push(Entry { op: op.to_owned(), payload });
        }
    }

    /// Undoes the innermost transaction's writes, newest first
    /// (`rollbackJournal()`).
    async fn rollback_journal(&self) -> Result<()> {
        let Some(frame) = self.frames().pop() else {
            return Ok(());
        };
        for entry in frame.iter().rev() {
            match entry.op.as_str() {
                "createDoc" => {
                    let collection = entry.text_or_empty("collection");
                    let id = entry.text_or_empty("id");
                    let lower_id = lower(&id);
                    let doc_key = entry.text("docKey").unwrap_or_else(|| self.doc_key(&collection, &lower_id, None));
                    let _: i64 = self.run(redis::cmd("DEL").arg(&doc_key)).await?;
                    let idx_key = entry.text("idxKey").unwrap_or_else(|| self.idx_key(&collection, None));
                    let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(&lower_id)).await?;
                    let perm_doc_key =
                        entry.text("permDocKey").unwrap_or_else(|| self.perm_doc_key(&collection, &lower_id, None));
                    let _: i64 = self.run(redis::cmd("DEL").arg(&perm_doc_key)).await?;
                }
                "deleteDoc" => {
                    let collection = entry.text_or_empty("collection");
                    let id = entry.text_or_empty("id");
                    let payload = entry.get("payload").unwrap_or_default().to_vec();
                    let lower_id = lower(&id);
                    let doc_key = entry.text("docKey").unwrap_or_else(|| self.doc_key(&collection, &lower_id, None));
                    let _: redis::Value = self.run(redis::cmd("SET").arg(&doc_key).arg(&payload)).await?;
                    let idx_key = entry.text("idxKey").unwrap_or_else(|| self.idx_key(&collection, None));
                    let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(&lower_id)).await?;
                }
                "updateDoc" => {
                    let collection = entry.text_or_empty("collection");
                    let id = entry.text_or_empty("id");
                    let payload = entry.get("payload").unwrap_or_default().to_vec();
                    let doc_key = entry.text("docKey").unwrap_or_else(|| self.doc_key(&collection, &id, None));
                    let _: redis::Value = self.run(redis::cmd("SET").arg(&doc_key).arg(&payload)).await?;
                    if let Some(new_id) = entry.text("newId")
                        && new_id != id
                    {
                        let new_doc_key =
                            entry.text("newDocKey").unwrap_or_else(|| self.doc_key(&collection, &new_id, None));
                        if new_doc_key != doc_key {
                            let _: i64 = self.run(redis::cmd("DEL").arg(&new_doc_key)).await?;
                        }
                        let idx_key = entry.text("idxKey").unwrap_or_else(|| self.idx_key(&collection, None));
                        let _: i64 = self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(&new_id))).await?;
                        let _: i64 = self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&id))).await?;
                    }
                }
                "createPerm" | "deletePerm" => {
                    let collection = entry.text_or_empty("collection");
                    let letter = entry.text_or_empty("letter");
                    let role = entry.text_or_empty("role");
                    let id = entry.text_or_empty("id");
                    let set_key =
                        entry.text("permKey").unwrap_or_else(|| self.perm_key(&collection, &letter, &role, None));
                    let hash_key =
                        entry.text("permDocKey").unwrap_or_else(|| self.perm_doc_key(&collection, &id, None));
                    if entry.op == "createPerm" {
                        let _: i64 = self.run(redis::cmd("SREM").arg(&set_key).arg(&id)).await?;
                        let _: i64 = self.run(redis::cmd("HDEL").arg(&hash_key).arg(&role)).await?;
                    } else {
                        let _: i64 = self.run(redis::cmd("SADD").arg(&set_key).arg(&id)).await?;
                        if let Some(previous) = entry.get("previous")
                            && !previous.is_empty()
                        {
                            let _: i64 = self.run(redis::cmd("HSET").arg(&hash_key).arg(&role).arg(previous)).await?;
                        }
                    }
                }
                other => return Err(Error::transaction(format!("Unknown journal op: {other}"))),
            }
        }
        Ok(())
    }

    // ----- Meta records -----

    /// The `attrs` field of a meta hash, as a list (`readAttributesField()`).
    async fn read_attributes(&self, meta_key: &str) -> Result<Vec<Value>> {
        let raw: Option<Vec<u8>> = self.run(redis::cmd("HGET").arg(meta_key).arg("attrs")).await?;
        let Some(raw) = raw.filter(|r| !r.is_empty()) else {
            return Ok(Vec::new());
        };
        Ok(match json_decode(&raw)? {
            Value::Array(a) => a.values().cloned().collect(),
            _ => Vec::new(),
        })
    }

    /// The `indexes` field of a meta hash, as stored (`readIndexesField()`).
    async fn read_indexes(&self, meta_key: &str) -> Result<Array> {
        let raw: Option<Vec<u8>> = self.run(redis::cmd("HGET").arg(meta_key).arg("indexes")).await?;
        let Some(raw) = raw.filter(|r| !r.is_empty()) else {
            return Ok(Array::new());
        };
        Ok(match json_decode(&raw)? {
            Value::Array(a) => a,
            _ => Array::new(),
        })
    }

    /// `HSET $metaKey $field json_encode($records)`.
    async fn write_meta_field(&self, meta_key: &str, field: &str, records: &Value) -> Result<()> {
        let json = json_encode(records, meta_flags())?;
        let _: i64 = self.run(redis::cmd("HSET").arg(meta_key).arg(field).arg(json)).await?;
        Ok(())
    }

    /// `attributeRecord()`: an attribute as the meta hash records it.
    fn attribute_record(id: &str, attribute: &Attribute) -> Value {
        Value::Array(assoc([
            (crate::document::ID, string(id)),
            ("key", string(id)),
            ("type", string(Attribute::stored_type(attribute.kind))),
            ("size", Value::Int(attribute.size.unwrap_or(0))),
            ("signed", Value::Bool(attribute.signed)),
            ("array", Value::Bool(attribute.array)),
            ("required", Value::Bool(attribute.required)),
        ]))
    }

    /// `orderValues()`: an index's orders as their values (`null` unset).
    fn order_values(index: &Index) -> Value {
        crate::value::list(index.orders.iter().map(|o| o.map_or(Value::Null, |o| string(o.as_str()))))
    }

    /// An index as the meta hash records it.
    fn index_record(id: &str, kind: IndexType, attributes: &[String], lengths: &[Option<i64>], orders: Value) -> Value {
        Value::Array(assoc([
            (crate::document::ID, string(id)),
            ("key", string(id)),
            ("type", string(kind.as_str())),
            ("attributes", crate::value::list(attributes.iter().map(|a| string(a.as_str())))),
            ("lengths", crate::value::list(lengths.iter().map(|l| l.map_or(Value::Null, Value::Int)))),
            ("orders", orders),
        ]))
    }
}

/// `recordIdentifier()`: a record's `$id`, else its `key`, when a string;
/// else `''`.
fn record_identifier(record: &Value) -> String {
    match field(record, crate::document::ID) {
        Some(Value::Str(s)) => utf8(s.as_bytes()),
        _ => match field(record, "key") {
            Some(Value::Str(s)) => utf8(s.as_bytes()),
            _ => String::new(),
        },
    }
}

/// `$index['$id'] ?? $index['key'] ?? null`.
fn index_identifier(index: &Value) -> Value {
    field(index, crate::document::ID).or_else(|| field(index, "key")).cloned().unwrap_or(Value::Null)
}

/// `upsertAttributeRecord()`: `record` replaces the record with its id, or
/// is appended.
fn upsert_attribute_record(mut attributes: Vec<Value>, record: Value) -> Vec<Value> {
    let target = match field(&record, crate::document::ID) {
        Some(Value::Str(s)) => utf8(s.as_bytes()),
        _ => String::new(),
    };
    match attributes.iter().position(|existing| record_identifier(existing) == target) {
        Some(i) => attributes[i] = record,
        None => attributes.push(record),
    }
    attributes
}

/// `extractRelationshipKeys()`: the ids of the relationship records.
fn relationship_keys(attributes: &[Value]) -> Vec<String> {
    attributes
        .iter()
        .filter(|a| matches!(field(a, "type"), Some(Value::Str(s)) if s.as_bytes() == ColumnType::Relationship.as_str().as_bytes()))
        .map(record_identifier)
        .filter(|k| !k.is_empty())
        .collect()
}

#[async_trait]
impl<C: ConnectionLike + Clone + Send + Sync + 'static> Adapter for Redis<C> {
    fn state(&self) -> &State {
        &self.state
    }

    fn as_any(&self) -> &dyn Any {
        self
    }

    fn capabilities(&self) -> Capabilities {
        Capabilities::of(&[
            Capability::IndexKey,
            Capability::IndexArray,
            Capability::IndexUnique,
            Capability::Schemas,
            Capability::IndexFulltext,
            Capability::AttributeResizing,
            Capability::Objects,
            Capability::Operators,
            Capability::OrderRandom,
            Capability::DefinedAttributes,
            Capability::TransactionNested,
        ])
    }

    fn limits(&self) -> Arc<Limits> {
        if let Some(limits) = self.state.lock().limits.clone() {
            return limits;
        }
        let date = |s: &str| DateTime::new(s.as_bytes(), None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!());
        let limits = Arc::new(Limits {
            string: 4_294_967_295,
            varchar: 16_381,
            integer: 4_294_967_295,
            big_integer: database::MAX_BIG_INT,
            attributes: 1017,
            indexes: 64,
            default_attributes: database::internal_attributes(true).len() as i64,
            default_indexes: database::INTERNAL_INDEXES.len() as i64,
            index_length: 1024,
            uid_length: 255,
            document_size: 0,
            min_date_time: date("0001-01-01 00:00:00"),
            max_date_time: date(super::MAX_DATETIME),
            id_type: ColumnType::Integer,
            keywords: Vec::new(),
            internal_index_keys: Vec::new(),
        });
        self.state.lock().limits = Some(limits.clone());
        limits
    }

    fn connection(&self) -> Option<&dyn Connection> {
        Some(self)
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        Some(self)
    }

    fn upserts(&self) -> Option<&dyn Upserts> {
        Some(self)
    }

    // ----- Transactions -----

    async fn start_transaction(&self) -> Result<bool> {
        self.frames().push(Vec::new());
        self.state.lock().in_transaction += 1;
        Ok(true)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        if self.state.lock().in_transaction == 0 {
            return Ok(false);
        }
        {
            let mut frames = self.frames();
            if let Some(frame) = frames.pop()
                && !frame.is_empty()
                && let Some(outer) = frames.last_mut()
            {
                outer.extend(frame);
            }
        }
        self.state.lock().in_transaction -= 1;
        Ok(true)
    }

    /// Undoes the innermost transaction. A failed undo resets the depth and
    /// the journal, and fails.
    async fn rollback_transaction(&self) -> Result<bool> {
        if self.state.lock().in_transaction == 0 {
            return Ok(false);
        }
        match self.rollback_journal().await {
            Ok(()) => {
                self.state.lock().in_transaction -= 1;
                Ok(true)
            }
            Err(e) => {
                self.state.lock().in_transaction = 0;
                self.frames().clear();
                Err(e)
            }
        }
    }

    // ----- Databases -----

    async fn create(&self, name: &str) -> Result<bool> {
        self.create_database(name).await
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        self.rename_database(name, new).await
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        let member: i64 = self.run(redis::cmd("SISMEMBER").arg(self.dbs_key()).arg(super::filter(database))).await?;
        Ok(member != 0)
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        if !self.exists(database).await? {
            return Ok(false);
        }
        let cols = Self::key(&[&Self::ns_for(&self.namespace(), &super::filter(database)), "cols"]);
        let member: i64 = self.run(redis::cmd("SISMEMBER").arg(cols).arg(super::filter(collection))).await?;
        Ok(member != 0)
    }

    async fn list(&self) -> Result<Vec<Document>> {
        let names = self.members(&self.dbs_key()).await?;
        names.into_iter().map(|name| Document::new(assoc([("name", string(name))]))).collect()
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.delete_database(name).await
    }

    // ----- Collections -----

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        self.create_collection_keys(collection, attributes, indexes).await
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        self.delete_collection_keys(collection).await
    }

    /// No statistics to refresh: `false`.
    async fn analyze_collection(&self, _collection: &str) -> Result<bool> {
        Ok(false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.collection_size(collection).await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.collection_size(collection).await
    }

    fn attribute_width(&self, _collection: &Collection) -> Result<i64> {
        Ok(0)
    }

    // ----- Attributes -----

    async fn create_attribute(&self, collection: &str, attribute: &Attribute) -> Result<bool> {
        self.put_attribute(collection, attribute).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Attribute]) -> Result<bool> {
        for attribute in attributes {
            self.put_attribute(collection, attribute).await?;
        }
        Ok(true)
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        self.change_attribute(collection, key, attribute).await
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        self.drop_attribute(collection, key).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_attribute_keys(collection, old, new).await
    }

    async fn schema_attributes(&self, _collection: &str) -> Result<Vec<schema::Column>> {
        Ok(Vec::new())
    }

    async fn schema_indexes(&self, _collection: &str) -> Result<Vec<schema::Index>> {
        Ok(Vec::new())
    }

    fn column_type(&self, _attribute: &Attribute) -> Result<Option<String>> {
        Ok(None)
    }

    // ----- Indexes -----

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        _attribute_types: &[(String, ColumnType)],
        _collation: &[(String, String)],
    ) -> Result<bool> {
        self.put_index(collection, index).await
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        self.drop_index(collection, key).await
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_index_keys(collection, old, new).await
    }

    // ----- Documents -----

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        Ok(match self.insert_document(collection, document).await? {
            Ok(stored) => stored,
            Err(skipped) => skipped,
        })
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        let mut created = Vec::with_capacity(documents.len());
        for document in documents {
            if let Ok(stored) = self.insert_document(collection, document).await? {
                created.push(stored);
            }
        }
        Ok(created)
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        _for_update: bool,
    ) -> Result<Document> {
        self.read_document(collection, id, &queries).await
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        self.replace_document(collection, id, document, skip_permissions).await
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        _skip_permissions: &[String],
    ) -> Result<i64> {
        self.replace_documents(collection, updates, documents).await
    }

    async fn increase_document_attribute(
        &self,
        collection: &Collection,
        id: &str,
        attribute: &str,
        value: Value,
        updated_at: &str,
        min: Option<Value>,
        max: Option<Value>,
    ) -> Result<bool> {
        self.increase(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        self.remove_document(collection, id).await
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
    ) -> Result<i64> {
        self.remove_documents(collection, sequences, permission_ids, &[]).await
    }

    async fn delete_documents_matching(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        permission_ids: Vec<String>,
        queries: Vec<Query>,
    ) -> Result<i64> {
        self.remove_documents(collection, sequences, permission_ids, &queries).await
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        self.find_documents(collection, &queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        self.count_documents(collection, &queries, max).await
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        self.sum_documents(collection, attribute, &queries, max).await
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.sequences(collection, documents).await
    }
}

#[async_trait]
impl<C: ConnectionLike + Clone + Send + Sync + 'static> Connection for Redis<C> {
    async fn ping(&self) -> Result<bool> {
        let reply: redis::Value = self.run(&redis::cmd("PING")).await?;
        Ok(!matches!(reply, redis::Value::Nil | redis::Value::Boolean(false)))
    }

    /// Nothing to re-establish (`reconnect()` does nothing).
    async fn reconnect(&self) -> Result<()> {
        Ok(())
    }

    /// Always `0`.
    async fn id(&self) -> Result<String> {
        Ok("0".to_owned())
    }

    /// The hostname set on the adapter.
    async fn hostname(&self) -> Result<String> {
        Ok(self.state.lock().hostname.clone())
    }
}

#[async_trait]
impl<C: ConnectionLike + Clone + Send + Sync + 'static> Relationships for Redis<C> {
    async fn create_relationship(&self, collection: &str, relationship: &Relationship) -> Result<bool> {
        self.create_relationship_fields(collection, relationship).await
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        self.update_relationship_fields(collection, relationship, side, update).await
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        self.delete_relationship_fields(collection, relationship, side).await
    }
}

#[async_trait]
impl<C: ConnectionLike + Clone + Send + Sync + 'static> Upserts for Redis<C> {
    async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document> {
        let mut documents = self.upsert(collection, vec![change], None).await?;
        Ok(documents.remove(0))
    }

    async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        self.upsert(collection, changes, increase).await
    }
}
