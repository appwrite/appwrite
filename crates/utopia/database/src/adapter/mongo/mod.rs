//! [`Mongo`]: the MongoDB adapter.
//!
//! A collection is a MongoDB collection named `<namespace>_<id>` in the
//! client's database; a document is a BSON document whose internal
//! attributes are stored under `_uid` (`$id`), `_id` (`$sequence`, a UUIDv7
//! string), `_tenant`, `_createdAt`, `_updatedAt` and `_permissions`, every
//! other `$` key under `_`, and dots in keys as `__dot__`. Attributes need no
//! schema: creating one is a no-op, and in schemaless mode
//! ([`Schemaless::set_schemaless`]) documents carry any attribute. Queries
//! become `find` filters (and `$match` stages of `count` and `sum`),
//! permissions and tenants narrow them through the read hooks
//! ([`crate::hook::mongo`]), operators become aggregation pipeline updates,
//! and transactions are the server's, on a replica set (a standalone server
//! runs without).
//!
//! It runs on a [`Driver`]: [`utopia_mongo::Client`] (the official driver)
//! in production, a test's stand-in otherwise. The commands it sends are
//! the ones PHP's adapter sends through utopia-php/mongo, so collections,
//! indexes and documents are the ones PHP's adapter writes.
//!
//! ```no_run
//! # async fn demo() -> utopia_database::Result<()> {
//! use std::sync::Arc;
//!
//! use utopia_database::Adapter;
//! use utopia_database::adapter::mongo::{Mongo, from_client};
//! use utopia_mongo::{Client, Config};
//!
//! let client = Client::new(Config::new("appwrite", "localhost", 27017, "root", "password")).map_err(from_client)?;
//! let adapter = Mongo::new(Arc::new(client)).await?;
//! adapter.set_database("appwrite");
//! adapter.set_namespace("_1");
//! # Ok(()) }
//! ```
//!
//! PHP: `Utopia\Database\Adapter\Mongo`.

use std::any::Any;
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::sync::{Arc, Mutex as SyncMutex};
use std::time::Duration;

use async_trait::async_trait;
use php_std::datetime::{DateTime, TzInfo};
use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};
use utopia_mongo::{Client, Options, Session};
use utopia_query::schema::{ColumnType, IndexType};

use super::{
    Adapter, Casting, Connection, Find, Limits, Relationships, Schemaless, State, Timeouts, TransactionStrategy,
    Upserts,
};
use crate::attribute::Attribute;
use crate::capability::{Capabilities, Capability};
use crate::change::Change;
use crate::collection::Collection;
use crate::database;
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::event::Event;
use crate::index::Index;
use crate::query::Query;
use crate::relationship::{self, Relationship, RelationshipSide};
use crate::schema;
use crate::value::{Array, ArrayKey, Str, Value};

pub(crate) mod bson;
mod casting;
mod documents;
pub mod driver;
mod filters;
mod operators;
mod schema_ops;

pub use driver::{Driver, DriverResult};
pub use filters::replace_characters;

/// The batch size of the adapter's reads (`DEFAULT_BATCH_SIZE`).
const BATCH_SIZE: i64 = 1000;

/// How many times a transaction that lost a write conflict runs again
/// (`WRITE_CONFLICT_RETRIES`).
const WRITE_CONFLICT_RETRIES: u32 = 20;

/// The shortest wait before a transaction that lost a write conflict runs
/// again (`WRITE_CONFLICT_SLEEP`, 5 ms).
const WRITE_CONFLICT_SLEEP: Duration = Duration::from_micros(5_000);

/// MongoDB's `WriteConflict` code.
const WRITE_CONFLICT: i64 = 112;

/// How many times a commit whose result is unknown is sent again
/// (`COMMIT_RETRIES`).
const COMMIT_RETRIES: u64 = 3;

/// The wait before each commit retry, times the retry number
/// (`COMMIT_RETRY_SLEEP`, 50 ms).
const COMMIT_RETRY_SLEEP: Duration = Duration::from_millis(50);

/// The operators `replaceInternalIdsKeys()` leaves alone (`$operators`).
const OPERATORS: [&str; 19] = [
    "$eq",
    "$ne",
    "$lt",
    "$lte",
    "$gt",
    "$gte",
    "$in",
    "$nin",
    "$text",
    "$search",
    "$or",
    "$and",
    "$match",
    "$regex",
    "$not",
    "$nor",
    "$exists",
    "$elemMatch",
    "$all",
];

/// Server codes of a network failure (`Exception::isNetworkError()`).
const NETWORK_ERRORS: [i64; 7] = [11600, 11601, 11602, 89, 9001, 6, 7];

