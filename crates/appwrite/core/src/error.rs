//! Appwrite errors (`Appwrite\Extend\Exception` + `app/config/errors.php`).

use std::borrow::Cow;
use std::fmt;
use std::panic::Location;

macro_rules! error_types {
    ($( $variant:ident => ($name:literal, $code:literal, $description:literal), )*) => {
        /// Error types with their wire name, default HTTP code and description.
        #[derive(Debug, Clone, Copy, PartialEq, Eq)]
        pub enum ErrorType {
            $( $variant, )*
        }

        impl ErrorType {
            /// Wire `type` string.
            pub const fn name(self) -> &'static str {
                match self { $( ErrorType::$variant => $name, )* }
            }

            /// Default HTTP status code.
            pub const fn code(self) -> u16 {
                match self { $( ErrorType::$variant => $code, )* }
            }

            /// Default message.
            pub const fn description(self) -> &'static str {
                match self { $( ErrorType::$variant => $description, )* }
            }
        }
    };
}

error_types! {
    GeneralUnknown => ("general_unknown", 500, "An unknown error has occurred. Please check the logs for more information."),
    GeneralServerError => ("general_server_error", 500, "An internal server error occurred."),
    GeneralAccessForbidden => ("general_access_forbidden", 401, "Access to this API is forbidden."),
    GeneralUnknownOrigin => ("general_unknown_origin", 403, "The request originated from an unknown origin. If you trust this domain, please list it as a trusted platform in the Appwrite console."),
    GeneralApiDisabled => ("general_api_disabled", 403, "The requested API is disabled. You can enable the API from the Appwrite console."),
    GeneralServiceDisabled => ("general_service_disabled", 403, "The requested service is disabled. You can enable the service from the Appwrite console."),
    GeneralUnauthorizedScope => ("general_unauthorized_scope", 401, "The current user or API key does not have the required scopes to access the requested resource."),
    GeneralRegionAccessDenied => ("general_region_access_denied", 451, "Your location is not supported due to legal requirements."),
    GeneralRateLimitExceeded => ("general_rate_limit_exceeded", 429, "Rate limit for the current endpoint has been exceeded. Please try again after some time."),
    GeneralArgumentInvalid => ("general_argument_invalid", 400, "The request contains one or more invalid arguments. Please refer to the endpoint documentation."),
    GeneralQueryInvalid => ("general_query_invalid", 400, "The query's syntax is invalid. Please check the query and try again."),
    GeneralRouteNotFound => ("general_route_not_found", 404, "Route not found. Please ensure the endpoint is configured correctly and that the API route is valid for this SDK version. Refer to the API docs for more details."),
    GeneralCursorNotFound => ("general_cursor_not_found", 400, "The cursor is invalid. This can happen if the item represented by the cursor has been deleted."),
    GeneralProtocolUnsupported => ("general_protocol_unsupported", 426, "The request cannot be fulfilled with the current protocol. Please check the value of the _APP_OPTIONS_FORCE_HTTPS environment variable."),
    GeneralBadRequest => ("general_bad_request", 400, "There was an error processing your request. Please check the inputs and try again."),
    GeneralInvalidEmail => ("general_invalid_email", 400, "Value must be a valid email address."),
    GeneralInvalidPhone => ("general_invalid_phone", 400, "Value must be a valid phone number. Format this number with a leading '+' and a country code, e.g., +16175551212."),
    GeneralPwnedPasswordsUnavailable => ("general_pwned_passwords_unavailable", 503, "The breached password service could not be reached, so the password could not be verified. Please try again later."),
    ProjectNotFound => ("project_not_found", 404, "Project with the requested ID could not be found. Please check the value of the X-Appwrite-Project header to ensure the correct project ID is being used."),
    ProjectKeyExpired => ("project_key_expired", 401, "The project key has expired. Please generate a new key using the Appwrite console."),
    ProjectIdMissing => ("project_id_missing", 403, "When using project API key, make sure to pass x-appwrite-project header with your project ID."),
    UserIdMissing => ("user_id_missing", 403, "When using account API key, make sure to pass x-appwrite-user header with your user ID."),
    OrganizationIdMissing => ("organization_id_missing", 403, "When using organization API key, make sure to pass x-appwrite-organization header with your organization ID."),
    AccountKeyExpired => ("account_key_expired", 401, "The account API key has expired. Please generate a new key using the Appwrite console."),
    UserUnauthorized => ("user_unauthorized", 401, "The current user is not authorized to perform the requested action."),
    UserJwtInvalid => ("user_jwt_invalid", 401, "The JWT token is invalid. Please check the value of the X-Appwrite-JWT header to ensure the correct token is being used."),
    UserJwtAndCookieSet => ("user_jwt_and_cookie_set", 403, "JWT and cookie used in the same request. Use either `setJWT` or `setCookie`. Learn about which authentication method to use in the SSR docs: https://appwrite.io/docs/products/auth/server-side-rendering"),
    UserApiKeyAndSessionSet => ("user_api_key_and_session_set", 403, "API key and session used in the same request. Use either `setSession` or `setKey`. Learn about which authentication method to use in the SSR docs: https://appwrite.io/docs/products/auth/server-side-rendering"),
    UserBlocked => ("user_blocked", 403, "The current user has been blocked."),
    UserPasswordResetRequired => ("user_password_reset_required", 412, "The current user requires a password reset."),
    UserSessionAlreadyExists => ("user_session_already_exists", 401, "Creation of a session is prohibited when a session is active."),
    UserChallengeRequired => ("user_challenge_required", 401, "A recently successful challenge is required to complete this action. A challenge is considered recent for 5 minutes."),
    UserReauthenticationRequired => ("user_reauthentication_required", 401, "This action requires a recent sign-in. Sign in again or complete an MFA challenge, then retry."),
    UserAuthMethodUnsupported => ("user_auth_method_unsupported", 501, "The requested authentication method is either disabled or unsupported. Please check the supported authentication methods in the Appwrite console."),
    UserMoreFactorsRequired => ("user_more_factors_required", 401, "More factors are required to complete the sign in process."),
    UserImpersonationReadOnly => ("user_impersonation_read_only", 403, "This account action is not allowed while impersonating a user."),
    UserNotFound => ("user_not_found", 404, "User with the requested ID could not be found."),
    UserAlreadyExists => ("user_already_exists", 409, "A user with the same id, email, or phone already exists in this project."),
    UserEmailAlreadyExists => ("user_email_already_exists", 409, "A user with the same email already exists in the current project."),
    UserPhoneAlreadyExists => ("user_phone_already_exists", 409, "A user with the same phone number already exists in the current project."),
    UserSessionNotFound => ("user_session_not_found", 404, "The current user session could not be found."),
    UserIdentityNotFound => ("user_identity_not_found", 404, "The identity could not be found. Please sign in with OAuth provider to create identity first."),
    UserTargetNotFound => ("user_target_not_found", 404, "The target could not be found."),
    UserTargetAlreadyExists => ("user_target_already_exists", 409, "A target with the same ID already exists."),
    UserPasswordRecentlyUsed => ("password_recently_used", 400, "The password you are trying to use is similar to your previous password. For your security, please choose a different password and try again."),
    UserPasswordPersonalData => ("password_personal_data", 400, "The password you are trying to use contains references to your name, email, phone or userID. For your security, please choose a different password and try again."),
    UserPasswordPwned => ("password_pwned", 400, "The password you are trying to use has been exposed in a known data breach. For your security, please choose a different password and try again."),
    UserEmailDisposable => ("user_email_disposable", 400, "Disposable email addresses are not allowed. Please use a permanent email address."),
    UserEmailFree => ("user_email_free", 400, "Free email addresses are not allowed. Please use a business or custom-domain email address."),
    UserEmailNotCanonical => ("user_email_not_canonical", 400, "This email address must already be in its canonical form. Please remove aliases, tags, or provider-specific variations and try again."),
    UserEmailNotCorporate => ("user_email_not_corporate", 400, "Only corporate email addresses are allowed. Please use a work email address and try again."),
    UserInvalidToken => ("user_invalid_token", 401, "Invalid token passed in the request."),
    UserRecoveryCodesAlreadyExists => ("user_recovery_codes_already_exists", 409, "The current user already generated recovery codes and they can only be read once for security reasons."),
    UserRecoveryCodesNotFound => ("user_recovery_codes_not_found", 404, "Recovery codes could not be found on the current user."),
    UserAuthenticatorNotFound => ("user_authenticator_not_found", 404, "Authenticator could not be found on the current user."),
    UserPasskeyNotFound => ("user_passkey_not_found", 404, "Passkey with the requested ID could not be found on the current user."),
    DatabaseQueryOrderNull => ("database_query_order_null", 400, "The order attribute/column had a null value. Cursor pagination requires all documents/rows order attribute/column values are non-null."),
    DatabaseTimeout => ("database_timeout", 408, "Database timed out. Try adjusting your queries or adding an index."),
    ProviderNotFound => ("provider_not_found", 404, "Provider with the requested ID could not be found."),
    DocumentAlreadyExists => ("document_already_exists", 409, "Document with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID."),
    ProviderIncorrectType => ("provider_incorrect_type", 400, "Provider with the requested ID is of the incorrect type."),
    DocumentInvalidStructure => ("document_invalid_structure", 400, "The document structure is invalid. Please ensure the attributes match the collection definition."),
    GeneralResourceLocked => ("general_resource_locked", 409, "The requested resource is currently being modified by another request. Please retry after a brief delay."),
    GeneralSmtpDisabled => ("general_smtp_disabled", 503, "SMTP is disabled on your Appwrite instance. You can <a href=\"/docs/email-delivery\">learn more about setting up SMTP</a> in our docs."),
    GeneralPhoneDisabled => ("general_phone_disabled", 503, "Phone provider is not configured. Please check the _APP_SMS_PROVIDER environment variable of your Appwrite server."),
    UserCountExceeded => ("user_count_exceeded", 400, "The current project has exceeded the maximum number of users. Please check your user limit in the Appwrite console."),
    TeamNotFound => ("team_not_found", 404, "Team with the requested ID could not be found."),
    TeamInviteNotFound => ("team_invite_not_found", 404, "The requested team invitation could not be found."),
    TeamInvalidSecret => ("team_invalid_secret", 401, "The team invitation secret is invalid. Please request a new invitation and try again."),
    TeamMembershipMismatch => ("team_membership_mismatch", 404, "The membership ID does not belong to the team ID."),
    TeamInviteMismatch => ("team_invite_mismatch", 401, "The invite does not belong to the current user."),
    TeamAlreadyExists => ("team_already_exists", 409, "Team with requested ID already exists. Please choose a different ID and try again."),
    OrganizationCreationProhibited => ("organization_creation_prohibited", 403, "This self-hosted instance already has an organization. Ask an organization owner to invite you instead."),
    MembershipNotFound => ("membership_not_found", 404, "Membership with the requested ID could not be found."),
    MembershipAlreadyExists => ("membership_already_exists", 409, "Membership with the requested ID already exists. Try again with a different ID or use ID.unique() to generate a unique ID."),
    MembershipAlreadyConfirmed => ("membership_already_confirmed", 409, "Membership is already confirmed."),
    MembershipDeletionProhibited => ("membership_deletion_prohibited", 400, "Membership deletion is prohibited."),
    MembershipDowngradeProhibited => ("membership_downgrade_prohibited", 400, "Membership role downgrade is prohibited."),
}

