//! [`Client`]: one MongoDB deployment, reached through the official driver.

use std::future::Future;
use std::sync::{Arc, Mutex as SyncMutex, MutexGuard};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use bson::spec::BinarySubtype;
use bson::{Binary, Bson, Document, doc};
use mongodb::ClientSession;
use mongodb::error::ErrorKind;
use mongodb::options::{
    AuthMechanism, ClientOptions, Credential, ReadConcern as DriverReadConcern, ServerAddress, SessionOptions, Tls,
    TlsOptions, TransactionOptions,
};
use tokio::sync::Mutex;

use crate::error::{Error, Result};

/// `TransientTransactionError`: the label of a transaction that may be retried.
pub const TRANSIENT_TRANSACTION_ERROR: &str = "TransientTransactionError";
/// `UnknownTransactionCommitResult`: the label of a commit that may have applied.
pub const UNKNOWN_TRANSACTION_COMMIT_RESULT: &str = "UnknownTransactionCommitResult";
/// `MaxTimeMSExpired`.
pub const TRANSACTION_TIMEOUT_ERROR: i64 = 50;
/// `NoSuchTransaction`.
pub const TRANSACTION_ABORTED_ERROR: i64 = 251;

/// Server codes of a primary that stepped down or is no longer primary.
const PRIMARY_CHANGE_ERRORS: [i64; 5] = [189, 10107, 11602, 13435, 13436];

/// Read concern levels `createReadConcern` accepts.
pub const READ_CONCERNS: [&str; 5] = ["local", "available", "majority", "linearizable", "snapshot"];

/// The command names of `Client::COMMAND_*`.
pub mod command {
    /// `COMMAND_CREATE`.
    pub const CREATE: &str = "create";
    /// `COMMAND_DELETE`.
    pub const DELETE: &str = "delete";
    /// `COMMAND_FIND`.
    pub const FIND: &str = "find";
    /// `COMMAND_FIND_AND_MODIFY`.
    pub const FIND_AND_MODIFY: &str = "findAndModify";
    /// `COMMAND_GET_LAST_ERROR`.
    pub const GET_LAST_ERROR: &str = "getLastError";
    /// `COMMAND_GET_MORE`.
    pub const GET_MORE: &str = "getMore";
    /// `COMMAND_INSERT`.
    pub const INSERT: &str = "insert";
    /// `COMMAND_RESET_ERROR`.
    pub const RESET_ERROR: &str = "resetError";
    /// `COMMAND_UPDATE`.
    pub const UPDATE: &str = "update";
    /// `COMMAND_COUNT`.
    pub const COUNT: &str = "count";
    /// `COMMAND_AGGREGATE`.
    pub const AGGREGATE: &str = "aggregate";
    /// `COMMAND_DISTINCT`.
    pub const DISTINCT: &str = "distinct";
    /// `COMMAND_MAP_REDUCE`.
    pub const MAP_REDUCE: &str = "mapReduce";
    /// `COMMAND_START_SESSION`.
    pub const START_SESSION: &str = "startSession";
    /// `COMMAND_COMMIT_TRANSACTION`.
    pub const COMMIT_TRANSACTION: &str = "commitTransaction";
    /// `COMMAND_ABORT_TRANSACTION`.
    pub const ABORT_TRANSACTION: &str = "abortTransaction";
    /// `COMMAND_END_SESSIONS`.
    pub const END_SESSIONS: &str = "endSessions";
    /// `COMMAND_LIST_INDEXES`.
    pub const LIST_INDEXES: &str = "listIndexes";
    /// `COMMAND_COLLMOD`.
    pub const COLLMOD: &str = "collMod";
    /// `COMMAND_KILL_CURSORS`.
    pub const KILL_CURSORS: &str = "killCursors";
}

/// Where a session's transaction is.
///
/// PHP: `Client::TRANSACTION_*`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TransactionState {
    /// `none`: no transaction was started on the session.
    None,
    /// `starting` (PHP never enters it; aborting it is allowed).
    Starting,
    /// `in_progress`: started, not committed or aborted.
    InProgress,
    /// `committed`.
    Committed,
    /// `aborted`.
    Aborted,
}

impl TransactionState {
    /// The state's PHP name (`none`, `in_progress`, ...).
    pub fn as_str(self) -> &'static str {
        match self {
            TransactionState::None => "none",
            TransactionState::Starting => "starting",
            TransactionState::InProgress => "in_progress",
            TransactionState::Committed => "committed",
            TransactionState::Aborted => "aborted",
        }
    }
}

/// How to reach the server and authenticate.
///
/// PHP: the parameters of `new Client(...)`.
#[derive(Debug, Clone)]
pub struct Config {
    /// The default database (`$database`).
    pub database: String,
    /// `$host`.
    pub host: String,
    /// `$port` (1 to 65535).
    pub port: i64,
    /// `$user` (SCRAM-SHA-256).
    pub user: String,
    /// `$password`.
    pub password: String,
    /// `$tls`.
    pub tls: bool,
    /// `$tlsOptions`: the certificate policy for TLS (the driver's;
    /// PHP passes Swoole's `ssl_*` options).
    pub tls_options: TlsOptions,
    /// `$authSource`: the database the user is defined in (`admin` when `None`).
    pub auth_source: Option<String>,
    /// `$timeout`: how long a command may wait for its reply, in seconds (30).
    pub timeout: f64,
    /// `$connectTimeout`: the budget for dialling and authenticating, in
    /// seconds (`timeout` when `None`).
    pub connect_timeout: Option<f64>,
    /// Where to dial, when it is not `host:port` (a forwarded port, a
    /// tunnel): `host` stays the name the client reports
    /// ([`Client::host`]), which caches keyed by host carry.
    pub address: Option<(String, u16)>,
}

impl Config {
    /// Settings for `user` on `host:port`, default database `database`,
    /// with PHP's defaults: no TLS, `admin` as auth source, 30 seconds.
    pub fn new(
        database: impl Into<String>,
        host: impl Into<String>,
        port: i64,
        user: impl Into<String>,
        password: impl Into<String>,
    ) -> Config {
        Config {
            database: database.into(),
            host: host.into(),
            port,
            user: user.into(),
            password: password.into(),
            tls: false,
            tls_options: TlsOptions::default(),
            auth_source: None,
            timeout: 30.0,
            connect_timeout: None,
            address: None,
        }
    }
}

/// What a command returned: its `n` (documents counted, inserted, matched
/// or deleted) when the reply has one, the whole reply otherwise.
///
/// PHP: `query()`'s `stdClass|int` (`$result->n` or `$result`).
#[derive(Debug, Clone, PartialEq)]
pub enum Reply {
    /// The reply's `n`.
    Count(Bson),
    /// The reply.
    Document(Document),
}

impl Reply {
    /// The reply document (`None` for a count).
    pub fn document(self) -> Option<Document> {
        match self {
            Reply::Document(d) => Some(d),
            Reply::Count(_) => None,
        }
    }

    /// The count as an integer (`None` for a document).
    pub fn count(&self) -> Option<i64> {
        match self {
            Reply::Count(n) => Some(integer(n)),
            Reply::Document(_) => None,
        }
    }
}

/// A logical session: transactions run on one. A cheap handle; the
/// client keeps its state.
///
/// PHP: the `['id' => ..., 'sessionId' => ...]` array `startSession()` returns.
#[derive(Debug, Clone, PartialEq)]
pub struct Session {
    /// `id`: the session id document (`{id: <UUID>}`), the `lsid` commands carry.
    pub id: Document,
    /// `sessionId`: the id's bytes in hex.
    pub session_id: String,
}

/// What a command carries besides its own fields: the session to run on
/// and options (`writeConcern`, `readConcern`, `readPreference`,
/// `maxTimeMS`, `limit`, `sort`, ...), merged into the command as PHP's
/// `$options` arrays are.
#[derive(Debug, Clone, Default)]
pub struct Options {
    /// `session`: run in this session (and its transaction, if one is in progress).
    pub session: Option<Session>,
    /// Every other option, by its command field name.
    pub fields: Document,
}

impl Options {
    /// No session, no options.
    pub fn new() -> Options {
        Options::default()
    }

    /// The options `fields`, outside any session.
    pub fn fields(fields: Document) -> Options {
        Options { session: None, fields }
    }

    /// In `session`.
    pub fn in_session(session: Option<Session>) -> Options {
        Options { session, fields: Document::new() }
    }
}

/// `getSessionState()`: a session's bookkeeping.
#[derive(Debug, Clone, PartialEq)]
pub struct SessionState {
    /// `sessionId`.
    pub session_id: String,
    /// `state`.
    pub state: TransactionState,
    /// `txnNumber`: transactions started on the session.
    pub txn_number: i64,
    /// `lastUse`: unix time of its last command.
    pub last_use: i64,
    /// `retryableWriteNumber`.
    pub retryable_write_number: i64,
}

