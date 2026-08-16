//! Multi-adapter `dbForPlatform` / `dbForProject` wiring.
//!
//! Mirrors PHP `app/init/registers.php` schemes for the platform database:
//! `postgresql`, `mysql`, `mariadb`, `mongodb` (plus in-process `memory`).
//! Namespace rules match [`crate::state`] / PHP `Appwrite\Database\Factory`.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use utopia_cache::adapter::{Memory as CacheMemory, Redis as CacheRedis};
use utopia_cache::Cache;
#[cfg(feature = "mongo")]
use utopia_database::adapter::mongo::Mongo;
#[cfg(feature = "mysql")]
use utopia_database::adapter::mysql::{MariaDb, Mysql};
#[cfg(feature = "postgres")]
use utopia_database::adapter::postgres::Postgres;
pub use utopia_database::adapter::Memory;
use utopia_database::helpers::{Permission, Role};
use utopia_database::{Database, DatabaseError, Document, Query};
use utopia_pools::{BoxError, Connection, Pool, PoolError, Recover, RecoverCall, ResourceGuard, Swoole};

use crate::state::{COLLECTIONS, PLATFORM_NAMESPACE};

/// PHP `APP_DATABASE` / `_APP_DB_SCHEMA` default.
pub const DEFAULT_SCHEMA: &str = "appwrite";

/// Adapter selected from `_APP_DB_ADAPTER` (PHP register schemes).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AdapterKind {
    Memory,
    Postgres,
    Mysql,
    MariaDb,
    Mongo,
}

impl AdapterKind {
    /// Parse `_APP_DB_ADAPTER` the same way PHP does (`postgresql` alias).
    #[must_use]
    pub fn from_env_value(value: &str) -> Self {
        match value.trim().to_ascii_lowercase().as_str() {
            "postgresql" | "postgres" => Self::Postgres,
            "mysql" => Self::Mysql,
            "mariadb" => Self::MariaDb,
            "mongodb" | "mongo" => Self::Mongo,
            "memory" | "" => Self::Memory,
            other => {
                eprintln!(
                    "appwrite-platform: unknown _APP_DB_ADAPTER={other:?}; falling back to memory"
                );
                Self::Memory
            }
        }
    }

    /// Stable name logged by `apps/server` at boot.
    #[must_use]
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Memory => "memory",
            Self::Postgres => "postgres",
            Self::Mysql => "mysql",
            Self::MariaDb => "mariadb",
            Self::Mongo => "mongodb",
        }
    }

    #[must_use]
    pub const fn default_port(self) -> u16 {
        match self {
            Self::Memory => 0,
            Self::Postgres => 5432,
            Self::Mysql | Self::MariaDb => 3306,
            Self::Mongo => 27017,
        }
    }

    #[must_use]
    pub const fn is_live(self) -> bool {
        !matches!(self, Self::Memory)
    }
}

/// Connection settings from `_APP_DB_*` (same vars PHP's pool register uses).
#[derive(Debug, Clone)]
pub struct DatabaseConfig {
    pub kind: AdapterKind,
    pub host: String,
    pub port: u16,
    pub user: String,
    pub pass: String,
    /// PHP `Factory::$database` / `_APP_DB_SCHEMA` (logical DB / schema name).
    pub schema: String,
}

impl DatabaseConfig {
    /// `None` when `_APP_DB_HOST` is unset/empty.
    #[must_use]
    pub fn from_env(kind: AdapterKind) -> Option<Self> {
        if !kind.is_live() {
            return None;
        }
        let host = std::env::var("_APP_DB_HOST")
            .ok()
            .filter(|s| !s.is_empty())?;
        let port = std::env::var("_APP_DB_PORT")
            .ok()
            .and_then(|p| p.parse().ok())
            .unwrap_or_else(|| kind.default_port());
        let user = std::env::var("_APP_DB_USER").unwrap_or_else(|_| "user".to_string());
        let pass = std::env::var("_APP_DB_PASS").unwrap_or_default();
        let schema = std::env::var("_APP_DB_SCHEMA").unwrap_or_else(|_| DEFAULT_SCHEMA.to_string());
        Some(Self {
            kind,
            host,
            port,
            user,
            pass,
            schema,
        })
    }
}

