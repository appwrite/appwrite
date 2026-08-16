//! Process-wide Appwrite server state.
//!
//! Rust stand-in for the platform database (`console` project store) plus a
//! per-project `dbForProject` connection pool. PHP resolves both from real
//! infrastructure (`app/init.php`'s `$pools`, the `_APP_DB_ADAPTER` env, and
//! `Appwrite\Database\Factory`); this crate defaults to an in-process
//! [`Memory`] implementation so `apps/server` has a working "first version"
//! without external services, but [`AppwriteState::connect_from_env`] (used
//! by `apps/server`'s `main()`) wires the real thing when
//! `_APP_DB_ADAPTER=postgresql`/`postgres`, sharing the same physical
//! Postgres PHP Appwrite runs against so `tests/e2e/Services/Users` (which
//! creates its fixture project via PHP) sees the same rows.
//!
//! ## Namespace mapping (PHP `Appwrite\Database\Factory`)
//!
//! - `dbForPlatform`: schema = `_APP_DB_SCHEMA` (PHP `APP_DATABASE` /
//!   `Factory::$database`, default `appwrite`), namespace =
//!   `_console` (PHP `Factory::$platformNamespace`). Tables end up
//!   `_console_projects`, `_console_keys`, etc.
//! - `dbForProject`: same schema; namespace = `_<project $sequence>` (PHP
//!   `Factory::configureProject()`'s `setNamespace('_' .
//!   $project->getSequence())`) whenever `_APP_DATABASE_SHARED_TABLES` is
//!   empty, which is the `.env` default this crate assumes -- shared-tables
//!   mode (`setTenant`/global-collections) is not implemented here. Tables
//!   end up `_<sequence>_users`, `_<sequence>_targets`, etc.
//!
//! ## Project + key loading
//!
//! `resolve_project` loads the `projects` document from `dbForPlatform`
//! (namespace `_console`) by `$id`, then attaches a `"keys"` JSON array by
//! hand-running the same query PHP's `subQueryKeys` filter
//! (`app/init/database/filters.php`) does -- `find('keys', [resourceType =
//! "projects", resourceInternalId = $project->getSequence()])` -- because
//! `utopia-database`'s filter-fn signature here is `Fn(&AttrValue) ->
//! AttrValue`, with no way to reach a live `Database`/`Document` the way
//! PHP's 3-arg subquery filters do. The resulting JSON shape (`{"$id", ...,
//! "keys": [{"secret", "scopes", "name", "expire"}, ...]}`) matches what
//! [`appwrite_auth::Key::decode_standard`] expects.
//!
//! `keys.secret` (like `users.password`) declares `filters: ["encrypt"]` in
//! PHP's collection schema, so it round-trips through
//! [`appwrite_database::filters::register`]'s AES-128-GCM filter
//! automatically as long as the metadata collection describing `keys`/
//! `users` (already created by PHP's install/project-provisioning flow)
//! declares that filter -- this crate does not recreate that schema for
//! Postgres (see [`DatabasePool`] docs).

use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use appwrite_event::{
    AuditPublisher, DeletePublisher, MemoryAuditPublisher, MemoryDeletePublisher,
};
use appwrite_hooks::Hooks;
use serde_json::{json, Value};
use utopia_cache::adapter::Memory as CacheMemory;
use utopia_cache::Cache;
#[cfg(feature = "postgres")]
use utopia_database::adapter::postgres::Postgres;
pub use utopia_database::adapter::Memory;
use utopia_database::helpers::{Permission, Role};
use utopia_database::{AttrValue, Database, DatabaseError, Document, Query};

/// PHP `Appwrite\Database\Factory::$platformNamespace`.
const PLATFORM_NAMESPACE: &str = "_console";
/// PHP `APP_DATABASE` (`app/init/constants.php`) / `.env`'s
/// `_APP_DB_SCHEMA` default.
const DEFAULT_SCHEMA: &str = "appwrite";

/// Collections the Users API reads/writes. Created (empty schema, validation
/// disabled) the first time a project's Memory-mode [`Database`] is
/// provisioned. Postgres-mode projects skip this -- see [`DatabasePool`]
/// docs -- since PHP's project-provisioning flow already created these
/// tables (with the real schema/filters) before Users E2E ever calls in.
pub const COLLECTIONS: &[&str] = &[
    "users",
    "targets",
    "sessions",
    "tokens",
    "identities",
    "memberships",
    "teams",
    "challenges",
    "authenticators",
    "providers",
];

