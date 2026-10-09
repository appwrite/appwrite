use serde::Serialize;
use serde::ser::{SerializeMap, Serializer};
use serde_json::{Map, Value};

use super::{Render, dt};
use crate::database::documents::{Authenticator, Challenge, Identity, Membership, Session, Target, Token, User};

fn s(v: &Option<String>) -> &str {
    v.as_deref().unwrap_or("")
}

/// `prefs` as PHP outputs it: an object (`{}` when empty).
pub fn prefs_value(prefs: &Option<Value>) -> Value {
    match prefs {
        Some(Value::Object(m)) if !m.is_empty() => Value::Object(m.clone()),
        Some(Value::Array(a)) if !a.is_empty() => {
            // A PHP list stored as prefs is output as a JSON list.
            Value::Array(a.clone())
        }
        _ => Value::Object(Map::new()),
    }
}

/// `user` model.
pub struct UserModel<'a> {
    pub user: &'a User,
    pub render: Render,
}

impl Serialize for UserModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let u = self.user;
        let r = self.render;
        let mut m = serializer.serialize_map(None)?;
        m.serialize_entry("$id", &u.id)?;
        m.serialize_entry("$createdAt", &dt(&u.created_at))?;
        m.serialize_entry("$updatedAt", &dt(&u.updated_at))?;
        m.serialize_entry("name", s(&u.name))?;
        m.serialize_entry("password", &u.password)?;
        m.serialize_entry("hash", &u.hash)?;
        m.serialize_entry("hashOptions", &u.hash_options)?;
        m.serialize_entry("registration", &dt(&u.registration))?;
        m.serialize_entry("status", &u.status.unwrap_or(true))?;
        m.serialize_entry("labels", &u.labels)?;
        m.serialize_entry("passwordUpdate", &dt(&u.password_update))?;
        m.serialize_entry("email", s(&u.email))?;
        m.serialize_entry("phone", s(&u.phone))?;
        m.serialize_entry("emailVerification", &u.email_verification.unwrap_or(false))?;
        m.serialize_entry("emailCanonical", &u.email_canonical)?;
        m.serialize_entry("emailIsFree", &u.email_is_free)?;
        m.serialize_entry("emailIsDisposable", &u.email_is_disposable)?;
        m.serialize_entry("emailIsCorporate", &u.email_is_corporate)?;
        m.serialize_entry("emailIsCanonical", &u.email_is_canonical)?;
        m.serialize_entry("passwordPwned", &u.password_pwned)?;
        m.serialize_entry("phoneVerification", &u.phone_verification.unwrap_or(false))?;
        if !r.before(1, 5, 0) {
            m.serialize_entry("mfa", &u.mfa.unwrap_or(false))?;
        }
        m.serialize_entry("prefs", &prefs_value(&u.prefs))?;
        if !r.before(1, 5, 0) {
            let targets: Vec<TargetModel<'_>> = u.targets.iter().map(|t| TargetModel { target: t }).collect();
            m.serialize_entry("targets", &targets)?;
        }
        m.serialize_entry("accessedAt", &dt(&u.accessed_at))?;
        if !r.before(1, 9, 0) {
            m.serialize_entry("impersonator", &u.impersonator)?;
            m.serialize_entry("impersonatorUserId", &Value::Null)?;
        }
        m.end()
    }
}

/// `{total, users}`.
pub struct UserListModel<'a> {
    pub total: i64,
    pub users: &'a [User],
    pub render: Render,
}

impl Serialize for UserListModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(Some(2))?;
        m.serialize_entry("total", &self.total)?;
        let users: Vec<UserModel<'_>> = self.users.iter().map(|u| UserModel { user: u, render: self.render }).collect();
        m.serialize_entry("users", &users)?;
        m.end()
    }
}

/// `target` model.
pub struct TargetModel<'a> {
    pub target: &'a Target,
}

impl Serialize for TargetModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let t = self.target;
        let mut m = serializer.serialize_map(Some(9))?;
        m.serialize_entry("$id", &t.id)?;
        m.serialize_entry("$createdAt", &dt(&t.created_at))?;
        m.serialize_entry("$updatedAt", &dt(&t.updated_at))?;
        m.serialize_entry("name", s(&t.name))?;
        m.serialize_entry("userId", &t.user_id)?;
        m.serialize_entry("providerId", &t.provider_id)?;
        m.serialize_entry("providerType", &t.provider_type)?;
        m.serialize_entry("identifier", &t.identifier)?;
        m.serialize_entry("expired", &t.expired.unwrap_or(false))?;
        m.end()
    }
}

/// `{total, targets}`.
pub struct TargetListModel<'a> {
    pub total: i64,
    pub targets: &'a [Target],
}

impl Serialize for TargetListModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(Some(2))?;
        m.serialize_entry("total", &self.total)?;
        let items: Vec<TargetModel<'_>> = self.targets.iter().map(|t| TargetModel { target: t }).collect();
        m.serialize_entry("targets", &items)?;
        m.end()
    }
}