/// `getConnectionInfo()`.
#[derive(Debug, Clone, PartialEq)]
pub struct ConnectionInfo {
    /// `connected`.
    pub connected: bool,
    /// `host`.
    pub host: String,
    /// `port`.
    pub port: i64,
    /// `database`.
    pub database: String,
    /// `activeSessions`.
    pub active_sessions: usize,
    /// `clusterTimeSet`.
    pub cluster_time_set: bool,
    /// `operationTimeSet`.
    pub operation_time_set: bool,
}

/// One upsert of [`Client::upsert`].
#[derive(Debug, Clone)]
pub struct Upsert {
    /// `filter`.
    pub filter: Document,
    /// `update`.
    pub update: Document,
    /// `multi`.
    pub multi: bool,
}

/// A write concern a command carries (`writeConcern`).
///
/// PHP: the array `createWriteConcern()` returns.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct WriteConcern {
    /// `w`: how many members (or `"majority"`) acknowledge the write.
    pub w: Option<Bson>,
    /// `j`: whether the write is journaled first.
    pub j: Option<bool>,
    /// `wtimeout`: milliseconds to wait for the acknowledgements.
    pub wtimeout: Option<Bson>,
}

impl WriteConcern {
    /// The concern as the command's document (set fields only, in order).
    pub fn to_document(&self) -> Document {
        let mut out = Document::new();
        if let Some(w) = &self.w {
            out.insert("w", w.clone());
        }
        if let Some(j) = self.j {
            out.insert("j", j);
        }
        if let Some(timeout) = &self.wtimeout {
            out.insert("wtimeout", timeout.clone());
        }
        out
    }
}

/// A read concern a command carries (`readConcern`).
///
/// PHP: the array `createReadConcern()` returns.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct ReadConcern {
    /// `level`: one of [`READ_CONCERNS`].
    pub level: Option<Bson>,
    /// `afterClusterTime`: read at or after this cluster time.
    pub after_cluster_time: Option<Bson>,
}

impl ReadConcern {
    /// The concern as the command's document (set fields only, in order).
    pub fn to_document(&self) -> Document {
        let mut out = Document::new();
        if let Some(level) = &self.level {
            out.insert("level", level.clone());
        }
        if let Some(after) = &self.after_cluster_time {
            out.insert("afterClusterTime", after.clone());
        }
        out
    }
}

/// A session the client tracks.
struct Tracked {
    id: Document,
    state: TransactionState,
    txn_number: i64,
    last_use: i64,
    first_operation_done: bool,
    write_concern: Option<Bson>,
    retryable_write_number: i64,
    driver: Arc<Mutex<ClientSession>>,
}

/// What belongs to the current connection.
#[derive(Default)]
struct Link {
    connected: bool,
    reconnect: bool,
    sessions: Vec<(String, Tracked)>,
    cluster_time: Option<Bson>,
    operation_time: Option<Bson>,
    replica_set: Option<bool>,
}

impl Link {
    fn session(&mut self, id: &str) -> Option<&mut Tracked> {
        self.sessions.iter_mut().find(|(k, _)| k == id).map(|(_, s)| s)
    }

    fn reset(&mut self) {
        self.connected = false;
        self.reconnect = false;
        self.sessions.clear();
        self.cluster_time = None;
        self.operation_time = None;
        self.replica_set = None;
    }
}

/// A MongoDB client. See the [crate docs](crate).
///
/// PHP: `Utopia\Mongo\Client`.
pub struct Client {
    config: Config,
    driver: SyncMutex<Option<mongodb::Client>>,
    link: SyncMutex<Link>,
}

/// Whether PHP's extension encodes `command`: a key holding a null byte is
/// an `UnexpectedValueException` naming the key up to it, a string that is
/// not UTF-8 (see [`string`]) one naming its field path from the command's
/// root and quoting its bytes. The extension stops encoding a document at a
/// null byte key but goes on after a string, and each refusal it throws
/// replaces the one before (as its previous), so the last one is reported.
fn encodable(command: &Document) -> Result<()> {
    fn walk(document: &Document, path: &mut Vec<String>, last: &mut Option<Vec<u8>>) {
        for (key, value) in document {
            if let Some((before, _)) = key.split_once('\0') {
                *last = Some(
                    format!("BSON keys cannot contain null bytes. Unexpected null byte after \"{before}\".")
                        .into_bytes(),
                );
                return;
            }
            path.push(key.clone());
            value_of(value, path, last);
            path.pop();
        }
    }
    fn value_of(value: &Bson, path: &mut Vec<String>, last: &mut Option<Vec<u8>>) {
        match value {
            Bson::Document(d) => walk(d, path, last),
            Bson::Array(items) => {
                for (i, item) in items.iter().enumerate() {
                    path.push(i.to_string());
                    value_of(item, path, last);
                    path.pop();
                }
            }
            Bson::Binary(binary) if unencoded(binary).is_some() => {
                let bytes = unencoded(binary).unwrap_or_default();
                let mut message =
                    format!("Detected invalid UTF-8 for field path \"{}\": ", path.join(".")).into_bytes();
                // The message is formatted with `%s`: the string up to its first null byte.
                message.extend_from_slice(bytes.split(|&b| b == 0).next().unwrap_or_default());
                *last = Some(message);
            }
            _ => {}
        }
    }
    let mut last = None;
    walk(command, &mut Vec::new(), &mut last);
    match last {
        None => Ok(()),
        Some(message) => Err(Error::unexpected_value(message)),
    }
}

fn now() -> i64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_secs() as i64)
}

/// An integer BSON value as `i64` (doubles truncated, others 0).
fn integer(value: &Bson) -> i64 {
    match value {
        Bson::Int32(i) => i64::from(*i),
        Bson::Int64(i) => *i,
        Bson::Double(f) => *f as i64,
        Bson::Boolean(b) => i64::from(*b),
        _ => 0,
    }
}

/// The binary subtype [`string`] gives a PHP string that is not UTF-8.
pub const STRING: BinarySubtype = BinarySubtype::UserDefined(0xfe);

/// The prefix that marks a [`STRING`] binary as one [`string`] made: random
/// per process, so a caller's own binary of the same user-defined subtype
/// is never taken for an unencodable string.
fn tag() -> &'static [u8; 16] {
    static TAG: std::sync::OnceLock<[u8; 16]> = std::sync::OnceLock::new();
    TAG.get_or_init(|| {
        let mut bytes = [0u8; 16];
        let _ = getrandom::fill(&mut bytes);
        bytes
    })
}

/// A PHP string as BSON: a string when it is UTF-8, otherwise its bytes as
/// a binary of subtype [`STRING`] behind the process's [`tag`], which BSON
/// text cannot hold. PHP's extension refuses a command holding one
/// (`UnexpectedValueException` "Detected invalid UTF-8 for field path ..."),
/// and so does every command of the client.
pub fn string(bytes: &[u8]) -> Bson {
    match std::str::from_utf8(bytes) {
        Ok(s) => Bson::String(s.to_owned()),
        Err(_) => Bson::Binary(Binary { subtype: STRING, bytes: [tag().as_slice(), bytes].concat() }),
    }
}

/// The PHP string's bytes when `binary` is one [`string`] made, `None` for
/// any other binary (a caller's own, of whatever subtype).
pub fn unencoded(binary: &Binary) -> Option<&[u8]> {
    if binary.subtype != STRING {
        return None;
    }
    binary.bytes.strip_prefix(tag().as_slice())
}

/// A PHP integer as BSON: `int32` when it fits, `int64` otherwise, as the
/// PHP extension encodes integers.
pub fn int(value: i64) -> Bson {
    i32::try_from(value).map_or(Bson::Int64(value), Bson::Int32)
}

/// Whether `value` is set, as PHP's `isset()` sees an array element.
fn set(value: Option<&Bson>) -> bool {
    !matches!(value, None | Some(Bson::Null))
}

/// A list's items, as documents (PHP's `toObject()` of each array item:
/// a list becomes a document keyed `0`, `1`, ...).
fn object(value: Bson) -> Bson {
    match value {
        Bson::Array(items) => Bson::Document(items.into_iter().enumerate().map(|(i, v)| (i.to_string(), v)).collect()),
        other => other,
    }
}

/// `cleanFilters()`: the items of `$and`, `$or` and `$nor` as documents.
fn clean_filters(filters: Document) -> Document {
    filters
        .into_iter()
        .map(|(k, v)| match (k.as_str(), v) {
            ("$and" | "$or" | "$nor", Bson::Array(items)) => (k, Bson::Array(items.into_iter().map(object).collect())),
            (_, v) => (k, v),
        })
        .collect()
}

/// `array_merge($command, $options)`: `options` overwrite the command's
/// fields of the same name, in place, and the others are appended.
fn merge(command: &mut Document, options: Document) {
    for (k, v) in options {
        command.insert(k, v);
    }
}

