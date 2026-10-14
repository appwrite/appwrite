//! Typed views of the auth-related documents.
//!
//! Each type is read from a [`Document`] as the database returns it:
//! filters already applied (encrypted attributes decrypted, JSON decoded,
//! dates in the API format, sub-queries resolved).

use chrono::NaiveDateTime;
use serde_json::Value as Json;
use utopia_database::value::{as_document, to_long, to_text};
use utopia_database::{Database, Document, Value};

use crate::datetime;
use crate::{Error, Result};

/// Typed reading of a document's attributes.
pub trait Attributes {
    /// A string attribute (`None` when unset or not a string).
    fn string(&self, key: &str) -> Option<String>;
    /// A string attribute, empty when unset.
    fn string_or_empty(&self, key: &str) -> String {
        self.string(key).unwrap_or_default()
    }
    /// A boolean attribute.
    fn boolean(&self, key: &str) -> Option<bool>;
    /// A date attribute.
    fn datetime(&self, key: &str) -> Option<NaiveDateTime>;
    /// A list of strings (array attributes, `$permissions`).
    fn strings(&self, key: &str) -> Vec<String>;
    /// A decoded JSON attribute as JSON.
    fn json(&self, key: &str) -> Option<Json>;
    /// The nested documents of an attribute (sub-queries).
    fn documents(&self, key: &str) -> Vec<&Document>;
    /// `$sequence` as an integer.
    fn sequence_i64(&self) -> i64;
}

impl Attributes for Document {
    fn string(&self, key: &str) -> Option<String> {
        match self.get(key) {
            Some(Value::Str(s)) => Some(String::from_utf8_lossy(s.as_bytes()).into_owned()),
            _ => None,
        }
    }

    fn boolean(&self, key: &str) -> Option<bool> {
        match self.get(key) {
            Some(Value::Bool(b)) => Some(*b),
            _ => None,
        }
    }

    fn datetime(&self, key: &str) -> Option<NaiveDateTime> {
        datetime::of(self.get(key))
    }

    fn strings(&self, key: &str) -> Vec<String> {
        match self.get(key) {
            Some(Value::Array(items)) => items.values().filter(|v| matches!(v, Value::Str(_))).map(to_text).collect(),
            _ => Vec::new(),
        }
    }

    fn json(&self, key: &str) -> Option<Json> {
        match self.get(key) {
            None | Some(Value::Null) => None,
            Some(v) => v.try_to_json(),
        }
    }

    fn documents(&self, key: &str) -> Vec<&Document> {
        match self.get(key) {
            Some(Value::Array(items)) => items.values().filter_map(as_document).collect(),
            _ => Vec::new(),
        }
    }

    fn sequence_i64(&self) -> i64 {
        self.sequence().map(to_long).unwrap_or(0)
    }
}

/// A user (project `users` or platform `users`), with its sub-queries.
#[derive(Debug, Clone, Default)]
pub struct User {
    pub id: String,
    pub sequence: i64,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub permissions: Vec<String>,
    pub name: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub status: Option<bool>,
    pub labels: Vec<String>,
    pub password_history: Vec<String>,
    pub password: Option<String>,
    pub hash: Option<String>,
    pub hash_options: Option<Json>,
    pub password_update: Option<NaiveDateTime>,
    pub prefs: Option<Json>,
    pub registration: Option<NaiveDateTime>,
    pub email_verification: Option<bool>,
    pub phone_verification: Option<bool>,
    pub reset: Option<bool>,
    pub mfa: Option<bool>,
    pub mfa_recovery_codes: Vec<String>,
    pub accessed_at: Option<NaiveDateTime>,
    pub email_canonical: Option<String>,
    pub email_is_free: Option<bool>,
    pub email_is_disposable: Option<bool>,
    pub email_is_corporate: Option<bool>,
    pub email_is_canonical: Option<bool>,
    pub password_pwned: Option<bool>,
    pub impersonator: Option<bool>,
    pub targets: Vec<Target>,
    pub sessions: Vec<Session>,
    pub memberships: Vec<Membership>,
    pub authenticators: Vec<Authenticator>,
    /// The whole document, for the queue messages that carry it (see
    /// [`User::message_payload`]).
    pub document: std::sync::Arc<Document>,
}