/// Adapter-erased `dbForProject` / `dbForPlatform` connection.
pub enum ProjectDb {
    Memory(Database<Memory>),
    #[cfg(feature = "postgres")]
    Postgres(Database<Postgres>),
    #[cfg(feature = "mysql")]
    Mysql(Database<Mysql>),
    #[cfg(feature = "mysql")]
    MariaDb(Database<MariaDb>),
    #[cfg(feature = "mongo")]
    Mongo(Database<Mongo>),
}

impl std::fmt::Debug for ProjectDb {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            ProjectDb::Memory(_) => f.write_str("ProjectDb::Memory"),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(_) => f.write_str("ProjectDb::Postgres"),
            #[cfg(feature = "mysql")]
            ProjectDb::Mysql(_) => f.write_str("ProjectDb::Mysql"),
            #[cfg(feature = "mysql")]
            ProjectDb::MariaDb(_) => f.write_str("ProjectDb::MariaDb"),
            #[cfg(feature = "mongo")]
            ProjectDb::Mongo(_) => f.write_str("ProjectDb::Mongo"),
        }
    }
}

macro_rules! with_db {
    ($self:expr, $db:ident => $body:expr) => {
        match $self {
            ProjectDb::Memory($db) => $body,
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres($db) => $body,
            #[cfg(feature = "mysql")]
            ProjectDb::Mysql($db) => $body,
            #[cfg(feature = "mysql")]
            ProjectDb::MariaDb($db) => $body,
            #[cfg(feature = "mongo")]
            ProjectDb::Mongo($db) => $body,
        }
    };
}

