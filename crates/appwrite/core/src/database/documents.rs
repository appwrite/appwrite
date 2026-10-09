//! Typed rows of the auth-related system collections.
//!
//! Each type selects only the columns the API needs. Encrypted attributes
//! (`encrypt` filter) are decrypted while decoding.

use chrono::NaiveDateTime;
use serde_json::Value;
use utopia_database::row;
use utopia_database::sql::Builder;
use utopia_database::{Database, FromRow, Param, Row};

use crate::Result;
use crate::crypto;

type DbResult<T> = utopia_database::Result<T>;

fn decrypted(r: &Row, column: &str) -> DbResult<Option<String>> {
    Ok(row::string(r, column)?.and_then(|s| crypto::decrypt_env(&s)))
}

/// A user (project `users` or platform `users`).
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
    pub hash_options: Option<Value>,
    pub password_update: Option<NaiveDateTime>,
    pub prefs: Option<Value>,
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
    // Relations (loaded on demand).
    pub targets: Vec<Target>,
    pub sessions: Vec<Session>,
    pub memberships: Vec<Membership>,
    pub authenticators: Vec<Authenticator>,
}

impl FromRow for User {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            sequence: row::sequence(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            updated_at: row::datetime(r, "_updatedAt")?,
            permissions: row::strings(r, "_permissions")?,
            name: row::string(r, "name")?,
            email: row::string(r, "email")?,
            phone: row::string(r, "phone")?,
            status: row::boolean(r, "status")?,
            labels: row::strings(r, "labels")?,
            password_history: row::strings(r, "passwordHistory")?,
            password: decrypted(r, "password")?,
            hash: row::string(r, "hash")?,
            hash_options: row::json_text(r, "hashOptions")?,
            password_update: row::datetime(r, "passwordUpdate")?,
            prefs: row::json_text(r, "prefs")?,
            registration: row::datetime(r, "registration")?,
            email_verification: row::boolean(r, "emailVerification")?,
            phone_verification: row::boolean(r, "phoneVerification")?,
            reset: row::boolean(r, "reset")?,
            mfa: row::boolean(r, "mfa")?,
            mfa_recovery_codes: row::strings(r, "mfaRecoveryCodes")?
                .into_iter()
                .filter_map(|c| crypto::decrypt_env(&c))
                .collect(),
            accessed_at: row::datetime(r, "accessedAt")?,
            email_canonical: row::string(r, "emailCanonical")?,
            email_is_free: row::boolean(r, "emailIsFree")?,
            email_is_disposable: row::boolean(r, "emailIsDisposable")?,
            email_is_corporate: row::boolean(r, "emailIsCorporate")?,
            email_is_canonical: row::boolean(r, "emailIsCanonical")?,
            password_pwned: row::boolean(r, "passwordPwned")?,
            impersonator: row::boolean(r, "impersonator")?,
            ..Default::default()
        })
    }
}

impl User {
    /// The `userSearch` filter value: id, email, name, phone, `label:<x>`.
    pub fn search(&self) -> String {
        let mut parts: Vec<String> = Vec::new();
        for v in [Some(self.id.as_str()), self.email.as_deref(), self.name.as_deref(), self.phone.as_deref()]
            .into_iter()
            .flatten()
        {
            if !v.is_empty() && v != "0" {
                parts.push(v.to_owned());
            }
        }
        for l in &self.labels {
            parts.push(format!("label:{l}"));
        }
        parts.join(" ")
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

impl FromRow for Session {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            updated_at: row::datetime(r, "_updatedAt")?,
            user_id: row::string_or_empty(r, "userId")?,
            expire: row::datetime(r, "expire")?,
            provider: row::string(r, "provider")?,
            provider_uid: row::string(r, "providerUid")?,
            provider_access_token: decrypted(r, "providerAccessToken")?,
            provider_access_token_expiry: row::datetime(r, "providerAccessTokenExpiry")?,
            provider_refresh_token: decrypted(r, "providerRefreshToken")?,
            secret: decrypted(r, "secret")?,
            user_agent: row::string(r, "userAgent")?,
            ip: row::string(r, "ip")?,
            os_code: row::string(r, "osCode")?,
            os_name: row::string(r, "osName")?,
            os_version: row::string(r, "osVersion")?,
            client_type: row::string(r, "clientType")?,
            client_code: row::string(r, "clientCode")?,
            client_name: row::string(r, "clientName")?,
            client_version: row::string(r, "clientVersion")?,
            client_engine: row::string(r, "clientEngine")?,
            client_engine_version: row::string(r, "clientEngineVersion")?,
            device_name: row::string(r, "deviceName")?,
            device_brand: row::string(r, "deviceBrand")?,
            device_model: row::string(r, "deviceModel")?,
            country_code: row::string(r, "countryCode")?,
            factors: row::strings(r, "factors")?,
            mfa_updated_at: row::datetime(r, "mfaUpdatedAt")?,
        })
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

impl FromRow for Token {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            user_id: row::string_or_empty(r, "userId")?,
            secret: decrypted(r, "secret")?,
            expire: row::datetime(r, "expire")?,
        })
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