/// Server codes after which a commit may have applied
/// (`Client::isUnknownTransactionCommitResult()`).
const UNKNOWN_COMMIT_ERRORS: [i64; 10] = [50, 91, 189, 262, 9001, 10107, 11600, 11602, 13435, 13436];

/// The MongoDB adapter. See the [module docs](self).
///
/// PHP: `Utopia\Database\Adapter\Mongo`.
pub struct Mongo {
    state: State,
    driver: Arc<dyn Driver>,
    /// The session of the transaction in progress (`$session`).
    session: SyncMutex<Option<Session>>,
    /// `$schemaless`.
    schemaless: AtomicBool,
    /// `$timeout`, in milliseconds.
    timeout: AtomicI64,
    /// Whether the server is a replica set, once a call asked.
    replica_set: SyncMutex<Option<bool>>,
}

impl std::fmt::Debug for Mongo {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Mongo").field("schemaless", &self.schemaless).field("timeout", &self.timeout).finish()
    }
}

/// A client error as the PHP adapter lets it out: `Utopia\Mongo\Exception`
/// (its subclasses too) and `UnsentException` with message, code and error
/// labels kept; the client's other failures as their own classes.
pub fn from_client(e: utopia_mongo::Error) -> Error {
    let kind = match &e {
        utopia_mongo::Error::Unsent(_) => Kind::MongoUnsent,
        utopia_mongo::Error::InvalidArgument(_) => Kind::InvalidArgument,
        utopia_mongo::Error::Engine { class, .. } => Kind::from_php_class(class).unwrap_or(Kind::Error),
        utopia_mongo::Error::UnexpectedValue { .. } => Kind::MongoUnexpectedValue,
        _ => Kind::Mongo,
    };
    let labels: Vec<String> = e
        .failure()
        .map(|f| f.error_labels.iter().filter_map(|l| l.as_str().map(str::to_owned)).collect())
        .unwrap_or_default();
    let error = Error::from_bytes(kind, e.message_bytes().to_vec()).with_code(e.code());
    if labels.is_empty() { error } else { error.with_labels(labels) }
}

/// Whether `error` is a `Utopia\Mongo\Exception` (`UnsentException` too).
fn is_mongo(error: &Error) -> bool {
    error.is_a(Kind::Mongo)
}

/// Whether `error` carries the server label `label`.
fn labelled(error: &Error, label: &str) -> bool {
    error.labels().iter().any(|l| l == label)
}

/// `Exception::isTransientError()`.
fn is_transient_error(error: &Error) -> bool {
    labelled(error, utopia_mongo::client::TRANSIENT_TRANSACTION_ERROR)
        || labelled(error, utopia_mongo::client::UNKNOWN_TRANSACTION_COMMIT_RESULT)
        || NETWORK_ERRORS.contains(&error.code())
        || labelled(error, "RetryableWriteError")
}

/// `processException($e)`: an error mapped by its code to the database
/// error PHP maps it to (time limits, duplicates, missing or aborted
/// transactions, invalid operations, values out of range), or as it is.
pub fn process_exception(e: Error) -> Error {
    let code = e.code();
    let mapped = |kind: Kind, message: &str| Error::new(kind, message).with_code(code).with_previous(e.clone());
    match code {
        50 | 262 => mapped(Kind::Timeout, "Query timed out"),
        11000 | 11001 => match violated_index(e.message()) {
            Some(index) if index != "_uid" && index != "_id_" => mapped(Kind::Unique, crate::error::UNIQUE_MESSAGE),
            _ => mapped(Kind::Duplicate, "Document already exists"),
        },
        48 => mapped(Kind::Duplicate, "Collection already exists"),
        85 => mapped(Kind::Duplicate, "Index already exists"),
        251 => mapped(Kind::Transaction, "No active transaction"),
        112 => mapped(Kind::Transaction, "Transaction aborted"),
        14 => mapped(Kind::Type, "Invalid operation"),
        28764 => mapped(Kind::Limit, "Value out of range"),
        _ => e,
    }
}

/// `getViolatedIndex($message)`: the index a duplicate key error names
/// (`... index: <name> dup key ...`).
pub fn violated_index(message: &str) -> Option<String> {
    // `/index:\s*(\S+)\s+dup key/`
    let bytes = message.as_bytes();
    let space = |b: u8| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | b'\x0b' | b'\x0c');
    let mut from = 0;
    while let Some(at) = message[from..].find("index:") {
        let mut i = from + at + "index:".len();
        while i < bytes.len() && space(bytes[i]) {
            i += 1;
        }
        let start = i;
        while i < bytes.len() && !space(bytes[i]) {
            i += 1;
        }
        // `\S+` backtracks: the name is the longest run of non-spaces after
        // which spaces and `dup key` follow, else a shorter one cannot be.
        if i > start {
            let mut j = i;
            while j < bytes.len() && space(bytes[j]) {
                j += 1;
            }
            if j > i && bytes[j..].starts_with(b"dup key") {
                return Some(message[start..i].to_owned());
            }
        }
        from += at + 1;
    }
    None
}

