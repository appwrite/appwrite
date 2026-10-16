//! Model type identifiers and rule specs.
//!
//! Rust port of the subset of `Appwrite\Utopia\Response::MODEL_*` constants and
//! `Appwrite\Utopia\Response\Model\*` rule definitions needed by the Users API
//! (`src/Appwrite/Utopia/Response.php`, `src/Appwrite/Utopia/Response/Model/*.php`).

/// No response body (PHP `Response::MODEL_NONE`).
pub const MODEL_NONE: &str = "none";
/// Error payload (PHP `Response::MODEL_ERROR`).
pub const MODEL_ERROR: &str = "error";

pub const MODEL_USER: &str = "user";
pub const MODEL_USER_LIST: &str = "userList";
pub const MODEL_SESSION: &str = "session";
pub const MODEL_SESSION_LIST: &str = "sessionList";
pub const MODEL_TOKEN: &str = "token";
pub const MODEL_JWT: &str = "jwt";
pub const MODEL_PREFERENCES: &str = "preferences";
pub const MODEL_TARGET: &str = "target";
pub const MODEL_TARGET_LIST: &str = "targetList";
pub const MODEL_MEMBERSHIP: &str = "membership";
pub const MODEL_MEMBERSHIP_LIST: &str = "membershipList";
pub const MODEL_IDENTITY: &str = "identity";
pub const MODEL_IDENTITY_LIST: &str = "identityList";
pub const MODEL_MFA_FACTORS: &str = "mfaFactors";
pub const MODEL_MFA_RECOVERY_CODES: &str = "mfaRecoveryCodes";
pub const MODEL_MFA_CHALLENGE_SECRET: &str = "mfaChallengeSecret";

/// Rule value type. Mirrors PHP `Model::TYPE_*` plus a `Model` variant for
/// rules whose `type` is another model name (e.g. `User::prefs` ->
/// `Response::MODEL_PREFERENCES`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum RuleType {
    String,
    Boolean,
    Integer,
    Datetime,
    /// PHP `Any`-typed / free-form JSON rule (e.g. `hashOptions`).
    Json,
    /// Nested model, referenced by its model-type key (e.g. `MODEL_TARGET`).
    Model(&'static str),
}

/// A single model field. Rust port of one PHP `Model::addRule()` call.
#[derive(Debug, Clone, Copy)]
pub struct Rule {
    pub name: &'static str,
    pub kind: RuleType,
    pub array: bool,
    pub required: bool,
}

impl Rule {
    #[must_use]
    pub const fn new(name: &'static str, kind: RuleType) -> Self {
        Self {
            name,
            kind,
            array: false,
            required: true,
        }
    }

    #[must_use]
    pub const fn array(mut self) -> Self {
        self.array = true;
        self
    }

    #[must_use]
    pub const fn optional(mut self) -> Self {
        self.required = false;
        self
    }
}

/// Rule trait shared by every model spec. Rust port of the common surface of
/// PHP `Appwrite\Utopia\Response\Model`.
pub trait ModelDef {
    /// Model display name (PHP `Model::getName()`).
    fn name(&self) -> &'static str;
    /// Model type key used in [`crate::dynamic`] lookups (PHP `Model::getType()`).
    fn model_type(&self) -> &'static str;
    /// Ordered field rules (PHP `Model::getRules()`).
    fn rules(&self) -> &'static [Rule];
}

/// A concrete, statically-defined model spec.
#[derive(Debug, Clone, Copy)]
pub struct ModelSpec {
    pub name: &'static str,
    pub model_type: &'static str,
    pub rules: &'static [Rule],
}

impl ModelDef for ModelSpec {
    fn name(&self) -> &'static str {
        self.name
    }

    fn model_type(&self) -> &'static str {
        self.model_type
    }

    fn rules(&self) -> &'static [Rule] {
        self.rules
    }
}

