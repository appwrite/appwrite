//! Projects (platform collection `projects`) with their keys, platforms and webhooks.
//!
//! A project is needed on every request. It is loaded with a single SQL
//! statement (sub-collections aggregated with `json_agg`) and cached in the
//! same Redis hash PHP uses for `projects/<id>`, under a Rust-specific field.
//! PHP purges therefore invalidate Rust entries and vice versa. A small
//! in-process map keyed by the hash generation avoids decoding the cached
//! bytes again while nothing changed.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use chrono::NaiveDateTime;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use utopia_database::{Database, Param, datetime, row};
use utopia_validators::php;

use crate::Result;
use crate::crypto;

/// A project API key (`keys` with `resourceType = projects`).
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Key {
    pub id: String,
    pub sequence: i64,
    pub name: String,
    pub scopes: Vec<String>,
    /// Decrypted secret (`standard_<hex>`).
    pub secret: String,
    pub expire: Option<NaiveDateTime>,
    pub accessed_at: Option<NaiveDateTime>,
    pub sdks: Vec<String>,
}

/// A client platform (only what CORS / origin checks need).
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Platform {
    pub kind: String,
    pub hostname: String,
    pub key: String,
}

/// A webhook (only what event fan-out needs).
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Webhook {
    pub enabled: bool,
    pub events: Vec<String>,
}

/// A project document.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Project {
    pub id: String,
    /// `$sequence` as PHP sees it (a string; `console` for the console project).
    pub sequence: String,
    pub name: String,
    pub database: String,
    pub region: String,
    pub team_id: Option<String>,
    pub team_internal_id: String,
    pub permissions: Vec<String>,
    pub created_at: Option<NaiveDateTime>,
    pub accessed_at: Option<NaiveDateTime>,
    pub services: Value,
    pub apis: Value,
    pub auths: Value,
    pub onboarding: Value,
    pub keys: Vec<Key>,
    pub platforms: Vec<Platform>,
    pub webhooks: Vec<Webhook>,
}

impl Project {
    pub const CONSOLE: &'static str = "console";

    pub fn is_console(&self) -> bool {
        self.id == Self::CONSOLE
    }

    pub fn sequence_i64(&self) -> i64 {
        self.sequence.parse().unwrap_or(0)
    }

    /// The console project (`app/config/console.php`).
    pub fn console(region: &str) -> Self {
        let enabled = |name: &str, default: &str| utopia_system::env_or(name, default) == "enabled";
        let mut auths = Map::new();
        for key in [
            "membershipsUserName",
            "membershipsUserEmail",
            "membershipsMfa",
            "membershipsUserId",
            "membershipsUserPhone",
            "membershipsUserAccessedAt",
        ] {
            auths.insert(key.into(), Value::Bool(true));
        }
        auths.insert("mockNumbers".into(), Value::Array(Vec::new()));
        auths.insert("invites".into(), Value::Bool(enabled("_APP_CONSOLE_INVITES", "enabled")));
        auths
            .insert("limit".into(), Value::from(if enabled("_APP_CONSOLE_WHITELIST_ROOT", "enabled") { 1 } else { 0 }));
        auths.insert("duration".into(), Value::from(31_536_000));
        auths.insert("sessionAlerts".into(), Value::Bool(enabled("_APP_CONSOLE_SESSION_ALERTS", "disabled")));
        for key in ["disposableEmails", "canonicalEmails", "freeEmails", "corporateEmails"] {
            auths.insert(key.into(), Value::Bool(false));
        }
        auths.insert("invalidateSessions".into(), Value::Bool(true));
        Self {
            id: Self::CONSOLE.into(),
            sequence: Self::CONSOLE.into(),
            name: "Appwrite".into(),
            database: String::new(),
            region: region.to_owned(),
            team_id: None,
            team_internal_id: String::new(),
            permissions: Vec::new(),
            created_at: None,
            accessed_at: None,
            services: Value::Array(Vec::new()),
            apis: Value::Array(Vec::new()),
            auths: Value::Object(auths),
            onboarding: Value::Array(Vec::new()),
            keys: Vec::new(),
            platforms: vec![Platform { kind: "web".into(), hostname: "localhost".into(), key: String::new() }],
            webhooks: Vec::new(),
        }
    }

