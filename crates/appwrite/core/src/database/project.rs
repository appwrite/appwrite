//! Projects (platform collection `projects`) with their keys, platforms and webhooks.
//!
//! A project is needed on every request. It is read as PHP reads it
//! (`getDocument('projects', $id)` with authorization skipped; the
//! `subQueryKeys`, `subQueryPlatforms` and `subQueryWebhooks` filters load
//! its keys, platforms and webhooks), so it comes from the document cache
//! PHP shares. A small in-process map keyed by the cached document's
//! generation skips reading and decoding that entry while nothing changed.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use chrono::NaiveDateTime;
use serde_json::{Map, Value};
use utopia_database::{Database, Document, PermissionType};
use utopia_validators::php;

use super::documents::Attributes;
use crate::Result;
use crate::datetime;

/// A project API key (`keys` with `resourceType = projects`).
#[derive(Debug, Clone)]
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

impl From<&Document> for Key {
    fn from(k: &Document) -> Self {
        Key {
            id: k.id().to_owned(),
            sequence: k.sequence_i64(),
            name: k.string_or_empty("name"),
            scopes: k.strings("scopes"),
            secret: k.string_or_empty("secret"),
            expire: k.datetime("expire"),
            accessed_at: k.datetime("accessedAt"),
            sdks: k.strings("sdks"),
        }
    }
}

/// The keys a user (account keys) or a team (organization keys) owns: the
/// `subQueryAccountKeys` / `subQueryOrganizationKeys` filters' query, with
/// authorization skipped. `resource_type` is `users` or `teams`.
pub async fn owned_keys(platform: &Database, resource_type: &str, internal_id: &str) -> Result<Vec<Key>> {
    let authorization = platform.authorization();
    let documents = authorization
        .skip(platform.find(
            "keys",
            vec![
                utopia_database::Query::equal("resourceType", [resource_type]),
                utopia_database::Query::equal("resourceInternalId", [internal_id]),
                utopia_database::Query::limit(super::filters::LIMIT_SUBQUERY),
            ],
            PermissionType::Read,
        ))
        .await?;
    Ok(documents.iter().map(Key::from).collect())
}

/// A client platform (only what CORS / origin checks need).
#[derive(Debug, Clone)]
pub struct Platform {
    pub kind: String,
    pub hostname: String,
    pub key: String,
    /// Android signing certificate fingerprints (passkey origins).
    pub fingerprints: Vec<String>,
}

/// A webhook (only what event fan-out needs).
#[derive(Debug, Clone)]
pub struct Webhook {
    pub enabled: bool,
    pub events: Vec<String>,
}