/// The options without `skip` (`array_diff_key`).
fn without(mut fields: Document, skip: &[&str]) -> Document {
    for key in skip {
        fields.remove(*key);
    }
    fields
}

/// The PHP class name of `value`'s type, for engine errors.
fn type_name(value: &Bson) -> &'static str {
    match value {
        Bson::Null => "null",
        Bson::Boolean(_) => "bool",
        Bson::Double(_) => "float",
        Bson::Int32(_) | Bson::Int64(_) => "int",
        Bson::String(_) => "string",
        Bson::Array(_) | Bson::Document(_) => "array",
        _ => "object",
    }
}

/// How PHP prints a scalar into an error message (`'...' . $level`).
fn text(value: &Bson) -> String {
    match value {
        Bson::String(s) => s.clone(),
        Bson::Int32(i) => i.to_string(),
        Bson::Int64(i) => i.to_string(),
        Bson::Double(f) => php_std::number::to_string(*f),
        Bson::Boolean(true) => "1".to_owned(),
        Bson::Array(_) | Bson::Document(_) => "Array".to_owned(),
        _ => String::new(),
    }
}

impl Client {
    /// A client for `config`, not connected yet. Fails on an empty
    /// database, host, user or password, a port outside 1 to 65535 or a
    /// timeout that is not positive.
    ///
    /// PHP: `new Client($database, $host, $port, $user, $password, $useCoroutine, $tls, $tlsOptions, $authSource, $timeout, $connectTimeout)`.
    pub fn new(config: Config) -> Result<Client> {
        let empty = |s: &str| s.is_empty() || s == "0";
        if empty(&config.database) {
            return Err(Error::InvalidArgument("Database name cannot be empty".into()));
        }
        if empty(&config.host) {
            return Err(Error::InvalidArgument("Host cannot be empty".into()));
        }
        if config.port <= 0 || config.port > 65535 {
            return Err(Error::InvalidArgument("Port must be between 1 and 65535".into()));
        }
        if empty(&config.user) {
            return Err(Error::InvalidArgument("Username cannot be empty".into()));
        }
        if empty(&config.password) {
            return Err(Error::InvalidArgument("Password cannot be empty".into()));
        }
        if config.timeout.is_nan() || config.timeout <= 0.0 {
            return Err(Error::InvalidArgument("Timeout must be greater than 0".into()));
        }
        if config.connect_timeout.is_some_and(|t| t.is_nan() || t <= 0.0) {
            return Err(Error::InvalidArgument("Connect timeout must be greater than 0".into()));
        }
        Ok(Client { config, driver: SyncMutex::new(None), link: SyncMutex::new(Link::default()) })
    }

    fn link(&self) -> MutexGuard<'_, Link> {
        self.link.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn driver(&self) -> Option<mongodb::Client> {
        self.driver.lock().unwrap_or_else(|e| e.into_inner()).clone()
    }

    /// Connects and authenticates, within the connect budget; a no-op when
    /// connected. Fails with [`Error::Unsent`] when the server cannot be
    /// reached, and with the server's error when authentication fails.
    ///
    /// PHP: `connect()`.
    pub async fn connect(&self) -> Result<&Self> {
        if self.link().connected {
            return Ok(self);
        }
        let budget = Duration::from_secs_f64(self.config.connect_timeout.unwrap_or(self.config.timeout));
        let port = u16::try_from(self.config.port)
            .map_err(|_| Error::exception("MongoDB port must be between 1 and 65535", 0))?;
        let mut options = ClientOptions::default();
        let (dial_host, dial_port) = self.config.address.clone().unwrap_or_else(|| (self.config.host.clone(), port));
        options.hosts = vec![ServerAddress::Tcp { host: dial_host, port: Some(dial_port) }];
        options.direct_connection = Some(true);
        options.connect_timeout = Some(budget);
        options.server_selection_timeout = Some(budget);
        options.retry_reads = Some(false);
        options.retry_writes = Some(false);
        let mut credential = Credential::default();
        credential.username = Some(self.config.user.clone());
        credential.password = Some(self.config.password.clone());
        credential.source = Some(self.config.auth_source.clone().unwrap_or_else(|| "admin".to_owned()));
        credential.mechanism = Some(AuthMechanism::ScramSha256);
        options.credential = Some(credential);
        if self.config.tls {
            options.tls = Some(Tls::Enabled(self.config.tls_options.clone()));
        }
        let unreachable = || Error::unsent(format!("Failed to connect to MongoDB at {}:{}", self.config.host, port));
        let driver = mongodb::Client::with_options(options).map_err(|_| unreachable())?;
        // The handshake: server selection dials and authenticates; the
        // client then reads at the cluster time its reply carries, as PHP's
        // does after the replies to its SCRAM commands. Server selection
        // gives up within the budget (unsent); the reply gets one more
        // budget, so a refused dial never races the receive timeout.
        let hello = tokio::time::timeout(budget * 2, driver.database("admin").run_command(doc! { "ping": 1 })).await;
        match hello {
            Ok(Ok(reply)) => self.update_causal_consistency(&reply),
            Ok(Err(e)) => {
                self.invalidate(false);
                return Err(match *e.kind {
                    ErrorKind::Authentication { .. } => {
                        Error::exception("E18 AuthenticationFailed: Authentication failed.", 18)
                    }
                    ErrorKind::Command(ref c) => {
                        Error::exception(format!("E{} {}: {}", c.code, c.code_name, c.message), i64::from(c.code))
                    }
                    _ => unreachable(),
                });
            }
            Err(_) => {
                self.invalidate(false);
                return Err(Error::exception("Receive timeout: no data received within reasonable time", 11601));
            }
        }
        *self.driver.lock().unwrap_or_else(|e| e.into_inner()) = Some(driver);
        self.link().connected = true;
        Ok(self)
    }

    /// The host the client connects to.
    ///
    /// PHP: `getHost()`.
    pub fn host(&self) -> &str {
        &self.config.host
    }

    /// A new UUIDv7, the `_id` of documents inserted without one.
    ///
    /// PHP: `createUuid()`.
    pub fn create_uuid(&self) -> String {
        crate::uuid::uuid7()
    }

    /// The client itself: MongoDB creates databases on the fly.
    ///
    /// PHP: `selectDatabase()`.
    pub fn select_database(&self) -> &Self {
        self
    }

    /// The client itself: MongoDB creates databases on the fly.
    ///
    /// PHP: `createDatabase()`.
    pub fn create_database(&self) -> &Self {
        self
    }

    /// The client itself: MongoDB creates collections on the fly.
    ///
    /// PHP: `selectCollection($name)`.
    pub fn select_collection(&self, _name: &str) -> &Self {
        self
    }

    /// Runs `command` on database `db` (the default database when `None`).
    ///
    /// PHP: `query($command, $db)` without a `session` in `$command`.
    pub async fn query(&self, command: Document, db: Option<&str>) -> Result<Reply> {
        self.query_in(None, command, db).await
    }

    /// Runs `command` on database `db` (the default database when `None`)
    /// in `session` (and in its transaction, if one is in progress): the
    /// reply's `n` when it has one, the reply otherwise. Outside a
    /// transaction, a command reads at or after the cluster time of the
    /// client's last reply (causal consistency), unless it carries
    /// `skipReadConcern`.
    ///
    /// PHP: `query($command, $db)`, `$command['session']` being `session`.
    pub async fn query_in(&self, session: Option<&Session>, mut command: Document, db: Option<&str>) -> Result<Reply> {
        self.validate_connection().await?;
        let driver = self.driver().ok_or_else(|| Error::unsent("Client is not connected to MongoDB"))?;
        let skip_read_concern = command.remove("skipReadConcern").is_some();
        let mut handle = None;
        {
            let mut link = self.link();
            let operation_time = link.operation_time.clone();
            let tracked = session.and_then(|s| link.session(&s.session_id));
            match tracked {
                Some(tracked) if tracked.state == TransactionState::InProgress => {
                    // The driver numbers the transaction's commands, starts
                    // it with the first and sends the transaction's read
                    // concern with it. The transaction's write concern goes
                    // on the first command as well, as PHP sends it (and the
                    // server refuses it there).
                    if !tracked.first_operation_done
                        && !command.contains_key("writeConcern")
                        && let Some(concern) = &tracked.write_concern
                    {
                        command.insert("writeConcern", concern.clone());
                    }
                    tracked.first_operation_done = true;
                    command.remove("readConcern");
                    handle = Some(tracked.driver.clone());
                }
                tracked => {
                    if let Some(tracked) = tracked {
                        handle = Some(tracked.driver.clone());
                    }
                    let causal = session.is_some() || !skip_read_concern;
                    let after = matches!(command.get("readConcern"), Some(Bson::Document(rc)) if rc.contains_key("afterClusterTime"));
                    if causal
                        && !after
                        && let Some(time) = operation_time
                    {
                        let mut concern = match command.get("readConcern") {
                            Some(Bson::Document(rc)) => rc.clone(),
                            _ => Document::new(),
                        };
                        concern.insert("afterClusterTime", time);
                        command.insert("readConcern", concern);
                    }
                }
            }
        }
        if matches!(command.keys().next().map(String::as_str), Some(command::GET_MORE | command::KILL_CURSORS)) {
            command.remove("readConcern");
        }
        encodable(&command)?;
        let database = driver.database(db.unwrap_or(&self.config.database));
        let timeout = Duration::from_secs_f64(self.config.timeout);
        let outcome = match &handle {
            Some(handle) => {
                let mut guard = handle.lock().await;
                let run = database.run_command(command).session(&mut *guard);
                tokio::time::timeout(timeout, run).await
            }
            None => tokio::time::timeout(timeout, database.run_command(command)).await,
        };
        let reply = match outcome {
            Ok(Ok(reply)) => reply,
            Ok(Err(e)) => return Err(self.driver_error(e)),
            Err(_) => {
                self.invalidate(false);
                return Err(Error::exception("Receive timeout: no data received within reasonable time", 11601));
            }
        };
        self.update_causal_consistency(&reply);
        if let Some(session) = session
            && let Some(tracked) = self.link().session(&session.session_id)
        {
            tracked.last_use = now();
        }
        self.parse_response(reply)
    }