impl From<&Document> for User {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            sequence: d.sequence_i64(),
            created_at: d.datetime("$createdAt"),
            updated_at: d.datetime("$updatedAt"),
            permissions: d.permissions(),
            name: d.string("name"),
            email: d.string("email"),
            phone: d.string("phone"),
            status: d.boolean("status"),
            labels: d.strings("labels"),
            password_history: d.strings("passwordHistory"),
            password: d.string("password"),
            hash: d.string("hash"),
            hash_options: d.json("hashOptions"),
            password_update: d.datetime("passwordUpdate"),
            prefs: d.json("prefs"),
            registration: d.datetime("registration"),
            email_verification: d.boolean("emailVerification"),
            phone_verification: d.boolean("phoneVerification"),
            reset: d.boolean("reset"),
            mfa: d.boolean("mfa"),
            mfa_recovery_codes: d.strings("mfaRecoveryCodes"),
            accessed_at: d.datetime("accessedAt"),
            email_canonical: d.string("emailCanonical"),
            email_is_free: d.boolean("emailIsFree"),
            email_is_disposable: d.boolean("emailIsDisposable"),
            email_is_corporate: d.boolean("emailIsCorporate"),
            email_is_canonical: d.boolean("emailIsCanonical"),
            password_pwned: d.boolean("passwordPwned"),
            impersonator: d.boolean("impersonator"),
            targets: d.documents("targets").into_iter().map(Target::from).collect(),
            sessions: d.documents("sessions").into_iter().map(Session::from).collect(),
            memberships: d.documents("memberships").into_iter().map(Membership::from).collect(),
            authenticators: d.documents("authenticators").into_iter().map(Authenticator::from).collect(),
            document: std::sync::Arc::new(d.clone()),
        }
    }
}

impl User {
    /// The user in queue messages: the whole document, sub-queries
    /// included, as PHP's `Event::setUser()` sends it.
    pub fn message_payload(&self) -> Json {
        utopia_database::Value::from((*self.document).clone()).try_to_json().unwrap_or(Json::Null)
    }

    /// `email || phone` verified.
    pub fn verified(&self) -> bool {
        self.email_verification == Some(true) || self.phone_verification == Some(true)
    }

    /// `User::getRoles()` for a session user.
    pub fn roles(&self) -> Vec<String> {
        let mut roles = Vec::with_capacity(4 + self.memberships.len() * 3 + self.labels.len());
        roles.push(format!("user:{}", self.id));
        roles.push("users".to_owned());
        let dimension = if self.verified() { "verified" } else { "unverified" };
        roles.push(format!("user:{}/{dimension}", self.id));
        roles.push(format!("users/{dimension}"));
        for m in &self.memberships {
            if m.confirm != Some(true) {
                continue;
            }
            roles.push(format!("team:{}", m.team_id));
            roles.push(format!("member:{}", m.id));
            for r in &m.roles {
                roles.push(format!("team:{}/{r}", m.team_id));
            }
        }
        for l in &self.labels {
            roles.push(format!("label:{l}"));
        }
        roles
    }
}

/// A session.
#[derive(Debug, Clone, Default)]
pub struct Session {
    pub id: String,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub user_id: String,
    pub expire: Option<NaiveDateTime>,
    pub provider: Option<String>,
    pub provider_uid: Option<String>,
    pub provider_access_token: Option<String>,
    pub provider_access_token_expiry: Option<NaiveDateTime>,
    pub provider_refresh_token: Option<String>,
    pub secret: Option<String>,
    pub user_agent: Option<String>,
    pub ip: Option<String>,
    pub os_code: Option<String>,
    pub os_name: Option<String>,
    pub os_version: Option<String>,
    pub client_type: Option<String>,
    pub client_code: Option<String>,
    pub client_name: Option<String>,
    pub client_version: Option<String>,
    pub client_engine: Option<String>,
    pub client_engine_version: Option<String>,
    pub device_name: Option<String>,
    pub device_brand: Option<String>,
    pub device_model: Option<String>,
    pub country_code: Option<String>,
    pub factors: Vec<String>,
    pub mfa_updated_at: Option<NaiveDateTime>,
}