/// The client's [`Options`] of a call: the session when one is given, and
/// `fields`.
fn options(session: Option<Session>, fields: BsonDocument) -> Options {
    Options { session, fields }
}

/// A database error wrapping `previous` with its code (`new
/// DatabaseException($message, $previous->getCode(), $previous)`).
fn wrapped(message: String, previous: Error) -> Error {
    Error::database(message).with_code(previous.code()).with_previous(previous)
}

/// A [`DateTime`] of `value` (`new \DateTime($value)`), PHP's failure as
/// its class.
fn date(value: &[u8]) -> Result<DateTime> {
    DateTime::new(value, None, &TzInfo::utc())
        .map_err(|e| Error::new(Kind::from_php_class(e.php_class()).unwrap_or(Kind::Exception), e.message()))
}

impl Mongo {
    /// The adapter over `client`, which it connects.
    ///
    /// PHP: `new Mongo($client)`.
    pub async fn new(client: Arc<Client>) -> Result<Mongo> {
        Mongo::with_driver(client).await
    }

    /// The adapter over `driver`, which it connects.
    ///
    /// PHP: `new Mongo($client)` with a client of another class.
    pub async fn with_driver(driver: Arc<dyn Driver>) -> Result<Mongo> {
        driver.connect().await.map_err(from_client)?;
        Ok(Mongo {
            state: State::default(),
            driver,
            session: SyncMutex::new(None),
            schemaless: AtomicBool::new(false),
            timeout: AtomicI64::new(0),
            replica_set: SyncMutex::new(None),
        })
    }

    /// The driver the adapter runs on (`getDriver()`).
    pub fn driver(&self) -> &Arc<dyn Driver> {
        &self.driver
    }

