//! Error type identifiers.
//!
//! Rust port of the `Appwrite\Extend\Exception::*` class constants
//! (`src/Appwrite/Extend/Exception.php`). Values match the PHP strings exactly
//! so error payloads stay wire-compatible with existing Appwrite clients.

/// General errors.
pub const GENERAL_UNKNOWN: &str = "general_unknown";
pub const GENERAL_MOCK: &str = "general_mock";
pub const GENERAL_ACCESS_FORBIDDEN: &str = "general_access_forbidden";
pub const GENERAL_RESOURCE_BLOCKED: &str = "general_resource_blocked";
pub const GENERAL_UNKNOWN_ORIGIN: &str = "general_unknown_origin";
pub const GENERAL_API_DISABLED: &str = "general_api_disabled";
pub const GENERAL_SERVICE_DISABLED: &str = "general_service_disabled";
pub const GENERAL_UNAUTHORIZED_SCOPE: &str = "general_unauthorized_scope";
pub const GENERAL_RATE_LIMIT_EXCEEDED: &str = "general_rate_limit_exceeded";
pub const GENERAL_RESOURCE_LOCKED: &str = "general_resource_locked";
pub const GENERAL_SMTP_DISABLED: &str = "general_smtp_disabled";
pub const GENERAL_PHONE_DISABLED: &str = "general_phone_disabled";
pub const GENERAL_ARGUMENT_INVALID: &str = "general_argument_invalid";
pub const GENERAL_COLUMN_QUERY_LIMIT_EXCEEDED: &str = "general_column_query_limit_exceeded";
pub const GENERAL_ATTRIBUTE_QUERY_LIMIT_EXCEEDED: &str = "general_attribute_query_limit_exceeded";
pub const GENERAL_QUERY_INVALID: &str = "general_query_invalid";
pub const GENERAL_ROUTE_NOT_FOUND: &str = "general_route_not_found";
pub const GENERAL_CURSOR_NOT_FOUND: &str = "general_cursor_not_found";
pub const GENERAL_SERVER_ERROR: &str = "general_server_error";
pub const GENERAL_PROTOCOL_UNSUPPORTED: &str = "general_protocol_unsupported";
pub const GENERAL_FEATURE_UNSUPPORTED: &str = "general_feature_unsupported";
pub const GENERAL_CODES_DISABLED: &str = "general_codes_disabled";
pub const GENERAL_USAGE_DISABLED: &str = "general_usage_disabled";
pub const GENERAL_NOT_IMPLEMENTED: &str = "general_not_implemented";
pub const GENERAL_INVALID_EMAIL: &str = "general_invalid_email";
pub const GENERAL_INVALID_PHONE: &str = "general_invalid_phone";
pub const GENERAL_REGION_ACCESS_DENIED: &str = "general_region_access_denied";
pub const GENERAL_BAD_REQUEST: &str = "general_bad_request";