    /// A driver error as PHP reports the same failure.
    fn driver_error(&self, e: mongodb::error::Error) -> Error {
        match *e.kind {
            ErrorKind::Command(ref c) => {
                let code = i64::from(c.code);
                if PRIMARY_CHANGE_ERRORS.contains(&code) {
                    self.invalidate(true);
                }
                Error::exception(format!("E{} {}: {}", c.code, c.code_name, c.message), code)
            }
            ErrorKind::ServerSelection { .. } => {
                self.invalidate(false);
                Error::unsent("Connection to MongoDB has been lost")
            }
            ErrorKind::Io(_) | ErrorKind::ConnectionPoolCleared { .. } => {
                self.invalidate(false);
                Error::exception("Receive timeout: no data received within reasonable time", 11601)
            }
            ErrorKind::InvalidArgument { ref message, .. } => Error::exception(message.clone(), 0),
            ErrorKind::Transaction { ref message, .. } => Error::exception(message.clone(), 0),
            ref other => Error::exception(format!("{other}"), 0),
        }
    }

    /// PHP: `parseResponse()` after the reply was decoded.
    fn parse_response(&self, reply: Document) -> Result<Reply> {
        if let Some(Bson::Array(errors)) = reply.get("writeErrors")
            && !errors.is_empty()
        {
            if let Some(Bson::Document(first)) = errors.first()
                && let (Some(message), Some(code)) = (first.get("errmsg"), first.get("code"))
                && set(Some(message))
                && set(Some(code))
            {
                let code = integer(code);
                if PRIMARY_CHANGE_ERRORS.contains(&code) {
                    self.invalidate(true);
                }
                return Err(Error::exception(text(message), code));
            }
            self.invalidate(false);
            return Err(Error::exception("Invalid write error response", 0));
        }
        if let Some(message) = reply.get("errmsg") {
            let code = reply.get("code").map_or(0, integer);
            let name = match reply.get("codeName") {
                Some(Bson::Null) | None => "MongoError".to_owned(),
                Some(n) => text(n),
            };
            if PRIMARY_CHANGE_ERRORS.contains(&code) {
                self.invalidate(true);
            }
            return Err(Error::exception(format!("E{code} {name}: {}", text(message)), code));
        }
        match reply.get("ok") {
            None => {
                self.invalidate(false);
                Err(Error::exception("Invalid response: missing operation status", 0))
            }
            Some(Bson::Double(ok)) if *ok == 1.0 => Ok(match reply.get("n") {
                Some(n) => Reply::Count(n.clone()),
                None => Reply::Document(reply),
            }),
            Some(_) => {
                self.invalidate(false);
                Err(Error::exception("Invalid unsuccessful response", 0))
            }
        }
    }

    /// Drops the connection's state: after a failed exchange, the client
    /// must reconnect before its next command. A primary change keeps the
    /// sessions and reconnects on its own.
    ///
    /// PHP: `invalidate($preserveSessions)`.
    fn invalidate(&self, preserve_sessions: bool) {
        let mut link = self.link();
        let sessions = std::mem::take(&mut link.sessions);
        link.reset();
        if preserve_sessions {
            link.sessions = sessions;
            link.reconnect = true;
        }
    }

    /// PHP: `validateConnection()`.
    async fn validate_connection(&self) -> Result<()> {
        let reconnect = {
            let mut link = self.link();
            std::mem::take(&mut link.reconnect)
        };
        if reconnect {
            let sessions = std::mem::take(&mut self.link().sessions);
            let result = self.connect().await;
            let mut link = self.link();
            link.sessions = sessions;
            if !link.connected {
                link.reconnect = true;
            }
            result?;
        }
        if !self.link().connected {
            return Err(Error::unsent("Client is not connected to MongoDB"));
        }
        Ok(())
    }

    /// PHP: `updateCausalConsistency($result)`.
    fn update_causal_consistency(&self, reply: &Document) {
        let mut link = self.link();
        if let Some(time) = reply.get("operationTime") {
            link.operation_time = Some(time.clone());
        }
        if let Some(time) = reply.get("$clusterTime") {
            link.cluster_time = Some(time.clone());
        }
    }

    /// A command's count, or PHP's `TypeError` for a reply without one.
    fn count_of(reply: Reply, method: &str) -> Result<i64> {
        match reply {
            Reply::Count(n) => Ok(integer(&n)),
            Reply::Document(_) => Err(Error::Engine {
                class: "TypeError",
                message: format!(
                    "Utopia\\Mongo\\Client::{method}(): Return value must be of type int, stdClass returned"
                ),
            }),
        }
    }

    /// A command's reply, or PHP's `TypeError` for a count.
    fn document_of(reply: Reply, method: &str) -> Result<Document> {
        match reply {
            Reply::Document(d) => Ok(d),
            Reply::Count(_) => Err(Error::Engine {
                class: "TypeError",
                message: format!(
                    "Utopia\\Mongo\\Client::{method}(): Return value must be of type stdClass, int returned"
                ),
            }),
        }
    }

    /// `$reply->ok === 1.0`.
    fn ok(reply: &Reply) -> bool {
        matches!(reply, Reply::Document(d) if matches!(d.get("ok"), Some(Bson::Double(ok)) if *ok == 1.0))
    }

    /// The names of the databases (`listDatabases` with `nameOnly`).
    ///
    /// PHP: `listDatabaseNames()`.
    pub async fn list_database_names(&self) -> Result<Document> {
        let reply = self.query(doc! { "listDatabases": 1, "nameOnly": true }, Some("admin")).await?;
        Self::document_of(reply, "listDatabaseNames")
    }

    /// Drops database `db` (the default database when `None`).
    ///
    /// PHP: `dropDatabase($options, $db)`.
    pub async fn drop_database(&self, options: Document, db: Option<&str>) -> Result<bool> {
        let mut command = doc! { "dropDatabase": 1 };
        merge(&mut command, options);
        let reply = self.query(command, Some(db.unwrap_or(&self.config.database))).await?;
        Ok(Self::ok(&reply))
    }

    /// Creates collection `name`; fails with code 48 when it exists.
    ///
    /// PHP: `createCollection($name, $options)`.
    pub async fn create_collection(&self, name: &str, options: Options) -> Result<bool> {
        let list = self.list_collection_names(doc! { "name": name }, options.clone()).await?;
        let exists = matches!(
            list.get_document("cursor").ok().and_then(|c| c.get_array("firstBatch").ok()),
            Some(batch) if !batch.is_empty()
        );
        if exists {
            return Err(Error::exception("Collection Exists", 48));
        }
        let mut command = doc! { "create": name };
        merge(&mut command, options.fields);
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Ok(Self::ok(&reply))
    }

    /// Drops collection `name`.
    ///
    /// PHP: `dropCollection($name, $options)`.
    pub async fn drop_collection(&self, name: &str, options: Options) -> Result<bool> {
        let mut command = doc! { "drop": name };
        merge(&mut command, options.fields);
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Ok(Self::ok(&reply))
    }

    /// The collections matching `filter` (names only, authorized ones).
    ///
    /// PHP: `listCollectionNames($filter, $options)`.
    pub async fn list_collection_names(&self, filter: Document, options: Options) -> Result<Document> {
        let mut command = doc! {
            "listCollections": 1.0,
            "nameOnly": true,
            "authorizedCollections": true,
            "filter": filter,
        };
        merge(&mut command, options.fields);
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::document_of(reply, "listCollectionNames")
    }