impl ProjectDb {
    pub fn get_document(
        &mut self,
        collection: &str,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> utopia_database::Result<Document> {
        with_db!(self, db => db.get_document(collection, id, queries, for_update))
    }

    pub fn create_document(
        &mut self,
        collection: &str,
        document: Document,
    ) -> utopia_database::Result<Document> {
        with_db!(self, db => db.create_document(collection, document))
    }

    pub fn update_document(
        &mut self,
        collection: &str,
        id: &str,
        document: Document,
    ) -> utopia_database::Result<Document> {
        with_db!(self, db => db.update_document(collection, id, document))
    }

    pub fn delete_document(&mut self, collection: &str, id: &str) -> utopia_database::Result<bool> {
        with_db!(self, db => db.delete_document(collection, id))
    }

    pub fn find(
        &mut self,
        collection: &str,
        queries: &[Query],
        for_permission: &str,
    ) -> utopia_database::Result<Vec<Document>> {
        with_db!(self, db => db.find(collection, queries, for_permission))
    }

    pub fn find_one(
        &mut self,
        collection: &str,
        queries: &[Query],
    ) -> utopia_database::Result<Document> {
        with_db!(self, db => db.find_one(collection, queries))
    }

    pub fn count(
        &mut self,
        collection: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> utopia_database::Result<i64> {
        with_db!(self, db => db.count(collection, queries, max))
    }

    pub fn create(&mut self, database: Option<&str>) -> utopia_database::Result<bool> {
        with_db!(self, db => db.create(database))
    }

    /// PHP `$dbForProject->purgeCachedDocument($collection, $id)`.
    ///
    /// Writing a child document (a session, a target) leaves the parent user
    /// document cached with the old relationship, so the handlers that mutate
    /// one purge the other, exactly where the PHP handlers do.
    pub fn purge_cached_document(
        &mut self,
        collection: &str,
        id: &str,
    ) -> utopia_database::Result<bool> {
        with_db!(self, db => db.purge_cached_document(collection, Some(id)))
    }

    pub fn create_collection(
        &mut self,
        id: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
        permissions: Option<Vec<String>>,
        document_security: bool,
    ) -> utopia_database::Result<Document> {
        with_db!(self, db => {
            db.create_collection(id, attributes, indexes, permissions, document_security)
        })
    }

    /// `Database::ping` across every adapter variant -- the health probe
    /// [`Recover`] uses to decide whether a checked-out connection is still
    /// good before it goes back to the pool.
    pub fn ping(&mut self) -> bool {
        with_db!(self, db => db.ping())
    }
}

/// PHP pooled resources implement `reset()`/`reconnect()`; the SQL/Mongo
/// adapters behind [`ProjectDb`] only expose a `ping()` probe (there is no
/// "drop and open a new socket in place" primitive at this layer), so both
/// hooks reduce to it. A failed ping destroys the connection instead of
/// handing it to the next checkout, matching what a real `reconnect()`
/// failure would do.
impl Recover for ProjectDb {
    fn reset(&mut self) -> RecoverCall {
        if self.ping() {
            RecoverCall::Succeeded
        } else {
            RecoverCall::Failed
        }
    }

    fn reconnect(&mut self) -> RecoverCall {
        if self.ping() {
            RecoverCall::Succeeded
        } else {
            RecoverCall::Failed
        }
    }
}

/// Connection-pool size for this Rust process.
///
/// PHP (`app/init/registers.php`) divides `_APP_CONNECTIONS_MAX /
/// _APP_POOL_CLIENTS` across **many Swoole worker processes**, so each
/// process often gets a small pool (sometimes size 1) and concurrency comes
/// from having many processes.
///
/// This server is a **single** multi-threaded process that opens **one pool
/// per project** (plus `dbForPlatform`). Compose Postgres defaults to
/// `max_connections=100` and PHP already holds dozens of idle sockets, so a
/// large per-project pool exhausts the server (warm-up then returns 500).
/// Use a modest per-instance size floored by CPU count and clamped to
/// `[2, 8]`.
#[must_use]
pub fn pool_size_from_env() -> usize {
    compute_pool_size(
        env_usize("_APP_CONNECTIONS_MAX", 151),
        env_usize("_APP_POOL_CLIENTS", 14),
        std::thread::available_parallelism()
            .map(std::num::NonZeroUsize::get)
            .unwrap_or(4),
    )
}

fn compute_pool_size(max_connections: usize, pool_clients: usize, cores: usize) -> usize {
    let instance_connections = max_connections / pool_clients.max(1);
    // Prefer enough sockets for parallel handlers, but never more than 8 per
    // project - see `pool_size_from_env` docs.
    instance_connections.min(cores.max(2)).clamp(2, 8)
}

/// PHP `_APP_CONNECTIONS_TIMEOUT`: seconds a `Pool::pop()` waits for an idle
/// connection before raising.
#[must_use]
pub fn pool_timeout_from_env() -> f64 {
    std::env::var("_APP_CONNECTIONS_TIMEOUT")
        .ok()
        .and_then(|value| value.parse().ok())
        .unwrap_or(10.0)
}

fn env_usize(name: &str, default: usize) -> usize {
    std::env::var(name)
        .ok()
        .and_then(|value| value.parse().ok())
        .unwrap_or(default)
}

/// Shared `dbForProject` / `dbForPlatform` handle.
///
/// Live adapters (Postgres/MySQL/MariaDB/Mongo) are backed by a real
/// [`utopia_pools::Pool`] of independent connections: every [`lock`](Self::lock)
/// checks a connection **out** of the pool instead of contending for one
/// shared mutex, so concurrent requests against the same project no longer
/// serialize behind each other's I/O.
///
/// Memory mode keeps a single shared connection: a `Pool<Memory>` of size >1
/// would be N independent, out-of-sync in-process stores instead of one
/// logical database, and the in-process adapter has no I/O latency to hide
/// behind pooling anyway.
#[derive(Clone)]
pub struct ProjectDatabase {
    inner: ProjectDatabaseInner,
}

#[derive(Clone)]
enum ProjectDatabaseInner {
    Memory(Arc<Mutex<ProjectDb>>),
    Pooled(Arc<Pool<ProjectDb>>),
}

impl std::fmt::Debug for ProjectDatabase {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match &self.inner {
            ProjectDatabaseInner::Memory(_) => f.write_str("ProjectDatabase(memory)"),
            ProjectDatabaseInner::Pooled(pool) => f
                .debug_struct("ProjectDatabase")
                .field("pool_size", &pool.size())
                .field("idle", &pool.count())
                .finish(),
        }
    }
}

impl ProjectDatabase {
    fn memory(db: ProjectDb) -> Self {
        Self {
            inner: ProjectDatabaseInner::Memory(Arc::new(Mutex::new(db))),
        }
    }