/// A `BaseList` model spec: `{ total, <key>: [<item_model> ...] }`.
/// Rust port of `Appwrite\Utopia\Response\Model\BaseList`.
#[derive(Debug, Clone, Copy)]
pub struct ListSpec {
    pub name: &'static str,
    pub model_type: &'static str,
    /// JSON key holding the item array (PHP `BaseList` `$key`, e.g. `"users"`).
    pub key: &'static str,
    /// Model type of each item (PHP `BaseList` `$model`).
    pub item_model: &'static str,
}

use RuleType::{Boolean, Datetime, Json, Model, String as Str};

pub(crate) const USER_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("$updatedAt", Datetime),
    Rule::new("name", Str),
    Rule::new("password", Str).optional(),
    Rule::new("hash", Str).optional(),
    Rule::new("hashOptions", Json).optional(),
    Rule::new("registration", Datetime),
    Rule::new("status", Boolean),
    Rule::new("labels", Str).array(),
    Rule::new("passwordUpdate", Datetime),
    Rule::new("email", Str),
    Rule::new("phone", Str),
    Rule::new("emailVerification", Boolean),
    Rule::new("emailCanonical", Str).optional(),
    Rule::new("emailIsFree", Boolean).optional(),
    Rule::new("emailIsDisposable", Boolean).optional(),
    Rule::new("emailIsCorporate", Boolean).optional(),
    Rule::new("emailIsCanonical", Boolean).optional(),
    Rule::new("phoneVerification", Boolean),
    Rule::new("mfa", Boolean),
    Rule::new("prefs", Model(MODEL_PREFERENCES)),
    Rule::new("targets", Model(MODEL_TARGET)).array(),
    Rule::new("accessedAt", Datetime),
    Rule::new("impersonator", Boolean).optional(),
    Rule::new("impersonatorUserId", Str).optional(),
];

pub(crate) const SESSION_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("$updatedAt", Datetime),
    Rule::new("userId", Str),
    Rule::new("expire", Datetime),
    Rule::new("provider", Str),
    Rule::new("providerUid", Str),
    Rule::new("providerAccessToken", Str),
    Rule::new("providerAccessTokenExpiry", Datetime),
    Rule::new("providerRefreshToken", Str),
    Rule::new("ip", Str),
    Rule::new("osCode", Str),
    Rule::new("osName", Str),
    Rule::new("osVersion", Str),
    Rule::new("clientType", Str),
    Rule::new("clientCode", Str),
    Rule::new("clientName", Str),
    Rule::new("clientVersion", Str),
    Rule::new("clientEngine", Str),
    Rule::new("clientEngineVersion", Str),
    Rule::new("deviceName", Str),
    Rule::new("deviceBrand", Str),
    Rule::new("deviceModel", Str),
    Rule::new("countryCode", Str),
    Rule::new("countryName", Str),
    Rule::new("current", Boolean),
    Rule::new("factors", Str).array(),
    Rule::new("secret", Str),
    Rule::new("mfaUpdatedAt", Datetime),
];

pub(crate) const TOKEN_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("userId", Str),
    Rule::new("secret", Str),
    Rule::new("expire", Datetime),
    Rule::new("phrase", Str),
];

pub(crate) const JWT_RULES: &[Rule] = &[Rule::new("jwt", Str)];

pub(crate) const TARGET_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("$updatedAt", Datetime),
    Rule::new("name", Str),
    Rule::new("userId", Str),
    Rule::new("providerId", Str).optional(),
    Rule::new("providerType", Str),
    Rule::new("identifier", Str),
    Rule::new("expired", Boolean),
];

pub(crate) const MEMBERSHIP_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("$updatedAt", Datetime),
    Rule::new("userId", Str),
    Rule::new("userName", Str),
    Rule::new("userEmail", Str),
    Rule::new("userPhone", Str),
    Rule::new("teamId", Str),
    Rule::new("teamName", Str),
    Rule::new("invited", Datetime),
    Rule::new("joined", Datetime),
    Rule::new("confirm", Boolean),
    Rule::new("mfa", Boolean),
    Rule::new("userAccessedAt", Datetime),
    Rule::new("roles", Str).array(),
];