    /// Creates `indexes` on `collection`. A unique index without a
    /// `partialFilterExpression` is made sparse.
    ///
    /// PHP: `createIndexes($collection, $indexes, $options)`.
    pub async fn create_indexes(&self, collection: &str, indexes: Vec<Document>, options: Options) -> Result<bool> {
        let indexes: Vec<Bson> = indexes
            .into_iter()
            .map(|mut index| {
                let unique = index.get("unique").is_some_and(truthy);
                if unique && !index.contains_key("partialFilterExpression") {
                    index.insert("sparse", true);
                }
                Bson::Document(index)
            })
            .collect();
        let mut command = doc! { "createIndexes": collection, "indexes": indexes };
        merge(&mut command, options.fields);
        self.query_in(options.session.as_ref(), command, None).await?;
        Ok(true)
    }

    /// Drops `indexes` (names, or `"*"`) of `collection`.
    ///
    /// PHP: `dropIndexes($collection, $indexes, $options)`.
    pub async fn drop_indexes(&self, collection: &str, indexes: Bson, options: Options) -> Result<&Self> {
        let mut command = doc! { "dropIndexes": collection, "index": indexes };
        merge(&mut command, options.fields);
        self.query_in(options.session.as_ref(), command, None).await?;
        Ok(self)
    }

    /// The fields a write's command carries from `options`: the session's,
    /// the write concern, the read concern unless the transaction is past
    /// its first command, and every other option but `skip`.
    fn concerns(&self, command: &mut Document, options: &Options, write: bool, skip: &[&str]) -> Result<()> {
        if write && let Some(concern) = options.fields.get("writeConcern").filter(|v| set(Some(v))) {
            command.insert("writeConcern", self.create_write_concern(concern)?.to_document());
        }
        if let Some(concern) = options.fields.get("readConcern").filter(|v| set(Some(v)))
            && !self.should_skip_read_concern(options)
        {
            command.insert("readConcern", self.create_read_concern(concern)?.to_document());
        }
        if !write && let Some(preference) = options.fields.get("readPreference").filter(|v| set(Some(v))) {
            command.insert("$readPreference", preference.clone());
        }
        let mut drop = vec!["session", "writeConcern", "readConcern"];
        if !write {
            drop.push("readPreference");
        }
        drop.extend_from_slice(skip);
        merge(command, without(options.fields.clone(), &drop));
        Ok(())
    }

    /// The document with an `_id` (a new UUIDv7 when it has none, or a
    /// null or empty one).
    fn with_id(&self, mut document: Document) -> Document {
        let missing = match document.get("_id") {
            None | Some(Bson::Null) => true,
            Some(Bson::String(s)) => s.is_empty(),
            Some(_) => false,
        };
        if missing {
            document.insert("_id", self.create_uuid());
        }
        document
    }

    /// Inserts `document` into `collection` (with a UUIDv7 `_id` when it
    /// has none) and returns it as inserted.
    ///
    /// PHP: `insert($collection, $document, $options)`.
    pub async fn insert(&self, collection: &str, document: Document, options: Options) -> Result<Document> {
        let document = self.with_id(document);
        let mut command = doc! { command::INSERT: collection, "documents": [document.clone()] };
        self.concerns(&mut command, &options, true, &[])?;
        self.query_in(options.session.as_ref(), command, None).await?;
        Ok(document)
    }

    /// Inserts `documents` into `collection`, in batches of `batchSize`
    /// (option, 1000), in order unless `ordered` is false, and returns them
    /// as inserted.
    ///
    /// PHP: `insertMany($collection, $documents, $options)`.
    pub async fn insert_many(
        &self,
        collection: &str,
        documents: Vec<Document>,
        options: Options,
    ) -> Result<Vec<Document>> {
        if documents.is_empty() {
            return Ok(Vec::new());
        }
        let size = options.fields.get("batchSize").map_or(1000, integer).max(1) as usize;
        let ordered = options.fields.get("ordered").filter(|v| set(Some(v))).cloned().unwrap_or(Bson::Boolean(true));
        let mut inserted = Vec::with_capacity(documents.len());
        let mut documents = documents.into_iter().peekable();
        while documents.peek().is_some() {
            let batch: Vec<Document> = documents.by_ref().take(size).map(|d| self.with_id(d)).collect();
            let mut command = doc! {
                command::INSERT: collection,
                "documents": batch.iter().cloned().map(Bson::Document).collect::<Vec<_>>(),
                "ordered": ordered.clone(),
            };
            self.concerns(&mut command, &options, true, &["batchSize"])?;
            self.query_in(options.session.as_ref(), command, None).await?;
            inserted.extend(batch);
        }
        Ok(inserted)
    }

    /// The document of `collection` with the greatest `_id`.
    ///
    /// PHP: `lastDocument($collection)`.
    pub async fn last_document(&self, collection: &str) -> Result<Document> {
        let reply =
            self.find(collection, Document::new(), Options::fields(doc! { "sort": { "_id": -1 }, "limit": 1 })).await?;
        let first = reply
            .get_document("cursor")
            .ok()
            .and_then(|c| c.get_array("firstBatch").ok())
            .and_then(|b| b.first().cloned());
        match first {
            Some(Bson::Document(d)) => Ok(d),
            Some(other) => {
                let mut d = Document::new();
                d.insert("0", other);
                Ok(d)
            }
            None => Err(Error::Engine {
                class: "TypeError",
                message: "Utopia\\Mongo\\Client::lastDocument(): Return value must be of type array, null returned"
                    .into(),
            }),
        }
    }

    /// Updates the document matching `filter` (every one when `multi`) with
    /// `updates`; option `upsert` inserts when none matches. Returns the
    /// number of documents matched.
    ///
    /// PHP: `update($collection, $where, $updates, $options, $multi)`.
    pub async fn update(
        &self,
        collection: &str,
        filter: Document,
        updates: Document,
        options: Options,
        multi: bool,
    ) -> Result<i64> {
        let upsert = options.fields.get("upsert").filter(|v| set(Some(v))).cloned().unwrap_or(Bson::Boolean(false));
        let mut command = doc! {
            command::UPDATE: collection,
            "updates": [{ "q": filter, "u": updates, "multi": multi, "upsert": upsert }],
        };
        self.concerns(&mut command, &options, true, &["upsert"])?;
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::count_of(reply, "update")
    }

    /// Upserts: each operation updates the document its filter matches, or
    /// inserts one. `options` are merged into the command as given.
    ///
    /// PHP: `upsert($collection, $operations, $options)`.
    pub async fn upsert(&self, collection: &str, operations: Vec<Upsert>, options: Options) -> Result<i64> {
        let updates: Vec<Bson> = operations
            .into_iter()
            .map(|op| Bson::Document(doc! { "q": op.filter, "u": op.update, "upsert": true, "multi": op.multi }))
            .collect();
        let mut command = doc! { command::UPDATE: collection, "updates": updates };
        merge(&mut command, options.fields);
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::count_of(reply, "upsert")
    }

    /// Finds the documents of `collection` matching `filter`. Options:
    /// `limit`, `skip`, `sort`, `projection`, `hint`, `batchSize`,
    /// `maxTimeMS`, `readConcern`, `readPreference`, ...
    ///
    /// PHP: `find($collection, $filters, $options)`.
    pub async fn find(&self, collection: &str, filter: Document, options: Options) -> Result<Document> {
        let mut command = doc! { command::FIND: collection, "filter": clean_filters(filter) };
        self.concerns(&mut command, &options, false, &[])?;
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::document_of(reply, "find")
    }

    /// Runs the aggregation `pipeline` on `collection`.
    ///
    /// PHP: `aggregate($collection, $pipeline, $options)`.
    pub async fn aggregate(&self, collection: &str, pipeline: Vec<Bson>, options: Options) -> Result<Document> {
        let mut command = doc! { command::AGGREGATE: collection, "pipeline": pipeline, "cursor": {} };
        self.concerns(&mut command, &options, false, &[])?;
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::document_of(reply, "aggregate")
    }

    /// Updates (or, with `remove`, deletes) one document matching `filter`
    /// and returns it.
    ///
    /// PHP: `findAndModify($collection, $update, $remove, $filters, $options)`.
    pub async fn find_and_modify(
        &self,
        collection: &str,
        update: Bson,
        remove: bool,
        filter: Document,
        options: Options,
    ) -> Result<Document> {
        let mut command =
            doc! { command::FIND_AND_MODIFY: collection, "filter": filter, "remove": remove, "update": update };
        merge(&mut command, options.fields);
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::document_of(reply, "findAndModify")
    }

