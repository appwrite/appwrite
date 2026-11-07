//! [`Redis`]: documents stored in Redis.
//!
//! Every key starts with `utopia:{namespace}:{database}`:
//!
//! | Key | Type | Holds |
//! |---|---|---|
//! | `utopia:{ns}:dbs` | SET | database names (per namespace) |
//! | `…:cols` | SET | collection ids of the database |
//! | `…:meta:{col}` | HASH | `schema`, `attrs`, `indexes`, `docCount`, `sizeBytes` |
//! | `…:doc:{col}:{id}` | STRING | the document as JSON |
//! | `…:idx:{col}` | SET | the ids of the collection's documents |
//! | `…:seq:{col}` | STRING | the last `$sequence` given |
//! | `…:perm:{col}:{r\|c\|u\|d\|w}:{role}` | SET | ids a role may act on |
//! | `…:perm:doc:{col}:{id}` | HASH | role => its action letters |
//!
//! With shared tables, documents, id sets, sequences and permissions are
//! bucketed by tenant (`…:doc:t:{tenant}:{col}:{id}`, `_` for none). Ids are
//! lower-cased in keys. Queries load the visible documents and filter,
//! order and page them here, with PHP's comparisons.
//!
//! Transactions keep a journal of the writes made in them and undo it on
//! rollback, with raw commands; there is no `MULTI`/`EXEC`. A Redis failure
//! inside a write is a `Utopia\Database\Exception\Transaction` (`tx failed:
//! …`); outside, PHP's `RedisException` passes through.
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use utopia_database::Adapter;
//! use utopia_database::adapter::redis::Redis;
//!
//! let adapter = Redis::connect("redis://localhost:6379/3").await?;
//! adapter.set_database("appwrite")?;
//! adapter.set_namespace("_1")?;
//! adapter.create("appwrite").await?;
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Redis` (over phpredis).

use std::sync::{Mutex, MutexGuard};

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};
use redis::aio::ConnectionManager;
use redis::{FromRedisValue, RedisError};

use super::{Adapter, FindOptions, State};
use crate::change::Change;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::query::Query;
use crate::value::{Array, Value, from_plain, to_plain, to_text};

mod documents;
mod operators;
mod query;
mod schema;

/// The prefix of every key (`KEY_PREFIX`).
pub const KEY_PREFIX: &str = "utopia";

/// The separator of key segments (`SEP`).
pub const SEP: &str = ":";

/// The `SCAN` batch, and the chunk of a variadic `DEL` (`SCAN_BATCH_SIZE`).
const SCAN_BATCH_SIZE: usize = 500;

/// The `json_decode` depth of payloads (`JSON_DECODE_DEPTH`).
const JSON_DECODE_DEPTH: i64 = 512;

/// A write recorded in a transaction, undone on rollback.
#[derive(Clone, Debug)]
enum Entry {
    /// A document was created: delete it.
    CreateDoc { collection: String, id: String, doc_key: String, idx_key: String, perm_doc_key: String },
    /// A document was deleted: restore its payload.
    DeleteDoc { collection: String, id: String, payload: Vec<u8>, doc_key: String, idx_key: String },
    /// A document was replaced: restore its payload (and its id).
    UpdateDoc {
        collection: String,
        id: String,
        new_id: String,
        payload: Vec<u8>,
        doc_key: String,
        new_doc_key: Option<String>,
        idx_key: Option<String>,
    },
    /// A role was granted an action on a document: revoke it.
    CreatePerm { collection: String, id: String, role: String, letter: String },
    /// A role lost an action on a document: grant it back.
    DeletePerm { collection: String, id: String, role: String, letter: String, previous: String },
}

/// The Redis adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Redis`.
pub struct Redis {
    state: State,
    client: ConnectionManager,
    /// `$journalStack`: one frame per open transaction.
    journal: Mutex<Vec<Vec<Entry>>>,
}