/// `session` model.
pub struct SessionModel<'a> {
    pub session: &'a Session,
    pub country_name: &'a str,
    pub current: bool,
    /// Overrides the stored secret (the encoded store on creation).
    pub secret: Option<&'a str>,
    pub render: Render,
}

impl Serialize for SessionModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let x = self.session;
        let r = self.render;
        let mut m = serializer.serialize_map(None)?;
        m.serialize_entry("$id", &x.id)?;
        m.serialize_entry("$createdAt", &dt(&x.created_at))?;
        m.serialize_entry("$updatedAt", &dt(&x.updated_at))?;
        m.serialize_entry("userId", &x.user_id)?;
        m.serialize_entry("expire", &dt(&x.expire))?;
        m.serialize_entry("provider", s(&x.provider))?;
        m.serialize_entry("providerUid", s(&x.provider_uid))?;
        m.serialize_entry("providerAccessToken", s(&x.provider_access_token))?;
        m.serialize_entry("providerAccessTokenExpiry", &dt(&x.provider_access_token_expiry))?;
        m.serialize_entry("providerRefreshToken", s(&x.provider_refresh_token))?;
        m.serialize_entry("ip", s(&x.ip))?;
        m.serialize_entry("osCode", s(&x.os_code))?;
        m.serialize_entry("osName", s(&x.os_name))?;
        m.serialize_entry("osVersion", s(&x.os_version))?;
        m.serialize_entry("clientType", s(&x.client_type))?;
        m.serialize_entry("clientCode", s(&x.client_code))?;
        m.serialize_entry("clientName", s(&x.client_name))?;
        m.serialize_entry("clientVersion", s(&x.client_version))?;
        m.serialize_entry("clientEngine", s(&x.client_engine))?;
        m.serialize_entry("clientEngineVersion", s(&x.client_engine_version))?;
        m.serialize_entry("deviceName", s(&x.device_name))?;
        m.serialize_entry("deviceBrand", s(&x.device_brand))?;
        m.serialize_entry("deviceModel", s(&x.device_model))?;
        m.serialize_entry("countryCode", s(&x.country_code))?;
        m.serialize_entry("countryName", self.country_name)?;
        m.serialize_entry("current", &self.current)?;
        if !r.before(1, 5, 0) {
            m.serialize_entry("factors", &x.factors)?;
            let secret = if r.sensitive { self.secret.or(x.secret.as_deref()).unwrap_or("") } else { "" };
            m.serialize_entry("secret", secret)?;
        }
        m.serialize_entry("mfaUpdatedAt", &dt(&x.mfa_updated_at))?;
        m.end()
    }
}

/// `{total, sessions}`.
pub struct SessionListModel<'a> {
    pub total: i64,
    pub sessions: Vec<SessionModel<'a>>,
}

impl Serialize for SessionListModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(Some(2))?;
        m.serialize_entry("total", &self.total)?;
        m.serialize_entry("sessions", &self.sessions)?;
        m.end()
    }
}

/// `token` model.
pub struct TokenModel<'a> {
    pub token: &'a Token,
    pub secret: Option<&'a str>,
    pub render: Render,
}

impl Serialize for TokenModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let t = self.token;
        let mut m = serializer.serialize_map(None)?;
        m.serialize_entry("$id", &t.id)?;
        m.serialize_entry("$createdAt", &dt(&t.created_at))?;
        m.serialize_entry("userId", &t.user_id)?;
        let secret = if self.render.sensitive { self.secret.or(t.secret.as_deref()).unwrap_or("") } else { "" };
        m.serialize_entry("secret", secret)?;
        m.serialize_entry("expire", &dt(&t.expire))?;
        if !self.render.before(1, 5, 0) {
            m.serialize_entry("phrase", "")?;
        }
        m.end()
    }
}

/// `identity` model.
pub struct IdentityModel<'a> {
    pub identity: &'a Identity,
}

impl Serialize for IdentityModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let i = self.identity;
        let mut m = serializer.serialize_map(Some(11))?;
        m.serialize_entry("$id", &i.id)?;
        m.serialize_entry("$createdAt", &dt(&i.created_at))?;
        m.serialize_entry("$updatedAt", &dt(&i.updated_at))?;
        m.serialize_entry("userId", s(&i.user_id))?;
        m.serialize_entry("provider", s(&i.provider))?;
        m.serialize_entry("providerUid", s(&i.provider_uid))?;
        m.serialize_entry("providerEmail", s(&i.provider_email))?;
        m.serialize_entry("providerAccessToken", s(&i.provider_access_token))?;
        m.serialize_entry("providerAccessTokenExpiry", &dt(&i.provider_access_token_expiry))?;
        m.serialize_entry("providerRefreshToken", s(&i.provider_refresh_token))?;
        m.serialize_entry("providerIdToken", s(&i.provider_id_token))?;
        m.end()
    }
}

/// `{total, identities}`.
pub struct IdentityListModel<'a> {
    pub total: i64,
    pub identities: &'a [Identity],
}

