//! Process-wide Appwrite server state.
//!
//! Rust stand-in for the platform database (`console` project store) plus a
//! per-project `dbForProject` connection pool. PHP resolves both from real
//! infrastructure (`app/init.php`'s `$pools`, the `_APP_DB_ADAPTER` env, and
//! `Appwrite\Database\Factory`); this crate defaults to an in-process
//! [`Memory`] implementation so `apps/server` has a working "first version"
//! without external services, but [`AppwriteState::connect_from_env`] (used
//! by `apps/server`'s `main()`) wires the real thing when
//! `_APP_DB_ADAPTER` is one of PHP's supported schemes (`postgresql`,
//! `mysql`, `mariadb`, `mongodb`), sharing the same physical database PHP
//! Appwrite runs against so `tests/e2e/Services/Users` (which creates its
//! fixture project via PHP) sees the same rows.
//!
//! ## Namespace mapping (PHP `Appwrite\Database\Factory`)
//!
//! - `dbForPlatform`: schema = `_APP_DB_SCHEMA` (PHP `APP_DATABASE` /
//!   `Factory::$database`, default `appwrite`), namespace =
//!   `_console` (PHP `Factory::$platformNamespace`).
//! - `dbForProject`: same schema; namespace = `_<project $sequence>` when
//!   `_APP_DATABASE_SHARED_TABLES` is empty (the `.env` default). Shared-
//!   tables mode is not implemented here.
//!
//! Live adapter details live in [`crate::db`].

use std::sync::{Arc, Mutex};

use appwrite_event::{
    AuditPublisher, DeletePublisher, MemoryAuditPublisher, MemoryDeletePublisher,
};
use appwrite_hooks::Hooks;
use serde_json::{json, Value};
use utopia_console::Console;
use utopia_database::{AttrValue, Document, Query};

pub use crate::db::{
    AdapterKind, DatabaseConfig, DatabasePool, ProjectDatabase, ProjectDb, DEFAULT_SCHEMA,
};
pub use utopia_database::adapter::Memory;

/// PHP `Appwrite\Database\Factory::$platformNamespace`.
pub const PLATFORM_NAMESPACE: &str = "_console";

/// Collections the Users API reads/writes. Created (empty schema, validation
/// disabled) the first time a project's Memory-mode database is provisioned.
/// Live-adapter projects skip this -- PHP's project-provisioning flow already
/// created these tables (with the real schema/filters).
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

/// Rust stand-in for the `dbForPlatform` "projects" + "keys" collections:
/// an in-memory map of project id -> project document JSON. Only consulted
/// when [`AppwriteState::connect_from_env`] falls back to Memory mode.
#[derive(Default, Debug)]
pub struct ProjectStore {
    projects: Mutex<std::collections::HashMap<String, Value>>,
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
pub struct AppwriteState {
    pub projects: ProjectStore,
    pub databases: DatabasePool,
    pub hooks: Arc<Hooks>,
    pub deletes: Arc<dyn DeletePublisher>,
    pub audits: Arc<dyn AuditPublisher>,
    pub passwords_dictionary: Arc<Vec<String>>,
    /// Live `dbForPlatform` when [`AppwriteState::connect_from_env`] connected
    /// successfully; `None` in Memory mode. Pooled the same way `dbForProject`
    /// is -- the console project's requests (Console UI, admin-mode API
    /// keys) no longer serialize behind one shared `dbForPlatform` socket.
    platform: Option<ProjectDatabase>,
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
    /// actually wired up (`postgres` / `mysql` / `mariadb` / `mongodb` /
    /// `memory`) so the caller can log it.
    ///
    /// Falls back to Memory when the adapter is unset/`memory`, the binary
    /// lacks the matching Cargo feature, `_APP_DB_HOST` is missing, or
    /// connecting fails.
    #[must_use]
    pub fn connect_from_env() -> (Self, &'static str) {
        appwrite_database::filters::register();

        let raw = std::env::var("_APP_DB_ADAPTER").unwrap_or_default();
        let kind = AdapterKind::from_env_value(&raw);
        if !kind.is_live() {
            return (Self::default(), AdapterKind::Memory.as_str());
        }

        if !crate::db::feature_enabled(kind) {
            let _ = Console::warning(&format!(
                "_APP_DB_ADAPTER={} requested but this binary was built without that adapter feature; falling back to in-memory state",
                kind.as_str()
            ));
            return (Self::default(), AdapterKind::Memory.as_str());
        }

        let Some(config) = DatabaseConfig::from_env(kind) else {
            let _ = Console::warning(&format!(
                "_APP_DB_ADAPTER={} but _APP_DB_HOST is unset; falling back to in-memory state",
                kind.as_str()
            ));
            return (Self::default(), AdapterKind::Memory.as_str());
        };

        let pool_size = crate::db::pool_size_from_env();
        let pool_timeout = crate::db::pool_timeout_from_env();
        match crate::db::new_platform_database_pool(&config, pool_size, pool_timeout) {
            Ok(platform_db) => {
                let name = kind.as_str();
                let state = Self {
                    databases: DatabasePool::live_with_pool(config, pool_size, pool_timeout),
                    platform: Some(platform_db),
                    ..Self::default()
                };
                (state, name)
            }
            Err(err) => {
                let _ = Console::warning(&format!(
                    "dbForPlatform {} connect failed ({err}); falling back to in-memory state",
                    kind.as_str()
                ));
                (Self::default(), AdapterKind::Memory.as_str())
            }
        }
    }

