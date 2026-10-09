//! Authentication: API keys, sessions, JWTs, roles and scopes.

pub mod jwt;
pub mod roles;

use chrono::NaiveDateTime;
use serde_json::Value;
use utopia_database::datetime;

use crate::database::documents::{Session, User};
use crate::database::project::{Key, Project};

/// API key types (`API_KEY_*`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum KeyType {
    Standard,
    Ephemeral,
    Dynamic,
    Organization,
    Account,
    OAuth2,
    Unknown,
}

impl KeyType {
    fn parse(prefix: &str) -> Self {
        match prefix {
            "standard" => KeyType::Standard,
            "ephemeral" => KeyType::Ephemeral,
            "dynamic" => KeyType::Dynamic,
            "organization" => KeyType::Organization,
            "account" => KeyType::Account,
            "oauth2" => KeyType::OAuth2,
            _ => KeyType::Unknown,
        }
    }
}

/// A decoded `X-Appwrite-Key` (`Appwrite\Auth\Key`).
#[derive(Debug, Clone)]
pub struct ApiKey {
    pub kind: KeyType,
    pub role: String,
    pub scopes: Vec<String>,
    pub name: String,
    pub project_id: String,
    pub user_id: String,
    pub team_id: String,
    pub expired: bool,
    pub disabled_metrics: Vec<String>,
    /// The matching stored project key (standard keys).
    pub stored: Option<Key>,
}

impl ApiKey {
    fn guest(project: &Project, kind: KeyType) -> Self {
        Self {
            kind,
            role: roles::GUESTS.to_owned(),
            scopes: roles::scopes(roles::GUESTS).into_iter().map(str::to_owned).collect(),
            name: "UNKNOWN".to_owned(),
            project_id: project.id.clone(),
            user_id: String::new(),
            team_id: String::new(),
            expired: false,
            disabled_metrics: Vec::new(),
            stored: None,
        }
    }

    /// `Key::decode($project, $team, $user, $key)` for project-scoped key types.
    pub fn decode(project: &Project, header: &str, jwt_key: &str) -> Self {
        let (kind, secret) = match header.split_once('_') {
            Some((prefix, secret)) => (KeyType::parse(prefix), secret),
            None => (KeyType::Standard, header),
        };
        match kind {
            KeyType::Ephemeral | KeyType::Dynamic => {
                let (claims, expired) = match jwt::decode(jwt_key, secret, 86_400) {
                    Ok(c) => (c, false),
                    Err(_) => (Default::default(), true),
                };
                let claim_str = |k: &str| claims.get(k).and_then(Value::as_str).unwrap_or("").to_owned();
                let project_check_disabled = matches!(claims.get("projectCheckDisabled"), Some(Value::Bool(true)));
                // As in PHP, the check also runs for a token that failed to
                // decode: its claims are empty, so it becomes a guest key.
                let project_id = claim_str("projectId");
                if !project_check_disabled && project_id != project.id {
                    return Self::guest(project, kind);
                }
                let mut scopes: Vec<String> = claims
                    .get("scopes")
                    .and_then(Value::as_array)
                    .map(|a| a.iter().filter_map(|s| s.as_str().map(str::to_owned)).collect())
                    .unwrap_or_default();
                for s in roles::key_scopes() {
                    if !scopes.iter().any(|x| x == s) {
                        scopes.push((*s).to_owned());
                    }
                }
                let name = claims.get("name").and_then(Value::as_str).unwrap_or("Ephemeral Key").to_owned();
                let disabled_metrics = claims
                    .get("disabledMetrics")
                    .and_then(Value::as_array)
                    .map(|a| a.iter().filter_map(|s| s.as_str().map(str::to_owned)).collect())
                    .unwrap_or_default();
                Self {
                    kind,
                    role: roles::KEYS.to_owned(),
                    scopes,
                    name,
                    project_id,
                    user_id: String::new(),
                    team_id: String::new(),
                    expired,
                    disabled_metrics,
                    stored: None,
                }
            }
            KeyType::Standard => {
                let Some(stored) = project.keys.iter().find(|k| k.secret == header) else {
                    return Self::guest(project, kind);
                };
                let now = datetime::now();
                let expired = stored.expire.map(|e| e < now).unwrap_or(false);
                let mut scopes = stored.scopes.clone();
                for s in roles::key_scopes() {
                    if !scopes.iter().any(|x| x == s) {
                        scopes.push((*s).to_owned());
                    }
                }
                Self {
                    kind,
                    role: roles::KEYS.to_owned(),
                    scopes,
                    name: stored.name.clone(),
                    project_id: project.id.clone(),
                    user_id: String::new(),
                    team_id: String::new(),
                    expired,
                    disabled_metrics: Vec::new(),
                    stored: Some(stored.clone()),
                }
            }
            // Organization and account keys are resolved by the PHP API; the
            // Rust services treat them like unknown keys (guest scopes).
            other => Self::guest(project, other),
        }
    }
}

/// Verifies a session secret: `sha256(secret)` equals the stored hash and
/// the session has not expired. Returns the session id.
pub fn session_verify<'a>(sessions: &'a [Session], secret: &str) -> Option<&'a Session> {
    if secret.is_empty() {
        return None;
    }
    let hashed = utopia_auth::proofs::sha256(secret);
    let now = datetime::now();
    sessions.iter().find(|s| {
        s.provider.is_some()
            && s.secret.as_deref().map(|stored| utopia_auth::hash_equals(stored, &hashed)).unwrap_or(false)
            && s.expire.map(|e| e >= now).unwrap_or(false)
    })
}

/// `User::sessionActive`.
pub fn session_active(user: &User, session_id: &str) -> bool {
    let now: NaiveDateTime = datetime::now();
    user.sessions.iter().any(|s| s.id == session_id && s.expire.map(|e| e >= now).unwrap_or(false))
}