    /// The next batch of cursor `cursor_id` on `collection`.
    ///
    /// PHP: `getMore($cursorId, $collection, $batchSize)`.
    pub async fn get_more(&self, cursor_id: i64, collection: &str, batch_size: i64) -> Result<Document> {
        let command =
            doc! { command::GET_MORE: int(cursor_id), "collection": collection, "batchSize": int(batch_size) };
        let reply = self.query(command, None).await?;
        Self::document_of(reply, "getMore")
    }

    /// Deletes up to `limit` documents matching `filter` (`0`: every one);
    /// `delete_options` go into the delete statement (`collation`, `hint`).
    /// Returns the number deleted.
    ///
    /// PHP: `delete($collection, $filters, $limit, $deleteOptions, $options)`.
    pub async fn delete(
        &self,
        collection: &str,
        filter: Document,
        limit: i64,
        delete_options: Document,
        options: Options,
    ) -> Result<i64> {
        let mut statement = doc! { "q": filter, "limit": int(limit) };
        merge(&mut statement, delete_options);
        let mut command = doc! { command::DELETE: collection, "deletes": [statement] };
        self.concerns(&mut command, &options, true, &[])?;
        let reply = self.query_in(options.session.as_ref(), command, None).await?;
        Self::count_of(reply, "delete")
    }

    /// The number of documents of `collection` matching `filter` (options
    /// `limit`, `skip`, `maxTimeMS`, ...), `0` when the command fails.
    ///
    /// PHP: `count($collection, $filters, $options)`.
    pub async fn count(&self, collection: &str, filter: Document, options: Options) -> Result<i64> {
        let mut command = doc! { command::COUNT: collection, "query": clean_filters(filter) };
        if let Some(concern) = options.fields.get("readConcern").filter(|v| set(Some(v)))
            && !self.should_skip_read_concern(&options)
        {
            command.insert("readConcern", self.create_read_concern(concern)?.to_document());
        }
        if let Some(preference) = options.fields.get("readPreference").filter(|v| set(Some(v))) {
            command.insert("$readPreference", preference.clone());
        }
        for key in ["limit", "skip", "maxTimeMS"] {
            if let Some(value) = options.fields.get(key).filter(|v| set(Some(v))) {
                command.insert(key, int(integer(value)));
            }
        }
        merge(
            &mut command,
            without(
                options.fields.clone(),
                &["session", "readConcern", "readPreference", "limit", "skip", "maxTimeMS"],
            ),
        );
        match self.query_in(options.session.as_ref(), command, None).await {
            Ok(Reply::Count(n)) => Ok(integer(&n)),
            Ok(Reply::Document(_)) => Ok(1),
            Err(Error::Exception(_) | Error::Unsent(_)) => Ok(0),
            Err(e) => Err(e),
        }
    }

    /// Starts a logical session: `causalConsistency` (option, `true`).
    ///
    /// PHP: `startSession($options)`.
    pub async fn start_session(&self, options: Document) -> Result<Session> {
        self.validate_connection().await?;
        let driver = self.driver().ok_or_else(|| Error::unsent("Client is not connected to MongoDB"))?;
        let causal = options.get("causalConsistency").filter(|v| set(Some(v))).is_none_or(truthy);
        let mut session_options = SessionOptions::default();
        session_options.causal_consistency = Some(causal);
        let session = driver.start_session().with_options(session_options).await.map_err(|e| self.driver_error(e))?;
        let id = session.id().clone();
        let session_id = match id.get("id") {
            Some(Bson::Binary(b)) => hex::encode(&b.bytes),
            Some(other) => text(other),
            None => String::new(),
        };
        self.link().sessions.push((
            session_id.clone(),
            Tracked {
                id: id.clone(),
                state: TransactionState::None,
                txn_number: 0,
                last_use: now(),
                first_operation_done: false,
                write_concern: None,
                retryable_write_number: 0,
                driver: Arc::new(Mutex::new(session)),
            },
        ));
        Ok(Session { id, session_id })
    }

    /// The tracked session `session` names.
    fn tracked(&self, session: &Session, method: &str) -> Result<Arc<Mutex<ClientSession>>> {
        self.link()
            .session(&session.session_id)
            .map(|t| t.driver.clone())
            .ok_or_else(|| Error::exception(format!("Invalid session provided to {method}"), 0))
    }

    /// Starts a transaction on `session` (`readConcern`, `writeConcern`,
    /// `maxCommitTimeMS` options); it begins with the session's next command.
    ///
    /// PHP: `startTransaction($session, $options)`.
    pub async fn start_transaction(&self, session: &Session, options: Document) -> Result<bool> {
        let handle = self.tracked(session, "startTransaction")?;
        {
            let mut link = self.link();
            let Some(tracked) = link.session(&session.session_id) else {
                return Err(Error::exception("Invalid session provided to startTransaction", 0));
            };
            if tracked.state == TransactionState::InProgress {
                return Err(Error::exception("Session already has a transaction in progress", 0));
            }
            tracked.txn_number += 1;
            tracked.state = TransactionState::InProgress;
            tracked.last_use = now();
            tracked.first_operation_done = false;
            // Sent with the transaction's first command, as PHP sends it;
            // `readPreference` and `maxCommitTimeMS` are kept by PHP and
            // never sent.
            tracked.write_concern = options.get("writeConcern").filter(|v| set(Some(v))).cloned();
        }
        // The read concern goes with the first command (the driver adds it).
        let mut transaction = TransactionOptions::default();
        if let Some(Bson::Document(rc)) = options.get("readConcern")
            && let Some(Bson::String(level)) = rc.get("level")
        {
            transaction.read_concern = Some(DriverReadConcern::custom(level.clone()));
        } else if let Some(Bson::String(level)) = options.get("readConcern") {
            transaction.read_concern = Some(DriverReadConcern::custom(level.clone()));
        }
        let mut driver = handle.lock().await;
        if driver.start_transaction().with_options(transaction.clone()).await.is_err() {
            // The driver still holds an earlier transaction this client
            // gave up on (a failed commit kept in progress): drop it.
            let _ = driver.abort_transaction().await;
            driver.start_transaction().with_options(transaction).await.map_err(|e| self.driver_error(e))?;
        }
        Ok(true)
    }

    /// The reply the server sends a session command: `ok` and the session's
    /// cluster and operation times, which the client then reads at.
    fn session_reply(&self, session: &ClientSession) -> Document {
        let mut reply = doc! { "ok": 1.0 };
        if let Some(time) = session.cluster_time()
            && let Ok(time) = bson::serialize_to_bson(time)
        {
            reply.insert("$clusterTime", time);
        }
        if let Some(time) = session.operation_time() {
            reply.insert("operationTime", Bson::Timestamp(time));
        }
        self.update_causal_consistency(&reply);
        reply
    }

    /// Commits the transaction of `session`. A transient failure or an
    /// unknown commit result leaves it in progress, to retry; any other
    /// marks it aborted.
    ///
    /// PHP: `commitTransaction($session, $options)`.
    pub async fn commit_transaction(&self, session: &Session, _options: Document) -> Result<Document> {
        let handle = self.tracked(session, "commitTransaction")?;
        {
            let mut link = self.link();
            let tracked = link.session(&session.session_id);
            if tracked.is_none_or(|t| t.state != TransactionState::InProgress) {
                return Err(Error::exception("No active transaction to commit", 0));
            }
        }
        self.validate_connection().await?;
        let mut driver = handle.lock().await;
        let outcome = driver.commit_transaction().await;
        let reply = self.session_reply(&driver);
        drop(driver);
        match outcome {
            Ok(()) => {
                if let Some(tracked) = self.link().session(&session.session_id) {
                    tracked.state = TransactionState::Committed;
                    tracked.last_use = now();
                    tracked.first_operation_done = false;
                }
                Ok(reply)
            }
            Err(e) => {
                let error = self.driver_error(e);
                if !(self.is_transient_transaction_error(&error) || self.is_unknown_transaction_commit_result(&error))
                    && let Some(tracked) = self.link().session(&session.session_id)
                {
                    tracked.state = TransactionState::Aborted;
                }
                Err(error)
            }
        }
    }

    /// Aborts (rolls back) the transaction of `session`.
    ///
    /// PHP: `abortTransaction($session, $options)`.
    pub async fn abort_transaction(&self, session: &Session, _options: Document) -> Result<Document> {
        let handle = self.tracked(session, "abortTransaction")?;
        {
            let mut link = self.link();
            let tracked = link.session(&session.session_id);
            if tracked.is_none_or(|t| !matches!(t.state, TransactionState::InProgress | TransactionState::Starting)) {
                return Err(Error::exception("No active transaction to abort", 0));
            }
        }
        self.validate_connection().await?;
        let mut driver = handle.lock().await;
        let outcome = driver.abort_transaction().await;
        let reply = self.session_reply(&driver);
        drop(driver);
        if let Some(tracked) = self.link().session(&session.session_id) {
            tracked.state = TransactionState::Aborted;
            tracked.first_operation_done = false;
            if outcome.is_ok() {
                tracked.last_use = now();
            }
        }
        outcome.map(|()| reply).map_err(|e| self.driver_error(e))
    }