    /// Seeds a Memory-mode project + API key for local/dev. No-op effect on
    /// live adapters (those resolve keys from the platform `keys` collection).
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

    /// PHP `getDocument('projects', $projectId)` + `keys` subquery.
    #[must_use]
    pub fn resolve_project(&self, project_id: &str) -> Option<Value> {
        let Some(platform) = &self.platform else {
            return self.projects.get(project_id);
        };
        let mut db = platform.lock();
        let project = match db.get_document("projects", project_id, &[], false) {
            Ok(project) => project,
            Err(err) => {
                let _ = Console::warning(&format!(
                    "resolve_project({project_id}) get_document failed: {err}"
                ));
                return None;
            }
        };
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

    /// PHP `inject('dbForPlatform')`. `None` in Memory mode, where there is
    /// no separate platform database to consult.
    #[must_use]
    pub fn platform_db(&self) -> Option<&ProjectDatabase> {
        self.platform.as_ref()
    }

    #[must_use]
    pub fn project_sequence(&self, project: &Value) -> Option<String> {
        match project.get("$sequence")? {
            Value::String(s) => Some(s.clone()),
            Value::Number(n) => Some(n.to_string()),
            _ => None,
        }
    }
}

#[must_use]
pub fn document_from_json(value: Value) -> Document {
    Document::try_from_json(value).unwrap_or_default()
}

#[must_use]
pub fn document_to_json(document: &Document) -> Value {
    Value::Object(document.get_array_copy_json(&[], &[]))
}

/// Live-adapter smoke test (Postgres). Other adapters use the same
/// [`AppwriteState::connect_from_env`] path; run against a real engine with
/// `_APP_DB_ADAPTER` set appropriately.
#[cfg(feature = "postgres")]
#[cfg(test)]
mod postgres_wiring_tests {
    use super::*;
    use utopia_database::constants::VAR_STRING;
    use utopia_database::helpers::{Id, Permission, Role};

    fn env_default(name: &str, default: &str) {
        // Workspace forbids `unsafe_code`, and Rust 1.97+ marks `set_var` unsafe.
        // Callers of this #[ignore]d test must export `_APP_DB_*` themselves.
        let _ = (name, default);
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

        appwrite_database::filters::register();

        let config = DatabaseConfig::from_env(AdapterKind::Postgres).expect("_APP_DB_HOST set");
        let mut platform =
            crate::db::new_platform_database(&config).expect("connect dbForPlatform for seeding");
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
        assert_eq!(key.role, appwrite_auth::ROLE_KEYS);
        assert!(!key.expired);
        assert!(key.scopes.iter().any(|s| s == "users.read"));

        let resolved_sequence = state
            .project_sequence(&resolved)
            .expect("resolved project should carry $sequence");
        assert_eq!(resolved_sequence, sequence);

        let db_project = state
            .databases
            .get_or_create(&project_id, Some(&resolved_sequence))
            .expect("dbForProject should connect");
        let mut db_project = db_project.lock();
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
            .expect("dbForProject(users) should be queryable");
        println!(
            "dbForProject(_{resolved_sequence}) users collection has {} document(s)",
            users.len()
        );

        let _ = platform.delete_document("keys", &key_doc.get_id());
        let _ = platform.delete_document("projects", &project_id);
    }
}