impl FromRow for Target {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            sequence: row::sequence(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            updated_at: row::datetime(r, "_updatedAt")?,
            user_id: row::string_or_empty(r, "userId")?,
            user_internal_id: row::string_or_empty(r, "userInternalId")?,
            provider_type: row::string_or_empty(r, "providerType")?,
            provider_id: row::string(r, "providerId")?,
            identifier: row::string_or_empty(r, "identifier")?,
            name: row::string(r, "name")?,
            expired: row::boolean(r, "expired")?,
        })
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

impl FromRow for Identity {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            updated_at: row::datetime(r, "_updatedAt")?,
            user_id: row::string(r, "userId")?,
            provider: row::string(r, "provider")?,
            provider_uid: row::string(r, "providerUid")?,
            provider_email: row::string(r, "providerEmail")?,
            provider_access_token: decrypted(r, "providerAccessToken")?,
            provider_access_token_expiry: row::datetime(r, "providerAccessTokenExpiry")?,
            provider_refresh_token: decrypted(r, "providerRefreshToken")?,
            provider_id_token: decrypted(r, "providerIdToken")?,
        })
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

impl FromRow for Membership {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            updated_at: row::datetime(r, "_updatedAt")?,
            user_id: row::string_or_empty(r, "userId")?,
            team_id: row::string_or_empty(r, "teamId")?,
            roles: row::strings(r, "roles")?,
            invited: row::datetime(r, "invited")?,
            joined: row::datetime(r, "joined")?,
            confirm: row::boolean(r, "confirm")?,
        })
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

impl FromRow for Challenge {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            user_id: row::string_or_empty(r, "userId")?,
            kind: row::string(r, "type")?,
            code: decrypted(r, "code")?,
            expire: row::datetime(r, "expire")?,
        })
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

impl FromRow for Authenticator {
    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self {
            id: row::uid(r)?,
            created_at: row::datetime(r, "_createdAt")?,
            updated_at: row::datetime(r, "_updatedAt")?,
            user_internal_id: row::string_or_empty(r, "userInternalId")?,
            kind: row::string(r, "type")?,
            verified: row::boolean(r, "verified")?,
            name: row::string(r, "name")?,
            accessed_at: row::datetime(r, "accessedAt")?,
        })
    }
}

/// A messaging provider (only identity and type).
#[derive(Debug, Clone, Default)]
pub struct Provider {
    pub id: String,
    pub sequence: i64,
    pub kind: String,
}

impl FromRow for Provider {
    const COLUMNS: &'static str = r#""_id", "_uid", "type""#;

    fn from_row(r: &Row) -> DbResult<Self> {
        Ok(Self { id: row::uid(r)?, sequence: row::sequence(r)?, kind: row::string_or_empty(r, "type")? })
    }
}

/// Which user relations to load alongside the user row.
#[derive(Debug, Clone, Copy, Default)]
pub struct Relations {
    pub targets: bool,
    pub sessions: bool,
    pub memberships: bool,
    pub authenticators: bool,
}

impl Relations {
    pub const NONE: Relations =
        Relations { targets: false, sessions: false, memberships: false, authenticators: false };
    pub const TARGETS: Relations = Relations { targets: true, ..Relations::NONE };
    pub const SESSIONS: Relations = Relations { sessions: true, ..Relations::NONE };
    pub const AUTH: Relations = Relations { sessions: true, memberships: true, ..Relations::NONE };
}