/// A project document.
#[derive(Debug, Clone)]
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
    /// `oAuthProviders` (`<provider>Enabled`, `<provider>Appid`, ...).
    pub oauth_providers: Value,
    /// `url`.
    pub url: String,
    /// `authWhitelistEmails` and `authWhitelistIPs` (console sign-up).
    pub auth_whitelist_emails: Vec<String>,
    pub auth_whitelist_ips: Vec<String>,
    /// The custom SMTP server (`smtp`), `[]` when unset.
    pub smtp: Value,
    /// Custom email and SMS templates (`templates`), `[]` when unset.
    pub templates: Value,
    /// `smtpBaseTemplate`.
    pub smtp_base_template: Option<String>,
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
        // `PasskeyConsole::getAuths($platform['consoleUrl'], _APP_CONSOLE_PASSKEY_ORIGINS)`.
        let domain = utopia_system::env_or("_APP_DOMAIN", "localhost").into_owned();
        let console_domain = utopia_system::env_or("_APP_CONSOLE_DOMAIN", &domain).into_owned();
        let scheme = if utopia_system::env("_APP_OPTIONS_FORCE_HTTPS").as_deref() == Some("disabled") {
            "http"
        } else {
            "https"
        };
        let console_url = utopia_system::env_or("_APP_CONSOLE_URL", &format!("{scheme}://{console_domain}"))
            .trim_end_matches('/')
            .to_owned();
        let (passkey, rp_id, origins) =
            console_passkeys(&console_url, &utopia_system::env_or("_APP_CONSOLE_PASSKEY_ORIGINS", ""));
        auths.insert("passkey".into(), Value::Bool(passkey));
        auths.insert("passkeyRpId".into(), Value::String(rp_id));
        auths.insert("passkeyOrigins".into(), Value::Array(origins.into_iter().map(Value::String).collect()));
        let split = |name: &str| -> Vec<String> {
            utopia_system::env(name).map(|v| v.split(',').map(str::to_owned).collect()).unwrap_or_default()
        };
        let mut oauth = Map::new();
        for (provider, secret, app) in [
            ("github", "_APP_CONSOLE_GITHUB_SECRET", "_APP_CONSOLE_GITHUB_APP_ID"),
            ("gitlab", "_APP_CONSOLE_GITLAB_SECRET", "_APP_CONSOLE_GITLAB_APP_ID"),
            ("bitbucket", "_APP_CONSOLE_BITBUCKET_SECRET", "_APP_CONSOLE_BITBUCKET_APP_ID"),
            ("google", "_APP_CONSOLE_GOOGLE_SECRET", "_APP_CONSOLE_GOOGLE_APP_ID"),
        ] {
            let mut secret = utopia_system::env_or(secret, "").into_owned();
            if provider == "gitlab" && !secret.is_empty() {
                let mut gitlab = Map::new();
                gitlab.insert("clientSecret".into(), Value::String(secret));
                gitlab.insert(
                    "endpoint".into(),
                    Value::String(
                        utopia_system::env_or("_APP_CONSOLE_GITLAB_ENDPOINT", "https://gitlab.com").into_owned(),
                    ),
                );
                secret = crate::json::to_string(&Value::Object(gitlab));
            }
            oauth.insert(format!("{provider}Enabled"), Value::Bool(true));
            oauth.insert(format!("{provider}Secret"), Value::String(secret));
            oauth.insert(format!("{provider}Appid"), Value::String(utopia_system::env_or(app, "").into_owned()));
        }
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
            platforms: vec![Platform {
                kind: "web".into(),
                hostname: "localhost".into(),
                key: String::new(),
                fingerprints: Vec::new(),
            }],
            webhooks: Vec::new(),
            oauth_providers: Value::Object(oauth),
            url: String::new(),
            auth_whitelist_emails: split("_APP_CONSOLE_WHITELIST_EMAILS"),
            auth_whitelist_ips: split("_APP_CONSOLE_WHITELIST_IPS"),
            smtp: Value::Array(Vec::new()),
            templates: Value::Array(Vec::new()),
            smtp_base_template: None,
        }
    }

    /// `oAuthProviders[key]` as a string (`''` when unset).
    pub fn oauth_string(&self, key: &str) -> String {
        match self.oauth_providers.get(key) {
            Some(Value::String(s)) => s.clone(),
            Some(v) => php::to_string(v).unwrap_or_default(),
            None => String::new(),
        }
    }

    /// `oAuthProviders[key] ?? false`, truthy.
    pub fn oauth_flag(&self, key: &str) -> bool {
        self.oauth_providers.get(key).is_some_and(php::truthy)
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

    /// Roles granted `read` on the project document (`getRead()`).
    pub fn read_roles(&self) -> Vec<String> {
        let mut document = Document::default();
        let _ = document.set(
            "$permissions",
            utopia_database::value::list(self.permissions.iter().map(|p| utopia_database::Value::from(p.as_str()))),
        );
        document.permissions_by_type(PermissionType::Read).unwrap_or_default()
    }

    /// The project in queue messages (PHP's `ProjectContext::toArray()`):
    /// its main fields, the empty ones left out.
    pub fn message_payload(&self) -> Value {
        let created = self.created_at.map(|d| datetime::format_tz(&d)).unwrap_or_default();
        let fields = [
            ("$id", self.id.as_str()),
            ("$sequence", self.sequence.as_str()),
            ("teamId", self.team_id.as_deref().unwrap_or("")),
            ("teamInternalId", self.team_internal_id.as_str()),
            ("$createdAt", created.as_str()),
            ("region", self.region.as_str()),
            ("database", self.database.as_str()),
        ];
        Value::Object(
            fields
                .into_iter()
                .filter(|(_, v)| !v.is_empty())
                .map(|(k, v)| (k.to_owned(), Value::String(v.to_owned())))
                .collect(),
        )
    }

    /// The project in usage messages.
    pub fn trimmed_payload(&self) -> Value {
        let mut m = Map::new();
        m.insert("$id".into(), Value::String(self.id.clone()));
        m.insert("$sequence".into(), Value::String(self.sequence.clone()));
        m.insert("database".into(), Value::String(self.database.clone()));
        Value::Object(m)
    }
}