    fn pooled(pool: Pool<ProjectDb>) -> Self {
        Self {
            inner: ProjectDatabaseInner::Pooled(Arc::new(pool)),
        }
    }

    /// Check a connection out for the duration of the returned guard.
    /// Existing call sites keep `let mut db = db_handle.lock();` and now get
    /// an exclusively-owned connection instead of contending for one shared
    /// mutex; dropping the guard returns the connection to the pool.
    ///
    /// Panics if a live pool cannot hand back a connection within
    /// `_APP_CONNECTIONS_TIMEOUT` (default 10s) -- the same "no capacity"
    /// failure PHP's `Pool::pop()` raises as an uncaught exception under
    /// sustained overload, surfaced here as a failed request rather than a
    /// JSON error body (see `3.x.x/AGENTS.md`).
    #[must_use]
    pub fn lock(&self) -> ProjectDbGuard<'_> {
        match &self.inner {
            ProjectDatabaseInner::Memory(mutex) => {
                ProjectDbGuard::Memory(mutex.lock().unwrap_or_else(std::sync::PoisonError::into_inner))
            }
            ProjectDatabaseInner::Pooled(pool) => {
                let connection = pop_sync(pool)
                    .unwrap_or_else(|err| panic!("dbForProject pool exhausted: {err}"));
                let resource = connection.resource_owned();
                ProjectDbGuard::Pooled {
                    connection,
                    resource: Some(resource),
                }
            }
        }
    }
}

/// PHP `Pool::pop()`, blocking for sync callers.
///
/// On a multi-thread Tokio runtime (`apps/server`'s), `block_in_place` hands
/// the worker thread back to the scheduler for the checkout's duration.
/// Without an ambient runtime (`AppwriteState::connect_from_env` warming a
/// pool up before `main` ever enters one, plain `#[test]`s, CLI-style call
/// sites), a throwaway runtime drives `pool.pop()` instead - and that
/// runtime must itself be multi-thread, not current-thread like
/// [`utopia_pools::Pool::use_sync`]'s fallback: the pool's `init` closure
/// opens a live SQL connection, which calls `postgres`/`mysql`'s own
/// `block_in_place` wrapper (`sql_client.rs`), and `block_in_place` panics
/// outright on a current-thread runtime.
fn pop_sync(pool: &Pool<ProjectDb>) -> Result<Connection<ProjectDb>, PoolError> {
    match tokio::runtime::Handle::try_current() {
        // Prefer spawning the async `pop` onto the runtime and waiting on a
        // channel under `block_in_place`. Driving `pool.pop()` with
        // `Handle::block_on` on this worker can run the pool `init` closure
        // (live SQL connect) while the thread is still entered into the
        // outer runtime, which panics inside the sync `postgres` client's
        // nested `Runtime::new()`.
        Ok(handle) => {
            let pool = pool.clone();
            let (tx, rx) = std::sync::mpsc::sync_channel(1);
            handle.spawn(async move {
                let _ = tx.send(pool.pop().await);
            });
            tokio::task::block_in_place(move || {
                rx.recv()
                    .expect("dbForProject pool pop task dropped before reply")
            })
        }
        Err(_) => tokio::runtime::Builder::new_multi_thread()
            .worker_threads(1)
            .enable_all()
            .build()
            .expect("blocking pool runtime")
            .block_on(pool.pop()),
    }
}

