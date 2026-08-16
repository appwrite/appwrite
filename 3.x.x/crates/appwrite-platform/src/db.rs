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
#[cfg(feature = "mysql")]
use utopia_database::pdo::Pdo;
use utopia_database::{Database, DatabaseError, Document, Query};

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
}

/// Shared `dbForProject` handle.
pub type ProjectDatabase = Arc<Mutex<ProjectDb>>;

/// Per-project `dbForProject` pool.
#[derive(Default)]
pub struct DatabasePool {
    projects: Mutex<HashMap<String, ProjectDatabase>>,
    live: Option<DatabaseConfig>,
}

impl std::fmt::Debug for DatabasePool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DatabasePool")
            .field(
                "projects",
                &self.projects.lock().map(|p| p.len()).unwrap_or_default(),
            )
            .field("live", &self.live.as_ref().map(|c| c.kind.as_str()))
            .finish()
    }
}

impl DatabasePool {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    #[must_use]
    pub fn live(config: DatabaseConfig) -> Self {
        Self {
            projects: Mutex::default(),
            live: Some(config),
        }
    }

    /// Backward-compatible alias used by older call sites / tests.
    #[must_use]
    pub fn postgres(config: DatabaseConfig) -> Self {
        Self::live(config)
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
                Arc::new(Mutex::new(new_project_database_live(config, sequence)?))
            }
            None => Arc::new(Mutex::new(ProjectDb::Memory(new_memory_project_database(
                project_id,
            )))),
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
    // PHP's pooled PDO adapter carries the DSN host, and that host is a
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
            let pdo = Pdo::mysql(
                &config.host,
                config.port,
                &config.user,
                &config.pass,
                Some(config.schema.as_str()),
                false,
            )
            .map_err(|err| DatabaseError::database(format!("mysql connect failed: {err}")))?;
            let db =
                configure_live_database(Database::new(Mysql::new(pdo), cache), config, namespace)?;
            Ok(ProjectDb::Mysql(db))
        }
        #[cfg(feature = "mysql")]
        AdapterKind::MariaDb => {
            let pdo = Pdo::mysql(
                &config.host,
                config.port,
                &config.user,
                &config.pass,
                Some(config.schema.as_str()),
                true,
            )
            .map_err(|err| DatabaseError::database(format!("mariadb connect failed: {err}")))?;
            let db = configure_live_database(
                Database::new(MariaDb::new(pdo), cache),
                config,
                namespace,
            )?;
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
}