    /// Ends `sessions` (aborting their transactions in progress) and forgets
    /// them. An empty reply when there are none.
    ///
    /// PHP: `endSessions($sessions, $options)`.
    pub async fn end_sessions(&self, sessions: &[Session], options: Document) -> Result<Document> {
        let mut ids = Vec::new();
        for session in sessions {
            let tracked = {
                let mut link = self.link();
                let at = link.sessions.iter().position(|(k, _)| *k == session.session_id);
                at.map(|i| link.sessions.remove(i).1)
            };
            match tracked {
                Some(tracked) => {
                    if tracked.state == TransactionState::InProgress {
                        let _ = tracked.driver.lock().await.abort_transaction().await;
                    }
                    ids.push(Bson::Document(tracked.id));
                }
                None => ids.push(Bson::Document(session.id.clone())),
            }
        }
        if ids.is_empty() {
            return Ok(Document::new());
        }
        let mut command = doc! { command::END_SESSIONS: ids };
        merge(&mut command, options);
        let reply = self.query(command, Some("admin")).await?;
        Self::document_of(reply, "endSessions")
    }

    /// Whether the server is a replica set member (transactions need one);
    /// asked once per connection.
    ///
    /// PHP: `isReplicaSet()`.
    pub async fn is_replica_set(&self) -> Result<bool> {
        if let Some(known) = self.link().replica_set {
            return Ok(known);
        }
        let reply = self.query(doc! { "isMaster": 1 }, Some("admin")).await?;
        let known = matches!(&reply, Reply::Document(d) if d.contains_key("setName"));
        self.link().replica_set = Some(known);
        Ok(known)
    }

    /// Ends the sessions and closes the connection; the client must
    /// [`connect`](Self::connect) again before its next command.
    ///
    /// PHP: `close()`.
    pub async fn close(&self) {
        let active: Vec<Session> =
            self.link().sessions.iter().map(|(k, t)| Session { id: t.id.clone(), session_id: k.clone() }).collect();
        if !active.is_empty() {
            let _ = self.end_sessions(&active, Document::new()).await;
        }
        self.driver.lock().unwrap_or_else(|e| e.into_inner()).take();
        self.link().reset();
    }

    /// Whether a failure leaves a transaction worth retrying: a transient
    /// code (time limit, no such transaction, host unreachable, network
    /// timeout, primary change, ...) or a message naming the label, a
    /// connection, a timeout or the network.
    ///
    /// PHP: `isTransientTransactionError($exception)`.
    pub fn is_transient_transaction_error(&self, error: &Error) -> bool {
        const CODES: [i64; 14] = [50, 251, 6, 7, 89, 91, 189, 262, 9001, 10107, 11600, 11602, 13435, 13436];
        let message = error.message();
        CODES.contains(&error.code())
            || message.contains(TRANSIENT_TRANSACTION_ERROR)
            || message.contains("connection")
            || message.contains("timeout")
            || message.contains("network")
    }

    /// Whether a commit's failure leaves its outcome unknown (it may have
    /// applied): retry the commit.
    ///
    /// PHP: `isUnknownTransactionCommitResult($exception)`.
    pub fn is_unknown_transaction_commit_result(&self, error: &Error) -> bool {
        const CODES: [i64; 10] = [50, 91, 189, 262, 9001, 10107, 11600, 11602, 13435, 13436];
        CODES.contains(&error.code()) || error.message().contains(UNKNOWN_TRANSACTION_COMMIT_RESULT)
    }

    /// Runs `callback` in a transaction on `session` and commits it,
    /// retrying the commit while its result is unknown (3 times) and the
    /// whole transaction on transient failures (`maxRetries`, 3), waiting
    /// `retryDelayMs` (100) between attempts. A failure aborts the
    /// transaction.
    ///
    /// PHP: `withTransaction($session, $callback, $options)`.
    pub async fn with_transaction<T, F, Fut>(&self, session: &Session, mut callback: F, options: Document) -> Result<T>
    where
        F: FnMut(Session) -> Fut,
        Fut: Future<Output = Result<T>>,
    {
        let max_retries = options.get("maxRetries").filter(|v| set(Some(v))).map_or(3, integer);
        let delay = options.get("retryDelayMs").filter(|v| set(Some(v))).map_or(100, integer);
        let pause = || async move {
            if delay > 0 {
                tokio::time::sleep(Duration::from_millis(delay as u64)).await;
            }
        };
        let mut attempt = 0;
        while attempt <= max_retries {
            let outcome = async {
                self.start_transaction(session, options.clone()).await?;
                let inner = async {
                    let result = callback(session.clone()).await?;
                    let mut commits = 0;
                    loop {
                        match self.commit_transaction(session, options.clone()).await {
                            Ok(_) => return Ok(result),
                            Err(e) if self.is_unknown_transaction_commit_result(&e) && commits < 3 => {
                                commits += 1;
                                pause().await;
                            }
                            Err(e) => return Err(e),
                        }
                    }
                }
                .await;
                if inner.is_err() {
                    let _ = self.abort_transaction(session, Document::new()).await;
                }
                inner
            }
            .await;
            match outcome {
                Ok(result) => return Ok(result),
                Err(e) if self.is_transient_transaction_error(&e) && attempt < max_retries => {
                    attempt += 1;
                    pause().await;
                }
                Err(e) => return Err(e),
            }
        }
        Err(Error::exception("Transaction failed after maximum retries", 0))
    }

    /// The operation time of the client's last reply.
    ///
    /// PHP: `getOperationTime()`.
    pub fn operation_time(&self) -> Option<Bson> {
        self.link().operation_time.clone()
    }

    /// The cluster time of the client's last reply.
    ///
    /// PHP: `getClusterTime()`.
    pub fn cluster_time(&self) -> Option<Bson> {
        self.link().cluster_time.clone()
    }

    /// The bookkeeping of `session`, `None` when the client does not know it.
    ///
    /// PHP: `getSessionState($session)` (`['error' => 'Session not found']` for `None`).
    pub fn session_state(&self, session: &Session) -> Option<SessionState> {
        let mut link = self.link();
        let tracked = link.session(&session.session_id)?;
        Some(SessionState {
            session_id: session.session_id.clone(),
            state: tracked.state,
            txn_number: tracked.txn_number,
            last_use: tracked.last_use,
            retryable_write_number: tracked.retryable_write_number,
        })
    }

    /// `w` from a string or a non-negative integer, or `w`, `j` and
    /// `wtimeout` from a document.
    ///
    /// PHP: `createWriteConcern($writeConcern)`.
    pub fn create_write_concern(&self, concern: &Bson) -> Result<WriteConcern> {
        let negative = || Error::exception("Write concern w value must be >= 0", 0);
        let level = |w: i64| -> Result<WriteConcern> {
            if w < 0 {
                return Err(negative());
            }
            Ok(WriteConcern { w: Some(int(w)), ..WriteConcern::default() })
        };
        // A bool or a float in range is juggled to the union's int
        // (truncated), any other float to its string.
        match concern {
            Bson::String(w) => Ok(WriteConcern { w: Some(Bson::String(w.clone())), ..WriteConcern::default() }),
            Bson::Int32(_) | Bson::Int64(_) => level(integer(concern)),
            Bson::Boolean(b) => level(i64::from(*b)),
            Bson::Double(f) if f.is_finite() && f.abs() < 9.2e18 => level(*f as i64),
            Bson::Double(f) => {
                Ok(WriteConcern { w: Some(Bson::String(php_std::number::to_string(*f))), ..WriteConcern::default() })
            }
            Bson::Document(spec) => {
                // One pass over the (few) fields, no hashing.
                let (mut w, mut j, mut wtimeout) = (None, None, None);
                for (key, value) in spec {
                    match key.as_str() {
                        "w" => w = Some(value),
                        "j" => j = Some(value),
                        "wtimeout" => wtimeout = Some(value),
                        _ => {}
                    }
                }
                Self::write_concern_of(|key| match key {
                    "w" => w,
                    "j" => j,
                    _ => wtimeout,
                })
            }
            Bson::Array(items) => Self::write_concern_of(|key| key.parse::<usize>().ok().and_then(|i| items.get(i))),
            other => Err(Error::Engine {
                class: "TypeError",
                message: format!(
                    "Utopia\\Mongo\\Client::createWriteConcern(): Argument #1 ($writeConcern) must be of type array|string|int, {} given",
                    type_name(other)
                ),
            }),
        }
    }