/// Status codes that pass through to clients; everything else becomes 500.
const PUBLIC_CODES: [u16; 16] = [400, 401, 402, 403, 404, 405, 408, 409, 410, 412, 416, 422, 429, 451, 501, 503];

/// An API error.
#[derive(Debug, Clone)]
pub struct Error {
    pub kind: ErrorType,
    pub message: Cow<'static, str>,
    pub code: u16,
    pub location: &'static Location<'static>,
}

impl Error {
    /// Error with the default message.
    #[track_caller]
    pub fn new(kind: ErrorType) -> Self {
        Self { kind, message: Cow::Borrowed(kind.description()), code: kind.code(), location: Location::caller() }
    }

    /// Error with a custom message.
    #[track_caller]
    pub fn with_message(kind: ErrorType, message: impl Into<Cow<'static, str>>) -> Self {
        Self { kind, message: message.into(), code: kind.code(), location: Location::caller() }
    }

    /// Error with a custom message and status code.
    #[track_caller]
    pub fn with_code(kind: ErrorType, message: impl Into<Cow<'static, str>>, code: u16) -> Self {
        Self { kind, message: message.into(), code, location: Location::caller() }
    }

    /// `Invalid `<key>` param: <description>`.
    #[track_caller]
    pub fn invalid_param(key: &str, description: &str) -> Self {
        Self::with_message(ErrorType::GeneralArgumentInvalid, format!("Invalid `{key}` param: {description}"))
    }

