//! Users, teams, memberships, password hashes and project auth settings.

use php_std::{Array, Value};

use super::{Input, cast_bool, cast_int, param, to_array, to_bool, to_int, to_opt_string, to_string};
use crate::error::Result;
use crate::resource::{Base, Kind, Resource, ResourceRef};

/// The project's enabled auth methods.
///
/// PHP: `Utopia\Migration\Resources\Auth\AuthMethods`.
#[derive(Debug, Clone, PartialEq)]
pub struct AuthMethods {
    /// Email and password sign in.
    pub email_password: bool,
    /// Magic URL sign in.
    pub magic_url: bool,
    /// Email OTP sign in.
    pub email_otp: bool,
    /// Anonymous sessions.
    pub anonymous: bool,
    /// Team invites.
    pub invites: bool,
    /// JWTs.
    pub jwt: bool,
    /// Phone sign in.
    pub phone: bool,
}

impl Default for AuthMethods {
    fn default() -> Self {
        Self {
            email_password: true,
            magic_url: true,
            email_otp: true,
            anonymous: true,
            invites: true,
            jwt: true,
            phone: true,
        }
    }
}

impl AuthMethods {
    /// A resource of these settings.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::AuthMethods(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `AuthMethods::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\AuthMethods::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let b = |k: &str| cast_bool(&i.or(k, Value::Bool(true)));
        let m = AuthMethods {
            email_password: b("emailPassword"),
            magic_url: b("magicURL"),
            email_otp: b("emailOtp"),
            anonymous: b("anonymous"),
            invites: b("invites"),
            jwt: b("jwt"),
            phone: b("phone"),
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(9, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(10, "updatedAt"))?;
        Ok(m.resource(id, created, updated))
    }
}

/// A password hash and the parameters of its algorithm.
///
/// PHP: `Utopia\Migration\Resources\Auth\Hash` (`ALGORITHM_*` are [`Hash`]
/// associated constants).
#[derive(Debug, Clone, PartialEq)]
pub struct Hash {
    /// The hash.
    pub hash: String,
    /// The salt.
    pub salt: String,
    /// The algorithm.
    pub algorithm: String,
    /// The salt separator (Firebase's modified scrypt).
    pub separator: String,
    /// The signing key (Firebase's modified scrypt).
    pub signing_key: String,
    /// scrypt CPU cost.
    pub password_cpu: i64,
    /// scrypt memory cost.
    pub password_memory: i64,
    /// scrypt parallelization.
    pub password_parallel: i64,
    /// scrypt key length.
    pub password_length: i64,
}

impl Hash {
    /// Firebase's modified scrypt.
    pub const ALGORITHM_SCRYPT_MODIFIED: &'static str = "scryptModified";
    /// bcrypt.
    pub const ALGORITHM_BCRYPT: &'static str = "bcrypt";
    /// MD5.
    pub const ALGORITHM_MD5: &'static str = "md5";
    /// Argon2.
    pub const ALGORITHM_ARGON2: &'static str = "argon2";
    /// SHA-256.
    pub const ALGORITHM_SHA256: &'static str = "sha256";
    /// PHPass.
    pub const ALGORITHM_PHPASS: &'static str = "phpass";
    /// scrypt.
    pub const ALGORITHM_SCRYPT: &'static str = "scrypt";
    /// A plain text password.
    pub const ALGORITHM_PLAINTEXT: &'static str = "plainText";

    /// A hash with the default parameters (SHA-256, no salt).
    pub fn new(hash: impl Into<String>) -> Self {
        Self {
            hash: hash.into(),
            salt: String::new(),
            algorithm: Self::ALGORITHM_SHA256.to_owned(),
            separator: String::new(),
            signing_key: String::new(),
            password_cpu: 0,
            password_memory: 0,
            password_parallel: 0,
            password_length: 0,
        }
    }

    /// A resource of this hash (with an empty id).
    pub fn resource(self) -> ResourceRef {
        Resource::new(Kind::Hash(self), Base::default())
    }