/// Users.
pub const USER_COUNT_EXCEEDED: &str = "user_count_exceeded";
pub const USER_CONSOLE_COUNT_EXCEEDED: &str = "user_console_count_exceeded";
pub const USER_JWT_INVALID: &str = "user_jwt_invalid";
pub const USER_ALREADY_EXISTS: &str = "user_already_exists";
pub const USER_BLOCKED: &str = "user_blocked";
pub const USER_INVALID_TOKEN: &str = "user_invalid_token";
pub const USER_PASSWORD_RESET_REQUIRED: &str = "user_password_reset_required";
pub const USER_EMAIL_NOT_WHITELISTED: &str = "user_email_not_whitelisted";
pub const USER_IP_NOT_WHITELISTED: &str = "user_ip_not_whitelisted";
pub const USER_INVALID_CODE: &str = "user_invalid_code";
pub const USER_INVALID_CREDENTIALS: &str = "user_invalid_credentials";
pub const USER_ANONYMOUS_CONSOLE_PROHIBITED: &str = "user_anonymous_console_prohibited";
pub const USER_SESSION_ALREADY_EXISTS: &str = "user_session_already_exists";
pub const USER_NOT_FOUND: &str = "user_not_found";
pub const USER_PASSWORD_RECENTLY_USED: &str = "password_recently_used";
pub const USER_PASSWORD_PERSONAL_DATA: &str = "password_personal_data";
pub const USER_EMAIL_ALREADY_EXISTS: &str = "user_email_already_exists";
pub const USER_EMAIL_DISPOSABLE: &str = "user_email_disposable";
pub const USER_EMAIL_FREE: &str = "user_email_free";
pub const USER_EMAIL_NOT_CORPORATE: &str = "user_email_not_corporate";
pub const USER_EMAIL_NOT_CANONICAL: &str = "user_email_not_canonical";
pub const USER_PASSWORD_MISMATCH: &str = "user_password_mismatch";
pub const USER_SESSION_NOT_FOUND: &str = "user_session_not_found";
pub const USER_IDENTITY_NOT_FOUND: &str = "user_identity_not_found";
pub const USER_UNAUTHORIZED: &str = "user_unauthorized";
pub const USER_AUTH_METHOD_UNSUPPORTED: &str = "user_auth_method_unsupported";
pub const USER_PHONE_ALREADY_EXISTS: &str = "user_phone_already_exists";
pub const USER_PHONE_NOT_FOUND: &str = "user_phone_not_found";
pub const USER_PHONE_NOT_VERIFIED: &str = "user_phone_not_verified";
pub const USER_EMAIL_NOT_FOUND: &str = "user_email_not_found";
pub const USER_EMAIL_NOT_VERIFIED: &str = "user_email_not_verified";
pub const USER_MISSING_ID: &str = "user_missing_id";
pub const USER_MORE_FACTORS_REQUIRED: &str = "user_more_factors_required";
pub const USER_AUTHENTICATOR_NOT_FOUND: &str = "user_authenticator_not_found";
pub const USER_AUTHENTICATOR_ALREADY_VERIFIED: &str = "user_authenticator_already_verified";
pub const USER_RECOVERY_CODES_ALREADY_EXISTS: &str = "user_recovery_codes_already_exists";
pub const USER_RECOVERY_CODES_NOT_FOUND: &str = "user_recovery_codes_not_found";
pub const USER_CHALLENGE_REQUIRED: &str = "user_challenge_required";
pub const USER_OAUTH2_BAD_REQUEST: &str = "user_oauth2_bad_request";
pub const USER_OAUTH2_UNAUTHORIZED: &str = "user_oauth2_unauthorized";
pub const USER_OAUTH2_PROVIDER_ERROR: &str = "user_oauth2_provider_error";
pub const USER_OAUTH2_PROVIDER_FAILURE: &str = "user_oauth2_provider_failure";
pub const USER_EMAIL_ALREADY_VERIFIED: &str = "user_email_already_verified";
pub const USER_PHONE_ALREADY_VERIFIED: &str = "user_phone_already_verified";
pub const USER_DELETION_PROHIBITED: &str = "user_deletion_prohibited";
pub const USER_TARGET_NOT_FOUND: &str = "user_target_not_found";
pub const USER_TARGET_ALREADY_EXISTS: &str = "user_target_already_exists";
pub const USER_API_KEY_AND_SESSION_SET: &str = "user_api_key_and_session_set";
pub const USER_JWT_AND_COOKIE_SET: &str = "user_jwt_and_cookie_set";
pub const USER_JWT_CREATION_DENIED: &str = "user_jwt_creation_denied";

pub const API_KEY_EXPIRED: &str = "api_key_expired";

/// Projects.
pub const PROJECT_NOT_FOUND: &str = "project_not_found";
pub const PROJECT_ID_MISSING: &str = "project_id_missing";
pub const PROJECT_PROVIDER_DISABLED: &str = "project_provider_disabled";
pub const PROJECT_PROVIDER_UNSUPPORTED: &str = "project_provider_unsupported";
pub const PROJECT_ALREADY_EXISTS: &str = "project_already_exists";
pub const PROJECT_INVALID_SUCCESS_URL: &str = "project_invalid_success_url";
pub const PROJECT_INVALID_FAILURE_URL: &str = "project_invalid_failure_url";
pub const PROJECT_RESERVED_PROJECT: &str = "project_reserved_project";
pub const PROJECT_KEY_EXPIRED: &str = "project_key_expired";
pub const ACCOUNT_KEY_EXPIRED: &str = "account_key_expired";
pub const PROJECT_SMTP_CONFIG_INVALID: &str = "project_smtp_config_invalid";
pub const PROJECT_TEMPLATE_DEFAULT_DELETION: &str = "project_template_default_deletion";
pub const PROJECT_REGION_UNSUPPORTED: &str = "project_region_unsupported";
/// Rust-only addition (no PHP `Exception::PROJECT_UNKNOWN` counterpart exists
/// upstream yet). Documented deviation: see crate README "Status" section.
pub const PROJECT_UNKNOWN: &str = "project_unknown";