    /// `auths[key]`.
    pub fn auth(&self, key: &str) -> Option<&Value> {
        self.auths.get(key)
    }

    /// `auths[key] ?? default` cast to bool.
    pub fn auth_bool(&self, key: &str, default: bool) -> bool {
        match self.auth(key) {
            Some(Value::Null) | None => default,
            Some(v) => php::truthy(v),
        }
    }

    /// `auths[key] ?? default` as an integer.
    pub fn auth_int(&self, key: &str, default: i64) -> i64 {
        match self.auth(key) {
            Some(Value::Number(n)) => n.as_i64().unwrap_or(default),
            Some(Value::String(s)) => s.parse().unwrap_or(default),
            Some(Value::Bool(b)) => i64::from(*b),
            _ => default,
        }
    }

    /// Whether `services[namespace]` is present and falsy.
    pub fn service_disabled(&self, namespace: &str) -> bool {
        match self.services.get(namespace) {
            Some(v) => !php::truthy(v),
            None => false,
        }
    }

    /// Whether `apis[api]` is present and falsy.
    pub fn api_disabled(&self, api: &str) -> bool {
        match self.apis.get(api) {
            Some(v) => !php::truthy(v),
            None => false,
        }
    }

    /// The empty document PHP returns for an unknown project.
    pub fn empty() -> Self {
        let mut p = Self::console("default");
        p.id = String::new();
        p.sequence = String::new();
        p.name = String::new();
        p.platforms = Vec::new();
        p.auths = Value::Array(Vec::new());
        p
    }

    /// Roles granted `read` on the project document.
    pub fn read_roles(&self) -> Vec<String> {
        utopia_database::permission::roles_for(&self.permissions, "read").collect()
    }

    /// Payload used in queue messages (`$id`, `$sequence`, `database`, ...).
    pub fn message_payload(&self) -> Value {
        let mut m = Map::new();
        m.insert("$id".into(), Value::String(self.id.clone()));
        m.insert("$sequence".into(), Value::String(self.sequence.clone()));
        m.insert("database".into(), Value::String(self.database.clone()));
        m.insert("teamId".into(), self.team_id.clone().map(Value::String).unwrap_or(Value::Null));
        m.insert("teamInternalId".into(), Value::String(self.team_internal_id.clone()));
        m.insert(
            "$createdAt".into(),
            self.created_at.map(|d| Value::String(datetime::format_tz(&d))).unwrap_or(Value::Null),
        );
        m.insert("region".into(), Value::String(self.region.clone()));
        Value::Object(m)
    }

    /// Trimmed project for webhook/usage messages.
    pub fn trimmed_payload(&self) -> Value {
        let mut m = Map::new();
        m.insert("$id".into(), Value::String(self.id.clone()));
        m.insert("$sequence".into(), Value::String(self.sequence.clone()));
        m.insert("database".into(), Value::String(self.database.clone()));
        Value::Object(m)
    }
}

fn json_datetime(v: Option<&Value>) -> Option<NaiveDateTime> {
    v.and_then(Value::as_str).and_then(datetime::parse)
}

fn json_strings(v: Option<&Value>) -> Vec<String> {
    match v {
        Some(Value::Array(a)) => a.iter().filter_map(|x| x.as_str().map(str::to_owned)).collect(),
        _ => Vec::new(),
    }
}