    /// PHP: `Hash::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\Hash::__construct", n, name);
        Ok(Hash {
            hash: to_string(&i.or("hash", Value::from("")), &p(1, "hash"))?,
            salt: to_string(&i.or("salt", Value::from("")), &p(2, "salt"))?,
            algorithm: to_string(&i.or("algorithm", Value::from(Self::ALGORITHM_SHA256)), &p(3, "algorithm"))?,
            separator: to_string(&i.or("separator", Value::from("")), &p(4, "separator"))?,
            signing_key: to_string(&i.or("signingKey", Value::from("")), &p(5, "signingKey"))?,
            password_cpu: to_int(&i.or("passwordCpu", Value::Int(0)), &p(6, "passwordCpu"))?,
            password_memory: to_int(&i.or("passwordMemory", Value::Int(0)), &p(7, "passwordMemory"))?,
            password_parallel: to_int(&i.or("passwordParallel", Value::Int(0)), &p(8, "passwordParallel"))?,
            password_length: to_int(&i.or("passwordLength", Value::Int(0)), &p(9, "passwordLength"))?,
        }
        .resource())
    }
}

/// A user's membership of a team.
///
/// PHP: `Utopia\Migration\Resources\Auth\Membership`.
#[derive(Debug)]
pub struct Membership {
    /// The team.
    pub team: ResourceRef,
    /// The user.
    pub user: ResourceRef,
    /// The roles.
    pub roles: Array,
    /// Whether the membership is confirmed.
    pub active: bool,
}

impl Membership {
    /// A resource of this membership.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::Membership(self), id)
    }

    /// PHP: `Membership::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\Membership::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let team = Team::from_array(&to_array(&i.or("team", Value::Array(Array::new())), &p(2, "team"))?)?;
        let user = User::from_array(&to_array(&i.or("user", Value::Array(Array::new())), &p(3, "user"))?)?;
        let roles = to_array(&i.or("roles", Value::Array(Array::new())), &p(4, "roles"))?;
        let active = to_bool(&i.or("active", Value::Bool(true)), &p(5, "active"))?;
        Ok(Membership { team, user, roles, active }.resource(id))
    }
}

/// The project's auth policies.
///
/// PHP: `Utopia\Migration\Resources\Auth\Policies`.
#[derive(Debug, Clone, PartialEq)]
pub struct Policies {
    /// Passwords remembered to prevent reuse.
    pub password_history: i64,
    /// Session length in seconds.
    pub session_duration: i64,
    /// Sessions per user.
    pub sessions_limit: i64,
    /// Users per project (0: unlimited).
    pub user_limit: i64,
    /// Reject common passwords.
    pub password_dictionary: bool,
    /// Reject passwords containing personal data.
    pub personal_data_check: bool,
    /// Email users on new sessions.
    pub session_alerts: bool,
    /// Invalidate sessions on password change.
    pub session_invalidation: bool,
    /// Show the user id in memberships.
    pub memberships_user_id: bool,
    /// Show the user email in memberships.
    pub memberships_user_email: bool,
    /// Show the user name in memberships.
    pub memberships_user_name: bool,
    /// Show the user MFA status in memberships.
    pub memberships_user_mfa: bool,
    /// Show the user phone in memberships.
    pub memberships_user_phone: bool,
}

impl Default for Policies {
    fn default() -> Self {
        Self {
            password_history: 0,
            session_duration: 31_536_000,
            sessions_limit: 100,
            user_limit: 0,
            password_dictionary: false,
            personal_data_check: false,
            session_alerts: false,
            session_invalidation: false,
            memberships_user_id: true,
            memberships_user_email: true,
            memberships_user_name: true,
            memberships_user_mfa: true,
            memberships_user_phone: true,
        }
    }
}

