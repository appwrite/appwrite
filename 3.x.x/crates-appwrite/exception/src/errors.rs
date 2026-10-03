//! Default error catalog: PHP `app/config/errors.php`.
//!
//! Keyed by the error `type` string (PHP `Exception::GENERAL_UNKNOWN`, etc.), each
//! entry carries the default HTTP status `code`, the default `message`, and an
//! optional `publish` override (PHP `'publish' => ...`). When `publish` is unset,
//! PHP falls back to `$code >= 500`.

use std::collections::HashMap;
use std::sync::OnceLock;

use crate::types::*;

#[derive(Debug, Clone, Copy)]
pub(crate) struct ErrorSpec {
    pub code: u16,
    pub message: &'static str,
    pub publish: Option<bool>,
}

macro_rules! spec {
    ($code:expr, $message:expr) => {
        ErrorSpec {
            code: $code,
            message: $message,
            publish: None,
        }
    };
    ($code:expr, $message:expr, publish: $publish:expr) => {
        ErrorSpec {
            code: $code,
            message: $message,
            publish: Some($publish),
        }
    };
}

pub(crate) fn table() -> &'static HashMap<&'static str, ErrorSpec> {
    static TABLE: OnceLock<HashMap<&'static str, ErrorSpec>> = OnceLock::new();
    TABLE.get_or_init(|| {
        HashMap::from([
            // General
            (GENERAL_UNKNOWN, spec!(500, "An unknown error has occurred. Please check the logs for more information.")),
            (GENERAL_MOCK, spec!(400, "General errors thrown by the mock controller used for testing.")),
            (GENERAL_ACCESS_FORBIDDEN, spec!(401, "Access to this API is forbidden.")),
            (GENERAL_RESOURCE_BLOCKED, spec!(403, "Access to this resource is blocked.")),
            (GENERAL_UNKNOWN_ORIGIN, spec!(403, "The request originated from an unknown origin. If you trust this domain, please list it as a trusted platform in the Appwrite console.")),
            (GENERAL_API_DISABLED, spec!(403, "The requested API is disabled. You can enable the API from the Appwrite console.")),
            (GENERAL_SERVICE_DISABLED, spec!(403, "The requested service is disabled. You can enable the service from the Appwrite console.")),
            (GENERAL_UNAUTHORIZED_SCOPE, spec!(401, "The current user or API key does not have the required scopes to access the requested resource.")),
            (GENERAL_RATE_LIMIT_EXCEEDED, spec!(429, "Rate limit for the current endpoint has been exceeded. Please try again after some time.")),
            (GENERAL_RESOURCE_LOCKED, spec!(409, "The requested resource is currently being modified by another request. Please retry after a brief delay.")),
            (GENERAL_SMTP_DISABLED, spec!(503, "SMTP is disabled on your Appwrite instance. You can learn more about setting up SMTP in our docs.")),
            (GENERAL_PHONE_DISABLED, spec!(503, "Phone provider is not configured. Please check the _APP_SMS_PROVIDER environment variable of your Appwrite server.")),
            (GENERAL_ARGUMENT_INVALID, spec!(400, "The request contains one or more invalid arguments. Please refer to the endpoint documentation.")),
            (GENERAL_ATTRIBUTE_QUERY_LIMIT_EXCEEDED, spec!(400, "Query limit exceeded for the current attribute.")),
            (GENERAL_COLUMN_QUERY_LIMIT_EXCEEDED, spec!(400, "Query limit exceeded for the current column.")),
            (GENERAL_QUERY_INVALID, spec!(400, "The query's syntax is invalid. Please check the query and try again.")),
            (GENERAL_ROUTE_NOT_FOUND, spec!(404, "Route not found. Please ensure the endpoint is configured correctly and that the API route is valid for this SDK version. Refer to the API docs for more details.")),
            (GENERAL_CURSOR_NOT_FOUND, spec!(400, "The cursor is invalid. This can happen if the item represented by the cursor has been deleted.")),
            (GENERAL_SERVER_ERROR, spec!(500, "An internal server error occurred.")),
            (GENERAL_PROTOCOL_UNSUPPORTED, spec!(426, "The request cannot be fulfilled with the current protocol. Please check the value of the _APP_OPTIONS_FORCE_HTTPS environment variable.")),
            (GENERAL_CODES_DISABLED, spec!(500, "Invitation codes are disabled on this server. Please contact the server administrator.")),
            (GENERAL_USAGE_DISABLED, spec!(501, "Usage stats is not configured. Please check the value of the _APP_USAGE_STATS environment variable of your Appwrite server.")),
            (GENERAL_NOT_IMPLEMENTED, spec!(405, "This method was not fully implemented yet. If you believe this is a mistake, please upgrade your Appwrite server version.")),
            (GENERAL_INVALID_EMAIL, spec!(400, "Value must be a valid email address.")),
            (GENERAL_INVALID_PHONE, spec!(400, "Value must be a valid phone number. Format this number with a leading '+' and a country code, e.g., +16175551212.")),
            (GENERAL_REGION_ACCESS_DENIED, spec!(451, "Your location is not supported due to legal requirements.")),
            (GENERAL_BAD_REQUEST, spec!(400, "There was an error processing your request. Please check the inputs and try again.")),
            (GENERAL_FEATURE_UNSUPPORTED, spec!(400, "This feature is not supported with your current configuration.")),

            // Users
            (USER_COUNT_EXCEEDED, spec!(400, "The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console.")),
            (USER_CONSOLE_COUNT_EXCEEDED, spec!(501, "Sign up to the console is restricted. You can contact an administrator to update console sign up restrictions by setting _APP_CONSOLE_WHITELIST_ROOT to \"disabled\".")),
            (USER_JWT_INVALID, spec!(401, "The JWT token is invalid. Please check the value of the X-Appwrite-JWT header to ensure the correct token is being used.")),
            (USER_ALREADY_EXISTS, spec!(409, "A user with the same id, email, or phone already exists in this project.")),
            (USER_BLOCKED, spec!(403, "The current user has been blocked.")),
            (USER_INVALID_TOKEN, spec!(401, "Invalid token passed in the request.")),
            (USER_PASSWORD_RESET_REQUIRED, spec!(412, "The current user requires a password reset.")),
            (USER_EMAIL_NOT_WHITELISTED, spec!(401, "Console registration is restricted to specific emails. Contact your administrator for more information.")),
            (USER_INVALID_CODE, spec!(401, "The specified code is not valid. Contact your administrator for more information.")),
            (USER_IP_NOT_WHITELISTED, spec!(401, "Console registration is restricted to specific IPs. Contact your administrator for more information.")),
            (USER_INVALID_CREDENTIALS, spec!(401, "Invalid credentials. Please check the email and password.")),
            (USER_ANONYMOUS_CONSOLE_PROHIBITED, spec!(401, "Anonymous users cannot be created for the console project.")),
            (USER_SESSION_ALREADY_EXISTS, spec!(401, "Creation of a session is prohibited when a session is active.")),
            (USER_NOT_FOUND, spec!(404, "User with the requested ID could not be found.")),
            (USER_EMAIL_NOT_FOUND, spec!(400, "User email could not be found.")),
            (USER_EMAIL_ALREADY_EXISTS, spec!(409, "A user with the same email already exists in the current project.")),
            (USER_EMAIL_DISPOSABLE, spec!(400, "Disposable email addresses are not allowed. Please use a permanent email address.")),
            (USER_EMAIL_FREE, spec!(400, "Free email addresses are not allowed. Please use a business or custom-domain email address.")),
            (USER_EMAIL_NOT_CANONICAL, spec!(400, "This email address must already be in its canonical form. Please remove aliases, tags, or provider-specific variations and try again.")),
            (USER_EMAIL_NOT_CORPORATE, spec!(400, "Only corporate email addresses are allowed. Please use a work email address and try again.")),
            (USER_PASSWORD_MISMATCH, spec!(400, "Passwords do not match. Please check the password and confirm password.")),
            (USER_PASSWORD_RECENTLY_USED, spec!(400, "The password you are trying to use is similar to your previous password. For your security, please choose a different password and try again.")),
            (USER_PASSWORD_PERSONAL_DATA, spec!(400, "The password you are trying to use contains references to your name, email, phone or userID. For your security, please choose a different password and try again.")),
            (USER_SESSION_NOT_FOUND, spec!(404, "The current user session could not be found.")),
            (USER_IDENTITY_NOT_FOUND, spec!(404, "The identity could not be found. Please sign in with OAuth provider to create identity first.")),
            (USER_UNAUTHORIZED, spec!(401, "The current user is not authorized to perform the requested action.")),
            (USER_AUTH_METHOD_UNSUPPORTED, spec!(501, "The requested authentication method is either disabled or unsupported. Please check the supported authentication methods in the Appwrite console.", publish: false)),
            (USER_PHONE_ALREADY_EXISTS, spec!(409, "A user with the same phone number already exists in the current project.")),
            (USER_RECOVERY_CODES_ALREADY_EXISTS, spec!(409, "The current user already generated recovery codes and they can only be read once for security reasons.")),
            (USER_AUTHENTICATOR_NOT_FOUND, spec!(404, "Authenticator could not be found on the current user.")),
            (USER_RECOVERY_CODES_NOT_FOUND, spec!(404, "Recovery codes could not be found on the current user.")),
            (USER_AUTHENTICATOR_ALREADY_VERIFIED, spec!(409, "This authenticator is already verified on the current user.")),
            (USER_PHONE_NOT_FOUND, spec!(400, "The current user does not have a phone number associated with their account.")),
            (USER_MISSING_ID, spec!(400, "Missing ID from OAuth2 provider.")),
            (USER_MORE_FACTORS_REQUIRED, spec!(401, "More factors are required to complete the sign in process.")),
            (USER_CHALLENGE_REQUIRED, spec!(401, "A recently successful challenge is required to complete this action. A challenge is considered recent for 5 minutes.")),
            (USER_OAUTH2_BAD_REQUEST, spec!(400, "OAuth2 provider rejected the bad request.")),
            (USER_OAUTH2_UNAUTHORIZED, spec!(401, "OAuth2 provider rejected the unauthorized request.")),
            (USER_OAUTH2_PROVIDER_ERROR, spec!(424, "OAuth2 provider returned some error.")),
            (USER_OAUTH2_PROVIDER_FAILURE, spec!(424, "%s couldn't complete sign-in (%s). Please try again.")),
            (USER_EMAIL_NOT_VERIFIED, spec!(400, "User email is not verified")),
            (USER_EMAIL_ALREADY_VERIFIED, spec!(409, "User email is already verified")),
            (USER_PHONE_NOT_VERIFIED, spec!(400, "User phone is not verified")),
            (USER_PHONE_ALREADY_VERIFIED, spec!(409, "User phone is already verified")),
            (USER_DELETION_PROHIBITED, spec!(400, "User deletion is not allowed for users with active memberships. Please delete all confirmed memberships before deleting the account.")),
            (USER_TARGET_NOT_FOUND, spec!(404, "The target could not be found.")),
            (USER_TARGET_ALREADY_EXISTS, spec!(409, "A target with the same ID already exists.")),
            (USER_API_KEY_AND_SESSION_SET, spec!(403, "API key and session used in the same request. Use either `setSession` or `setKey`. Learn about which authentication method to use in the SSR docs: https://appwrite.io/docs/products/auth/server-side-rendering")),
            (USER_JWT_AND_COOKIE_SET, spec!(403, "JWT and cookie used in the same request. Use either `setJWT` or `setCookie`. Learn about which authentication method to use in the SSR docs: https://appwrite.io/docs/products/auth/server-side-rendering")),
            (USER_JWT_CREATION_DENIED, spec!(403, "A JWT cannot be created from a request authorized with a JWT. Authenticate with a session cookie or session header instead.")),
            (API_KEY_EXPIRED, spec!(401, "The ephemeral API key has expired. Please don't use ephemeral API keys for more than duration of the execution.")),

            // Projects
            (PROJECT_NOT_FOUND, spec!(404, "Project with the requested ID could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used.")),
            (PROJECT_ALREADY_EXISTS, spec!(409, "Project with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID.")),
            (PROJECT_ID_MISSING, spec!(403, "When using project API key, make sure to pass x-appwrite-project header with your project ID.")),
            (PROJECT_PROVIDER_DISABLED, spec!(412, "The chosen OAuth provider is disabled. You can enable the OAuth provider using the Appwrite console.")),
            (PROJECT_PROVIDER_UNSUPPORTED, spec!(400, "The chosen OAuth provider is unsupported. Please check the Create OAuth2 Session docs for the complete list of supported OAuth providers.")),
            (PROJECT_INVALID_SUCCESS_URL, spec!(400, "Invalid redirect URL for OAuth success.")),
            (PROJECT_INVALID_FAILURE_URL, spec!(400, "Invalid redirect URL for OAuth failure.")),
            (PROJECT_RESERVED_PROJECT, spec!(400, "The project ID is reserved. Please choose another project ID.")),
            (PROJECT_KEY_EXPIRED, spec!(401, "The project key has expired. Please generate a new key using the Appwrite console.")),
            (ACCOUNT_KEY_EXPIRED, spec!(401, "The account API key has expired. Please generate a new key using the Appwrite console.")),
            (PROJECT_SMTP_CONFIG_INVALID, spec!(400, "Provided SMTP config is invalid. Please check the configured values and try again.")),
            (PROJECT_TEMPLATE_DEFAULT_DELETION, spec!(401, "You can't delete default template. If you are trying to reset your template changes, you can ignore this error as it's already been reset.")),
            (PROJECT_REGION_UNSUPPORTED, spec!(400, "The requested region is either inactive or unsupported. Please check the value of the _APP_REGIONS environment variable.")),
            // `PROJECT_UNKNOWN` has no PHP `errors.php` counterpart yet; Rust-only
            // addition for callers that need a generic project-scoped unknown error.
            (PROJECT_UNKNOWN, spec!(500, "An unknown error has occurred with the requested project. Please check the logs for more information.")),
        ])
    })
}

pub(crate) fn lookup(type_: &str) -> Option<ErrorSpec> {
    table().get(type_).copied()
}