/// Loads a project with keys, platforms and webhooks in one statement.
pub async fn load(platform: &Database, id: &str) -> Result<Option<Project>> {
    let mut b = utopia_database::sql::Builder::new();
    let p = b.bind(Param::text(id));
    let sql = format!(
        r#"SELECT p."_id", p."_uid", p."_createdAt", p."_permissions", p."name", p."database", p."region",
  p."teamId", p."teamInternalId", p."accessedAt", p."services", p."apis", p."auths", p."onboarding",
  (SELECT COALESCE(json_agg(json_build_object('id', k."_uid", 'sequence', k."_id", 'name', k."name",
      'scopes', k."scopes", 'secret', k."secret", 'expire', k."expire", 'accessedAt', k."accessedAt",
      'sdks', k."sdks") ORDER BY k."_id"), '[]'::json)
     FROM {keys} k WHERE k."resourceType" = 'projects' AND k."resourceInternalId" = p."_id"::text) AS "keys",
  (SELECT COALESCE(json_agg(json_build_object('type', pl."type", 'hostname', pl."hostname", 'key', pl."key")
      ORDER BY pl."_id"), '[]'::json)
     FROM {platforms} pl WHERE pl."projectInternalId" = p."_id"::text) AS "platforms",
  (SELECT COALESCE(json_agg(json_build_object('enabled', w."enabled", 'events', w."events") ORDER BY w."_id"), '[]'::json)
     FROM {webhooks} w WHERE w."projectInternalId" = p."_id"::text) AS "webhooks"
FROM {projects} p WHERE p."_uid" = {p}"#,
        keys = platform.table("keys"),
        platforms = platform.table("platforms"),
        webhooks = platform.table("webhooks"),
        projects = platform.table("projects"),
    );
    let Some(r) = platform.query_opt(&sql, &b).await? else {
        return Ok(None);
    };
    let keys = match row::jsonb(&r, "keys")? {
        Some(Value::Array(items)) => items
            .iter()
            .map(|k| Key {
                id: k.get("id").and_then(Value::as_str).unwrap_or_default().to_owned(),
                sequence: k.get("sequence").and_then(Value::as_i64).unwrap_or(0),
                name: k.get("name").and_then(Value::as_str).unwrap_or_default().to_owned(),
                scopes: json_strings(k.get("scopes")),
                secret: k.get("secret").and_then(Value::as_str).and_then(crypto::decrypt_env).unwrap_or_default(),
                expire: json_datetime(k.get("expire")),
                accessed_at: json_datetime(k.get("accessedAt")),
                sdks: json_strings(k.get("sdks")),
            })
            .collect(),
        _ => Vec::new(),
    };
    let platforms = match row::jsonb(&r, "platforms")? {
        Some(Value::Array(items)) => items
            .iter()
            .map(|p| {
                let s = |k: &str| p.get(k).and_then(Value::as_str).unwrap_or_default().to_owned();
                Platform { kind: map_platform_type(&s("type")), hostname: s("hostname"), key: s("key") }
            })
            .collect(),
        _ => Vec::new(),
    };
    let webhooks = match row::jsonb(&r, "webhooks")? {
        Some(Value::Array(items)) => items
            .iter()
            .map(|w| Webhook {
                enabled: matches!(w.get("enabled"), Some(Value::Bool(true))),
                events: json_strings(w.get("events")),
            })
            .collect(),
        _ => Vec::new(),
    };
    let json_or_empty = |c: &str| -> Result<Value> { Ok(row::json_text(&r, c)?.unwrap_or(Value::Array(Vec::new()))) };
    Ok(Some(Project {
        id: row::uid(&r)?,
        sequence: row::sequence(&r)?.to_string(),
        name: row::string_or_empty(&r, "name")?,
        database: row::string_or_empty(&r, "database")?,
        region: row::string(&r, "region")?.unwrap_or_else(|| "default".to_owned()),
        team_id: row::string(&r, "teamId")?,
        team_internal_id: row::string_or_empty(&r, "teamInternalId")?,
        permissions: row::strings(&r, "_permissions")?,
        created_at: row::datetime(&r, "_createdAt")?,
        accessed_at: row::datetime(&r, "accessedAt")?,
        services: json_or_empty("services")?,
        apis: json_or_empty("apis")?,
        auths: json_or_empty("auths")?,
        onboarding: json_or_empty("onboarding")?,
        keys,
        platforms,
        webhooks,
    }))
}