/// Subquery of a user relation (`find(<coll>, [equal('userInternalId', [seq])], limit 1000)`).
fn relation_sql<T: FromRow>(db: &Database, collection: &str, order: &str) -> (String, Builder) {
    let mut b = Builder::new();
    let p = b.bind(Param::text(""));
    let tenant_users = db.tenant_condition(&mut b, "u");
    let tenant_rel = db.tenant_condition(&mut b, "main");
    let sql = format!(
        "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"userInternalId\" = (SELECT u.\"_id\"::text FROM {} AS u WHERE u.\"_uid\" = {p}{tenant_users}){tenant_rel} ORDER BY {order} LIMIT 1000",
        T::COLUMNS,
        db.table(collection),
        db.table("users"),
    );
    (sql, b)
}

/// Loads a user and the requested relations in one round trip
/// (statements are pipelined on a single connection).
pub async fn load_user(db: &Database, id: &str, relations: Relations) -> Result<Option<User>> {
    let client = db.client().await?;
    let mut ub = Builder::new();
    let p = ub.bind(Param::text(id));
    let tenant = db.tenant_condition(&mut ub, "main");
    let user_sql = format!(
        "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"_uid\" = {p}{tenant}",
        User::COLUMNS,
        db.table("users")
    );

    let with_id = |(sql, mut b): (String, Builder)| {
        b.params[0] = Param::text(id);
        (sql, b)
    };
    let targets = with_id(relation_sql::<Target>(db, "targets", "\"_id\" ASC"));
    let sessions = with_id(relation_sql::<Session>(db, "sessions", "\"_id\" ASC"));
    let memberships = with_id(relation_sql::<Membership>(db, "memberships", "\"_id\" ASC"));
    let authenticators =
        with_id(relation_sql::<Authenticator>(db, "authenticators", "\"_createdAt\" DESC, \"_id\" DESC"));

    let none = || async { Ok::<Vec<Row>, utopia_database::tokio_postgres::Error>(Vec::new()) };
    let user_params = ub.typed();
    let t_params = targets.1.typed();
    let s_params = sessions.1.typed();
    let m_params = memberships.1.typed();
    let a_params = authenticators.1.typed();
    let (user_rows, t_rows, s_rows, m_rows, a_rows) = futures_util::join!(
        client.query_typed(&user_sql, &user_params),
        async { if relations.targets { client.query_typed(&targets.0, &t_params).await } else { none().await } },
        async { if relations.sessions { client.query_typed(&sessions.0, &s_params).await } else { none().await } },
        async {
            if relations.memberships { client.query_typed(&memberships.0, &m_params).await } else { none().await }
        },
        async {
            if relations.authenticators { client.query_typed(&authenticators.0, &a_params).await } else { none().await }
        },
    );
    let user_rows = user_rows.map_err(utopia_database::Error::from)?;
    let Some(row) = user_rows.first() else {
        return Ok(None);
    };
    let mut user = User::from_row(row)?;
    let decode = |rows: std::result::Result<Vec<Row>, utopia_database::tokio_postgres::Error>| {
        rows.map_err(utopia_database::Error::from)
    };
    user.targets = decode(t_rows)?.iter().map(Target::from_row).collect::<DbResult<_>>()?;
    user.sessions = decode(s_rows)?.iter().map(Session::from_row).collect::<DbResult<_>>()?;
    user.memberships = decode(m_rows)?.iter().map(Membership::from_row).collect::<DbResult<_>>()?;
    user.authenticators = decode(a_rows)?.iter().map(Authenticator::from_row).collect::<DbResult<_>>()?;
    Ok(Some(user))
}

/// Loads rows of a user relation by the user's internal id.
pub async fn find_by_user<T: FromRow>(
    db: &Database,
    collection: &str,
    user_sequence: i64,
    order: &str,
) -> Result<Vec<T>> {
    let mut b = Builder::new();
    let p = b.bind(Param::text(user_sequence.to_string()));
    let tenant = db.tenant_condition(&mut b, "main");
    let sql = format!(
        "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"userInternalId\" = {p}{tenant} ORDER BY {order} LIMIT 1000",
        T::COLUMNS,
        db.table(collection)
    );
    let rows = db.query(&sql, &b).await?;
    Ok(rows.iter().map(T::from_row).collect::<DbResult<Vec<T>>>()?)
}