/// [`ProjectDatabase::lock`]'s guard: `Deref`/`DerefMut`s to [`ProjectDb`]
/// exactly like the `Arc<Mutex<ProjectDb>>` guard it replaces. Dropping a
/// `Pooled` guard returns the connection to the pool; callers never call
/// `reclaim()` themselves.
pub enum ProjectDbGuard<'a> {
    Memory(std::sync::MutexGuard<'a, ProjectDb>),
    Pooled {
        connection: Connection<ProjectDb>,
        /// `Some` until `Drop`, which takes it so the resource unlocks
        /// *before* the connection is reclaimed -- a racing `pop()` must
        /// not block on us finishing our own teardown.
        resource: Option<ResourceGuard<ProjectDb>>,
    },
}

impl std::fmt::Debug for ProjectDbGuard<'_> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Memory(_) => f.write_str("ProjectDbGuard::Memory"),
            Self::Pooled { .. } => f.write_str("ProjectDbGuard::Pooled"),
        }
    }
}

impl std::ops::Deref for ProjectDbGuard<'_> {
    type Target = ProjectDb;
    fn deref(&self) -> &ProjectDb {
        match self {
            Self::Memory(guard) => guard,
            Self::Pooled { resource, .. } => {
                resource.as_ref().expect("resource present until Drop")
            }
        }
    }
}

impl std::ops::DerefMut for ProjectDbGuard<'_> {
    fn deref_mut(&mut self) -> &mut ProjectDb {
        match self {
            Self::Memory(guard) => guard,
            Self::Pooled { resource, .. } => {
                resource.as_mut().expect("resource present until Drop")
            }
        }
    }
}

impl Drop for ProjectDbGuard<'_> {
    fn drop(&mut self) {
        if let Self::Pooled { connection, resource } = self {
            drop(resource.take());
            connection.reclaim();
        }
    }
}

/// Per-project `dbForProject` pool.
#[derive(Default)]
pub struct DatabasePool {
    projects: Mutex<HashMap<String, ProjectDatabase>>,
    live: Option<DatabaseConfig>,
    pool_size: usize,
    pool_timeout: f64,
}

impl std::fmt::Debug for DatabasePool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DatabasePool")
            .field(
                "projects",
                &self.projects.lock().map(|p| p.len()).unwrap_or_default(),
            )
            .field("live", &self.live.as_ref().map(|c| c.kind.as_str()))
            .field("pool_size", &self.pool_size)
            .field("pool_timeout", &self.pool_timeout)
            .finish()
    }
}

impl DatabasePool {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Pool size/timeout from `_APP_CONNECTIONS_MAX` / `_APP_POOL_CLIENTS` /
    /// `_APP_WORKER_MAX_COROUTINES` / `_APP_CONNECTIONS_TIMEOUT`. Use
    /// [`DatabasePool::live_with_pool`] to pin an explicit size (tests).
    #[must_use]
    pub fn live(config: DatabaseConfig) -> Self {
        Self::live_with_pool(config, pool_size_from_env(), pool_timeout_from_env())
    }

    /// [`DatabasePool::live`] with an explicit pool size/timeout instead of
    /// reading them from the environment.
    #[must_use]
    pub fn live_with_pool(config: DatabaseConfig, pool_size: usize, pool_timeout: f64) -> Self {
        Self {
            projects: Mutex::default(),
            live: Some(config),
            pool_size: pool_size.max(1),
            pool_timeout,
        }
    }

    /// Backward-compatible alias used by older call sites / tests.
    #[must_use]
    pub fn postgres(config: DatabaseConfig) -> Self {
        Self::live(config)
    }

    /// The connection pool size live-adapter projects/`dbForPlatform` use
    /// (`apps/server` logs this at boot).
    #[must_use]
    pub fn pool_size(&self) -> usize {
        self.pool_size
    }

    pub fn get_or_create(
        &self,
        project_id: &str,
        sequence: Option<&str>,
    ) -> Result<ProjectDatabase, DatabaseError> {
        let mut projects = self.projects.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(existing) = projects.get(project_id) {
            return Ok(existing.clone());
        }
        let db = match &self.live {
            Some(config) => {
                let sequence = sequence.ok_or_else(|| {
                    DatabaseError::database("project sequence required to open a live dbForProject")
                })?;
                new_project_database_pool(config, project_id, sequence, self.pool_size, self.pool_timeout)?
            }
            None => ProjectDatabase::memory(ProjectDb::Memory(new_memory_project_database(
                project_id,
            ))),
        };
        projects.insert(project_id.to_string(), db.clone());
        Ok(db)
    }
}