/// `Platform::mapDeprecatedType`.
fn map_platform_type(kind: &str) -> String {
    match kind {
        "flutter-web" | "unity" => "web",
        "flutter-ios" | "flutter-macos" | "apple-ios" | "apple-macos" | "apple-watchos" | "apple-tvos"
        | "react-native-ios" => "apple",
        "flutter-android" | "react-native-android" => "android",
        "flutter-windows" => "windows",
        "flutter-linux" => "linux",
        other => other,
    }
    .to_owned()
}

const CACHE_FIELD: &str = "rust:project:v1";
const CACHE_TTL_SECS: u64 = 86_400;
const L1_MAX_AGE: Duration = Duration::from_secs(30);
const L1_MAX_ENTRIES: usize = 10_000;

/// In-process entry: generation seen, load time, project.
type L1Entry = (String, Instant, Arc<Project>);

/// Project lookups with Redis + in-process caching.
pub struct ProjectStore {
    l1: Mutex<HashMap<String, L1Entry>>,
    console: Arc<Project>,
}

impl ProjectStore {
    pub fn new(region: &str) -> Self {
        Self { l1: Mutex::new(HashMap::new()), console: Arc::new(Project::console(region)) }
    }

    pub fn console(&self) -> Arc<Project> {
        self.console.clone()
    }

    /// Fetches a project by id (`None` when it does not exist).
    pub async fn get(&self, platform: &Database, id: &str) -> Result<Option<Arc<Project>>> {
        if id.is_empty() || id == Project::CONSOLE {
            return Ok(Some(self.console.clone()));
        }
        let Some(cache) = platform.cache() else {
            return Ok(load(platform, id).await?.map(Arc::new));
        };
        let (collection_key, document_key) = platform.cache_keys("projects", id);
        let lookup = match cache.lookup(&document_key, CACHE_FIELD, CACHE_TTL_SECS).await {
            Ok(l) => Some(l),
            Err(e) => {
                tracing::warn!(error = %e, "project cache lookup failed");
                None
            }
        };
        if let Some(lookup) = &lookup
            && let Some(bytes) = &lookup.value
        {
            if let Some(hit) = self.l1_get(id, &lookup.generation) {
                return Ok(Some(hit));
            }
            if let Ok(project) = serde_json::from_slice::<Project>(bytes) {
                let project = Arc::new(project);
                self.l1_put(id, &lookup.generation, project.clone());
                return Ok(Some(project));
            }
        }
        let Some(project) = load(platform, id).await? else {
            return Ok(None);
        };
        let project = Arc::new(project);
        if let Some(lookup) = lookup
            && let Ok(bytes) = serde_json::to_vec(&*project)
        {
            match cache.save_with_lease(&document_key, CACHE_FIELD, &bytes, &lookup.generation).await {
                Ok(true) => {
                    let _ = cache.save(&collection_key, &document_key, b"empty").await;
                    self.l1_put(id, &lookup.generation, project.clone());
                }
                Ok(false) => {}
                Err(e) => tracing::warn!(error = %e, "project cache save failed"),
            }
        }
        Ok(Some(project))
    }

    fn l1_get(&self, id: &str, generation: &str) -> Option<Arc<Project>> {
        let l1 = self.l1.lock().ok()?;
        let (generation_seen, at, project) = l1.get(id)?;
        if generation_seen == generation && at.elapsed() < L1_MAX_AGE { Some(project.clone()) } else { None }
    }

    fn l1_put(&self, id: &str, generation: &str, project: Arc<Project>) {
        if let Ok(mut l1) = self.l1.lock() {
            if l1.len() >= L1_MAX_ENTRIES {
                l1.clear();
            }
            l1.insert(id.to_owned(), (generation.to_owned(), Instant::now(), project));
        }
    }

    /// Drops the in-process entry (after Rust writes the project).
    pub fn forget(&self, id: &str) {
        if let Ok(mut l1) = self.l1.lock() {
            l1.remove(id);
        }
    }
}