    fn session(&self) -> Option<Session> {
        self.session.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    fn set_session(&self, session: Option<Session>) {
        *self.session.lock().unwrap_or_else(|e| e.into_inner()) = session;
    }

    fn depth(&self) -> i64 {
        self.state.lock().in_transaction
    }

    fn set_depth(&self, depth: i64) {
        self.state.lock().in_transaction = depth;
    }

    /// `getNamespace() . '_' . filter($collection)`: a collection's name.
    fn name(&self, collection: &str) -> String {
        format!("{}_{}", self.namespace(), super::filter(collection))
    }

    /// `getTransactionOptions()`: the session of the transaction in
    /// progress, if any.
    fn transaction_session(&self) -> Option<Session> {
        if self.depth() > 0 { self.session() } else { None }
    }

    /// `getTransactionOptions($options)` as client options.
    fn options(&self, fields: BsonDocument) -> Options {
        options(self.transaction_session(), fields)
    }

    /// A client failure as the adapter rethrows it from its
    /// `catch (MongoException $e)` blocks: mapped by
    /// [`process_exception`]; the client's other failures as they are.
    fn mongo(&self, e: utopia_mongo::Error) -> Error {
        let e = from_client(e);
        if is_mongo(&e) { process_exception(e) } else { e }
    }

    /// `isReplicaSet()`, remembered for [`Adapter::is_retryable`].
    async fn is_replica_set(&self) -> Result<bool> {
        let known = self.driver.is_replica_set().await.map_err(from_client)?;
        *self.replica_set.lock().unwrap_or_else(|e| e.into_inner()) = Some(known);
        Ok(known)
    }

    /// `endSession()`: ends the transaction's session (best effort) and
    /// forgets it.
    async fn end_session(&self) {
        if let Some(session) = self.session() {
            let _ = self.driver.end_sessions(std::slice::from_ref(&session)).await;
        }
        self.set_session(None);
    }

    /// `isUnknownCommitResult($error)`: whether a commit reached the server
    /// but its result is unknown.
    fn is_unknown_commit_result(error: &Error) -> bool {
        if !is_mongo(error) || error.kind() == Kind::MongoUnsent {
            return false;
        }
        if labelled(error, utopia_mongo::client::TRANSIENT_TRANSACTION_ERROR) {
            return false;
        }
        labelled(error, utopia_mongo::client::UNKNOWN_TRANSACTION_COMMIT_RESULT)
            || NETWORK_ERRORS.contains(&error.code())
            || UNKNOWN_COMMIT_ERRORS.contains(&error.code())
            || error.message().contains(utopia_mongo::client::UNKNOWN_TRANSACTION_COMMIT_RESULT)
    }

    /// `isAbortedCommit($error)`: whether the server reports the
    /// transaction aborted.
    fn is_aborted_commit(error: &Error) -> bool {
        is_mongo(error)
            && (labelled(error, utopia_mongo::client::TRANSIENT_TRANSACTION_ERROR)
                || process_exception(error.clone()).kind() == Kind::Transaction)
    }

    /// `commit($session)`: commits, sending only the commit again while its
    /// result is unknown.
    async fn commit(&self, session: &Session) -> Result<()> {
        let Err(e) = self.driver.commit_transaction(session, BsonDocument::new()).await else { return Ok(()) };
        let error = from_client(e);
        if Self::is_unknown_commit_result(&error) {
            return self.retry_commit(session, error).await;
        }
        if !is_mongo(&error) {
            return Err(wrapped(error.message().to_owned(), error));
        }
        Err(process_exception(error))
    }

    /// `retryCommit($session, $unknown)`.
    async fn retry_commit(&self, session: &Session, unknown: Error) -> Result<()> {
        for retry in 1..=COMMIT_RETRIES {
            tokio::time::sleep(COMMIT_RETRY_SLEEP * retry as u32).await;
            let concern = doc! { "writeConcern": { "w": "majority", "wtimeout": 10_000 } };
            match self.driver.commit_transaction(session, concern).await {
                Ok(()) => return Ok(()),
                Err(e) => {
                    let error = from_client(e);
                    if error.kind() == Kind::MongoUnsent || Self::is_unknown_commit_result(&error) {
                        continue;
                    }
                    if Self::is_aborted_commit(&error) {
                        return Err(Error::transaction("The transaction was aborted while its commit was retried")
                            .with_previous(error));
                    }
                    break;
                }
            }
        }
        Err(Error::new(Kind::Unconfirmed, "Failed to commit transaction: the commit could not be confirmed")
            .with_previous(unknown))
    }

    /// `getTenantFilters($collection, $tenants)`: the `_tenant` filter of a
    /// shared collection: the tenant (or `tenants`), plus `null` for the
    /// metadata collection, `$in` when there are several; `null` without
    /// shared tables or a tenant.
    pub fn tenant_filters(&self, collection: &str, tenants: &[Value]) -> Value {
        if !self.shared_tables() {
            return Value::Null;
        }
        let mut values: Vec<Value> = Vec::new();
        if tenants.is_empty() {
            let tenant = self.tenant();
            if !matches!(tenant, Value::Null) {
                values.push(tenant);
            }
        } else {
            values.extend(tenants.iter().cloned());
        }
        if collection == database::METADATA && !values.is_empty() {
            values.push(Value::Null);
            return filters::assoc([("$in", Value::Array(values.into_iter().collect()))]);
        }
        match values.len() {
            0 => Value::Null,
            1 => values.remove(0),
            _ => filters::assoc([("$in", Value::Array(values.into_iter().collect()))]),
        }
    }

    /// `applyTenantFilter($filters, $collection)`.
    fn apply_tenant_filter(&self, filters: Array, collection: &str) -> Array {
        use crate::hook::mongo::Read;
        let hook = crate::hook::mongo::Tenant::new(self.shared_tables(), |c: &str| self.tenant_filters(c, &[]));
        hook.apply_filters(filters, collection, crate::permission::PermissionType::Read)
    }

    /// `applyReadFilters($filters, $collection, $forPermission)`: the tenant
    /// filter, then the permission hook.
    fn apply_read_filters(
        &self,
        filters: Array,
        collection: &str,
        permission: crate::permission::PermissionType,
    ) -> Array {
        use crate::hook::mongo::Read;
        let filters = self.apply_tenant_filter(filters, collection);
        let hooks: [Box<dyn Read>; 1] = [Box::new(crate::hook::mongo::Permission::new(self.authorization()))];
        hooks.iter().fold(filters, |filters, hook| hook.apply_filters(filters, collection, permission))
    }

    /// `getOrder($order)`: `1` ascending, `-1` descending.
    ///
    /// Fails ([`Kind::Query`]) for a random order.
    fn order(order: utopia_query::OrderDirection) -> Result<i64> {
        match order {
            utopia_query::OrderDirection::Asc => Ok(1),
            utopia_query::OrderDirection::Desc => Ok(-1),
            utopia_query::OrderDirection::Random => Err(Error::query("Random order is not supported by this adapter")),
        }
    }

    /// `getInternalKeyForAttribute($attribute)`: the stored name of an
    /// internal attribute (`$id` is `_uid`, `$sequence` is `_id`, ...).
    pub fn internal_key(attribute: &str) -> &str {
        crate::storage::column(attribute)
    }

    /// `escapeMongoFieldName($name)`: a leading `$` as `_`, dots as `__dot__`.
    pub fn escape_field_name(name: &str) -> String {
        let name = match name.strip_prefix('$') {
            Some(rest) => format!("_{rest}"),
            None => name.to_owned(),
        };
        if name.contains('.') { name.replace('.', "__dot__") } else { name }
    }

    /// `shouldAddTenantToIndex($type)`: every index but a TTL one leads with
    /// `_tenant` under shared tables.
    fn should_add_tenant_to_index(&self, kind: IndexType) -> bool {
        self.shared_tables() && kind != IndexType::Ttl
    }

    /// The timeout reads run under (`$this->timeout`).
    fn read_timeout(&self) -> i64 {
        self.timeout.load(Ordering::Relaxed)
    }

    /// `isRetryable()` of the base adapter.
    fn base_is_retryable(&self, failure: &Error) -> bool {
        let mut cause = Some(failure);
        while let Some(c) = cause {
            if c.is_a(Kind::Transaction) {
                return true;
            }
            if c.is_a(Kind::Database) && c.kind() != Kind::Database {
                return false;
            }
            if self.is_transient(c) {
                return true;
            }
            cause = c.previous();
        }
        false
    }

    /// The attributes of `collection` followed by every internal one
    /// (`collectionAttributesWithInternal()`).
    fn attributes_with_internal(collection: &Collection) -> Result<Vec<Attribute>> {
        collection.attributes_with(database::internal_attributes(true))
    }
}

#[async_trait]
impl Adapter for Mongo {
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
            Capability::Objects,
            Capability::IndexFulltext,
            Capability::IndexTtl,
            Capability::Caching,
            Capability::Operators,
            Capability::TransactionRetries,
        ])
    }

    /// `supports()`: [`Capability::DefinedAttributes`] unless schemaless.
    fn supports(&self, capability: Capability) -> bool {
        if capability == Capability::DefinedAttributes {
            return !self.schemaless.load(Ordering::Relaxed);
        }
        self.capabilities().contains(capability)
    }

    /// `limits()`: collections hold any number of attributes and documents
    /// of any width, so both caps are 0.
    fn limits(&self) -> Arc<Limits> {
        if let Some(limits) = self.state.lock().limits.clone() {
            return limits;
        }
        let min = date(b"-9999-01-01 00:00:00").unwrap_or_else(|_| unreachable!("a valid date"));
        let max = date(super::MAX_DATETIME.as_bytes()).unwrap_or_else(|_| unreachable!("a valid date"));
        let limits = Arc::new(Limits {
            string: 2_147_483_647,
            varchar: 2_147_483_647,
            integer: 4_294_967_295,
            big_integer: database::MAX_BIG_INT,
            attributes: 0,
            indexes: 64,
            default_attributes: database::internal_attributes(true).len() as i64,
            default_indexes: database::INTERNAL_INDEXES.len() as i64,
            index_length: 1024,
            uid_length: 255,
            document_size: 0,
            min_date_time: min,
            max_date_time: max,
            id_type: ColumnType::Uuid7,
            keywords: Vec::new(),
            internal_index_keys: Vec::new(),
        });
        self.state.lock().limits = Some(limits.clone());
        limits
    }

    fn casting(&self) -> Option<&dyn Casting> {
        Some(self)
    }

    fn connection(&self) -> Option<&dyn Connection> {
        Some(self)
    }

    fn relationships(&self) -> Option<&dyn Relationships> {
        Some(self)
    }

    fn schemaless(&self) -> Option<&dyn Schemaless> {
        Some(self)
    }

    fn timeouts(&self) -> Option<&dyn Timeouts> {
        Some(self)
    }

    fn upserts(&self) -> Option<&dyn Upserts> {
        Some(self)
    }

    // ---- Transactions --------------------------------------------------

    /// `startTransaction()`: a session and its transaction, or one more
    /// level of nesting; nothing on a standalone server.
    async fn start_transaction(&self) -> Result<bool> {
        if !self.is_replica_set().await? {
            return Ok(true);
        }
        let started: Result<()> = async {
            if self.depth() == 0 && self.session().is_none() {
                let session = self.driver.start_session().await.map_err(from_client)?;
                self.set_session(Some(session.clone()));
                self.driver.start_transaction(&session).await.map_err(from_client)?;
            }
            self.set_depth(self.depth() + 1);
            Ok(())
        }
        .await;
        if let Err(e) = started {
            self.set_session(None);
            self.set_depth(0);
            return Err(wrapped(format!("Failed to start transaction: {}", e.message()), e));
        }
        Ok(true)
    }

    /// `commitTransaction()`: one level less of nesting, and the commit at
    /// the outermost.
    ///
    /// Fails ([`Kind::Unconfirmed`]) when the commit was sent but its result
    /// could not be confirmed, and ([`Kind::Database`]) otherwise, with a
    /// [`Kind::Transaction`] cause when the server reports it aborted.
    async fn commit_transaction(&self) -> Result<bool> {
        if !self.is_replica_set().await? {
            return Ok(true);
        }
        let outcome: Result<bool> = async {
            if self.depth() == 0 {
                return Ok(false);
            }
            self.set_depth(self.depth() - 1);
            if self.depth() > 0 {
                return Ok(true);
            }
            let Some(session) = self.session() else { return Ok(false) };
            let committed = self.commit(&session).await;
            self.end_session().await;
            committed.map(|()| true)
        }
        .await;
        match outcome {
            Ok(done) => Ok(done),
            Err(e) => {
                self.end_session().await;
                self.set_depth(0);
                if e.kind() == Kind::Unconfirmed {
                    return Err(e);
                }
                Err(wrapped(format!("Failed to commit transaction: {}", e.message()), e))
            }
        }
    }

    /// `rollbackTransaction()`: one level less of nesting, and the abort at
    /// the outermost; a transaction the server already aborted counts as
    /// rolled back.
    async fn rollback_transaction(&self) -> Result<bool> {
        if !self.is_replica_set().await? {
            return Ok(true);
        }
        let outcome: Result<bool> = async {
            if self.depth() == 0 {
                return Ok(false);
            }
            self.set_depth(self.depth() - 1);
            if self.depth() > 0 {
                return Ok(true);
            }
            let Some(session) = self.session() else { return Ok(false) };
            let aborted = match self.driver.abort_transaction(&session).await {
                Ok(()) => Ok(true),
                Err(e) => {
                    let e = process_exception(from_client(e));
                    if e.kind() == Kind::Transaction { Ok(true) } else { Err(e) }
                }
            };
            self.end_session().await;
            aborted
        }
        .await;
        match outcome {
            Ok(done) => Ok(done),
            Err(e) => {
                self.end_session().await;
                self.set_depth(0);
                Err(wrapped(format!("Failed to rollback transaction: {}", e.message()), e))
            }
        }
    }

    /// What `withTransaction()` does after a failed attempt's rollback: the
    /// session ended and the nesting reset.
    async fn abandon_transaction(&self) {
        self.end_session().await;
        self.set_depth(0);
    }

    /// `withTransaction()`: the callback alone on a standalone server, in a
    /// transaction already open, or while duplicates are ignored (an upsert
    /// with `$setOnInsert` hits write conflicts under a transaction's
    /// snapshot); otherwise a session per attempt, write conflicts retried
    /// up to 20 times.
    async fn transaction_strategy(&self) -> Result<TransactionStrategy> {
        if !self.is_replica_set().await? || self.depth() > 0 || self.state.ignoring_duplicates().get() {
            return Ok(TransactionStrategy::Direct);
        }
        Ok(TransactionStrategy::Sessions {
            conflict_code: WRITE_CONFLICT,
            conflict_retries: WRITE_CONFLICT_RETRIES,
            conflict_wait: WRITE_CONFLICT_SLEEP,
        })
    }

    /// `isRetryable()`: the base adapter's answer, and only on a replica set
    /// (a standalone server runs no transaction to retry). Before any call
    /// asked the server, it is taken to be a replica set.
    fn is_retryable(&self, failure: &Error) -> bool {
        self.base_is_retryable(failure) && self.replica_set.lock().unwrap_or_else(|e| e.into_inner()).unwrap_or(true)
    }

    /// `isTransient()`: a MongoDB error the server labels transient, a
    /// network error, one never sent, or one mapped to an aborted
    /// transaction; else a lost connection.
    fn is_transient(&self, error: &Error) -> bool {
        if is_mongo(error)
            && (error.kind() == Kind::MongoUnsent
                || is_transient_error(error)
                || process_exception(error.clone()).kind() == Kind::Transaction)
        {
            return true;
        }
        crate::connection::is_lost(error)
    }

    // ---- Databases -----------------------------------------------------

    async fn create(&self, _name: &str) -> Result<bool> {
        Ok(true)
    }

    async fn update(&self, name: &str, new: &str) -> Result<bool> {
        self.rename_database(name, new).await
    }

    async fn exists(&self, database: &str) -> Result<bool> {
        Ok(self.database_names().await?.contains(&super::filter(database)))
    }

    async fn collection_exists(&self, database: &str, collection: &str) -> Result<bool> {
        Ok(self.collection_exists_in(database, collection).await)
    }

    async fn list(&self) -> Result<Vec<Document>> {
        self.list_databases().await
    }

    async fn delete(&self, name: &str) -> Result<bool> {
        self.driver.drop_database(BsonDocument::new(), Some(&super::filter(name))).await.map_err(from_client)?;
        Ok(true)
    }

    // ---- Collections ---------------------------------------------------

    async fn create_collection(&self, collection: &str, attributes: &[Attribute], indexes: &[Index]) -> Result<bool> {
        self.create_collection_impl(collection, attributes, indexes).await
    }

    async fn delete_collection(&self, collection: &str) -> Result<bool> {
        self.driver.drop_collection(&self.name(collection), Options::new()).await.map_err(from_client)
    }

    async fn analyze_collection(&self, _collection: &str) -> Result<bool> {
        Ok(false)
    }

    async fn size_of_collection(&self, collection: &str) -> Result<i64> {
        self.size_of_collection_impl(collection).await
    }

    async fn size_of_collection_on_disk(&self, collection: &str) -> Result<i64> {
        self.size_of_collection_impl(collection).await
    }

    fn attribute_width(&self, _collection: &Collection) -> Result<i64> {
        Ok(0)
    }

    // ---- Attributes ----------------------------------------------------

    async fn create_attribute(&self, _collection: &str, _attribute: &Attribute) -> Result<bool> {
        Ok(true)
    }

    async fn create_attributes(&self, _collection: &str, _attributes: &[Attribute]) -> Result<bool> {
        Ok(true)
    }

    async fn update_attribute(&self, collection: &str, key: &str, attribute: &Attribute) -> Result<bool> {
        if attribute.key != key {
            return self.rename_attribute(collection, key, &attribute.key).await;
        }
        Ok(true)
    }

    async fn delete_attribute(&self, collection: &str, key: &str) -> Result<bool> {
        let field = Self::escape_field_name(Self::internal_key(key));
        self.driver
            .update(&self.name(collection), BsonDocument::new(), doc! { "$unset": { field: "" } }, Options::new(), true)
            .await
            .map_err(from_client)?;
        Ok(true)
    }

    async fn rename_attribute(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        let from = Self::escape_field_name(Self::internal_key(old));
        let to = Self::escape_field_name(Self::internal_key(new));
        self.driver
            .update(
                &self.name(collection),
                BsonDocument::new(),
                doc! { "$rename": { from: to } },
                self.options(BsonDocument::new()),
                true,
            )
            .await
            .map_err(from_client)?;
        Ok(true)
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

    // ---- Indexes -------------------------------------------------------

    async fn create_index(
        &self,
        collection: &str,
        index: &Index,
        attribute_types: &[(String, ColumnType)],
        collation: &[(String, String)],
    ) -> Result<bool> {
        self.create_index_impl(collection, index, attribute_types, !collation.is_empty()).await
    }

    async fn delete_index(&self, collection: &str, key: &str) -> Result<bool> {
        self.driver
            .drop_indexes(&self.name(collection), vec![super::filter(key)], Options::new())
            .await
            .map_err(from_client)?;
        Ok(true)
    }

    async fn rename_index(&self, collection: &str, old: &str, new: &str) -> Result<bool> {
        self.rename_index_impl(collection, old, new).await
    }

    // ---- Documents -----------------------------------------------------

    async fn create_document(&self, collection: &Collection, document: Document) -> Result<Document> {
        self.create_document_impl(collection, document).await
    }

    async fn create_documents(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.create_documents_impl(collection, documents).await
    }

    async fn get_document(
        &self,
        collection: &Collection,
        id: &str,
        queries: Vec<Query>,
        _for_update: bool,
    ) -> Result<Document> {
        self.get_document_impl(collection, id, &queries).await
    }

    async fn update_document(
        &self,
        collection: &Collection,
        id: &str,
        document: Document,
        _skip_permissions: bool,
    ) -> Result<Document> {
        self.update_document_impl(collection, id, document).await
    }

    async fn update_documents(
        &self,
        collection: &Collection,
        updates: &Document,
        documents: Vec<Document>,
        _skip_permissions: &[String],
    ) -> Result<i64> {
        self.update_documents_impl(collection, updates, &documents).await
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
        self.increase_document_attribute_impl(collection, id, attribute, value, updated_at, min, max).await
    }

    async fn delete_document(&self, collection: &Collection, id: &str) -> Result<bool> {
        self.delete_document_impl(collection, id).await
    }

    async fn delete_documents(
        &self,
        collection: &Collection,
        sequences: Vec<Value>,
        _permission_ids: Vec<String>,
    ) -> Result<i64> {
        self.delete_documents_impl(collection, sequences).await
    }

    async fn find(&self, collection: &Collection, queries: Vec<Query>, options: Find) -> Result<Vec<Document>> {
        self.find_impl(collection, queries, options).await
    }

    async fn count(&self, collection: &Collection, queries: Vec<Query>, max: Option<i64>) -> Result<i64> {
        self.count_impl(collection, queries, max).await
    }

    async fn sum(
        &self,
        collection: &Collection,
        attribute: &str,
        queries: Vec<Query>,
        max: Option<i64>,
    ) -> Result<Value> {
        self.sum_impl(collection, attribute, queries, max).await
    }

    async fn get_sequences(&self, collection: &Collection, documents: Vec<Document>) -> Result<Vec<Document>> {
        self.get_sequences_impl(collection, documents).await
    }
}