impl From<&Document> for Project {
    fn from(d: &Document) -> Self {
        let json_or_empty = |key: &str| d.json(key).unwrap_or(Value::Array(Vec::new()));
        Project {
            id: d.id().to_owned(),
            sequence: super::documents::sequence_text(d),
            name: d.string_or_empty("name"),
            database: d.string_or_empty("database"),
            region: d.string("region").unwrap_or_else(|| "default".to_owned()),
            team_id: d.string("teamId"),
            team_internal_id: d.string_or_empty("teamInternalId"),
            permissions: d.permissions().unwrap_or_default(),
            created_at: d.datetime("$createdAt"),
            accessed_at: d.datetime("accessedAt"),
            services: json_or_empty("services"),
            apis: json_or_empty("apis"),
            auths: json_or_empty("auths"),
            onboarding: json_or_empty("onboarding"),
            keys: d.nested("keys").into_iter().map(Key::from).collect(),
            platforms: d
                .nested("platforms")
                .into_iter()
                .map(|p| Platform {
                    kind: p.string_or_empty("type"),
                    hostname: p.string_or_empty("hostname"),
                    key: p.string_or_empty("key"),
                    fingerprints: p.strings("fingerprints"),
                })
                .collect(),
            webhooks: d
                .nested("webhooks")
                .into_iter()
                .map(|w| Webhook { enabled: w.boolean("enabled") == Some(true), events: w.strings("events") })
                .collect(),
            oauth_providers: json_or_empty("oAuthProviders"),
            url: d.string_or_empty("url"),
            auth_whitelist_emails: d.strings("authWhitelistEmails"),
            auth_whitelist_ips: d.strings("authWhitelistIPs"),
            smtp: json_or_empty("smtp"),
            templates: json_or_empty("templates"),
            smtp_base_template: d.string("smtpBaseTemplate"),
        }
    }
}

/// `Appwrite\Auth\Passkey\Console::getAuths($url, $origins)`: whether the
/// console signs in with passkeys, its relying party ID and its origins.
fn console_passkeys(url: &str, origins: &str) -> (bool, String, Vec<String>) {
    let rp_id = crate::network::url_host(url).unwrap_or_default();
    let mut normalized: Vec<String> = Vec::new();
    use utopia_validators::Validator as _;
    let valid =
        rp_id == "localhost" || utopia_domains::validator::RegistrableDomain.is_valid(&Value::String(rp_id.clone()));
    if valid {
        let origin = utopia_auth::passkeys::Origin::new(rp_id.clone());
        for candidate in std::iter::once(url).chain(origins.split(',')) {
            if let Some(value) = origin.normalize(candidate.trim().as_bytes())
                && !normalized.contains(&value)
            {
                normalized.push(value);
            }
        }
    }
    let rp_id = if normalized.is_empty() { String::new() } else { rp_id };
    (!normalized.is_empty(), rp_id, normalized)
}

/// `$authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId))`.
pub async fn load(platform: &Database, id: &str) -> Result<Option<Project>> {
    let authorization = platform.authorization();
    let document = authorization.skip(platform.get_document("projects", id, Vec::new(), false)).await?;
    Ok(if document.is_empty() { None } else { Some(Project::from(&document)) })
}

/// `Platform::mapDeprecatedType`.
pub fn map_platform_type(kind: &str) -> String {
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

const L1_MAX_AGE: Duration = Duration::from_secs(30);
const L1_MAX_ENTRIES: usize = 10_000;

/// In-process entry: generation seen, load time, project.
type L1Entry = (String, Instant, Arc<Project>);

/// Project lookups through the document cache, with an in-process map.
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
        let (_, document_key) = platform.cache_base_keys("projects", Some(id)).await?;
        // The generation is read before the document: a purge in between
        // changes it, so the copy kept below is never taken for current.
        let generation = match platform.cache().generation(&document_key).await {
            Ok(generation) => Some(generation),
            Err(e) => {
                tracing::warn!(error = %e, "project cache lookup failed");
                None
            }
        };
        if let Some(generation) = &generation
            && let Some(hit) = self.l1_get(id, generation)
        {
            return Ok(Some(hit));
        }
        let Some(project) = load(platform, id).await? else {
            return Ok(None);
        };
        let project = Arc::new(project);
        if let Some(generation) = generation {
            self.l1_put(id, &generation, project.clone());
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_a_project_document() {
        let mut key = Document::default();
        key.put("$id", "key".into());
        key.put("$sequence", "7".into());
        key.put("secret", "standard_00".into());
        key.put("scopes", utopia_database::value::list(["users.read".into()]));
        let mut platform = Document::default();
        platform.put("type", "flutter-ios".into());
        platform.put("hostname", "example.com".into());
        let mut project = Document::default();
        project.put("$id", "p1".into());
        project.put("$sequence", "42".into());
        project.put("$createdAt", "2025-03-04T10:11:12.345+00:00".into());
        project.set("$permissions", utopia_database::value::list([r#"read("team:t")"#.into()])).unwrap();
        project.put("keys", utopia_database::value::list([key.into()]));
        project.put("platforms", utopia_database::value::list([platform.into()]));
        let p = Project::from(&project);
        assert_eq!(p.sequence, "42");
        assert_eq!(p.keys[0].sequence, 7);
        assert_eq!(p.keys[0].scopes, vec!["users.read"]);
        assert_eq!(p.platforms[0].kind, "flutter-ios");
        assert_eq!(p.created_at, datetime::parse("2025-03-04 10:11:12.345"));
        assert_eq!(p.read_roles(), vec!["team:t"]);
        assert_eq!(p.auths, Value::Array(Vec::new()));
    }
}