impl Policies {
    /// A resource of these policies.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::Policies(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// PHP: `Policies::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\Policies::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let d = Policies::default();
        let n = |k: &str, v: i64| cast_int(&i.or(k, Value::Int(v)));
        let b = |k: &str, v: bool| cast_bool(&i.or(k, Value::Bool(v)));
        let policies = Policies {
            password_history: n("passwordHistory", d.password_history),
            session_duration: n("sessionDuration", d.session_duration),
            sessions_limit: n("sessionsLimit", d.sessions_limit),
            user_limit: n("userLimit", d.user_limit),
            password_dictionary: b("passwordDictionary", false),
            personal_data_check: b("personalDataCheck", false),
            session_alerts: b("sessionAlerts", false),
            session_invalidation: b("sessionInvalidation", false),
            memberships_user_id: b("membershipsUserId", true),
            memberships_user_email: b("membershipsUserEmail", true),
            memberships_user_name: b("membershipsUserName", true),
            memberships_user_mfa: b("membershipsUserMfa", true),
            memberships_user_phone: b("membershipsUserPhone", true),
        };
        let created = to_string(&i.or("createdAt", Value::from("")), &p(15, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(16, "updatedAt"))?;
        Ok(policies.resource(id, created, updated))
    }
}

/// A team.
///
/// PHP: `Utopia\Migration\Resources\Auth\Team`.
#[derive(Debug, Clone, PartialEq, Default)]
pub struct Team {
    /// The name (`getTeamName()`).
    pub name: String,
    /// The preferences.
    pub preferences: Array,
    /// The members.
    pub members: Array,
}

impl Team {
    /// A resource of this team.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::Team(self), id)
    }

    /// PHP: `Team::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\Team::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        Ok(Team {
            name: to_string(&i.raw("name"), &p(2, "name"))?,
            preferences: to_array(&i.or("preferences", Value::Array(Array::new())), &p(3, "preferences"))?,
            members: to_array(&i.or("members", Value::Array(Array::new())), &p(4, "members"))?,
        }
        .resource(id))
    }
}

/// A user.
///
/// PHP: `Utopia\Migration\Resources\Auth\User` (`getUsername()` is the name).
#[derive(Debug, Clone)]
pub struct User {
    /// The email.
    pub email: Option<String>,
    /// The name.
    pub username: Option<String>,
    /// The password hash ([`Hash`] resource).
    pub password_hash: Option<ResourceRef>,
    /// The phone number.
    pub phone: Option<String>,
    /// The labels.
    pub labels: Array,
    /// The OAuth provider the user signed up with.
    pub oauth_provider: String,
    /// Whether the email is verified.
    pub email_verified: bool,
    /// Whether the phone is verified.
    pub phone_verified: bool,
    /// Whether the user is blocked.
    pub disabled: bool,
    /// The preferences.
    pub preferences: Array,
    /// The messaging targets (arrays of the API's target model).
    pub targets: Array,
}

impl Default for User {
    fn default() -> Self {
        Self {
            email: Some(String::new()),
            username: Some(String::new()),
            password_hash: None,
            phone: None,
            labels: Array::new(),
            oauth_provider: String::new(),
            email_verified: false,
            phone_verified: false,
            disabled: false,
            preferences: Array::new(),
            targets: Array::new(),
        }
    }
}

impl User {
    /// A resource of this user.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::User(self), id)
    }

    /// The password hash data.
    pub fn hash(&self) -> Option<&Hash> {
        match self.password_hash.as_deref().map(Resource::kind) {
            Some(Kind::Hash(h)) => Some(h),
            _ => None,
        }
    }

    /// PHP: `User::fromArray()`. A `passwordHash` must be a hash resource
    /// already (PHP passes the array value through to a `?Hash` parameter),
    /// so an array there is a `TypeError`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\User::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let email = to_opt_string(&i.or("email", Value::from("")), &p(2, "email"))?;
        let username = to_opt_string(&i.or("username", Value::from("")), &p(3, "username"))?;
        if let Some(v) = i.get("passwordHash") {
            return Err(crate::error::Error::TypeError(format!(
                "Utopia\\Migration\\Resources\\Auth\\User::__construct(): Argument #4 ($passwordHash) must be of type ?Utopia\\Migration\\Resources\\Auth\\Hash, {} given",
                v.debug_type()
            )));
        }
        Ok(User {
            email,
            username,
            password_hash: None,
            phone: to_opt_string(&i.or("phone", Value::from("")), &p(5, "phone"))?,
            labels: to_array(&i.or("labels", Value::Array(Array::new())), &p(6, "labels"))?,
            oauth_provider: to_string(&i.or("oauthProvider", Value::from("")), &p(7, "oauthProvider"))?,
            email_verified: to_bool(&i.or("emailVerified", Value::Bool(false)), &p(8, "emailVerified"))?,
            phone_verified: to_bool(&i.or("phoneVerified", Value::Bool(false)), &p(9, "phoneVerified"))?,
            disabled: to_bool(&i.or("disabled", Value::Bool(false)), &p(10, "disabled"))?,
            preferences: to_array(&i.or("preferences", Value::Array(Array::new())), &p(11, "preferences"))?,
            targets: to_array(&i.or("targets", Value::Array(Array::new())), &p(12, "targets"))?,
        }
        .resource(id))
    }
}