impl Schemaless for Mongo {
    fn set_schemaless(&self, schemaless: bool) {
        self.schemaless.store(schemaless, Ordering::Relaxed);
    }

    fn is_schemaless(&self) -> bool {
        self.schemaless.load(Ordering::Relaxed)
    }
}

impl Timeouts for Mongo {
    /// `setTimeout()`: one timeout for every read, whatever the event.
    fn set_timeout(&self, milliseconds: i64, _event: Event) -> Result<()> {
        self.timeout.store(milliseconds, Ordering::Relaxed);
        Ok(())
    }

    /// `clearTimeout()`: no timeout, whatever the event.
    fn clear_timeout(&self, _event: Event) {
        self.timeout.store(0, Ordering::Relaxed);
    }

    fn timeout(&self, _event: Event) -> i64 {
        self.read_timeout()
    }
}

#[async_trait]
impl Connection for Mongo {
    /// `ping()`: whether the server answers `ok`.
    async fn ping(&self) -> Result<bool> {
        let reply =
            self.driver.query(None, doc! { "ping": 1, "skipReadConcern": true }, None).await.map_err(from_client)?;
        Ok(match reply {
            utopia_mongo::Reply::Document(d) => d.get("ok").is_some_and(|ok| bson::decode(ok).truthy()),
            utopia_mongo::Reply::Count(_) => false,
        })
    }

