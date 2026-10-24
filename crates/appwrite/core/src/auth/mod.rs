//! Authentication: API keys, sessions, JWTs, roles and scopes.

pub mod jwt;
pub mod roles;

use crate::datetime;
use chrono::NaiveDateTime;
use serde_json::Value;

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

/// The keys a request can carry beyond the project's: an account key's
/// user (`$user`) and the project's organization (`$team`), each with the
/// keys it owns.
#[derive(Default, Clone, Copy)]
pub struct Owners<'a> {
    pub account: Option<(&'a str, &'a [Key])>,
    pub organization: Option<(&'a str, &'a [Key])>,
}

impl ApiKey {
    /// A key a user or a team owns, as `Key::decode` builds it.
    fn owned(kind: KeyType, role: &str, stored: &Key, project_id: String, user_id: String, team_id: String) -> Self {
        Self {
            kind,
            role: role.to_owned(),
            scopes: stored.scopes.clone(),
            name: stored.name.clone(),
            project_id,
            user_id,
            team_id,
            expired: stored.expire.is_some_and(|e| e < datetime::now()),
            disabled_metrics: Vec::new(),
            stored: Some(stored.clone()),
        }
    }

    /// `Key::decode($project, $team, $user, $key)`. `owners` are the keys of
    /// the account key's user and of the project's organization, when the
    /// header is of those types. `None` where PHP throws `USER_UNAUTHORIZED`:
    /// an unknown secret or prefix, a JWT that fails to decode, or an
    /// ephemeral key of another project.
    pub fn decode(project: &Project, header: &str, jwt_key: &str, owners: &Owners<'_>) -> Option<Self> {
        let (kind, secret) = match header.split_once('_') {
            Some((prefix, secret)) => (KeyType::parse(prefix), secret),
            None => (KeyType::Standard, header),
        };
        match kind {
            KeyType::Ephemeral | KeyType::Dynamic => {
                let claims = jwt::decode(jwt_key, secret, 86_400).ok()?;
                let claim_str = |k: &str| claims.get(k).and_then(Value::as_str).unwrap_or("").to_owned();
                let project_check_disabled = matches!(claims.get("projectCheckDisabled"), Some(Value::Bool(true)));
                let project_id = claim_str("projectId");
                if !project_check_disabled && project_id != project.id {
                    return None;
                }
                // Keys issued before the sequence claim carry none; they
                // expire within a day.
                let sequence = match claims.get("projectSequence") {
                    None | Some(Value::Null) => None,
                    Some(Value::String(s)) => Some(s.clone()),
                    Some(other) => Some(other.to_string()),
                };
                if !project_check_disabled && sequence.is_some_and(|s| s != project.sequence) {
                    return None;
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
                Some(Self {
                    kind,
                    role: roles::KEYS.to_owned(),
                    scopes,
                    name,
                    project_id,
                    user_id: String::new(),
                    team_id: String::new(),
                    expired: false,
                    disabled_metrics,
                    stored: None,
                })
            }
            KeyType::Standard => {
                let stored = project.keys.iter().find(|k| k.secret == header)?;
                let now = datetime::now();
                let expired = stored.expire.map(|e| e < now).unwrap_or(false);
                let mut scopes = stored.scopes.clone();
                for s in roles::key_scopes() {
                    if !scopes.iter().any(|x| x == s) {
                        scopes.push((*s).to_owned());
                    }
                }
                Some(Self {
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
                })
            }
            // An account key: role `users`, its own scopes only.
            KeyType::Account => {
                let (user_id, stored) =
                    owners.account.and_then(|(id, keys)| keys.iter().find(|k| k.secret == header).map(|k| (id, k)))?;
                Some(Self::owned(kind, roles::USERS, stored, String::new(), user_id.to_owned(), String::new()))
            }
            // An organization key: role `keys`, its own scopes only.
            KeyType::Organization => {
                let (team_id, stored) = owners
                    .organization
                    .and_then(|(id, keys)| keys.iter().find(|k| k.secret == header).map(|k| (id, k)))?;
                Some(Self::owned(kind, roles::KEYS, stored, String::new(), String::new(), team_id.to_owned()))
            }
            KeyType::OAuth2 | KeyType::Unknown => None,
        }
    }
}

/// Verifies a session secret: `sha256(secret)` equals the stored hash and
/// the session has not expired. Returns the session id.
pub fn session_verify<'a>(sessions: &'a [Session], secret: &str) -> Option<&'a Session> {
    if secret.is_empty() {
        return None;
    }
    // `Proofs\Token` with the `Sha` hash (SHA-256 hex), as Appwrite stores session secrets.
    let Ok(hashed) = utopia_auth::Hash::hash(&utopia_auth::hashes::Sha::new(), secret.as_bytes()) else {
        return None;
    };
    let now = datetime::now();
    sessions.iter().find(|s| {
        s.provider.is_some()
            && s.secret.as_deref().map(|stored| utopia_auth::hash_equals(stored.as_bytes(), &hashed)).unwrap_or(false)
            && s.expire.map(|e| e >= now).unwrap_or(false)
    })
}