    /// `Param "<key>" is not optional.`
    #[track_caller]
    pub fn missing_param(key: &str) -> Self {
        Self::with_message(ErrorType::GeneralArgumentInvalid, format!("Param \"{key}\" is not optional."))
    }

    /// Unexpected internal failure (logged, rendered as 500).
    #[track_caller]
    pub fn internal(message: impl Into<Cow<'static, str>>) -> Self {
        Self::with_code(ErrorType::GeneralUnknown, message, 500)
    }

    /// The status code sent to clients.
    pub fn status(&self) -> u16 {
        if PUBLIC_CODES.contains(&self.code) { self.code } else { 500 }
    }

    /// The message sent to clients (`Server Error` for masked codes).
    pub fn public_message(&self) -> &str {
        if PUBLIC_CODES.contains(&self.code) { &self.message } else { "Server Error" }
    }

    pub fn is_server_error(&self) -> bool {
        self.status() >= 500
    }
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{} ({}): {}", self.kind.name(), self.code, self.message)
    }
}

impl std::error::Error for Error {}

/// Database exceptions as the API reports them: the error hook's mapping
/// (`general.php`: authorization, timeout, structure) and the ones actions
/// catch (duplicates, invalid queries, null cursor order values).
impl From<utopia_database::Error> for Error {
    #[track_caller]
    fn from(err: utopia_database::Error) -> Self {
        use utopia_database::Kind;
        if err.is_a(Kind::Duplicate) {
            return Error::with_message(ErrorType::DocumentAlreadyExists, err.message().to_owned());
        }
        match err.kind() {
            Kind::Authorization => Error::new(ErrorType::UserUnauthorized),
            Kind::Timeout => Error::new(ErrorType::DatabaseTimeout),
            Kind::Structure => Error::with_message(ErrorType::GeneralArgumentInvalid, err.message().to_owned()),
            Kind::Query => Error::with_message(ErrorType::GeneralQueryInvalid, err.message().to_owned()),
            Kind::Order => Error::with_message(
                ErrorType::DatabaseQueryOrderNull,
                format!(
                    "The order attribute '{}' had a null value. Cursor pagination requires all documents order attribute values are non-null.",
                    err.attribute().unwrap_or("")
                ),
            ),
            _ => {
                tracing::error!(error = %err, "database error");
                Error::internal(err.to_string())
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn masking() {
        let e = Error::new(ErrorType::GeneralProtocolUnsupported);
        assert_eq!(e.status(), 500);
        assert_eq!(e.public_message(), "Server Error");
        let e = Error::new(ErrorType::UserNotFound);
        assert_eq!(e.status(), 404);
        assert_eq!(e.kind.name(), "user_not_found");
    }
}