    /// The write concern of an array's `w`, `j` and `wtimeout`.
    fn write_concern_of<'a>(get: impl Fn(&str) -> Option<&'a Bson>) -> Result<WriteConcern> {
        let mut out = WriteConcern::default();
        if let Some(w) = get("w").filter(|v| set(Some(v))) {
            if matches!(w, Bson::Int32(_) | Bson::Int64(_)) && integer(w) < 0 {
                return Err(Error::exception("Write concern w value must be >= 0", 0));
            }
            out.w = Some(w.clone());
        }
        if let Some(j) = get("j").filter(|v| set(Some(v))) {
            out.j = Some(truthy(j));
        }
        if let Some(timeout) = get("wtimeout").filter(|v| set(Some(v))) {
            if !matches!(timeout, Bson::Int32(_) | Bson::Int64(_)) || integer(timeout) < 0 {
                return Err(Error::exception("Write concern wtimeout must be a non-negative integer", 0));
            }
            out.wtimeout = Some(timeout.clone());
        }
        Ok(out)
    }

    /// `level` from a string or a document (one of [`READ_CONCERNS`]), and
    /// `afterClusterTime` from a document.
    ///
    /// PHP: `createReadConcern($readConcern)`.
    pub fn create_read_concern(&self, concern: &Bson) -> Result<ReadConcern> {
        let valid = |level: &Bson| match level {
            Bson::String(s) => READ_CONCERNS.contains(&s.as_str()),
            Bson::Boolean(true) => true,
            _ => false,
        };
        // A scalar is juggled to the union's string.
        let concern = match concern {
            Bson::Int32(_) | Bson::Int64(_) | Bson::Double(_) | Bson::Boolean(_) => &Bson::String(text(concern)),
            other => other,
        };
        match concern {
            Bson::String(level) => {
                if !valid(concern) {
                    return Err(Error::exception(format!("Invalid read concern level: {level}"), 0));
                }
                Ok(ReadConcern { level: Some(concern.clone()), after_cluster_time: None })
            }
            Bson::Document(_) | Bson::Array(_) => {
                let spec = keyed(concern);
                let mut out = ReadConcern::default();
                if let Some(level) = spec.get("level").filter(|v| set(Some(v))) {
                    if !valid(level) {
                        return Err(Error::exception(format!("Invalid read concern level: {}", text(level)), 0));
                    }
                    out.level = Some(level.clone());
                }
                if let Some(after) = spec.get("afterClusterTime").filter(|v| set(Some(v))) {
                    out.after_cluster_time = Some(after.clone());
                }
                Ok(out)
            }
            other => Err(Error::Engine {
                class: "TypeError",
                message: format!(
                    "Utopia\\Mongo\\Client::createReadConcern(): Argument #1 ($readConcern) must be of type array|string, {} given",
                    type_name(other)
                ),
            }),
        }
    }

    /// PHP: `shouldSkipReadConcern($options)`: in a transaction past its
    /// first command.
    fn should_skip_read_concern(&self, options: &Options) -> bool {
        let Some(session) = &options.session else { return false };
        self.link()
            .session(&session.session_id)
            .is_some_and(|t| t.state == TransactionState::InProgress && t.first_operation_done)
    }

    /// The connection's status.
    ///
    /// PHP: `getConnectionInfo()`.
    pub fn connection_info(&self) -> ConnectionInfo {
        let link = self.link();
        ConnectionInfo {
            connected: link.connected,
            host: self.config.host.clone(),
            port: self.config.port,
            database: self.config.database.clone(),
            active_sessions: link.sessions.len(),
            cluster_time_set: link.cluster_time.is_some(),
            operation_time_set: link.operation_time.is_some(),
        }
    }

    /// Ends the sessions unused for 30 minutes.
    ///
    /// PHP: `cleanupStaleSessions()`.
    pub async fn cleanup_stale_sessions(&self) {
        let cutoff = now() - 1800;
        let stale: Vec<Session> = self
            .link()
            .sessions
            .iter()
            .filter(|(_, t)| t.last_use < cutoff)
            .map(|(k, t)| Session { id: t.id.clone(), session_id: k.clone() })
            .collect();
        if !stale.is_empty() {
            let _ = self.end_sessions(&stale, Document::new()).await;
        }
    }
}

/// A document or list as a document (a list keyed `0`, `1`, ...), as PHP
/// reads either as an array.
fn keyed(value: &Bson) -> Document {
    match value {
        Bson::Document(d) => d.clone(),
        Bson::Array(items) => items.iter().enumerate().map(|(i, v)| (i.to_string(), v.clone())).collect(),
        _ => Document::new(),
    }
}

/// PHP's truthiness of a BSON value (`(bool)`, `?? false` then tested).
fn truthy(value: &Bson) -> bool {
    match value {
        Bson::Null => false,
        Bson::Boolean(b) => *b,
        Bson::Int32(i) => *i != 0,
        Bson::Int64(i) => *i != 0,
        Bson::Double(f) => *f != 0.0,
        Bson::String(s) => !(s.is_empty() || s == "0"),
        Bson::Array(a) => !a.is_empty(),
        Bson::Document(_) => true,
        _ => true,
    }
}

impl std::fmt::Debug for Client {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Client").field("host", &self.config.host).field("port", &self.config.port).finish()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn client() -> Client {
        Client::new(Config::new("db", "localhost", 27017, "root", "password")).unwrap()
    }

    /// A caller's own binary of the marker's subtype is data, not an
    /// unencodable string, and round-trips through [`unencoded`] as `None`.
    #[test]
    fn user_binaries_of_the_marker_subtype_encode() {
        let own = Binary { subtype: STRING, bytes: b"\x9f payload".to_vec() };
        assert_eq!(unencoded(&own), None);
        assert!(encodable(&doc! { "insert": "c", "documents": [{ "b": Bson::Binary(own) }] }).is_ok());
        let Bson::Binary(marker) = string(b"\x9f") else { panic!("not a marker") };
        assert_eq!(unencoded(&marker), Some(&b"\x9f"[..]));
    }

    /// What PHP's extension (2.5) throws for these documents.
    #[test]
    fn unencodable_commands() {
        let refused = |d: Document| match encodable(&d) {
            Err(e) => (e.php_class(), e.message_bytes().to_vec()),
            Ok(()) => panic!("encoded"),
        };
        let class = "MongoDB\\Driver\\Exception\\UnexpectedValueException";
        let filter = doc! { "find": "c", "filter": { "$and": [{ "$or": [
            { "": { "$lt": string(b"\xf0") } },
            { "": { "$gt": string(b"\x9f") } },
        ] }] } };
        assert_eq!(
            refused(filter),
            (class, b"Detected invalid UTF-8 for field path \"filter.$and.0.$or.1..$gt\": \x9f".to_vec())
        );
        let last = doc! { "a": string(b"\xff"), "b": { "c": string(b"\xfe"), "d": "ok" }, "e": [1, string(b"\xfd")] };
        assert_eq!(refused(last).1, b"Detected invalid UTF-8 for field path \"e.1\": \xfd");
        // A null byte key ends its document; a later string still counts.
        let key = doc! { "a": string(b"\xff"), "b": { "x\0y": 1, "z": string(b"\xfc") }, "e": "ok" };
        assert_eq!(refused(key).1, b"BSON keys cannot contain null bytes. Unexpected null byte after \"x\".");
        let after = doc! { "a": { "x\0y": 1 }, "b": string(b"\xfc") };
        assert_eq!(refused(after).1, b"Detected invalid UTF-8 for field path \"b\": \xfc");
        // `%s`: the string up to its first null byte.
        let nul = doc! { "a": { "k": string(b"ab\xffcd\0ef") } };
        assert_eq!(refused(nul).1, b"Detected invalid UTF-8 for field path \"a.k\": ab\xffcd");
        assert!(encodable(&doc! { "a": string("é".as_bytes()) }).is_ok());
    }

    #[test]
    fn settings_are_checked() {
        let bad = |c: Config| Client::new(c).unwrap_err().message().to_owned();
        assert_eq!(bad(Config::new("", "h", 1, "u", "p")), "Database name cannot be empty");
        assert_eq!(bad(Config::new("d", "h", 0, "u", "p")), "Port must be between 1 and 65535");
        assert_eq!(bad(Config::new("d", "h", 1, "u", "")), "Password cannot be empty");
    }

    #[test]
    fn concerns() {
        let c = client();
        assert_eq!(
            c.create_write_concern(&Bson::String("majority".into())).unwrap().to_document(),
            doc! { "w": "majority" }
        );
        assert!(c.create_write_concern(&Bson::Int32(-1)).is_err());
        assert_eq!(
            c.create_write_concern(&Bson::Document(doc! { "w": 1, "j": 1, "wtimeout": 5 })).unwrap().to_document(),
            doc! { "w": 1, "j": true, "wtimeout": 5 }
        );
        assert_eq!(
            c.create_read_concern(&Bson::String("bad".into())).unwrap_err().message(),
            "Invalid read concern level: bad"
        );
    }
}