pub fn new_memory_project_database(project_id: &str) -> Database<Memory> {
    let cache = Cache::new(CacheMemory::new());
    let mut db = Database::new(Memory::new(), cache);
    db.disable_validation();
    let namespace = format!("project_{project_id}");
    let _ = db.set_namespace(&namespace);
    let _ = db.set_database(&namespace);
    let _ = db.create(None);
    for collection in COLLECTIONS {
        let permissions = vec![
            Permission::create(&Role::any()),
            Permission::read(&Role::any()),
            Permission::update(&Role::any()),
            Permission::delete(&Role::any()),
        ];
        let _ = db.create_collection(collection, Vec::new(), Vec::new(), Some(permissions), true);
    }
    db
}

fn configure_live_database<A: utopia_database::adapter::Adapter>(
    mut db: Database<A>,
    config: &DatabaseConfig,
    namespace: &str,
) -> Result<Database<A>, DatabaseError> {
    db.disable_validation();
    db.get_authorization_mut().disable();
    // PHP's pooled SQL adapter carries the DSN host, and that host is a
    // segment of every cache key. Without it, Rust would read and purge a
    // different key space than the PHP server sharing this Redis.
    db.get_adapter_mut().set_hostname(config.host.as_str());
    db.set_database(&config.schema)
        .map_err(|err| DatabaseError::database(format!("setDatabase failed: {err}")))?;
    db.set_namespace(namespace)
        .map_err(|err| DatabaseError::database(format!("setNamespace failed: {err}")))?;
    Ok(db)
}

/// The cache PHP and Rust share.
///
/// `Database` builds keys as `{cacheName}-cache-{hostname}:{namespace}:
/// {tenant}:collection:{id}[:{documentId}]`, so pointing both servers at the
/// same Redis makes a purge on one side invalidate the other's entry. Falls
/// back to a private in-process cache when Redis is unreachable: correct for
/// this process, but PHP will not see its purges.
fn shared_cache() -> Cache {
    let Some(host) = std::env::var("_APP_REDIS_HOST")
        .ok()
        .filter(|host| !host.is_empty())
    else {
        return Cache::new(CacheMemory::new());
    };
    let port = std::env::var("_APP_REDIS_PORT")
        .ok()
        .and_then(|port| port.parse::<u16>().ok())
        .unwrap_or(6379);
    let user = std::env::var("_APP_REDIS_USER").unwrap_or_default();
    let pass = std::env::var("_APP_REDIS_PASS").unwrap_or_default();
    let credentials = match (user.is_empty(), pass.is_empty()) {
        (true, true) => String::new(),
        (true, false) => format!(":{pass}@"),
        _ => format!("{user}:{pass}@"),
    };

    match CacheRedis::connect_url(&format!("redis://{credentials}{host}:{port}/")) {
        Ok(redis) => Cache::new(redis),
        Err(err) => {
            eprintln!(
                "appwrite-platform: redis cache connect failed ({err}); falling back to an \
                 in-process cache, which PHP cannot invalidate"
            );
            Cache::new(CacheMemory::new())
        }
    }
}

fn new_project_database_live(
    config: &DatabaseConfig,
    sequence: &str,
) -> Result<ProjectDb, DatabaseError> {
    let namespace = format!("_{sequence}");
    open_database(config, &namespace)
}

/// `dbForPlatform`: namespace `_console`.
pub fn new_platform_database(config: &DatabaseConfig) -> Result<ProjectDb, DatabaseError> {
    open_database(config, PLATFORM_NAMESPACE)
}

