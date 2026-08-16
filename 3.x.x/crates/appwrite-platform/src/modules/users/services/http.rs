//! `/v1/users*` HTTP action registration. Rust port of
//! `Appwrite\Platform\Modules\Users\Services\Http` (`Services/Http.php`):
//! every `addAction(Action::getName(), new Action())` call, in the same
//! order, using each PHP action's `getName()` string as the registered
//! action id.

use utopia_platform::Service;

use crate::modules::users::http::users::{
    argon2, bcrypt, create, delete, email, get, identities, impersonator, jwts, labels, md5,
    memberships, mfa, name, password, phone, phpass, prefs, scrypt, sessions, sha, status, targets,
    tokens, verification, xlist,
};

/// Registers all 41 `/v1/users*` actions on `service` (PHP `Http::__construct()`).
#[must_use]
pub fn register(service: Service) -> Service {
    service
        // Users
        .add_action("createUser", create::create())
        .add_action("getUser", get::get())
        .add_action("listUsers", xlist::xlist())
        .add_action("deleteUser", delete::delete())
        // Users with pre-hashed passwords
        .add_action("createBcryptUser", bcrypt::create::create())
        .add_action("createMD5User", md5::create::create())
        .add_action("createArgon2User", argon2::create::create())
        .add_action("createSHAUser", sha::create::create())
        .add_action("createPHPassUser", phpass::create::create())
        .add_action("createScryptUser", scrypt::create::create())
        .add_action("createScryptModifiedUser", scrypt::modified::create::create())
        // Properties
        .add_action("updateUserStatus", status::update::update())
        .add_action("updateUserLabels", labels::update::update())
        .add_action("updateUserImpersonator", impersonator::update::update())
        .add_action("updateUserName", name::update::update())
        .add_action("updateUserPassword", password::update::update())
        .add_action("updateUserEmail", email::update::update())
        .add_action("updateUserPhone", phone::update::update())
        .add_action("updateUserEmailVerification", verification::update::update())
        .add_action("updateUserPhoneVerification", verification::phone::update::update())
        // Preferences
        .add_action("getUserPrefs", prefs::get::get())
        .add_action("updateUserPrefs", prefs::update::update())
        // Targets
        .add_action("createUserTarget", targets::create::create())
        .add_action("getUserTarget", targets::get::get())
        .add_action("listUserTargets", targets::xlist::xlist())
        .add_action("updateUserTarget", targets::update::update())
        .add_action("deleteUserTarget", targets::delete::delete())
        // Sessions
        .add_action("createUserSession", sessions::create::create())
        .add_action("listUserSessions", sessions::xlist::xlist())
        .add_action("deleteUserSession", sessions::delete::delete())
        .add_action("deleteUserSessions", sessions::bulk::delete::delete())
        // Tokens
        .add_action("createUserToken", tokens::create::create())
        .add_action("createUserJWT", jwts::create::create())
        // Memberships
        .add_action("listUserMemberships", memberships::xlist::xlist())
        // Identities
        .add_action("listIdentities", identities::xlist::xlist())
        .add_action("deleteIdentity", identities::delete::delete())
        // MFA
        .add_action("updateUserMFA", mfa::update::update())
        .add_action("listUserMFAFactors", mfa::factors::xlist::xlist())
        .add_action("getUserMFAChallenge", mfa::challenges::get::get())
        .add_action("getUserMFARecoveryCodes", mfa::recovery_codes::get::get())
        .add_action("createUserMFARecoveryCodes", mfa::recovery_codes::create::create())
        .add_action("updateUserMFARecoveryCodes", mfa::recovery_codes::update::update())
        .add_action("deleteUserMFAAuthenticator", mfa::authenticators::delete::delete())
}
