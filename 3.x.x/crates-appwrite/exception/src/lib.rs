//! Appwrite exception and error types.
//!
//! Rust port of PHP `Appwrite\Extend\Exception` (`src/Appwrite/Extend/Exception.php`)
//! and its error catalog (`app/config/errors.php`).
//!
//! ```
//! use appwrite_exception::Exception;
//!
//! let err = Exception::new(Exception::USER_NOT_FOUND);
//! assert_eq!(err.code(), 404);
//! assert_eq!(err.type_(), Exception::USER_NOT_FOUND);
//!
//! let json = err.to_json();
//! assert_eq!(json["type"], Exception::USER_NOT_FOUND);
//! ```

mod errors;
mod types;

use serde::{Deserialize, Serialize};
use serde_json::{json, Value};

pub use types::*;

/// Server version reported in [`Exception::to_json`] unless overridden with
/// [`Exception::with_version`]. Mirrors PHP `Error` model `version` field,
/// which the server fills in from `APP_VERSION_STABLE`.
pub const DEFAULT_VERSION: &str = env!("CARGO_PKG_VERSION");

/// Root Appwrite error type. Rust port of `Appwrite\Extend\Exception`.
///
/// Error type identifiers (`Exception::USER_NOT_FOUND`, etc.) are exposed as
/// associated constants below, mirroring the PHP class constants of the same
/// name so lookups read the same at call sites (`Exception::new(Exception::USER_NOT_FOUND)`).
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Exception {
    #[serde(rename = "type")]
    type_: String,
    message: String,
    code: u16,
    version: String,
}

impl Exception {
    // Re-exported as associated constants so `Exception::GENERAL_UNKNOWN` reads
    // like the PHP `Exception::GENERAL_UNKNOWN` class constant it mirrors.
    pub const GENERAL_UNKNOWN: &'static str = GENERAL_UNKNOWN;
    pub const GENERAL_MOCK: &'static str = GENERAL_MOCK;
    pub const GENERAL_ACCESS_FORBIDDEN: &'static str = GENERAL_ACCESS_FORBIDDEN;
    pub const GENERAL_RESOURCE_BLOCKED: &'static str = GENERAL_RESOURCE_BLOCKED;
    pub const GENERAL_UNKNOWN_ORIGIN: &'static str = GENERAL_UNKNOWN_ORIGIN;
    pub const GENERAL_API_DISABLED: &'static str = GENERAL_API_DISABLED;
    pub const GENERAL_SERVICE_DISABLED: &'static str = GENERAL_SERVICE_DISABLED;
    pub const GENERAL_UNAUTHORIZED_SCOPE: &'static str = GENERAL_UNAUTHORIZED_SCOPE;
    pub const GENERAL_RATE_LIMIT_EXCEEDED: &'static str = GENERAL_RATE_LIMIT_EXCEEDED;
    pub const GENERAL_RESOURCE_LOCKED: &'static str = GENERAL_RESOURCE_LOCKED;
    pub const GENERAL_SMTP_DISABLED: &'static str = GENERAL_SMTP_DISABLED;
    pub const GENERAL_PHONE_DISABLED: &'static str = GENERAL_PHONE_DISABLED;
    pub const GENERAL_ARGUMENT_INVALID: &'static str = GENERAL_ARGUMENT_INVALID;
    pub const GENERAL_COLUMN_QUERY_LIMIT_EXCEEDED: &'static str =
        GENERAL_COLUMN_QUERY_LIMIT_EXCEEDED;
    pub const GENERAL_ATTRIBUTE_QUERY_LIMIT_EXCEEDED: &'static str =
        GENERAL_ATTRIBUTE_QUERY_LIMIT_EXCEEDED;
    pub const GENERAL_QUERY_INVALID: &'static str = GENERAL_QUERY_INVALID;
    pub const GENERAL_ROUTE_NOT_FOUND: &'static str = GENERAL_ROUTE_NOT_FOUND;
    pub const GENERAL_CURSOR_NOT_FOUND: &'static str = GENERAL_CURSOR_NOT_FOUND;
    pub const GENERAL_SERVER_ERROR: &'static str = GENERAL_SERVER_ERROR;
    pub const GENERAL_PROTOCOL_UNSUPPORTED: &'static str = GENERAL_PROTOCOL_UNSUPPORTED;
    pub const GENERAL_FEATURE_UNSUPPORTED: &'static str = GENERAL_FEATURE_UNSUPPORTED;
    pub const GENERAL_CODES_DISABLED: &'static str = GENERAL_CODES_DISABLED;
    pub const GENERAL_USAGE_DISABLED: &'static str = GENERAL_USAGE_DISABLED;
    pub const GENERAL_NOT_IMPLEMENTED: &'static str = GENERAL_NOT_IMPLEMENTED;
    pub const GENERAL_INVALID_EMAIL: &'static str = GENERAL_INVALID_EMAIL;
    pub const GENERAL_INVALID_PHONE: &'static str = GENERAL_INVALID_PHONE;
    pub const GENERAL_REGION_ACCESS_DENIED: &'static str = GENERAL_REGION_ACCESS_DENIED;
    pub const GENERAL_BAD_REQUEST: &'static str = GENERAL_BAD_REQUEST;

