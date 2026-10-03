//! API key decoding. Rust port of `Appwrite\Auth\Key::decode()`
//! (`src/Appwrite/Auth/Key.php`), scoped to the `API_KEY_STANDARD` case.

use chrono::Utc;
use serde_json::Value;

/// PHP `API_KEY_STANDARD` (`app/init/constants.php`).
pub const TYPE_STANDARD: &str = "standard";
/// PHP `Appwrite\Utopia\Database\Documents\User::ROLE_KEYS`.
pub const ROLE_KEYS: &str = "keys";
/// PHP `Appwrite\Utopia\Database\Documents\User::ROLE_GUESTS`.
pub const ROLE_GUESTS: &str = "guests";

/// A decoded API key. Rust port of `Appwrite\Auth\Key`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Key {
    pub project_id: String,
    pub scopes: Vec<String>,
    pub name: String,
    pub key_type: String,
    pub expired: bool,
    pub role: String,
}

impl Key {
    /// PHP `Key::decode()`'s guest fallback: an unrecognized or missing key
    /// still returns a `Key`, just scoped to the guest role with no scopes.
    #[must_use]
    pub fn guest(project_id: impl Into<String>) -> Self {
        Self {
            project_id: project_id.into(),
            scopes: Vec::new(),
            name: "UNKNOWN".to_string(),
            key_type: TYPE_STANDARD.to_string(),
            expired: false,
            role: ROLE_GUESTS.to_string(),
        }
    }

    /// PHP `Key::decode()` for the `API_KEY_STANDARD` case: looks up `secret`
    /// in the project document's `keys` array (each entry shaped like
    /// `{ "secret": ..., "scopes": [...], "name": ..., "expire": <ISO 8601> }`)
    /// and returns the matching key, or [`Self::guest`] when not found.
    ///
    /// `project` is the raw project document JSON (as returned by
    /// `utopia-database`), matching PHP's `Document $project` parameter.
    #[must_use]
    pub fn decode_standard(project: &Value, secret: &str) -> Self {
        let project_id = project
            .get("$id")
            .and_then(Value::as_str)
            .unwrap_or_default()
            .to_string();

        let Some(found) = project
            .get("keys")
            .and_then(Value::as_array)
            .and_then(|keys| {
                keys.iter()
                    .find(|key| key.get("secret").and_then(Value::as_str) == Some(secret))
            })
        else {
            return Self::guest(project_id);
        };

        let name = found
            .get("name")
            .and_then(Value::as_str)
            .unwrap_or("UNKNOWN")
            .to_string();

        let scopes = found
            .get("scopes")
            .and_then(Value::as_array)
            .map(|values| {
                values
                    .iter()
                    .filter_map(|value| value.as_str().map(str::to_string))
                    .collect()
            })
            .unwrap_or_default();

        let expired = found
            .get("expire")
            .and_then(Value::as_str)
            .is_some_and(is_expired);

        Self {
            project_id,
            scopes,
            name,
            key_type: TYPE_STANDARD.to_string(),
            expired,
            role: ROLE_KEYS.to_string(),
        }
    }
}

/// PHP `!empty($expire) && $expire < DateTime::formatTz(DateTime::now())`.
///
/// Both timestamps are formatted as fixed-width ISO 8601 UTC strings
/// (`Utopia\Database\DateTime::formatTz`), so a plain string comparison is
/// equivalent to a chronological one and avoids a parse round-trip.
fn is_expired(expire: &str) -> bool {
    if expire.is_empty() {
        return false;
    }
    let now = Utc::now().format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string();
    expire < now.as_str()
}