/// How one OAuth2 provider setting maps onto the destination: into the
/// app id, or into the JSON secret under `key` (the setting's own name when
/// `None`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ProviderField {
    /// The setting's name on the source.
    pub field: &'static str,
    /// Whether it is the app id (else a secret field).
    pub app_id: bool,
    /// The name of the secret field, when it differs.
    pub key: Option<&'static str>,
}

const fn app(field: &'static str) -> ProviderField {
    ProviderField { field, app_id: true, key: None }
}

const fn secret(field: &'static str, key: Option<&'static str>) -> ProviderField {
    ProviderField { field, app_id: false, key }
}

const CLIENT: &[ProviderField] = &[app("clientId")];

/// The OAuth2 providers a migration carries, and the settings each copies.
///
/// PHP: `OAuth2Provider::PROVIDERS`.
pub const OAUTH2_PROVIDERS: &[(&str, &[ProviderField])] = &[
    ("amazon", CLIENT),
    ("apple", &[app("serviceId"), secret("keyId", Some("keyID")), secret("teamId", Some("teamID"))]),
    ("appwrite", CLIENT),
    ("auth0", &[app("clientId"), secret("endpoint", None)]),
    ("authentik", &[app("clientId"), secret("endpoint", None)]),
    ("autodesk", CLIENT),
    ("bitbucket", CLIENT),
    ("bitly", CLIENT),
    ("box", CLIENT),
    ("cloudflare", CLIENT),
    ("dailymotion", CLIENT),
    ("discord", CLIENT),
    ("disqus", CLIENT),
    ("dropbox", CLIENT),
    ("etsy", CLIENT),
    ("facebook", CLIENT),
    ("figma", CLIENT),
    ("fusionauth", &[app("clientId"), secret("endpoint", None)]),
    ("github", CLIENT),
    ("gitlab", &[app("clientId"), secret("endpoint", None)]),
    ("google", &[app("clientId"), secret("prompt", None)]),
    ("huggingface", CLIENT),
    ("kakao", CLIENT),
    (
        "keycloak",
        &[app("clientId"), secret("endpoint", Some("keycloakDomain")), secret("realmName", Some("keycloakRealm"))],
    ),
    ("kick", CLIENT),
    ("linkedin", CLIENT),
    ("microsoft", &[app("clientId"), secret("tenant", None)]),
    ("notion", CLIENT),
    (
        "oidc",
        &[
            app("clientId"),
            secret("wellKnownURL", Some("wellKnownEndpoint")),
            secret("authorizationURL", Some("authorizationEndpoint")),
            secret("tokenURL", Some("tokenEndpoint")),
            secret("userInfoURL", Some("userInfoEndpoint")),
        ],
    ),
    ("okta", &[app("clientId"), secret("domain", Some("oktaDomain")), secret("authorizationServerId", None)]),
    ("paypal", CLIENT),
    ("paypalSandbox", CLIENT),
    ("podio", CLIENT),
    ("resend", CLIENT),
    ("salesforce", CLIENT),
    ("slack", CLIENT),
    ("spotify", CLIENT),
    ("stripe", CLIENT),
    ("tiktok", CLIENT),
    ("tradeshift", CLIENT),
    ("tradeshiftBox", CLIENT),
    ("twitch", CLIENT),
    ("webflow", CLIENT),
    ("wordpress", CLIENT),
    ("x", CLIENT),
    ("yahoo", CLIENT),
    ("yandex", CLIENT),
    ("zoho", CLIENT),
    ("zoom", CLIENT),
];