    pub const USER_COUNT_EXCEEDED: &'static str = USER_COUNT_EXCEEDED;
    pub const USER_CONSOLE_COUNT_EXCEEDED: &'static str = USER_CONSOLE_COUNT_EXCEEDED;
    pub const USER_JWT_INVALID: &'static str = USER_JWT_INVALID;
    pub const USER_ALREADY_EXISTS: &'static str = USER_ALREADY_EXISTS;
    pub const USER_BLOCKED: &'static str = USER_BLOCKED;
    pub const USER_INVALID_TOKEN: &'static str = USER_INVALID_TOKEN;
    pub const USER_PASSWORD_RESET_REQUIRED: &'static str = USER_PASSWORD_RESET_REQUIRED;
    pub const USER_EMAIL_NOT_WHITELISTED: &'static str = USER_EMAIL_NOT_WHITELISTED;
    pub const USER_IP_NOT_WHITELISTED: &'static str = USER_IP_NOT_WHITELISTED;
    pub const USER_INVALID_CODE: &'static str = USER_INVALID_CODE;
    pub const USER_INVALID_CREDENTIALS: &'static str = USER_INVALID_CREDENTIALS;
    pub const USER_ANONYMOUS_CONSOLE_PROHIBITED: &'static str = USER_ANONYMOUS_CONSOLE_PROHIBITED;
    pub const USER_SESSION_ALREADY_EXISTS: &'static str = USER_SESSION_ALREADY_EXISTS;
    pub const USER_NOT_FOUND: &'static str = USER_NOT_FOUND;
    pub const USER_PASSWORD_RECENTLY_USED: &'static str = USER_PASSWORD_RECENTLY_USED;
    pub const USER_PASSWORD_PERSONAL_DATA: &'static str = USER_PASSWORD_PERSONAL_DATA;
    pub const USER_EMAIL_ALREADY_EXISTS: &'static str = USER_EMAIL_ALREADY_EXISTS;
    pub const USER_EMAIL_DISPOSABLE: &'static str = USER_EMAIL_DISPOSABLE;
    pub const USER_EMAIL_FREE: &'static str = USER_EMAIL_FREE;
    pub const USER_EMAIL_NOT_CORPORATE: &'static str = USER_EMAIL_NOT_CORPORATE;
    pub const USER_EMAIL_NOT_CANONICAL: &'static str = USER_EMAIL_NOT_CANONICAL;
    pub const USER_PASSWORD_MISMATCH: &'static str = USER_PASSWORD_MISMATCH;
    pub const USER_SESSION_NOT_FOUND: &'static str = USER_SESSION_NOT_FOUND;
    pub const USER_IDENTITY_NOT_FOUND: &'static str = USER_IDENTITY_NOT_FOUND;
    pub const USER_UNAUTHORIZED: &'static str = USER_UNAUTHORIZED;
    pub const USER_AUTH_METHOD_UNSUPPORTED: &'static str = USER_AUTH_METHOD_UNSUPPORTED;
    pub const USER_PHONE_ALREADY_EXISTS: &'static str = USER_PHONE_ALREADY_EXISTS;
    pub const USER_PHONE_NOT_FOUND: &'static str = USER_PHONE_NOT_FOUND;
    pub const USER_PHONE_NOT_VERIFIED: &'static str = USER_PHONE_NOT_VERIFIED;
    pub const USER_EMAIL_NOT_FOUND: &'static str = USER_EMAIL_NOT_FOUND;
    pub const USER_EMAIL_NOT_VERIFIED: &'static str = USER_EMAIL_NOT_VERIFIED;
    pub const USER_MISSING_ID: &'static str = USER_MISSING_ID;
    pub const USER_MORE_FACTORS_REQUIRED: &'static str = USER_MORE_FACTORS_REQUIRED;
    pub const USER_AUTHENTICATOR_NOT_FOUND: &'static str = USER_AUTHENTICATOR_NOT_FOUND;
    pub const USER_AUTHENTICATOR_ALREADY_VERIFIED: &'static str =
        USER_AUTHENTICATOR_ALREADY_VERIFIED;
    pub const USER_RECOVERY_CODES_ALREADY_EXISTS: &'static str = USER_RECOVERY_CODES_ALREADY_EXISTS;
    pub const USER_RECOVERY_CODES_NOT_FOUND: &'static str = USER_RECOVERY_CODES_NOT_FOUND;
    pub const USER_CHALLENGE_REQUIRED: &'static str = USER_CHALLENGE_REQUIRED;
    pub const USER_OAUTH2_BAD_REQUEST: &'static str = USER_OAUTH2_BAD_REQUEST;
    pub const USER_OAUTH2_UNAUTHORIZED: &'static str = USER_OAUTH2_UNAUTHORIZED;
    pub const USER_OAUTH2_PROVIDER_ERROR: &'static str = USER_OAUTH2_PROVIDER_ERROR;
    pub const USER_OAUTH2_PROVIDER_FAILURE: &'static str = USER_OAUTH2_PROVIDER_FAILURE;
    pub const USER_EMAIL_ALREADY_VERIFIED: &'static str = USER_EMAIL_ALREADY_VERIFIED;
    pub const USER_PHONE_ALREADY_VERIFIED: &'static str = USER_PHONE_ALREADY_VERIFIED;
    pub const USER_DELETION_PROHIBITED: &'static str = USER_DELETION_PROHIBITED;
    pub const USER_TARGET_NOT_FOUND: &'static str = USER_TARGET_NOT_FOUND;
    pub const USER_TARGET_ALREADY_EXISTS: &'static str = USER_TARGET_ALREADY_EXISTS;
    pub const USER_API_KEY_AND_SESSION_SET: &'static str = USER_API_KEY_AND_SESSION_SET;
    pub const USER_JWT_AND_COOKIE_SET: &'static str = USER_JWT_AND_COOKIE_SET;
    pub const USER_JWT_CREATION_DENIED: &'static str = USER_JWT_CREATION_DENIED;

