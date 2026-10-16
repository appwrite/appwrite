# appwrite-exception

Appwrite exception and error types. Rust port of PHP `Appwrite\Extend\Exception`
(`src/Appwrite/Extend/Exception.php`) and its error catalog (`app/config/errors.php`).

## Install

```toml
appwrite-exception = { workspace = true }
```

## API

### `Exception`

```rust
pub struct Exception { /* type_, message, code, version */ }

impl Exception {
    pub fn new(type_: impl Into<String>) -> Self;
    pub fn with_message(type_: impl Into<String>, message: impl Into<String>) -> Self;
    pub fn new_with_code(type_: impl Into<String>, code: u16) -> Self;
    pub fn with_code(self, code: u16) -> Self;
    pub fn with_version(self, version: impl Into<String>) -> Self;

    pub fn type_(&self) -> &str;
    pub fn message(&self) -> &str;
    pub fn code(&self) -> u16;
    pub fn version(&self) -> &str;
    pub fn is_publishable(&self) -> bool;

    pub fn default_code(type_: &str) -> u16;
    pub fn default_message(type_: &str) -> String;

    /// PHP `Response\Model\Error` shape: `{ message, code, type, version }`.
    pub fn to_json(&self) -> serde_json::Value;
}
```

`Exception::new` looks up the default HTTP status code and message for a
`type_` string in the built-in error catalog (mirrors PHP's
`Config::getParam('errors')` lookup driven by `app/config/errors.php`).
Unknown types fall back to `500` with a diagnostic message instead of PHP's
undefined-array-key warning.

`Exception` implements `std::error::Error` and `Display`, and derives
`Serialize`/`Deserialize` (the `type_` field serializes as `"type"`).

### Error type identifiers

Every PHP `Exception::<NAME>` class constant needed by the Users API and
shared server plumbing is available both as an associated constant on
`Exception` (`Exception::USER_NOT_FOUND`, matching the PHP call-site spelling)
and as a free constant at the crate root (`appwrite_exception::USER_NOT_FOUND`):

| Group | Identifiers |
|-------|-------------|
| General | `GENERAL_UNKNOWN`, `GENERAL_MOCK`, `GENERAL_ACCESS_FORBIDDEN`, `GENERAL_RESOURCE_BLOCKED`, `GENERAL_UNKNOWN_ORIGIN`, `GENERAL_API_DISABLED`, `GENERAL_SERVICE_DISABLED`, `GENERAL_UNAUTHORIZED_SCOPE`, `GENERAL_RATE_LIMIT_EXCEEDED`, `GENERAL_RESOURCE_LOCKED`, `GENERAL_SMTP_DISABLED`, `GENERAL_PHONE_DISABLED`, `GENERAL_ARGUMENT_INVALID`, `GENERAL_COLUMN_QUERY_LIMIT_EXCEEDED`, `GENERAL_ATTRIBUTE_QUERY_LIMIT_EXCEEDED`, `GENERAL_QUERY_INVALID`, `GENERAL_ROUTE_NOT_FOUND`, `GENERAL_CURSOR_NOT_FOUND`, `GENERAL_SERVER_ERROR`, `GENERAL_PROTOCOL_UNSUPPORTED`, `GENERAL_FEATURE_UNSUPPORTED`, `GENERAL_CODES_DISABLED`, `GENERAL_USAGE_DISABLED`, `GENERAL_NOT_IMPLEMENTED`, `GENERAL_INVALID_EMAIL`, `GENERAL_INVALID_PHONE`, `GENERAL_REGION_ACCESS_DENIED`, `GENERAL_BAD_REQUEST` |
| Users | `USER_COUNT_EXCEEDED`, `USER_CONSOLE_COUNT_EXCEEDED`, `USER_JWT_INVALID`, `USER_ALREADY_EXISTS`, `USER_BLOCKED`, `USER_INVALID_TOKEN`, `USER_PASSWORD_RESET_REQUIRED`, `USER_EMAIL_NOT_WHITELISTED`, `USER_IP_NOT_WHITELISTED`, `USER_INVALID_CODE`, `USER_INVALID_CREDENTIALS`, `USER_ANONYMOUS_CONSOLE_PROHIBITED`, `USER_SESSION_ALREADY_EXISTS`, `USER_NOT_FOUND`, `USER_EMAIL_NOT_FOUND`, `USER_EMAIL_ALREADY_EXISTS`, `USER_EMAIL_DISPOSABLE`, `USER_EMAIL_FREE`, `USER_EMAIL_NOT_CANONICAL`, `USER_EMAIL_NOT_CORPORATE`, `USER_PASSWORD_MISMATCH`, `USER_PASSWORD_RECENTLY_USED`, `USER_PASSWORD_PERSONAL_DATA`, `USER_SESSION_NOT_FOUND`, `USER_IDENTITY_NOT_FOUND`, `USER_UNAUTHORIZED`, `USER_AUTH_METHOD_UNSUPPORTED`, `USER_PHONE_ALREADY_EXISTS`, `USER_RECOVERY_CODES_ALREADY_EXISTS`, `USER_AUTHENTICATOR_NOT_FOUND`, `USER_RECOVERY_CODES_NOT_FOUND`, `USER_AUTHENTICATOR_ALREADY_VERIFIED`, `USER_PHONE_NOT_FOUND`, `USER_MISSING_ID`, `USER_MORE_FACTORS_REQUIRED`, `USER_CHALLENGE_REQUIRED`, `USER_OAUTH2_BAD_REQUEST`, `USER_OAUTH2_UNAUTHORIZED`, `USER_OAUTH2_PROVIDER_ERROR`, `USER_OAUTH2_PROVIDER_FAILURE`, `USER_EMAIL_NOT_VERIFIED`, `USER_EMAIL_ALREADY_VERIFIED`, `USER_PHONE_NOT_VERIFIED`, `USER_PHONE_ALREADY_VERIFIED`, `USER_DELETION_PROHIBITED`, `USER_TARGET_NOT_FOUND`, `USER_TARGET_ALREADY_EXISTS`, `USER_API_KEY_AND_SESSION_SET`, `USER_JWT_AND_COOKIE_SET`, `USER_JWT_CREATION_DENIED`, `API_KEY_EXPIRED` |
| Projects | `PROJECT_NOT_FOUND`, `PROJECT_ID_MISSING`, `PROJECT_PROVIDER_DISABLED`, `PROJECT_PROVIDER_UNSUPPORTED`, `PROJECT_ALREADY_EXISTS`, `PROJECT_INVALID_SUCCESS_URL`, `PROJECT_INVALID_FAILURE_URL`, `PROJECT_RESERVED_PROJECT`, `PROJECT_KEY_EXPIRED`, `ACCOUNT_KEY_EXPIRED`, `PROJECT_SMTP_CONFIG_INVALID`, `PROJECT_TEMPLATE_DEFAULT_DELETION`, `PROJECT_REGION_UNSUPPORTED`, `PROJECT_UNKNOWN`* |

`*` `PROJECT_UNKNOWN` is a Rust-only addition: no `Exception::PROJECT_UNKNOWN`
constant exists in the current PHP `Appwrite\Extend\Exception` class. It is
included here (as requested for the Users API foundation work) with a `500`
default code so callers have a generic project-scoped fallback; treat it as a
deviation to reconcile if/when PHP adds an equivalent.

## Status

Full error catalog covering the General, Users, and Projects error groups
(the subset the Users API migration depends on). Other PHP `errors.php`
groups (Teams, Storage, Functions, Databases, ...) are not yet ported; add
entries to `src/errors.rs` following the same pattern as needed.