/// The settings descriptor of a provider.
pub fn oauth2_descriptor(provider: &str) -> Option<&'static [ProviderField]> {
    OAUTH2_PROVIDERS.iter().find(|(k, _)| *k == provider).map(|(_, d)| *d)
}

/// An OAuth2 provider's configuration (never its secret).
///
/// PHP: `Utopia\Migration\Resources\Auth\OAuth2\OAuth2Provider`.
#[derive(Debug, Clone, PartialEq)]
pub struct OAuth2Provider {
    /// The provider (`github`, `google`, ...).
    pub provider_key: String,
    /// Whether it is enabled.
    pub enabled: bool,
    /// The copied settings (the descriptor's fields present on the source).
    pub settings: Array,
}

fn is_empty_setting(v: Option<&Value>) -> bool {
    match v {
        None | Some(Value::Null) => true,
        Some(Value::Str(s)) => s.is_empty(),
        Some(Value::Array(a)) => a.is_empty(),
        _ => false,
    }
}

impl OAuth2Provider {
    /// A resource of this provider.
    pub fn resource(
        self,
        id: impl Into<String>,
        created_at: impl Into<String>,
        updated_at: impl Into<String>,
    ) -> ResourceRef {
        Resource::new(
            Kind::OAuth2Provider(self),
            Base { id: id.into(), created_at: created_at.into(), updated_at: updated_at.into(), ..Base::default() },
        )
    }

    /// The provider `provider_key` from the API's provider model, copying
    /// only its descriptor's settings; `None` for a provider without one.
    ///
    /// PHP: `OAuth2Provider::fromArray($providerKey, $array)`.
    pub fn from_array(provider_key: &str, array: &Array) -> Result<Option<ResourceRef>> {
        let Some(descriptor) = oauth2_descriptor(provider_key) else {
            return Ok(None);
        };
        let mut settings = Array::new();
        for f in descriptor {
            if let Some(v) = array.get_str(f.field) {
                settings.set(f.field.into(), v.clone());
            }
        }
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Auth\\OAuth2\\OAuth2Provider::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let created = to_string(&i.or("createdAt", Value::from("")), &p(5, "createdAt"))?;
        let updated = to_string(&i.or("updatedAt", Value::from("")), &p(6, "updatedAt"))?;
        let enabled = cast_bool(&i.or("enabled", Value::Bool(false)));
        Ok(Some(
            OAuth2Provider { provider_key: provider_key.to_owned(), enabled, settings }.resource(id, created, updated),
        ))
    }

    /// The app id the destination stores, when the source set one.
    ///
    /// PHP: `getDestinationAppId()`.
    pub fn destination_app_id(&self) -> Option<String> {
        let field = oauth2_descriptor(&self.provider_key)?.iter().find(|f| f.app_id)?;
        let v = self.settings.get_str(field.field);
        if is_empty_setting(v) {
            return None;
        }
        Some(super::cast_string(v.unwrap_or(&Value::Null)))
    }

    /// The secret JSON fields the destination merges in, by their
    /// destination names.
    ///
    /// PHP: `getDestinationSecretFields()`.
    pub fn destination_secret_fields(&self) -> Array {
        let mut out = Array::new();
        for f in oauth2_descriptor(&self.provider_key).unwrap_or(&[]) {
            if f.app_id {
                continue;
            }
            let Some(v) = self.settings.get_str(f.field) else {
                continue;
            };
            if is_empty_setting(Some(v)) {
                continue;
            }
            out.set(f.key.unwrap_or(f.field).into(), v.clone());
        }
        out
    }

    /// Whether the provider is worth migrating: enabled, or with an app id.
    ///
    /// PHP: `isConfigured()`.
    pub fn is_configured(&self) -> bool {
        self.enabled || self.destination_app_id().is_some()
    }
}