impl From<&Document> for Session {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            created_at: d.datetime("$createdAt"),
            updated_at: d.datetime("$updatedAt"),
            user_id: d.string_or_empty("userId"),
            expire: d.datetime("expire"),
            provider: d.string("provider"),
            provider_uid: d.string("providerUid"),
            provider_access_token: d.string("providerAccessToken"),
            provider_access_token_expiry: d.datetime("providerAccessTokenExpiry"),
            provider_refresh_token: d.string("providerRefreshToken"),
            secret: d.string("secret"),
            user_agent: d.string("userAgent"),
            ip: d.string("ip"),
            os_code: d.string("osCode"),
            os_name: d.string("osName"),
            os_version: d.string("osVersion"),
            client_type: d.string("clientType"),
            client_code: d.string("clientCode"),
            client_name: d.string("clientName"),
            client_version: d.string("clientVersion"),
            client_engine: d.string("clientEngine"),
            client_engine_version: d.string("clientEngineVersion"),
            device_name: d.string("deviceName"),
            device_brand: d.string("deviceBrand"),
            device_model: d.string("deviceModel"),
            country_code: d.string("countryCode"),
            factors: d.strings("factors"),
            mfa_updated_at: d.datetime("mfaUpdatedAt"),
        }
    }
}

/// A token.
#[derive(Debug, Clone, Default)]
pub struct Token {
    pub id: String,
    pub created_at: Option<NaiveDateTime>,
    pub user_id: String,
    pub secret: Option<String>,
    pub expire: Option<NaiveDateTime>,
}

impl From<&Document> for Token {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            created_at: d.datetime("$createdAt"),
            user_id: d.string_or_empty("userId"),
            secret: d.string("secret"),
            expire: d.datetime("expire"),
        }
    }
}

/// A messaging target.
#[derive(Debug, Clone, Default)]
pub struct Target {
    pub id: String,
    pub sequence: i64,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub user_id: String,
    pub user_internal_id: String,
    pub provider_type: String,
    pub provider_id: Option<String>,
    pub identifier: String,
    pub name: Option<String>,
    pub expired: Option<bool>,
}

impl From<&Document> for Target {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            sequence: d.sequence_i64(),
            created_at: d.datetime("$createdAt"),
            updated_at: d.datetime("$updatedAt"),
            user_id: d.string_or_empty("userId"),
            user_internal_id: d.string_or_empty("userInternalId"),
            provider_type: d.string_or_empty("providerType"),
            provider_id: d.string("providerId"),
            identifier: d.string_or_empty("identifier"),
            name: d.string("name"),
            expired: d.boolean("expired"),
        }
    }
}

/// An OAuth identity.
#[derive(Debug, Clone, Default)]
pub struct Identity {
    pub id: String,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub user_id: Option<String>,
    pub provider: Option<String>,
    pub provider_uid: Option<String>,
    pub provider_email: Option<String>,
    pub provider_access_token: Option<String>,
    pub provider_access_token_expiry: Option<NaiveDateTime>,
    pub provider_refresh_token: Option<String>,
    pub provider_id_token: Option<String>,
}

impl From<&Document> for Identity {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            created_at: d.datetime("$createdAt"),
            updated_at: d.datetime("$updatedAt"),
            user_id: d.string("userId"),
            provider: d.string("provider"),
            provider_uid: d.string("providerUid"),
            provider_email: d.string("providerEmail"),
            provider_access_token: d.string("providerAccessToken"),
            provider_access_token_expiry: d.datetime("providerAccessTokenExpiry"),
            provider_refresh_token: d.string("providerRefreshToken"),
            provider_id_token: d.string("providerIdToken"),
        }
    }
}