/// Adapter-erased `dbForProject` / `dbForPlatform` connection: either the
/// in-process [`Memory`] adapter (default, and the Postgres-connect-failure
/// fallback) or a live Postgres connection sharing PHP Appwrite's database.
/// Every `Database<A>` method the Users module needs is forwarded by hand
/// below rather than through a `dyn Adapter` trait object, because
/// `utopia_database::adapter::Adapter` is not dyn-compatible (`set_namespace`
/// et al. return `&mut Self`) -- see `crates/utopia-database/src/adapter/mod.rs`.
pub enum ProjectDb {
    Memory(Database<Memory>),
    #[cfg(feature = "postgres")]
    Postgres(Database<Postgres>),
}

impl std::fmt::Debug for ProjectDb {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            ProjectDb::Memory(_) => f.write_str("ProjectDb::Memory"),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(_) => f.write_str("ProjectDb::Postgres"),
        }
    }
}

impl ProjectDb {
    pub fn get_document(
        &mut self,
        collection: &str,
        id: &str,
        queries: &[Query],
        for_update: bool,
    ) -> utopia_database::Result<Document> {
        match self {
            ProjectDb::Memory(db) => db.get_document(collection, id, queries, for_update),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.get_document(collection, id, queries, for_update),
        }
    }

    pub fn create_document(
        &mut self,
        collection: &str,
        document: Document,
    ) -> utopia_database::Result<Document> {
        match self {
            ProjectDb::Memory(db) => db.create_document(collection, document),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.create_document(collection, document),
        }
    }

    pub fn update_document(
        &mut self,
        collection: &str,
        id: &str,
        document: Document,
    ) -> utopia_database::Result<Document> {
        match self {
            ProjectDb::Memory(db) => db.update_document(collection, id, document),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.update_document(collection, id, document),
        }
    }

    pub fn delete_document(&mut self, collection: &str, id: &str) -> utopia_database::Result<bool> {
        match self {
            ProjectDb::Memory(db) => db.delete_document(collection, id),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.delete_document(collection, id),
        }
    }

    pub fn find(
        &mut self,
        collection: &str,
        queries: &[Query],
        for_permission: &str,
    ) -> utopia_database::Result<Vec<Document>> {
        match self {
            ProjectDb::Memory(db) => db.find(collection, queries, for_permission),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.find(collection, queries, for_permission),
        }
    }

    pub fn count(
        &mut self,
        collection: &str,
        queries: &[Query],
        max: Option<i64>,
    ) -> utopia_database::Result<i64> {
        match self {
            ProjectDb::Memory(db) => db.count(collection, queries, max),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.count(collection, queries, max),
        }
    }

    /// PHP `Database::create()`: provisions the schema/database itself
    /// (idempotent -- a no-op once it already exists). Postgres-mode
    /// projects normally never need this (see [`DatabasePool`] docs -- PHP's
    /// provisioning flow already ran it), but tests seeding their own
    /// schema against a bare Postgres do.
    pub fn create(&mut self, database: Option<&str>) -> utopia_database::Result<bool> {
        match self {
            ProjectDb::Memory(db) => db.create(database),
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => db.create(database),
        }
    }

    pub fn create_collection(
        &mut self,
        id: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
        permissions: Option<Vec<String>>,
        document_security: bool,
    ) -> utopia_database::Result<Document> {
        match self {
            ProjectDb::Memory(db) => {
                db.create_collection(id, attributes, indexes, permissions, document_security)
            }
            #[cfg(feature = "postgres")]
            ProjectDb::Postgres(db) => {
                db.create_collection(id, attributes, indexes, permissions, document_security)
            }
        }
    }
}

/// A project's `dbForProject`, shared across requests. `Mutex` mirrors the
/// single-connection-at-a-time PDO/Swoole-coroutine model PHP relies on --
/// only one request touches a given project's connection at a time.
pub type ProjectDatabase = Arc<Mutex<ProjectDb>>;