/// What a Redis error says, as phpredis reports it: a server error is its
/// reply (`WRONGTYPE Operation against …`).
fn redis_message(e: &RedisError) -> String {
    match (e.code(), e.detail()) {
        (Some(code), Some(detail)) => format!("{code} {detail}"),
        (Some(code), None) => code.to_owned(),
        _ => e.to_string(),
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

/// A Redis failure inside `tx()`: `TransactionException('tx failed: …')`.
fn tx_error(e: RedisError) -> Error {
    Error::transaction(format!("tx failed: {}", redis_message(&e)))
}

/// A Redis failure outside `tx()`: phpredis's `RedisException`.
fn raw_error(e: RedisError) -> Error {
    Error::new(Kind::Redis, redis_message(&e))
}

/// `strtolower()`: ASCII only.
fn lower(s: &str) -> String {
    s.to_ascii_lowercase()
}

/// The letter of a permission action in permission keys
/// (`actionLetter()`).
fn action_letter(action: &str) -> Result<&'static str> {
    Ok(match action {
        Database::PERMISSION_READ => "r",
        Database::PERMISSION_CREATE => "c",
        Database::PERMISSION_UPDATE => "u",
        Database::PERMISSION_DELETE => "d",
        Database::PERMISSION_WRITE => "w",
        other => return Err(Error::database(format!("Unknown permission action: {other}"))),
    })
}

/// `json_encode($value, JSON_THROW_ON_ERROR | $flags)`.
fn json_encode(value: &Value, flags: php_std::json::Flags) -> Result<Vec<u8>> {
    php_std::json::encode(&to_plain(value), flags | php_std::json::Flags::THROW_ON_ERROR, 512)
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

/// `json_decode($payload, true, 512, JSON_THROW_ON_ERROR)`.
fn json_decode(payload: &[u8]) -> Result<Value> {
    php_std::json::decode(payload, Some(true), JSON_DECODE_DEPTH, php_std::json::Flags::THROW_ON_ERROR)
        .map(from_plain)
        .map_err(|e| json_exception(&e))
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

/// `count($collection->getAttribute($key, []))`.
fn count_of(collection: &Document, key: &str) -> i64 {
    match collection.get(key) {
        Some(Value::Array(a)) => a.len() as i64,
        _ => 0,
    }
}

impl Redis {
    /// An adapter over `client`.
    ///
    /// PHP: `new Redis($client)`.
    pub fn new(client: ConnectionManager) -> Self {
        Redis { state: State::default(), client, journal: Mutex::new(Vec::new()) }
    }

    /// Connects to `url` (`redis://host:port/db`).
    pub async fn connect(url: &str) -> Result<Self> {
        let client = redis::Client::open(url).map_err(raw_error)?;
        let manager = client.get_connection_manager().await.map_err(raw_error)?;
        Ok(Self::new(manager))
    }

    /// The connection.
    pub fn client(&self) -> &ConnectionManager {
        &self.client
    }

    fn journal(&self) -> MutexGuard<'_, Vec<Vec<Entry>>> {
        self.journal.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Runs a command. An error the server replies with (`WRONGTYPE …`) is
    /// phpredis's `false`, not an exception: the reply as `T` reads `nil`
    /// (or `0`); only a failure to talk to the server is an error.
    async fn run<T: FromRedisValue>(&self, cmd: &redis::Cmd) -> std::result::Result<T, RedisError> {
        match cmd.query_async::<redis::Value>(&mut self.client.clone()).await {
            Ok(redis::Value::ServerError(_)) => falsy(),
            Ok(value) => T::from_owned_redis_value(value),
            Err(e) if replied(&e) => falsy(),
            Err(e) => Err(e),
        }
    }

    /// Runs a pipeline: each reply in order, a server error as `false` (as
    /// phpredis's `exec()` gives it). The adapter's pipelines are idempotent,
    /// so one a server error stops is run again command by command.
    async fn pipe<T: FromRedisValue>(&self, pipe: &redis::Pipeline) -> std::result::Result<Vec<T>, RedisError> {
        match pipe.query_async::<Vec<redis::Value>>(&mut self.client.clone()).await {
            Ok(values) => values
                .into_iter()
                .map(|value| match value {
                    redis::Value::ServerError(_) => falsy(),
                    value => T::from_owned_redis_value(value),
                })
                .collect(),
            Err(e) if replied(&e) => {
                let mut out = Vec::with_capacity(pipe.len());
                for cmd in pipe.cmd_iter() {
                    out.push(self.run(cmd).await?);
                }
                Ok(out)
            }
            Err(e) => Err(e),
        }
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

    /// `{ns}:{part}:{part}…` (`key()` with `ns()` first).
    fn key(&self, parts: &[&str]) -> String {
        let mut key = self.ns();
        for part in parts {
            key.push_str(SEP);
            key.push_str(part);
        }
        key
    }

    /// The tenant bucket of shared-tables keys: the tenant given, or the
    /// adapter's, `_` for none (`bucketFor()`).
    fn bucket_for(&self, tenant: Option<&Value>) -> String {
        let tenant = match tenant {
            Some(t) if !matches!(t, Value::Null) => t.clone(),
            _ => self.tenant(),
        };
        if matches!(tenant, Value::Null) { "_".to_owned() } else { to_text(&tenant) }
    }

    /// A document's key (`docKey()`); `tenant` picks the bucket under shared
    /// tables (a string `_` for the null tenant).
    fn doc_key(&self, collection: &str, id: &str, tenant: Option<&Value>) -> String {
        let id = lower(id);
        if !self.shared_tables() {
            return self.key(&["doc", collection, &id]);
        }
        self.key(&["doc", "t", &self.bucket_for(tenant), collection, &id])
    }

    /// A collection's id set (`idxKey()`).
    fn idx_key(&self, collection: &str, tenant: Option<&Value>) -> String {
        if !self.shared_tables() {
            return self.key(&["idx", collection]);
        }
        self.key(&["idx", "t", &self.bucket_for(tenant), collection])
    }

    /// A collection's sequence counter (`seqKey()`).
    fn seq_key(&self, collection: &str, tenant: Option<&Value>) -> String {
        if !self.shared_tables() {
            return self.key(&["seq", collection]);
        }
        self.key(&["seq", "t", &self.bucket_for(tenant), collection])
    }

    /// The tenant bucket of permission keys, `None` without shared tables
    /// (`tenantBucket()`).
    fn tenant_bucket(&self) -> Option<String> {
        if !self.shared_tables() {
            return None;
        }
        let tenant = self.tenant();
        Some(if matches!(tenant, Value::Null) { "_".to_owned() } else { to_text(&tenant) })
    }

    /// The set of documents `role` may act on with `letter` (`permKey()`).
    fn perm_key(&self, collection: &str, letter: &str, role: &str) -> String {
        match self.tenant_bucket() {
            Some(bucket) => self.key(&["perm", "t", &bucket, collection, letter, role]),
            None => self.key(&["perm", collection, letter, role]),
        }
    }

    /// A document's role => letters hash (`permDocKey()`).
    fn perm_doc_key(&self, collection: &str, id: &str) -> String {
        match self.tenant_bucket() {
            Some(bucket) => self.key(&["perm", "t", &bucket, "doc", collection, id]),
            None => self.key(&["perm", "doc", collection, id]),
        }
    }

    /// A collection's meta hash.
    fn meta_key(&self, collection: &str) -> String {
        self.key(&["meta", collection])
    }

    // ----- Payloads -----

    /// A document as stored: `json_encode($document->getArrayCopy(), …)`.
    fn encode(document: &Document) -> Result<Vec<u8>> {
        json_encode(&Value::Array(document.array_copy(&[], &[])), payload_flags())
    }

    /// A stored document (`decode()`).
    fn decode(payload: &[u8]) -> Result<Document> {
        let data =
            json_decode(payload).map_err(|e| Error::database(format!("Document decode failed: {}", e.message())))?;
        match data {
            Value::Array(a) => Document::new(a),
            other => Err(Error::new(
                Kind::TypeError,
                format!(
                    "Utopia\\Database\\Document::__construct(): Argument #1 ($input) must be of type array, {} given",
                    other.type_name()
                ),
            )),
        }
    }

    // ----- Journal -----

    /// Records a write in the innermost open transaction; outside one,
    /// nothing (`journal()`).
    fn record(&self, entry: Entry) {
        if self.state.lock().in_transaction == 0 {
            return;
        }
        if let Some(frame) = self.journal().last_mut() {
            frame.push(entry);
        }
    }

    /// Undoes the innermost transaction's writes, newest first, with raw
    /// commands (`rollbackJournal()`).
    async fn rollback_journal(&self) -> Result<()> {
        let Some(frame) = self.journal().pop() else {
            return Ok(());
        };
        for entry in frame.into_iter().rev() {
            match entry {
                Entry::CreateDoc { collection, id, doc_key, idx_key, perm_doc_key } => {
                    self.raw_delete_doc(&collection, &id, Some(&doc_key), Some(&idx_key), Some(&perm_doc_key)).await?;
                }
                Entry::DeleteDoc { collection, id, payload, doc_key, idx_key } => {
                    self.raw_restore_doc(&collection, &id, &payload, Some(&doc_key), Some(&idx_key)).await?;
                }
                Entry::UpdateDoc { collection, id, new_id, payload, doc_key, new_doc_key, idx_key } => {
                    let _: () = self.run(redis::cmd("SET").arg(&doc_key).arg(&payload)).await.map_err(raw_error)?;
                    if new_id != id {
                        let new_doc_key = new_doc_key.unwrap_or_else(|| self.doc_key(&collection, &new_id, None));
                        let _: i64 = self.run(redis::cmd("DEL").arg(&new_doc_key)).await.map_err(raw_error)?;
                        let idx_key = idx_key.unwrap_or_else(|| self.idx_key(&collection, None));
                        let _: i64 =
                            self.run(redis::cmd("SREM").arg(&idx_key).arg(lower(&new_id))).await.map_err(raw_error)?;
                        let _: i64 =
                            self.run(redis::cmd("SADD").arg(&idx_key).arg(lower(&id))).await.map_err(raw_error)?;
                    }
                }
                Entry::CreatePerm { collection, id, role, letter } => {
                    let perm_key = self.perm_key(&collection, &letter, &role);
                    let _: i64 = self.run(redis::cmd("SREM").arg(perm_key).arg(&id)).await.map_err(raw_error)?;
                    let doc_key = self.perm_doc_key(&collection, &id);
                    let _: i64 = self.run(redis::cmd("HDEL").arg(doc_key).arg(&role)).await.map_err(raw_error)?;
                }
                Entry::DeletePerm { collection, id, role, letter, previous } => {
                    let perm_key = self.perm_key(&collection, &letter, &role);
                    let _: i64 = self.run(redis::cmd("SADD").arg(perm_key).arg(&id)).await.map_err(raw_error)?;
                    if !previous.is_empty() {
                        let doc_key = self.perm_doc_key(&collection, &id);
                        let _: i64 = self
                            .run(redis::cmd("HSET").arg(doc_key).arg(&role).arg(&previous))
                            .await
                            .map_err(raw_error)?;
                    }
                }
            }
        }
        Ok(())
    }

    /// Closes the innermost frame: its writes join the enclosing frame, or
    /// are forgotten at the outermost level (`commitJournal()`).
    fn commit_journal(&self) {
        let mut journal = self.journal();
        let Some(frame) = journal.pop() else {
            return;
        };
        if !frame.is_empty()
            && let Some(outer) = journal.last_mut()
        {
            outer.extend(frame);
        }
    }

    /// `rawDeleteDoc()`.
    async fn raw_delete_doc(
        &self,
        collection: &str,
        id: &str,
        doc_key: Option<&str>,
        idx_key: Option<&str>,
        perm_doc_key: Option<&str>,
    ) -> Result<()> {
        let lower_id = lower(id);
        let doc_key = doc_key.map(str::to_owned).unwrap_or_else(|| self.doc_key(collection, &lower_id, None));
        let _: i64 = self.run(redis::cmd("DEL").arg(doc_key)).await.map_err(raw_error)?;
        let idx_key = idx_key.map(str::to_owned).unwrap_or_else(|| self.idx_key(collection, None));
        let _: i64 = self.run(redis::cmd("SREM").arg(idx_key).arg(&lower_id)).await.map_err(raw_error)?;
        let perm_doc_key = perm_doc_key.map(str::to_owned).unwrap_or_else(|| self.perm_doc_key(collection, &lower_id));
        let _: i64 = self.run(redis::cmd("DEL").arg(perm_doc_key)).await.map_err(raw_error)?;
        Ok(())
    }

    /// `rawRestoreDoc()`.
    async fn raw_restore_doc(
        &self,
        collection: &str,
        id: &str,
        payload: &[u8],
        doc_key: Option<&str>,
        idx_key: Option<&str>,
    ) -> Result<()> {
        let lower_id = lower(id);
        let doc_key = doc_key.map(str::to_owned).unwrap_or_else(|| self.doc_key(collection, &lower_id, None));
        let _: () = self.run(redis::cmd("SET").arg(doc_key).arg(payload)).await.map_err(raw_error)?;
        let idx_key = idx_key.map(str::to_owned).unwrap_or_else(|| self.idx_key(collection, None));
        let _: i64 = self.run(redis::cmd("SADD").arg(idx_key).arg(&lower_id)).await.map_err(raw_error)?;
        Ok(())
    }

    /// Whether a key exists (`(bool) $client->exists($key)`), outside `tx()`.
    async fn exists_key(&self, key: &str) -> Result<bool> {
        let n: i64 = self.run(redis::cmd("EXISTS").arg(key)).await.map_err(raw_error)?;
        Ok(n > 0)
    }

    // ----- Permissions -----

    /// Stores a document's permissions in the role sets and its hash, and
    /// journals each grant (`writePermissions()`).
    async fn write_permissions(&self, collection: &str, id: &str, document: &Document) -> Result<()> {
        let id = lower(id);
        let mut by_role: Vec<(String, Vec<&'static str>)> = Vec::new();
        for kind in Database::PERMISSIONS {
            let letter = action_letter(kind)?;
            for role in document.permissions_by_type(kind) {
                match by_role.iter_mut().find(|(r, _)| *r == role) {
                    Some((_, letters)) => letters.push(letter),
                    None => by_role.push((role, vec![letter])),
                }
            }
        }
        if by_role.is_empty() {
            return Ok(());
        }
        let hash_key = self.perm_doc_key(collection, &id);
        let mut fields: Vec<(String, String)> = Vec::new();
        let mut writes: Vec<(String, String)> = Vec::new();
        for (role, letters) in &by_role {
            let mut unique: Vec<&str> = Vec::new();
            for l in letters {
                if !unique.contains(l) {
                    unique.push(l);
                }
            }
            unique.sort_unstable();
            fields.push((role.clone(), unique.join(",")));
            for letter in unique {
                writes.push((role.clone(), letter.to_owned()));
            }
        }
        let mut pipe = redis::pipe();
        for (role, letter) in &writes {
            pipe.cmd("SADD").arg(self.perm_key(collection, letter, role)).arg(&id);
        }
        let mut hmset = redis::cmd("HMSET");
        hmset.arg(&hash_key);
        for (field, value) in &fields {
            hmset.arg(field).arg(value);
        }
        pipe.add_command(hmset);
        let _: Vec<redis::Value> = self.pipe(&pipe).await.map_err(tx_error)?;
        for (role, letter) in writes {
            self.record(Entry::CreatePerm { collection: collection.to_owned(), id: id.clone(), role, letter });
        }
        Ok(())
    }

    /// Removes a document's permissions from the role sets and drops its
    /// hash, journalling each (`clearPermissions()`).
    async fn clear_permissions(&self, collection: &str, id: &str) -> Result<()> {
        let id = lower(id);
        let hash_key = self.perm_doc_key(collection, &id);
        let hash: Vec<(String, String)> = self.run(redis::cmd("HGETALL").arg(&hash_key)).await.map_err(tx_error)?;
        if hash.is_empty() {
            return Ok(());
        }
        let mut removals: Vec<(String, String)> = Vec::new();
        for (role, csv) in &hash {
            if csv.is_empty() {
                continue;
            }
            for letter in csv.split(',') {
                removals.push((role.clone(), letter.to_owned()));
            }
        }
        let mut pipe = redis::pipe();
        for (role, letter) in &removals {
            pipe.cmd("SREM").arg(self.perm_key(collection, letter, role)).arg(&id);
        }
        pipe.cmd("DEL").arg(&hash_key);
        let _: Vec<redis::Value> = self.pipe(&pipe).await.map_err(tx_error)?;
        for (role, letter) in removals {
            let previous = hash.iter().find(|(r, _)| *r == role).map(|(_, v)| v.clone()).unwrap_or_default();
            self.record(Entry::DeletePerm {
                collection: collection.to_owned(),
                id: id.clone(),
                role,
                letter,
                previous,
            });
        }
        Ok(())
    }

    /// The ids (of `ids`, in order) the authorization's roles may act on
    /// with `action`; all of them when authorization is off
    /// (`applyPermissionFilter()`).
    async fn permitted(&self, collection: &str, ids: Vec<String>, action: &str) -> Result<Vec<String>> {
        if ids.is_empty() {
            return Ok(ids);
        }
        let authorization = self.authorization();
        if !authorization.status() {
            return Ok(ids);
        }
        let roles = authorization.roles();
        if roles.is_empty() {
            return Ok(Vec::new());
        }
        let letter = action_letter(action)?;
        let keys: Vec<String> = roles.iter().map(|r| self.perm_key(collection, letter, &to_text(r))).collect();
        let allowed: Vec<String> = if keys.len() == 1 {
            self.run(redis::cmd("SMEMBERS").arg(&keys[0])).await.map_err(tx_error)?
        } else {
            self.run(redis::cmd("SUNION").arg(&keys)).await.map_err(tx_error)?
        };
        if allowed.is_empty() {
            return Ok(Vec::new());
        }
        let allowed: std::collections::HashSet<String> = allowed.into_iter().collect();
        Ok(ids.into_iter().filter(|id| allowed.contains(id)).collect())
    }
}

#[async_trait]
impl Adapter for Redis {
    fn state(&self) -> &State {
        &self.state
    }

    /// Timeouts do not apply (`setTimeout()` does nothing).
    fn set_timeout(&self, _milliseconds: i64, _event: &str) -> Result<()> {
        Ok(())
    }

    // ----- Transactions -----

    async fn start_transaction(&self) -> Result<bool> {
        self.journal().push(Vec::new());
        self.state.lock().in_transaction += 1;
        Ok(true)
    }

    async fn commit_transaction(&self) -> Result<bool> {
        if self.state.lock().in_transaction == 0 {
            return Ok(false);
        }
        self.commit_journal();
        self.state.lock().in_transaction -= 1;
        Ok(true)
    }

    /// Undoes the innermost transaction. A failed undo resets the depth and
    /// the journal together, so the adapter is clean for reuse.
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
                self.journal().clear();
                Err(e)
            }
        }
    }

    // ----- Connection -----

    async fn ping(&self) -> Result<bool> {
        let reply: redis::Value = self.run(&redis::cmd("PING")).await.map_err(raw_error)?;
        Ok(match reply {
            redis::Value::Nil => false,
            redis::Value::Boolean(b) => b,
            _ => true,
        })
    }

    async fn reconnect(&self) -> Result<()> {
        Ok(())
    }

    async fn connection_id(&self) -> Result<String> {
        Ok("0".to_owned())
    }

    // ----- Databases, collections, attributes, relationships, indexes -----

    async fn create(&self, name: &str) -> Result<bool> {
        self.create_database(name).await
    }

    async fn exists(&self, database: &str, collection: Option<&str>) -> Result<bool> {
        self.exists_in(database, collection).await
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.list_databases().await
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.delete_database(name).await
    }

    async fn create_collection(&self, name: &str, attributes: &[Document], indexes: &[Document]) -> Result<bool> {
        self.create_collection_keys(name, attributes, indexes).await
    }

    async fn delete_collection(&self, id: &str) -> Result<bool> {
        self.delete_collection_keys(id).await
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

    async fn schema_attributes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn schema_indexes(&self, _collection: &str) -> Result<Vec<Document>> {
        Ok(Vec::new())
    }

    async fn create_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        required: bool,
    ) -> Result<bool> {
        self.put_attribute(collection, id, kind, size, signed, array, required).await
    }

    async fn create_attributes(&self, collection: &str, attributes: &[Array]) -> Result<bool> {
        for attribute in attributes {
            let get = |k: &str| attribute.get_str(k).cloned();
            self.put_attribute(
                collection,
                &get("$id").map(|v| to_text(&v)).unwrap_or_default(),
                &get("type").map(|v| to_text(&v)).unwrap_or_default(),
                get("size").map(|v| crate::adapter::redis::query::int_of(&v)).unwrap_or(0),
                get("signed").map(|v| v.truthy()).unwrap_or(true),
                get("array").map(|v| v.truthy()).unwrap_or(false),
                get("required").map(|v| v.truthy()).unwrap_or(false),
            )
            .await?;
        }
        Ok(true)
    }

    async fn update_attribute(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        size: i64,
        signed: bool,
        array: bool,
        new_key: Option<&str>,
        required: bool,
    ) -> Result<bool> {
        self.change_attribute(collection, id, kind, size, signed, array, new_key, required).await
    }

    async fn delete_attribute(&self, collection: &str, id: &str) -> Result<bool> {
        self.drop_attribute(collection, id).await
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_attribute_keys(collection, old, new).await
    }

    async fn create_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        id: &str,
        two_way_key: &str,
    ) -> Result<bool> {
        self.create_relationship_attributes(collection, related_collection, kind, two_way, id, two_way_key).await
    }

    async fn update_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
        new_key: Option<&str>,
        new_two_way_key: Option<&str>,
    ) -> Result<bool> {
        self.update_relationship_attributes(
            collection,
            related_collection,
            kind,
            two_way,
            key,
            two_way_key,
            side,
            new_key,
            new_two_way_key,
        )
        .await
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        related_collection: &str,
        kind: &str,
        two_way: bool,
        key: &str,
        two_way_key: &str,
        side: &str,
    ) -> Result<bool> {
        self.delete_relationship_attributes(collection, related_collection, kind, two_way, key, two_way_key, side).await
    }

    async fn create_index(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &[Value],
        orders: &[Value],
        _attribute_types: &[(String, String)],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        self.put_index(collection, id, kind, attributes, lengths, orders).await
    }

    /// Stores `$lengths` and `$orders` as `createIndex()` hands them over: a
    /// list as it is (PHP's `array_values`), otherwise by position (`null`
    /// where unset) up to the last one set.
    async fn create_index_arrays(
        &self,
        collection: &str,
        id: &str,
        kind: &str,
        attributes: &[String],
        lengths: &Array,
        orders: &Array,
        _attribute_types: &[(String, String)],
        _collation: &[String],
        _ttl: i64,
    ) -> Result<bool> {
        self.put_index(collection, id, kind, attributes, &as_given(lengths), &as_given(orders)).await
    }

    async fn delete_index(&self, collection: &str, id: &str) -> Result<bool> {
        self.drop_index(collection, id).await
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_index_keys(collection, old, new).await
    }

    // ----- Documents -----

    async fn get_document(
        &self,
        collection: &Document,
        id: &str,
        queries: &[Query],
        _for_update: bool,
    ) -> Result<Document> {
        self.read_document(collection, id, queries).await
    }

    async fn create_document(&self, collection: &Document, document: Document) -> Result<Document> {
        self.insert_document(collection, document).await
    }

    async fn create_documents(&self, collection: &Document, documents: Vec<Document>) -> Result<Vec<Document>> {
        let mut created = Vec::with_capacity(documents.len());
        for document in documents {
            created.push(self.insert_document(collection, document).await?);
        }
        Ok(created)
    }

    async fn update_document(
        &self,
        collection: &Document,
        id: &str,
        document: Document,
        skip_permissions: bool,
    ) -> Result<Document> {
        // PHP returns the merged row but leaves the caller's document as it
        // was, and `Database::updateDocument` carries on with that one
        // (see [`Adapter::update_document`]).
        let held = document.clone();
        self.replace_document(collection, id, document, skip_permissions).await?;
        Ok(held)
    }

    async fn update_documents(&self, collection: &Document, updates: &Document, documents: &[Document]) -> Result<i64> {
        self.replace_documents(collection, updates, documents).await
    }

    async fn upsert_documents(
        &self,
        collection: &Document,
        attribute: &str,
        changes: Vec<Change>,
    ) -> Result<Vec<Document>> {
        self.upsert(collection, attribute, changes).await
    }

    async fn get_sequences(&self, collection: &str, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.sequences(collection, documents).await
    }

    async fn delete_document(&self, collection: &str, id: &str) -> Result<bool> {
        self.remove_document(collection, id).await
    }

    async fn delete_documents(&self, collection: &str, sequences: &[Value], permission_ids: &[String]) -> Result<i64> {
        self.remove_documents(collection, sequences, permission_ids).await
    }

    async fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
        value: &Value,
        updated_at: &str,
        min: Option<&Value>,
        max: Option<&Value>,
    ) -> Result<bool> {
        self.increase(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn find(&self, collection: &Document, queries: &[Query], options: FindOptions<'_>) -> Result<Vec<Document>> {
        self.find_documents(collection, queries, options).await
    }

    async fn sum(&self, collection: &Document, attribute: &str, queries: &[Query], max: Option<i64>) -> Result<Value> {
        self.sum_documents(collection, attribute, queries, max).await
    }

    async fn count(&self, collection: &Document, queries: &[Query], max: Option<i64>) -> Result<i64> {
        self.count_documents(collection, queries, max).await
    }

    // ----- Value conversion -----

    fn casting_before(&self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn casting_after(&self, _collection: &Document, document: Document) -> Result<Document> {
        Ok(document)
    }

    fn set_utc_datetime(&self, value: &str) -> Result<Value> {
        Ok(Value::from(value))
    }

    fn decode_point(&self, _wkb: &[u8]) -> Result<Value> {
        Err(Error::database("Spatial types are not implemented in the Redis adapter"))
    }

    fn decode_linestring(&self, _wkb: &[u8]) -> Result<Value> {
        Err(Error::database("Spatial types are not implemented in the Redis adapter"))
    }

    fn decode_polygon(&self, _wkb: &[u8]) -> Result<Value> {
        Err(Error::database("Spatial types are not implemented in the Redis adapter"))
    }

    fn tenant_query(&self, _collection: &str, _alias: &str) -> String {
        String::new()
    }

    // ----- Limits -----

    fn limit_for_string(&self) -> i64 {
        4294967295
    }

    fn limit_for_int(&self) -> i64 {
        4294967295
    }

    fn limit_for_big_int(&self) -> i64 {
        Database::MAX_BIG_INT
    }

    fn limit_for_attributes(&self) -> i64 {
        1017
    }

    fn limit_for_indexes(&self) -> i64 {
        64
    }

    fn max_index_length(&self) -> i64 {
        1024
    }

    fn max_varchar_length(&self) -> i64 {
        16381
    }

    fn max_uid_length(&self) -> i64 {
        255
    }

    fn min_date_time(&self) -> DateTime {
        DateTime::new(b"0001-01-01 00:00:00", None, &TzInfo::utc()).unwrap_or_else(|_| unreachable!("a valid date"))
    }

    fn id_attribute_type(&self) -> &'static str {
        Database::VAR_INTEGER
    }

    fn count_of_attributes(&self, collection: &Document) -> i64 {
        count_of(collection, "attributes") + self.count_of_default_attributes()
    }

    fn count_of_indexes(&self, collection: &Document) -> i64 {
        count_of(collection, "indexes") + Database::INTERNAL_INDEXES.len() as i64
    }

    fn count_of_default_attributes(&self) -> i64 {
        Database::internal_attributes_const().len() as i64
    }

    fn count_of_default_indexes(&self) -> i64 {
        Database::INTERNAL_INDEXES.len() as i64
    }

    fn document_size_limit(&self) -> i64 {
        0
    }

    fn attribute_width(&self, _collection: &Document) -> Result<i64> {
        Ok(0)
    }

    fn keywords(&self) -> &'static [&'static str] {
        &[]
    }

    fn internal_indexes_keys(&self) -> &'static [&'static str] {
        &[]
    }

    // ----- Capabilities -----

    fn support_for_schemas(&self) -> bool {
        true
    }
    fn support_for_attributes(&self) -> bool {
        true
    }
    fn set_support_for_attributes(&self, _support: bool) -> bool {
        true
    }
    fn support_for_schema_attributes(&self) -> bool {
        false
    }
    fn support_for_schema_indexes(&self) -> bool {
        false
    }
    fn support_for_index(&self) -> bool {
        true
    }
    fn support_for_index_array(&self) -> bool {
        false
    }
    fn support_for_cast_index_array(&self) -> bool {
        false
    }
    fn support_for_unique_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_index(&self) -> bool {
        true
    }
    fn support_for_fulltext_wildcard_index(&self) -> bool {
        false
    }
    fn support_for_casting(&self) -> bool {
        true
    }
    fn support_for_query_contains(&self) -> bool {
        true
    }
    fn support_for_timeouts(&self) -> bool {
        false
    }
    fn support_for_relationships(&self) -> bool {
        true
    }
    fn support_for_update_lock(&self) -> bool {
        false
    }
    fn support_for_batch_operations(&self) -> bool {
        true
    }
    fn support_for_attribute_resizing(&self) -> bool {
        true
    }
    fn support_for_get_connection_id(&self) -> bool {
        false
    }
    fn support_for_upserts(&self) -> bool {
        false
    }
    fn support_for_upsert_on_unique_index(&self) -> bool {
        false
    }
    fn support_for_vectors(&self) -> bool {
        false
    }
    fn support_for_cache_skip_on_failure(&self) -> bool {
        false
    }
    /// The adapter is the store: reads go to Redis, never through a cache.
    fn support_for_caching(&self) -> bool {
        false
    }
    fn support_for_reconnection(&self) -> bool {
        false
    }
    fn support_for_hostname(&self) -> bool {
        false
    }
    fn support_for_batch_create_attributes(&self) -> bool {
        true
    }
    fn support_for_spatial_attributes(&self) -> bool {
        false
    }
    fn support_for_object(&self) -> bool {
        true
    }
    fn support_for_object_indexes(&self) -> bool {
        false
    }
    fn support_for_spatial_index_null(&self) -> bool {
        false
    }
    fn support_for_operators(&self) -> bool {
        true
    }
    fn support_for_optional_spatial_attribute_with_existing_rows(&self) -> bool {
        false
    }
    fn support_for_spatial_index_order(&self) -> bool {
        false
    }
    fn support_for_spatial_axis_order(&self) -> bool {
        false
    }
    fn support_for_boundary_inclusive_contains(&self) -> bool {
        false
    }
    fn support_for_distance_between_multi_dimension_geometry_in_meters(&self) -> bool {
        false
    }
    fn support_for_multiple_fulltext_indexes(&self) -> bool {
        false
    }
    fn support_for_identical_indexes(&self) -> bool {
        false
    }
    fn support_for_order_random(&self) -> bool {
        true
    }
    fn support_for_internal_casting(&self) -> bool {
        false
    }
    fn support_for_utc_casting(&self) -> bool {
        false
    }
    fn support_for_integer_booleans(&self) -> bool {
        false
    }
    fn support_for_alter_locks(&self) -> bool {
        false
    }
    fn support_non_utf_characters(&self) -> bool {
        false
    }
    fn support_for_trigram_index(&self) -> bool {
        false
    }
    fn support_for_pcre_regex(&self) -> bool {
        true
    }
    fn support_for_posix_regex(&self) -> bool {
        false
    }
    /// No optimistic retries: `tx()` runs once (a retry would replay
    /// journalled writes).
    fn support_for_transaction_retries(&self) -> bool {
        false
    }
    fn support_for_nested_transactions(&self) -> bool {
        true
    }
}

impl Redis {
    /// A meta hash field's JSON.
    fn meta_json(value: &Value) -> Result<Vec<u8>> {
        json_encode(value, meta_flags())
    }
}

/// An index's lengths or orders as `createIndex()` hands them to the
/// adapter: a list as it is, otherwise by position (`null` where unset) up
/// to the last one set.
fn as_given(array: &Array) -> Vec<Value> {
    let list = array.iter().enumerate().all(|(i, (k, _))| matches!(k, crate::value::KeyRef::Int(n) if n == i as i64));
    if list {
        return array.values().cloned().collect();
    }
    let count = array
        .keys()
        .filter_map(|k| match k {
            crate::value::KeyRef::Int(n) if n >= 0 => Some(n as usize + 1),
            _ => None,
        })
        .max()
        .unwrap_or(0);
    (0..count).map(|i| array.get(&crate::value::ArrayKey::Int(i as i64)).cloned().unwrap_or(Value::Null)).collect()
}