    pub const API_KEY_EXPIRED: &'static str = API_KEY_EXPIRED;

    pub const PROJECT_NOT_FOUND: &'static str = PROJECT_NOT_FOUND;
    pub const PROJECT_ID_MISSING: &'static str = PROJECT_ID_MISSING;
    pub const PROJECT_PROVIDER_DISABLED: &'static str = PROJECT_PROVIDER_DISABLED;
    pub const PROJECT_PROVIDER_UNSUPPORTED: &'static str = PROJECT_PROVIDER_UNSUPPORTED;
    pub const PROJECT_ALREADY_EXISTS: &'static str = PROJECT_ALREADY_EXISTS;
    pub const PROJECT_INVALID_SUCCESS_URL: &'static str = PROJECT_INVALID_SUCCESS_URL;
    pub const PROJECT_INVALID_FAILURE_URL: &'static str = PROJECT_INVALID_FAILURE_URL;
    pub const PROJECT_RESERVED_PROJECT: &'static str = PROJECT_RESERVED_PROJECT;
    pub const PROJECT_KEY_EXPIRED: &'static str = PROJECT_KEY_EXPIRED;
    pub const ACCOUNT_KEY_EXPIRED: &'static str = ACCOUNT_KEY_EXPIRED;
    pub const PROJECT_SMTP_CONFIG_INVALID: &'static str = PROJECT_SMTP_CONFIG_INVALID;
    pub const PROJECT_TEMPLATE_DEFAULT_DELETION: &'static str = PROJECT_TEMPLATE_DEFAULT_DELETION;
    pub const PROJECT_REGION_UNSUPPORTED: &'static str = PROJECT_REGION_UNSUPPORTED;
    pub const PROJECT_UNKNOWN: &'static str = PROJECT_UNKNOWN;