/// Live Postgres connection settings for `dbForPlatform`/`dbForProject`,
/// read from the same `_APP_DB_*` env vars PHP's `Utopia\Pools\Group` /
/// `Appwrite\Database\Factory` resolve (see `.env`: `_APP_DB_ADAPTER`,
/// `_APP_DB_HOST`, `_APP_DB_PORT`, `_APP_DB_USER`, `_APP_DB_PASS`,
/// `_APP_DB_SCHEMA`).
#[derive(Debug, Clone)]
pub struct PostgresConfig {
    pub host: String,
    pub port: u16,
    pub user: String,
    pub pass: String,
    /// PHP `Appwrite\Database\Factory::$database` / `APP_DATABASE` --
    /// the Postgres *schema* name (`_APP_DB_SCHEMA`, default `appwrite`),
    /// not a separate physical database.
    pub schema: String,
}

impl PostgresConfig {
    /// `None` when `_APP_DB_HOST` is unset/empty -- nothing to connect to,
    /// so callers should fall back to the in-memory path (task 4).
    #[must_use]
    pub fn from_env() -> Option<Self> {
        let host = std::env::var("_APP_DB_HOST")
            .ok()
            .filter(|s| !s.is_empty())?;
        let port = std::env::var("_APP_DB_PORT")
            .ok()
            .and_then(|p| p.parse().ok())
            .unwrap_or(5432);
        let user = std::env::var("_APP_DB_USER").unwrap_or_else(|_| "user".to_string());
        let pass = std::env::var("_APP_DB_PASS").unwrap_or_default();
        let schema = std::env::var("_APP_DB_SCHEMA").unwrap_or_else(|_| DEFAULT_SCHEMA.to_string());
        Some(Self {
            host,
            port,
            user,
            pass,
            schema,
        })
    }
}

/// Per-project `dbForProject` pool. Rust stand-in for `app/init.php`'s
/// `$pools->get('database_db_' . $project->getAttribute('database'))`, via
/// `Appwrite\Database\Factory::project()`.
///
/// In Postgres mode, connections are cached per project (one dedicated
/// `postgres::Client` each, opened lazily on first use and kept for the
/// life of the process) rather than pulled from a real connection pool --
/// `utopia-pools` integration is a documented follow-up, not required for
/// the Users-API E2E milestone this wires up.
#[derive(Default)]
pub struct DatabasePool {
    projects: Mutex<HashMap<String, ProjectDatabase>>,
    postgres: Option<PostgresConfig>,
}

impl std::fmt::Debug for DatabasePool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DatabasePool")
            .field(
                "projects",
                &self.projects.lock().map(|p| p.len()).unwrap_or_default(),
            )
            .field("postgres", &self.postgres.is_some())
            .finish()
    }
}

impl DatabasePool {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Every `get_or_create` call opens/returns a live Postgres connection
    /// sharing PHP Appwrite's database instead of the default in-memory one.
    #[must_use]
    pub fn postgres(config: PostgresConfig) -> Self {
        Self {
            projects: Mutex::default(),
            postgres: Some(config),
        }
    }

    /// Lazily provisions and returns the shared `dbForProject` for
    /// `project_id`.
    ///
    /// `sequence` is the project document's `$sequence` (PHP
    /// `$project->getSequence()`) -- required (and used to compute the
    /// Postgres namespace `_<sequence>`) only when this pool is wired to
    /// Postgres; ignored for the Memory path, where every project gets its
    /// own `project_<id>` namespace regardless.
    pub fn get_or_create(
        &self,
        project_id: &str,
        sequence: Option<&str>,
    ) -> Result<ProjectDatabase, DatabaseError> {
        let mut projects = self.projects.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(existing) = projects.get(project_id) {
            return Ok(existing.clone());
        }
        let db = match &self.postgres {
            Some(config) => {
                let sequence = sequence.ok_or_else(|| {
                    DatabaseError::database(
                        "project sequence required to open a Postgres dbForProject",
                    )
                })?;
                Arc::new(Mutex::new(new_postgres_project_database(config, sequence)?))
            }
            None => Arc::new(Mutex::new(ProjectDb::Memory(new_project_database(
                project_id,
            )))),
        };
        projects.insert(project_id.to_string(), db.clone());
        Ok(db)
    }
}