    async fn reconnect(&self) -> Result<()> {
        self.driver.connect().await.map_err(from_client)
    }

    /// `id()`: the wire protocol has no connection id, so the driver's
    /// address names the connection, unique only while it lives.
    async fn id(&self) -> Result<String> {
        Ok((Arc::as_ptr(&self.driver) as *const () as usize).to_string())
    }

    async fn hostname(&self) -> Result<String> {
        Ok(self.driver.host())
    }
}

#[async_trait]
impl Relationships for Mongo {
    /// A no-op: relationships are virtual.
    async fn create_relationship(&self, _collection: &str, _relationship: &Relationship) -> Result<bool> {
        Ok(true)
    }

    async fn update_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
        update: &relationship::Update,
    ) -> Result<bool> {
        self.update_relationship_impl(collection, relationship, side, update).await
    }

    async fn delete_relationship(
        &self,
        collection: &str,
        relationship: &Relationship,
        side: RelationshipSide,
    ) -> Result<bool> {
        self.delete_relationship_impl(collection, relationship, side).await
    }
}

#[async_trait]
impl Upserts for Mongo {
    async fn upsert_document(&self, collection: &Collection, change: Change) -> Result<Document> {
        let mut documents = self.upsert_documents_impl(collection, vec![change], None).await?;
        Ok(documents.remove(0))
    }