/// `User::sessionActive`.
pub fn session_active(user: &User, session_id: &str) -> bool {
    let now: NaiveDateTime = datetime::now();
    user.sessions.iter().any(|s| s.id == session_id && s.expire.map(|e| e >= now).unwrap_or(false))
}

#[cfg(test)]
mod tests {
    use serde_json::{Map, Value, json};
    use utopia_database::Document;

    use super::{ApiKey, KeyType, Owners, jwt};
    use crate::database::Project;

    const SECRET: &str = "test-openssl-key";

    fn project() -> Project {
        let mut document = Document::default();
        document.put("$id", utopia_database::Value::from("p1"));
        document.put("$sequence", utopia_database::Value::from("7"));
        Project::from(&document)
    }

    fn ephemeral(claims: Value) -> String {
        let Value::Object(claims) = claims else { unreachable!() };
        format!("ephemeral_{}", jwt::encode(SECRET, claims, 3600))
    }

    fn decode(header: &str) -> Option<ApiKey> {
        ApiKey::decode(&project(), header, SECRET, &Owners::default())
    }

    #[test]
    fn refuses_unknown_secrets_and_prefixes() {
        assert!(decode("standard_unknown").is_none());
        assert!(decode("unknown").is_none());
        assert!(decode("oauth2_whatever").is_none());
        assert!(decode("account_whatever").is_none());
        assert!(decode("organization_whatever").is_none());
    }

    #[test]
    fn refuses_an_ephemeral_key_that_fails_to_decode() {
        assert!(decode("ephemeral_not.a.jwt").is_none());
        let other = format!("ephemeral_{}", jwt::encode("other-key", Map::new(), 3600));
        assert!(decode(&other).is_none());
    }

    #[test]
    fn binds_ephemeral_keys_to_the_project_and_its_sequence() {
        assert!(decode(&ephemeral(json!({"projectId": "p2"}))).is_none());
        assert!(decode(&ephemeral(json!({"projectId": "p1", "projectSequence": 8}))).is_none());
        assert!(decode(&ephemeral(json!({"projectId": "p1", "projectSequence": "8"}))).is_none());

        let key = decode(&ephemeral(json!({"projectId": "p1", "projectSequence": 7}))).expect("same sequence");
        assert_eq!(key.kind, KeyType::Ephemeral);
        assert!(!key.expired);
        // Keys issued before the sequence claim carry none.
        assert!(decode(&ephemeral(json!({"projectId": "p1"}))).is_some());
        // With the project check off, neither is checked.
        let unchecked = json!({"projectId": "p2", "projectSequence": 8, "projectCheckDisabled": true});
        assert!(decode(&ephemeral(unchecked)).is_some());
    }
}