/// Build the size-`pool_size` [`Pool`] behind a live `dbForProject`, one
/// independent connection per slot (`init` reruns `new_project_database_live`
/// for every connection the pool opens, not once).
fn new_project_database_pool(
    config: &DatabaseConfig,
    project_id: &str,
    sequence: &str,
    pool_size: usize,
    pool_timeout: f64,
) -> Result<ProjectDatabase, DatabaseError> {
    let init_config = config.clone();
    let init_sequence = sequence.to_string();
    let pool = Pool::try_new(
        Swoole::new(),
        format!("project-{project_id}"),
        pool_size,
        move || {
            new_project_database_live(&init_config, &init_sequence)
                .map_err(|err| -> BoxError { Box::new(err) })
        },
        pool_timeout,
        None,
    )
    .map_err(|err| DatabaseError::database(format!("dbForProject pool init failed: {err}")))?;

    warm_up_pool(&pool, project_id)?;
    Ok(ProjectDatabase::pooled(pool))
}

/// Build the size-`pool_size` [`Pool`] behind `dbForPlatform`.
pub fn new_platform_database_pool(
    config: &DatabaseConfig,
    pool_size: usize,
    pool_timeout: f64,
) -> Result<ProjectDatabase, DatabaseError> {
    let init_config = config.clone();
    let pool = Pool::try_new(
        Swoole::new(),
        "console",
        pool_size,
        move || new_platform_database(&init_config).map_err(|err| -> BoxError { Box::new(err) }),
        pool_timeout,
        None,
    )
    .map_err(|err| DatabaseError::database(format!("dbForPlatform pool init failed: {err}")))?;

    warm_up_pool(&pool, "console")?;
    Ok(ProjectDatabase::pooled(pool))
}

/// Eagerly open one pool slot at construction so an unreachable database
/// fails loudly at boot / first `get_or_create` instead of mid-request.
/// Remaining slots are created lazily on first checkout (`Pool` `init`),
/// which keeps compose Postgres (`max_connections=100`) from being exhausted
/// when many projects each warm a full pool alongside PHP's idle sockets.
/// `Pool::try_new` only validates `size`/`timeout`; the `init` closure that
/// actually opens a socket does not run until `pop()`.
fn warm_up_pool(pool: &Pool<ProjectDb>, name: &str) -> Result<(), DatabaseError> {
    let connection = pop_sync(pool).map_err(|err| {
        DatabaseError::database(format!("dbForProject pool warm-up failed for {name}: {err}"))
    })?;
    connection.reclaim();
    Ok(())
}

fn open_database(config: &DatabaseConfig, namespace: &str) -> Result<ProjectDb, DatabaseError> {
    let cache = shared_cache();
    match config.kind {
        AdapterKind::Memory => Err(DatabaseError::database(
            "open_database called for memory adapter",
        )),
        #[cfg(feature = "postgres")]
        AdapterKind::Postgres => {
            let adapter = Postgres::connect(
                &config.host,
                config.port,
                &config.user,
                &config.pass,
                &config.schema,
            )
            .map_err(|err| DatabaseError::database(format!("postgres connect failed: {err}")))?;
            let db = configure_live_database(Database::new(adapter, cache), config, namespace)?;
            Ok(ProjectDb::Postgres(db))
        }
        #[cfg(not(feature = "postgres"))]
        AdapterKind::Postgres => Err(DatabaseError::database(
            "binary built without the `postgres` feature",
        )),
        #[cfg(feature = "mysql")]
        AdapterKind::Mysql => {
            let adapter = Mysql::connect_db(
                &config.host,
                config.port,
                &config.user,
                &config.pass,
                Some(config.schema.as_str()),
            )
            .map_err(|err| DatabaseError::database(format!("mysql connect failed: {err}")))?;
            let db = configure_live_database(Database::new(adapter, cache), config, namespace)?;
            Ok(ProjectDb::Mysql(db))
        }
        #[cfg(feature = "mysql")]
        AdapterKind::MariaDb => {
            let adapter = MariaDb::connect_db(
                &config.host,
                config.port,
                &config.user,
                &config.pass,
                Some(config.schema.as_str()),
            )
            .map_err(|err| DatabaseError::database(format!("mariadb connect failed: {err}")))?;
            let db = configure_live_database(Database::new(adapter, cache), config, namespace)?;
            Ok(ProjectDb::MariaDb(db))
        }
        #[cfg(not(feature = "mysql"))]
        AdapterKind::Mysql | AdapterKind::MariaDb => Err(DatabaseError::database(
            "binary built without the `mysql` feature",
        )),
        #[cfg(feature = "mongo")]
        AdapterKind::Mongo => {
            let uri = mongo_uri(config);
            let adapter = Mongo::connect(&uri)
                .map_err(|err| DatabaseError::database(format!("mongodb connect failed: {err}")))?;
            let db = configure_live_database(Database::new(adapter, cache), config, namespace)?;
            Ok(ProjectDb::Mongo(db))
        }
        #[cfg(not(feature = "mongo"))]
        AdapterKind::Mongo => Err(DatabaseError::database(
            "binary built without the `mongo` feature",
        )),
    }
}