    /// PHP `new Exception($type)`: look up the default code/message for `type_`
    /// in the error catalog. Unknown types fall back to a `500` with a
    /// diagnostic message (PHP would instead emit an undefined-array-key
    /// warning and a null code).
    #[must_use]
    pub fn new(type_: impl Into<String>) -> Self {
        let type_ = type_.into();
        let (code, message) = match errors::lookup(&type_) {
            Some(spec) => (spec.code, spec.message.to_string()),
            None => (500, format!("Unknown error type: {type_}")),
        };
        Self {
            type_,
            message,
            code,
            version: DEFAULT_VERSION.to_string(),
        }
    }

    /// PHP `new Exception($type, $message)`: same lookup as [`Self::new`] but
    /// with an explicit message overriding the catalog default.
    #[must_use]
    pub fn with_message(type_: impl Into<String>, message: impl Into<String>) -> Self {
        let mut exception = Self::new(type_);
        exception.message = message.into();
        exception
    }

    /// PHP `new Exception($type, null, $code)`: same lookup as [`Self::new`]
    /// but with an explicit status code overriding the catalog default.
    #[must_use]
    pub fn new_with_code(type_: impl Into<String>, code: u16) -> Self {
        let mut exception = Self::new(type_);
        exception.code = code;
        exception
    }

    /// Override the status code (builder style).
    #[must_use]
    pub fn with_code(mut self, code: u16) -> Self {
        self.code = code;
        self
    }

    /// Override the reported server version (builder style). Defaults to
    /// [`DEFAULT_VERSION`]; production callers should set this to the running
    /// Appwrite server version.
    #[must_use]
    pub fn with_version(mut self, version: impl Into<String>) -> Self {
        self.version = version.into();
        self
    }

    /// PHP `Exception::getType()`.
    #[must_use]
    pub fn type_(&self) -> &str {
        &self.type_
    }

    /// Error message.
    #[must_use]
    pub fn message(&self) -> &str {
        &self.message
    }

    /// HTTP status code.
    #[must_use]
    pub fn code(&self) -> u16 {
        self.code
    }

    /// Reported server version.
    #[must_use]
    pub fn version(&self) -> &str {
        &self.version
    }

    /// PHP `Exception::isPublishable()`: whether this error should be
    /// published to error tracking. Catalog entries may set an explicit
    /// `publish` flag (e.g. [`Self::USER_AUTH_METHOD_UNSUPPORTED`]); otherwise
    /// PHP falls back to `code >= 500`.
    #[must_use]
    pub fn is_publishable(&self) -> bool {
        errors::lookup(&self.type_)
            .and_then(|spec| spec.publish)
            .unwrap_or(self.code >= 500)
    }

    /// Default status code for a given error type, `500` when unknown.
    #[must_use]
    pub fn default_code(type_: &str) -> u16 {
        errors::lookup(type_).map_or(500, |spec| spec.code)
    }

    /// Default message for a given error type, empty string when unknown.
    #[must_use]
    pub fn default_message(type_: &str) -> String {
        errors::lookup(type_).map_or_else(String::new, |spec| spec.message.to_string())
    }

    /// PHP `Response\Model\Error`: `{ message, code, type, version }`.
    #[must_use]
    pub fn to_json(&self) -> Value {
        json!({
            "message": self.message,
            "code": self.code,
            "type": self.type_,
            "version": self.version,
        })
    }
}

impl std::fmt::Display for Exception {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{} ({}): {}", self.type_, self.code, self.message)
    }
}

impl std::error::Error for Exception {}