pub(crate) const IDENTITY_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("$updatedAt", Datetime),
    Rule::new("userId", Str),
    Rule::new("provider", Str),
    Rule::new("providerUid", Str),
    Rule::new("providerEmail", Str),
    Rule::new("providerAccessToken", Str),
    Rule::new("providerAccessTokenExpiry", Datetime),
    Rule::new("providerRefreshToken", Str),
];

/// PHP `Appwrite\Auth\MFA\Type` constant values, used as `MFAFactors` rule
/// names.
pub(crate) const MFA_FACTORS_RULES: &[Rule] = &[
    Rule::new("totp", Boolean),
    Rule::new("phone", Boolean),
    Rule::new("email", Boolean),
    Rule::new("recoveryCode", Boolean),
    Rule::new("custom", Boolean),
];

pub(crate) const MFA_RECOVERY_CODES_RULES: &[Rule] = &[Rule::new("recoveryCodes", Str).array()];

/// `MFAChallenge` base rules + `code` (PHP `MFAChallengeSecret extends MFAChallenge`).
pub(crate) const MFA_CHALLENGE_SECRET_RULES: &[Rule] = &[
    Rule::new("$id", Str),
    Rule::new("$createdAt", Datetime),
    Rule::new("userId", Str),
    Rule::new("expire", Datetime),
    Rule::new("code", Str),
];

pub(crate) const MODEL_SPECS: &[ModelSpec] = &[
    ModelSpec {
        name: "User",
        model_type: MODEL_USER,
        rules: USER_RULES,
    },
    ModelSpec {
        name: "Session",
        model_type: MODEL_SESSION,
        rules: SESSION_RULES,
    },
    ModelSpec {
        name: "Token",
        model_type: MODEL_TOKEN,
        rules: TOKEN_RULES,
    },
    ModelSpec {
        name: "JWT",
        model_type: MODEL_JWT,
        rules: JWT_RULES,
    },
    ModelSpec {
        name: "Target",
        model_type: MODEL_TARGET,
        rules: TARGET_RULES,
    },
    ModelSpec {
        name: "Membership",
        model_type: MODEL_MEMBERSHIP,
        rules: MEMBERSHIP_RULES,
    },
    ModelSpec {
        name: "Identity",
        model_type: MODEL_IDENTITY,
        rules: IDENTITY_RULES,
    },
    ModelSpec {
        name: "MFAFactors",
        model_type: MODEL_MFA_FACTORS,
        rules: MFA_FACTORS_RULES,
    },
    ModelSpec {
        name: "MFA Recovery Codes",
        model_type: MODEL_MFA_RECOVERY_CODES,
        rules: MFA_RECOVERY_CODES_RULES,
    },
    ModelSpec {
        name: "MFA Challenge Secret",
        model_type: MODEL_MFA_CHALLENGE_SECRET,
        rules: MFA_CHALLENGE_SECRET_RULES,
    },
];

/// PHP `app/init/models.php` `BaseList` registrations for the Users API.
pub(crate) const LIST_SPECS: &[ListSpec] = &[
    ListSpec {
        name: "Users List",
        model_type: MODEL_USER_LIST,
        key: "users",
        item_model: MODEL_USER,
    },
    ListSpec {
        name: "Sessions List",
        model_type: MODEL_SESSION_LIST,
        key: "sessions",
        item_model: MODEL_SESSION,
    },
    ListSpec {
        name: "Target list",
        model_type: MODEL_TARGET_LIST,
        key: "targets",
        item_model: MODEL_TARGET,
    },
    ListSpec {
        name: "Memberships List",
        model_type: MODEL_MEMBERSHIP_LIST,
        key: "memberships",
        item_model: MODEL_MEMBERSHIP,
    },
    ListSpec {
        name: "Identities List",
        model_type: MODEL_IDENTITY_LIST,
        key: "identities",
        item_model: MODEL_IDENTITY,
    },
];

#[must_use]
pub fn spec(model_type: &str) -> Option<&'static ModelSpec> {
    MODEL_SPECS
        .iter()
        .find(|spec| spec.model_type == model_type)
}

#[must_use]
pub fn list_spec(model_type: &str) -> Option<&'static ListSpec> {
    LIST_SPECS.iter().find(|spec| spec.model_type == model_type)
}