#[cfg(feature = "mongo")]
fn mongo_uri(config: &DatabaseConfig) -> String {
    use std::borrow::Cow;

    fn enc(s: &str) -> Cow<'_, str> {
        if s.bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.' | b'~'))
        {
            Cow::Borrowed(s)
        } else {
            Cow::Owned(
                s.bytes()
                    .flat_map(|b| {
                        if b.is_ascii_alphanumeric() || matches!(b, b'-' | b'_' | b'.' | b'~') {
                            vec![b as char]
                        } else {
                            format!("%{b:02X}").chars().collect()
                        }
                    })
                    .collect(),
            )
        }
    }

    if config.user.is_empty() {
        format!(
            "mongodb://{}:{}/{}",
            config.host, config.port, config.schema
        )
    } else {
        format!(
            "mongodb://{}:{}@{}:{}/{}?authSource=admin",
            enc(&config.user),
            enc(&config.pass),
            config.host,
            config.port,
            config.schema
        )
    }
}

/// Whether this build includes the requested live adapter feature.
#[must_use]
pub fn feature_enabled(kind: AdapterKind) -> bool {
    match kind {
        AdapterKind::Memory => true,
        AdapterKind::Postgres => cfg!(feature = "postgres"),
        AdapterKind::Mysql | AdapterKind::MariaDb => cfg!(feature = "mysql"),
        AdapterKind::Mongo => cfg!(feature = "mongo"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_php_adapter_schemes() {
        assert_eq!(
            AdapterKind::from_env_value("postgresql"),
            AdapterKind::Postgres
        );
        assert_eq!(
            AdapterKind::from_env_value("postgres"),
            AdapterKind::Postgres
        );
        assert_eq!(AdapterKind::from_env_value("mysql"), AdapterKind::Mysql);
        assert_eq!(AdapterKind::from_env_value("mariadb"), AdapterKind::MariaDb);
        assert_eq!(AdapterKind::from_env_value("mongodb"), AdapterKind::Mongo);
        assert_eq!(AdapterKind::from_env_value("mongo"), AdapterKind::Mongo);
        assert_eq!(AdapterKind::from_env_value("memory"), AdapterKind::Memory);
        assert_eq!(AdapterKind::from_env_value(""), AdapterKind::Memory);
        assert_eq!(AdapterKind::from_env_value("nope"), AdapterKind::Memory);
    }

    #[test]
    fn default_ports_match_php_compose() {
        assert_eq!(AdapterKind::Postgres.default_port(), 5432);
        assert_eq!(AdapterKind::Mysql.default_port(), 3306);
        assert_eq!(AdapterKind::MariaDb.default_port(), 3306);
        assert_eq!(AdapterKind::Mongo.default_port(), 27017);
    }

    #[test]
    fn pool_size_uses_instance_budget_not_worker_division() {
        // PHP defaults: 151 / 14 ≈ 10, but we clamp per-project pools to 8 and
        // prefer min(budget, cores) so compose Postgres (max_connections=100)
        // is not exhausted when many projects are opened.
        assert_eq!(compute_pool_size(151, 14, 4), 4);
        assert_eq!(compute_pool_size(151, 14, 16), 8); // clamp
        assert_eq!(compute_pool_size(20, 14, 1), 2); // clamp floor
        assert_eq!(compute_pool_size(151, 0, 4), 4); // clients treated as 1 → min(151,4)
    }
}