    async fn upsert_documents(
        &self,
        collection: &Collection,
        changes: Vec<Change>,
        increase: Option<&str>,
    ) -> Result<Vec<Document>> {
        self.upsert_documents_impl(collection, changes, increase).await
    }
}

/// `$array[$key]` key of a string.
pub(crate) fn key(name: &str) -> ArrayKey {
    ArrayKey::normalize(Str::from(name))
}

/// A string value.
pub(crate) fn text(value: &str) -> Value {
    Value::Str(Str::from(value))
}

/// `$reply->cursor->firstBatch` (or `nextBatch`) as the documents it holds,
/// and the cursor's id (`0` and missing ids are `None`).
pub(crate) fn batch(reply: &BsonDocument, field: &str) -> (Vec<Value>, Option<i64>) {
    let Ok(cursor) = reply.get_document("cursor") else { return (Vec::new(), None) };
    let rows = match cursor.get(field) {
        Some(Bson::Array(items)) => items.iter().map(bson::decode).collect(),
        _ => Vec::new(),
    };
    let id = match cursor.get("id") {
        None | Some(Bson::Null) => None,
        Some(id) => match bson::decode(id) {
            Value::Int(0) => None,
            Value::Int(i) => Some(i),
            Value::Float(f) => Some(php_std::format::Arg::Float(f).to_int()).filter(|i| *i != 0),
            Value::Bool(b) => Some(i64::from(b)).filter(|i| *i != 0),
            Value::Str(s) => Some(php_std::format::Arg::Str(s.as_bytes()).to_int()).filter(|i| *i != 0),
            _ => None,
        },
    };
    (rows, id)
}