fn new_project_database(project_id: &str) -> Database<Memory> {
    let cache = Cache::new(CacheMemory::new());
    let mut db = Database::new(Memory::new(), cache);
    // Structure/query-attribute validation assumes a fully declared
    // `attributes`/`indexes` schema per collection (PHP's `collections.php`).
    // The Users-API v1 milestone stores documents dynamically instead, so
    // validation is disabled here -- a deliberate, documented simplification
    // (see module docs) rather than a port of PHP's schema. This does not
    // affect `encode`/`decode` filters (`self.filters`, a separate flag from
    // `self.validate`), which still run -- irrelevant here since Memory-mode
    // collections have no `filters: [...]` declared on any attribute, but
    // relevant for the Postgres path below.
    db.disable_validation();
    let namespace = format!("project_{project_id}");
    let _ = db.set_namespace(&namespace);
    let _ = db.set_database(&namespace);
    let _ = db.create(None);
    for collection in COLLECTIONS {
        // `Role::any()` grants full CRUD regardless of caller; row-level
        // permission enforcement for end users is out of scope for the
        // Users-API v1 milestone (server API-key requests already bypass
        // it in PHP via `Authorization::skip`).
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

/// PHP `Appwrite\Database\Factory::project()` + `configureProject()` for the
/// `_APP_DATABASE_SHARED_TABLES`-unset (default) case: a dedicated
/// connection namespaced `_<sequence>` in the shared `_APP_DB_SCHEMA`
/// schema. Unlike [`new_project_database`], this does **not** create the
/// schema or any collection -- PHP's project-provisioning flow
/// (`Appwrite\Platform\Modules\Projects\Http\Projects\Create` +
/// `worker-database`) already created every table (and the `_metadata` rows
/// describing their `attributes`/`filters`) before this code path is ever
/// reached, so recreating them here would either fight PHP's schema or
/// silently diverge from it.
#[cfg(feature = "postgres")]
fn new_postgres_project_database(
    config: &PostgresConfig,
    sequence: &str,
) -> Result<ProjectDb, DatabaseError> {
    let adapter = Postgres::connect(
        &config.host,
        config.port,
        &config.user,
        &config.pass,
        &config.schema,
    )
    .map_err(|err| DatabaseError::database(format!("dbForProject connect failed: {err}")))?;
    let cache = Cache::new(CacheMemory::new());
    let mut db = Database::new(adapter, cache);
    // See `new_project_database`'s comment: only `self.validate` is
    // disabled, `encode`/`decode` filters (`encrypt` on `users.password`)
    // still run against the real schema PHP created.
    db.disable_validation();
    // Users-API requests only reach here after the `Init` hook's scope gate
    // already required a privileged (`users.read`/`users.write`) API key,
    // matching PHP's server-side `Authorization::skip()` for the same
    // requests -- so document-level permission checks are redundant here
    // and would otherwise reject rows PHP's own server-key requests wrote
    // without ever calling `Authorization::skip()` from this process.
    db.get_authorization_mut().disable();
    db.set_database(&config.schema)
        .map_err(|err| DatabaseError::database(format!("setDatabase failed: {err}")))?;
    let namespace = format!("_{sequence}");
    db.set_namespace(&namespace)
        .map_err(|err| DatabaseError::database(format!("setNamespace failed: {err}")))?;
    Ok(ProjectDb::Postgres(db))
}

/// PHP `Appwrite\Database\Factory::platform()`: `dbForPlatform`, namespace
/// `_console`, same schema as every project.
#[cfg(feature = "postgres")]
fn new_postgres_platform_database(
    config: &PostgresConfig,
) -> Result<Database<Postgres>, DatabaseError> {
    let adapter = Postgres::connect(
        &config.host,
        config.port,
        &config.user,
        &config.pass,
        &config.schema,
    )
    .map_err(|err| DatabaseError::database(format!("dbForPlatform connect failed: {err}")))?;
    let cache = Cache::new(CacheMemory::new());
    let mut db = Database::new(adapter, cache);
    db.disable_validation();
    db.get_authorization_mut().disable();
    db.set_database(&config.schema)
        .map_err(|err| DatabaseError::database(format!("setDatabase failed: {err}")))?;
    db.set_namespace(PLATFORM_NAMESPACE)
        .map_err(|err| DatabaseError::database(format!("setNamespace failed: {err}")))?;
    Ok(db)
}

/// Rust stand-in for the `dbForPlatform` "projects" + "keys" collections:
/// an in-memory map of project id -> project document JSON (shaped like
/// PHP's `Document $project`, i.e. `{ "$id", "keys": [...], "auths": {...} }`).
/// Only consulted when [`AppwriteState::connect_from_env`] falls back to
/// Memory mode (or for `seed_dev_project`/tests) -- Postgres mode resolves
/// projects from the live `platform` connection instead (see
/// [`AppwriteState::resolve_project`]).
#[derive(Default, Debug)]
pub struct ProjectStore {
    projects: Mutex<HashMap<String, Value>>,
}

impl ProjectStore {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    pub fn upsert(&self, project: Value) {
        let id = project
            .get("$id")
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_string();
        if id.is_empty() {
            return;
        }
        self.projects
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .insert(id, project);
    }

    #[must_use]
    pub fn get(&self, id: &str) -> Option<Value> {
        self.projects
            .lock()
            .unwrap_or_else(|e| e.into_inner())
            .get(id)
            .cloned()
    }
}

/// Process-wide state shared by every request via the root DI container.
/// Bound once at boot (`apps/server`'s `main()`) and reached from the `api`
/// group `Init` hook to resolve `project` / `dbForProject` per request, and
/// from [`crate::build`] to seed the global resources (`hooks`,
/// `publisherForDeletes`, `publisherForAudits`, `passwordsDictionary`) every
/// request's DI container falls through to.
pub struct AppwriteState {
    pub projects: ProjectStore,
    pub databases: DatabasePool,
    /// PHP `Appwrite\Hooks\Hooks` (`$hooks` in `app/init.php`), e.g. the
    /// `passwordValidator` hook `Base::createUser()` triggers.
    pub hooks: Arc<Hooks>,
    /// PHP `$publisherForDeletes` (`v1-deletes` queue). In-memory for the
    /// Users-API v1 milestone; `apps/server` may swap this for a
    /// Redis-backed publisher later.
    pub deletes: Arc<dyn DeletePublisher>,
    /// PHP `$publisherForAudits` (`v1-audits` queue).
    pub audits: Arc<dyn AuditPublisher>,
    /// PHP `$passwordsDictionary` (common-password deny-list). Empty by
    /// default; `apps/server` may load a real word list at boot.
    pub passwords_dictionary: Arc<Vec<String>>,
    /// Live `dbForPlatform` (`Database<Postgres>`, namespace `_console`)
    /// when [`AppwriteState::connect_from_env`] connected successfully;
    /// `None` in Memory mode, where `projects`/`seed_dev_project` stand in.
    platform: Option<Mutex<ProjectDb>>,
}

impl std::fmt::Debug for AppwriteState {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("AppwriteState")
            .field("projects", &self.projects)
            .field("databases", &self.databases)
            .field("hooks", &self.hooks)
            .field("deletes_size", &self.deletes.size())
            .field("audits_size", &self.audits.size())
            .field("passwords_dictionary_len", &self.passwords_dictionary.len())
            .field("platform", &self.platform.is_some())
            .finish()
    }
}

impl Default for AppwriteState {
    fn default() -> Self {
        let mut hooks = Hooks::new();
        hooks.add(appwrite_hooks::PASSWORD_VALIDATOR, |params| {
            let password = params.first().and_then(|v| v.as_str()).unwrap_or_default();
            json!(!password.is_empty())
        });
        Self {
            projects: ProjectStore::default(),
            databases: DatabasePool::default(),
            hooks: Arc::new(hooks),
            deletes: Arc::new(MemoryDeletePublisher::new()),
            audits: Arc::new(MemoryAuditPublisher::new()),
            passwords_dictionary: Arc::new(Vec::new()),
            platform: None,
        }
    }
}

impl AppwriteState {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Boot-time entrypoint (`apps/server`'s `main()`): mirrors PHP's
    /// `_APP_DB_ADAPTER`-driven adapter choice. Returns the adapter name
    /// actually wired up (`"postgres"` or `"memory"`) so the caller can log
    /// it.
    ///
    /// Falls back to the in-memory path (task 4) whenever `_APP_DB_ADAPTER`
    /// is `memory`/unset, the crate was built without the `postgres`
    /// feature, or connecting fails for any reason (bad credentials, host
    /// unreachable, ...) -- `cargo test`/local dev without a live Postgres
    /// keeps working, only real `_APP_DB_ADAPTER=postgresql` deployments
    /// (e.g. the Docker Compose stack Users E2E runs against) pay for a
    /// live connection attempt.
    #[must_use]
    pub fn connect_from_env() -> (Self, &'static str) {
        appwrite_database::filters::register();

        let adapter = std::env::var("_APP_DB_ADAPTER").unwrap_or_default();
        let wants_postgres = matches!(adapter.as_str(), "postgresql" | "postgres");
        if !wants_postgres {
            return (Self::default(), "memory");
        }

        #[cfg(feature = "postgres")]
        {
            let Some(config) = PostgresConfig::from_env() else {
                eprintln!(
                    "appwrite-platform: _APP_DB_ADAPTER={adapter} but _APP_DB_HOST is unset; \
                     falling back to in-memory state"
                );
                return (Self::default(), "memory");
            };
            match new_postgres_platform_database(&config) {
                Ok(platform_db) => {
                    let state = Self {
                        databases: DatabasePool::postgres(config),
                        platform: Some(Mutex::new(ProjectDb::Postgres(platform_db))),
                        ..Self::default()
                    };
                    (state, "postgres")
                }
                Err(err) => {
                    eprintln!(
                        "appwrite-platform: dbForPlatform postgres connect failed ({err}); \
                         falling back to in-memory state"
                    );
                    (Self::default(), "memory")
                }
            }
        }
        #[cfg(not(feature = "postgres"))]
        {
            eprintln!(
                "appwrite-platform: _APP_DB_ADAPTER={adapter} requested but this binary was \
                 built without the `postgres` feature; falling back to in-memory state"
            );
            (Self::default(), "memory")
        }
    }

    /// PHP has no single equivalent -- this seeds a `console`-like project
    /// with one `standard` API key scoped to `users.read`/`users.write`, so
    /// `apps/server` (or a test) can exercise `/v1/users*` without a real
    /// platform database. Controlled by `_APP_RUST_SEED=1` in `apps/server`.
    /// Only takes effect in Memory mode -- Postgres mode resolves `keys`
    /// from the live `keys` collection PHP populated instead (see
    /// [`Self::resolve_project`]).
    pub fn seed_dev_project(&self, project_id: &str, key_secret: &str, scopes: &[&str]) {
        self.projects.upsert(json!({
            "$id": project_id,
            "$permissions": [],
            "name": "Dev Project",
            "auths": {},
            "keys": [
                {
                    "$id": "dev",
                    "name": "Dev key",
                    "scopes": scopes,
                    "secret": key_secret,
                    "expire": null,
                }
            ],
        }));
    }

    /// PHP `Http::init()`'s `$dbForPlatform->getDocument('projects',
    /// $projectId)`, plus the `keys` subquery (see module docs). `None`
    /// when the project does not exist (Postgres mode) or was never seeded
    /// (Memory mode) -- both map to PHP's `PROJECT_NOT_FOUND`.
    #[must_use]
    pub fn resolve_project(&self, project_id: &str) -> Option<Value> {
        let Some(platform) = &self.platform else {
            return self.projects.get(project_id);
        };
        let mut db = platform.lock().unwrap_or_else(|e| e.into_inner());
        let project = db.get_document("projects", project_id, &[], false).ok()?;
        if project.is_empty() {
            return None;
        }
        let sequence = project.get_sequence().unwrap_or_default();
        let keys = db
            .find(
                "keys",
                &[
                    Query::equal("resourceType", vec![AttrValue::from("projects")]),
                    Query::equal(
                        "resourceInternalId",
                        vec![AttrValue::from(sequence.as_str())],
                    ),
                    Query::limit(100),
                ],
                "read",
            )
            .unwrap_or_default();
        let mut project_json = document_to_json(&project);
        project_json["keys"] = Value::Array(keys.iter().map(document_to_json).collect());
        Some(project_json)
    }

    /// The project's internal `$sequence`, used by [`DatabasePool::get_or_create`]
    /// to compute the Postgres `dbForProject` namespace. `None` in Memory
    /// mode (ignored there) or if `project` (from [`Self::resolve_project`])
    /// has no `$sequence` attribute.
    #[must_use]
    pub fn project_sequence(&self, project: &Value) -> Option<String> {
        match project.get("$sequence")? {
            Value::String(s) => Some(s.clone()),
            Value::Number(n) => Some(n.to_string()),
            _ => None,
        }
    }
}

/// Build an empty [`Document`] from a JSON object, matching PHP's
/// `new Document([...])`. Panics never occur for well-formed callers within
/// this crate (all fields come from `serde_json::json!`).
#[must_use]
pub fn document_from_json(value: Value) -> Document {
    Document::try_from_json(value).unwrap_or_default()
}

/// Full document as a JSON object (`Document::getArrayCopy()` equivalent),
/// used before [`appwrite_response::dynamic`] filters it to a response model.
#[must_use]
pub fn document_to_json(document: &Document) -> Value {
    Value::Object(document.get_array_copy_json(&[], &[]))
}

/// In-process, no-PHP-required exercise of the whole Postgres wiring: opens a
/// `dbForPlatform` against a **real** local Postgres, provisions a minimal
/// `projects`/`keys` schema by hand (standing in for PHP's install/
/// project-provisioning flow, which this crate does not reimplement -- see
/// [`DatabasePool`] docs), seeds one project + one `encrypt`-filtered key,
/// then drives the same [`AppwriteState::connect_from_env`] /
/// [`AppwriteState::resolve_project`] / [`AppwriteState::project_sequence`]
/// path `apps/server` uses per-request. `#[ignore]`d (task 7): needs a live
/// Postgres, so it never runs in the default `cargo test`. Run with:
///
/// ```bash
/// _APP_DB_HOST=127.0.0.1 _APP_DB_PORT=5432 _APP_DB_USER=user \
/// _APP_DB_PASS=password _APP_DB_SCHEMA=appwrite \
/// cargo test -p appwrite-platform --features postgres postgres_wiring -- --ignored --nocapture
/// ```
#[cfg(feature = "postgres")]
#[cfg(test)]
mod postgres_wiring_tests {
    use super::*;
    use utopia_database::constants::VAR_STRING;
    use utopia_database::helpers::Id;

    fn env_default(name: &str, default: &str) {
        if std::env::var(name).map(|v| v.is_empty()).unwrap_or(true) {
            std::env::set_var(name, default);
        }
    }

    fn attribute(key: &str, size: i64, array: bool, filters: Vec<&str>) -> Document {
        Document::from_pairs([
            ("$id", AttrValue::from(key)),
            ("type", AttrValue::from(VAR_STRING)),
            ("size", AttrValue::from(size)),
            ("required", AttrValue::from(false)),
            ("signed", AttrValue::from(true)),
            ("array", AttrValue::from(array)),
            (
                "filters",
                AttrValue::from(filters.into_iter().map(AttrValue::from).collect::<Vec<_>>()),
            ),
        ])
        .expect("well-formed attribute document")
    }

    #[test]
    #[ignore = "needs a live local Postgres; see this module's doc comment for how to run it"]
    fn postgres_wiring_resolves_a_hand_seeded_project_and_key() {
        env_default("_APP_DB_ADAPTER", "postgresql");
        env_default("_APP_DB_HOST", "127.0.0.1");
        env_default("_APP_DB_PORT", "5432");
        env_default("_APP_DB_USER", "user");
        env_default("_APP_DB_PASS", "password");
        env_default("_APP_DB_SCHEMA", "appwrite");
        env_default("_APP_OPENSSL_KEY_V1", "your-secret-key");

        // Registers the `encrypt` filter this test relies on for `keys.secret`
        // -- normally done once by `connect_from_env`, but seeding below
        // needs it active *before* that call.
        appwrite_database::filters::register();

        let config = PostgresConfig::from_env().expect("_APP_DB_HOST set above");

        // Provision (idempotently) the tiny slice of PHP's `projects`/`keys`
        // schema this test needs, directly on `_console` -- normally PHP's
        // install flow does this once for the whole deployment.
        let mut platform =
            new_postgres_platform_database(&config).expect("connect dbForPlatform for seeding");
        let _ = platform.create(None);
        let _ = platform.create_collection(
            "projects",
            Vec::new(),
            Vec::new(),
            Some(vec![
                Permission::create(&Role::any()),
                Permission::read(&Role::any()),
                Permission::update(&Role::any()),
                Permission::delete(&Role::any()),
            ]),
            true,
        );
        let _ = platform.create_collection(
            "keys",
            vec![
                attribute("resourceType", 64, false, Vec::new()),
                attribute("resourceInternalId", 64, false, Vec::new()),
                attribute("name", 128, false, Vec::new()),
                attribute("secret", 512, false, vec!["encrypt"]),
                attribute("scopes", 64, true, Vec::new()),
                attribute("expire", 64, false, Vec::new()),
            ],
            Vec::new(),
            Some(vec![
                Permission::create(&Role::any()),
                Permission::read(&Role::any()),
                Permission::update(&Role::any()),
                Permission::delete(&Role::any()),
            ]),
            true,
        );

        let project_id = format!("rustpoc{}", Id::unique().unwrap());
        let _ = platform.delete_document("projects", &project_id);
        let project = platform
            .create_document(
                "projects",
                Document::from_pairs([("$id", AttrValue::from(project_id.as_str()))]).unwrap(),
            )
            .expect("create seed project");
        let sequence = project.get_sequence().expect("project has a $sequence");

        let key_secret = format!("rustpoc_secret_{}", Id::unique().unwrap());
        let key_doc = platform
            .create_document(
                "keys",
                Document::from_pairs([
                    ("resourceType", AttrValue::from("projects")),
                    ("resourceInternalId", AttrValue::from(sequence.as_str())),
                    ("name", AttrValue::from("Rust smoke key")),
                    ("secret", AttrValue::from(key_secret.as_str())),
                    (
                        "scopes",
                        AttrValue::from(vec![AttrValue::from("users.read")]),
                    ),
                    ("expire", AttrValue::from("")),
                ])
                .unwrap(),
            )
            .expect("create seed key");

        // Now exercise the exact path `apps/server` runs per-request.
        let (state, adapter) = AppwriteState::connect_from_env();
        assert_eq!(adapter, "postgres");

        let resolved = state
            .resolve_project(&project_id)
            .expect("resolve_project should find the seeded project via dbForPlatform");
        assert_eq!(
            resolved.get("$id").and_then(Value::as_str),
            Some(project_id.as_str())
        );

        let key = appwrite_auth::Key::decode_standard(&resolved, &key_secret);
        assert_eq!(
            key.role,
            appwrite_auth::ROLE_KEYS,
            "decoded key should resolve to the `keys` role (not fall back to guest) -- if this \
             fails, the `encrypt` filter round-trip (crates/appwrite-database/src/filters.rs) \
             does not match what dbForPlatform stored"
        );
        assert!(!key.expired);
        assert!(key.scopes.iter().any(|s| s == "users.read"));

        let resolved_sequence = state
            .project_sequence(&resolved)
            .expect("resolved project should carry $sequence");
        assert_eq!(resolved_sequence, sequence);

        let db_project = state
            .databases
            .get_or_create(&project_id, Some(&resolved_sequence))
            .expect("dbForProject should connect using the project's namespace");
        let mut db_project = db_project.lock().unwrap();
        let _ = db_project.create(None);
        let _ = db_project.create_collection(
            "users",
            Vec::new(),
            Vec::new(),
            Some(vec![
                Permission::create(&Role::any()),
                Permission::read(&Role::any()),
                Permission::update(&Role::any()),
                Permission::delete(&Role::any()),
            ]),
            true,
        );
        let users = db_project
            .find("users", &[], "read")
            .expect("dbForProject(users) should be queryable at the project's namespace");
        println!(
            "dbForProject(_{resolved_sequence}) users collection has {} document(s)",
            users.len()
        );

        // Cleanup so re-running this test does not accumulate rows.
        let _ = platform.delete_document("keys", &key_doc.get_id());
        let _ = platform.delete_document("projects", &project_id);
    }
}
