//! `/v1/users*` HTTP action registration. Rust port of
//! `Appwrite\Platform\Modules\Users\Services\Http` (`Services/Http.php`):
//! every `addAction(Action::getName(), new Action())` call, in the same
//! order, using each PHP action's `getName()` string as the registered
//! action id.

use utopia_platform::Service;

use crate::modules::users::http::{
    crud, hashes, identities, memberships, mfa, properties, sessions, targets,
};

/// Registers all 41 `/v1/users*` actions on `service` (PHP `Http::__construct()`).
#[must_use]
pub fn register(service: Service) -> Service {
    service
        // Users
        .add_action("createUser", crud::create())
        .add_action("getUser", crud::get())
        .add_action("listUsers", crud::list())
        .add_action("deleteUser", crud::delete())
        // Users with pre-hashed passwords
        .add_action("createBcryptUser", hashes::create_bcrypt())
        .add_action("createMD5User", hashes::create_md5())
        .add_action("createArgon2User", hashes::create_argon2())
        .add_action("createSHAUser", hashes::create_sha())
        .add_action("createPHPassUser", hashes::create_phpass())
        .add_action("createScryptUser", hashes::create_scrypt())
        .add_action("createScryptModifiedUser", hashes::create_scrypt_modified())
        // Properties
        .add_action("updateUserStatus", properties::update_status())
        .add_action("updateUserLabels", properties::update_labels())
        .add_action("updateUserImpersonator", properties::update_impersonator())
        .add_action("updateUserName", properties::update_name())
        .add_action("updateUserPassword", properties::update_password())
        .add_action("updateUserEmail", properties::update_email())
        .add_action("updateUserPhone", properties::update_phone())
        .add_action("updateUserEmailVerification", properties::update_verification())
        .add_action("updateUserPhoneVerification", properties::update_verification_phone())
        // Preferences
        .add_action("getUserPrefs", properties::get_prefs())
        .add_action("updateUserPrefs", properties::update_prefs())
        // Targets
        .add_action("createUserTarget", targets::create())
        .add_action("getUserTarget", targets::get())
        .add_action("listUserTargets", targets::list())
        .add_action("updateUserTarget", targets::update())
        .add_action("deleteUserTarget", targets::delete())
        // Sessions
        .add_action("createUserSession", sessions::create())
        .add_action("listUserSessions", sessions::list())
        .add_action("deleteUserSession", sessions::delete())
        .add_action("deleteUserSessions", sessions::delete_all())
        // Tokens
        .add_action("createUserToken", sessions::create_token())
        .add_action("createUserJWT", sessions::create_jwt())
        // Memberships
        .add_action("listUserMemberships", memberships::list())
        // Identities
        .add_action("listIdentities", identities::list())
        .add_action("deleteIdentity", identities::delete())
        // MFA
        .add_action("updateUserMFA", mfa::update())
        .add_action("listUserMFAFactors", mfa::list_factors())
        .add_action("getUserMFAChallenge", mfa::get_challenge())
        .add_action("getUserMFARecoveryCodes", mfa::get_recovery_codes())
        .add_action("createUserMFARecoveryCodes", mfa::create_recovery_codes())
        .add_action("updateUserMFARecoveryCodes", mfa::update_recovery_codes())
        .add_action("deleteUserMFAAuthenticator", mfa::delete_authenticator())
}