impl Serialize for IdentityListModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(Some(2))?;
        m.serialize_entry("total", &self.total)?;
        let items: Vec<IdentityModel<'_>> = self.identities.iter().map(|i| IdentityModel { identity: i }).collect();
        m.serialize_entry("identities", &items)?;
        m.end()
    }
}

/// `membership` model (as returned by the Users API).
pub struct MembershipModel<'a> {
    pub membership: &'a Membership,
    pub user_name: &'a str,
    pub user_email: &'a str,
    pub team_name: &'a str,
    pub render: Render,
}

impl Serialize for MembershipModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let x = self.membership;
        let r = self.render;
        let mut m = serializer.serialize_map(None)?;
        m.serialize_entry("$id", &x.id)?;
        m.serialize_entry("$createdAt", &dt(&x.created_at))?;
        m.serialize_entry("$updatedAt", &dt(&x.updated_at))?;
        m.serialize_entry("userId", &x.user_id)?;
        m.serialize_entry("userName", self.user_name)?;
        m.serialize_entry("userEmail", self.user_email)?;
        if !r.before(1, 9, 2) {
            m.serialize_entry("userPhone", "")?;
        }
        m.serialize_entry("teamId", &x.team_id)?;
        m.serialize_entry("teamName", self.team_name)?;
        m.serialize_entry("invited", &dt(&x.invited))?;
        m.serialize_entry("joined", &dt(&x.joined))?;
        m.serialize_entry("confirm", &x.confirm.unwrap_or(false))?;
        if !r.before(1, 5, 0) {
            m.serialize_entry("mfa", &false)?;
        }
        m.serialize_entry("userAccessedAt", "")?;
        m.serialize_entry("roles", &x.roles)?;
        m.end()
    }
}

/// `{total, memberships}`.
pub struct MembershipListModel<'a> {
    pub total: i64,
    pub memberships: Vec<MembershipModel<'a>>,
}

impl Serialize for MembershipListModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(Some(2))?;
        m.serialize_entry("total", &self.total)?;
        m.serialize_entry("memberships", &self.memberships)?;
        m.end()
    }
}

/// `mfaFactors`.
#[derive(Serialize)]
pub struct MfaFactorsModel {
    pub totp: bool,
    pub phone: bool,
    pub email: bool,
    #[serde(rename = "recoveryCode")]
    pub recovery_code: bool,
    pub custom: Value,
}

/// `mfaRecoveryCodes`.
#[derive(Serialize)]
pub struct MfaRecoveryCodesModel<'a> {
    #[serde(rename = "recoveryCodes")]
    pub recovery_codes: &'a [String],
}

/// `mfaChallengeSecret`.
pub struct MfaChallengeSecretModel<'a> {
    pub challenge: &'a Challenge,
}

impl Serialize for MfaChallengeSecretModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let c = self.challenge;
        let mut m = serializer.serialize_map(Some(5))?;
        m.serialize_entry("$id", &c.id)?;
        m.serialize_entry("$createdAt", &dt(&c.created_at))?;
        m.serialize_entry("userId", &c.user_id)?;
        m.serialize_entry("expire", &dt(&c.expire))?;
        m.serialize_entry("code", s(&c.code))?;
        m.end()
    }
}

/// `passkey`.
pub struct PasskeyModel<'a> {
    pub passkey: &'a Authenticator,
}

impl Serialize for PasskeyModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let p = self.passkey;
        let mut m = serializer.serialize_map(Some(5))?;
        m.serialize_entry("$id", &p.id)?;
        m.serialize_entry("$createdAt", &dt(&p.created_at))?;
        m.serialize_entry("$updatedAt", &dt(&p.updated_at))?;
        m.serialize_entry("name", s(&p.name))?;
        m.serialize_entry("accessedAt", &dt(&p.accessed_at))?;
        m.end()
    }
}

/// `{total, passkeys}`.
pub struct PasskeyListModel<'a> {
    pub total: i64,
    pub passkeys: &'a [Authenticator],
}

impl Serialize for PasskeyListModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(Some(2))?;
        m.serialize_entry("total", &self.total)?;
        let items: Vec<PasskeyModel<'_>> = self.passkeys.iter().map(|p| PasskeyModel { passkey: p }).collect();
        m.serialize_entry("passkeys", &items)?;
        m.end()
    }
}

/// `jwt`.
#[derive(Serialize)]
pub struct JwtModel<'a> {
    pub jwt: &'a str,
}

/// Error body (`error` / `errorDev`).
pub struct ErrorModel<'a> {
    pub message: &'a str,
    pub code: u16,
    pub kind: &'a str,
    pub version: &'a str,
    pub dev: Option<(&'a str, u32)>,
}

impl Serialize for ErrorModel<'_> {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        let mut m = serializer.serialize_map(None)?;
        m.serialize_entry("message", self.message)?;
        m.serialize_entry("code", &self.code)?;
        m.serialize_entry("type", self.kind)?;
        m.serialize_entry("version", self.version)?;
        if let Some((file, line)) = self.dev {
            m.serialize_entry("file", file)?;
            m.serialize_entry("line", &line)?;
            m.serialize_entry("trace", &Vec::<Value>::new())?;
        }
        m.end()
    }
}
