//! Process-wide Appwrite server state.
//!
//! Rust stand-in for the platform database (`console` project store) plus a
//! per-project `dbForProject` connection pool. PHP resolves both from real
//! infrastructure (`app/init.php`'s `$pools`, the `_APP_DB_ADAPTER` env, and
//! the `dbForPlatform` "projects" collection); this crate ships an
//! in-process [`Memory`] implementation so `apps/server` has a working
//! "first version" without external services. Swapping in Postgres later
//! only needs a new [`DatabasePool`] variant behind the `postgres` feature --
//! everything above `dbForProject` (Init hook, Users handlers) is
//! adapter-agnostic because it only sees `Arc<Mutex<Database<Memory>>>`
//! through the DI container as a boxed `Any`.
//!
//! TODO(postgres): `_APP_DB_ADAPTER=postgresql` is not wired yet. Wiring it
//! means giving [`DatabasePool`] a second backing map of
//! `Arc<Mutex<Database<utopia_database::adapter::postgres::Postgres>>>` (or
//! erasing the adapter behind a trait object) and reading `_APP_DB_HOST`/
//! `_APP_DB_USER`/`_APP_DB_PASS`/`_APP_DB_SCHEMA` in `apps/server`. Deferred
//! here because `utopia-database`'s `Postgres` adapter takes a live `r2d2`
//! pool this crate does not otherwise depend on; Memory is sufficient for
//! the Users-API v1 milestone and keeps `cargo test` hermetic.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use appwrite_event::{
    AuditPublisher, DeletePublisher, MemoryAuditPublisher, MemoryDeletePublisher,
};
use appwrite_hooks::Hooks;
use serde_json::{json, Value};
use utopia_cache::adapter::Memory as CacheMemory;
use utopia_cache::Cache;
pub use utopia_database::adapter::Memory;
use utopia_database::helpers::{Permission, Role};
use utopia_database::{Database, Document};

/// Collections the Users API reads/writes. Created (empty schema, validation
/// disabled) the first time a project's [`Database`] is provisioned.
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

/// A project's `dbForProject`, shared across requests. `Mutex` mirrors the
/// single-connection-at-a-time PDO/Swoole-coroutine model PHP relies on --
/// only one request touches a given project's Memory adapter at a time.
pub type ProjectDatabase = Arc<Mutex<Database<Memory>>>;

/// Per-project `dbForProject` pool. Rust stand-in for `app/init.php`'s
/// `$pools->get('database_db_' . $project->getAttribute('database'))`.
#[derive(Default)]
pub struct DatabasePool {
    projects: Mutex<HashMap<String, ProjectDatabase>>,
}

impl std::fmt::Debug for DatabasePool {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DatabasePool")
            .field(
                "projects",
                &self.projects.lock().map(|p| p.len()).unwrap_or_default(),
            )
            .finish()
    }
}

impl DatabasePool {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Lazily provisions (namespace, empty collections, validation disabled)
    /// and returns the shared `Database<Memory>` for `project_id`.
    pub fn get_or_create(&self, project_id: &str) -> ProjectDatabase {
        let mut projects = self.projects.lock().unwrap_or_else(|e| e.into_inner());
        if let Some(existing) = projects.get(project_id) {
            return existing.clone();
        }
        let db = Arc::new(Mutex::new(new_project_database(project_id)));
        projects.insert(project_id.to_string(), db.clone());
        db
    }
}

fn new_project_database(project_id: &str) -> Database<Memory> {
    let cache = Cache::new(CacheMemory::new());
    let mut db = Database::new(Memory::new(), cache);
    // Structure/query-attribute validation assumes a fully declared
    // `attributes`/`indexes` schema per collection (PHP's `collections.php`).
    // The Users-API v1 milestone stores documents dynamically instead, so
    // validation is disabled here -- a deliberate, documented simplification
    // (see module docs) rather than a port of PHP's schema.
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

/// Rust stand-in for the `dbForPlatform` "projects" + "keys" collections:
/// an in-memory map of project id -> project document JSON (shaped like
/// PHP's `Document $project`, i.e. `{ "$id", "keys": [...], "auths": {...} }`).
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
        }
    }
}

impl AppwriteState {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// PHP has no single equivalent -- this seeds a `console`-like project
    /// with one `standard` API key scoped to `users.read`/`users.write`, so
    /// `apps/server` (or a test) can exercise `/v1/users*` without a real
    /// platform database. Controlled by `_APP_RUST_SEED=1` in `apps/server`.
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