/// A team membership.
#[derive(Debug, Clone, Default)]
pub struct Membership {
    pub id: String,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub user_id: String,
    pub team_id: String,
    pub roles: Vec<String>,
    pub invited: Option<NaiveDateTime>,
    pub joined: Option<NaiveDateTime>,
    pub confirm: Option<bool>,
}

impl From<&Document> for Membership {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            created_at: d.datetime("$createdAt"),
            updated_at: d.datetime("$updatedAt"),
            user_id: d.string_or_empty("userId"),
            team_id: d.string_or_empty("teamId"),
            roles: d.strings("roles"),
            invited: d.datetime("invited"),
            joined: d.datetime("joined"),
            confirm: d.boolean("confirm"),
        }
    }
}

/// An MFA challenge.
#[derive(Debug, Clone, Default)]
pub struct Challenge {
    pub id: String,
    pub created_at: Option<NaiveDateTime>,
    pub user_id: String,
    pub kind: Option<String>,
    pub code: Option<String>,
    pub expire: Option<NaiveDateTime>,
}

impl From<&Document> for Challenge {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            created_at: d.datetime("$createdAt"),
            user_id: d.string_or_empty("userId"),
            kind: d.string("type"),
            code: d.string("code"),
            expire: d.datetime("expire"),
        }
    }
}

/// An authenticator (TOTP or passkey).
#[derive(Debug, Clone, Default)]
pub struct Authenticator {
    pub id: String,
    pub created_at: Option<NaiveDateTime>,
    pub updated_at: Option<NaiveDateTime>,
    pub user_internal_id: String,
    pub kind: Option<String>,
    pub verified: Option<bool>,
    pub name: Option<String>,
    pub accessed_at: Option<NaiveDateTime>,
}

impl From<&Document> for Authenticator {
    fn from(d: &Document) -> Self {
        Self {
            id: d.id().to_owned(),
            created_at: d.datetime("$createdAt"),
            updated_at: d.datetime("$updatedAt"),
            user_internal_id: d.string_or_empty("userInternalId"),
            kind: d.string("type"),
            verified: d.boolean("verified"),
            name: d.string("name"),
            accessed_at: d.datetime("accessedAt"),
        }
    }
}

/// A messaging provider (only identity and type).
#[derive(Debug, Clone, Default)]
pub struct Provider {
    pub id: String,
    pub sequence: i64,
    pub kind: String,
}

impl From<&Document> for Provider {
    fn from(d: &Document) -> Self {
        Self { id: d.id().to_owned(), sequence: d.sequence_i64(), kind: d.string_or_empty("type") }
    }
}

/// `getDocument($collection, $id)`: the document, `None` when it is empty
/// (missing or not readable).
pub async fn get<T: for<'a> From<&'a Document>>(db: &Database, collection: &str, id: &str) -> Result<Option<T>> {
    let document = db.get_document(collection, id, Vec::new(), false).await.map_err(Error::from)?;
    Ok(if document.is_empty() { None } else { Some(T::from(&document)) })
}

/// Loads a user with its sessions, memberships, targets and authenticators.
pub async fn load_user(db: &Database, id: &str) -> Result<Option<User>> {
    get::<User>(db, "users", id).await
}

/// The values of a list as documents' typed views.
pub fn all<T: for<'a> From<&'a Document>>(documents: &[Document]) -> Vec<T> {
    documents.iter().map(T::from).collect()
}

/// A string attribute value (`null` for `None`).
pub fn text(value: Option<&str>) -> Value {
    value.map(Value::from).unwrap_or(Value::Null)
}

/// A list of strings as an attribute value.
pub fn strings(values: &[String]) -> Value {
    utopia_database::value::list(values.iter().map(|v| Value::from(v.as_str())))
}

/// `$value` for a sequence-valued attribute (`userInternalId`, ...): PHP
/// stores internal ids as strings.
pub fn internal_id(sequence: i64) -> Value {
    Value::from(sequence.to_string().as_str())
}

/// The `$sequence` of a document as text.
pub fn sequence_text(document: &Document) -> String {
    document.sequence().map(to_text).unwrap_or_default()
}
