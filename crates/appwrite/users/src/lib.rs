//! The Users service (`src/Appwrite/Platform/Modules/Users`).
//!
//! Every route keeps the PHP contract: path, parameters and validators (in
//! declaration order), scopes, events, audits, status codes and response
//! models. Implementations are rewritten for efficiency: only the user
//! relations an action needs are loaded, related queries are pipelined on
//! one connection, writes are single statements and password hashing runs
//! on the blocking pool.

mod base;
mod http;

use appwrite_core::action;
use appwrite_core::platform::{Audit, Module, Route};
use utopia_http::Method;

/// The Users module.
pub struct Users;

const GROUPS: &[&str] = &["api", "users"];
const READ: &[&str] = &["users.read"];
const WRITE: &[&str] = &["users.write"];

const fn audit(event: &'static str, resource: &'static str) -> Option<Audit> {
    Some(Audit { event, resource })
}

macro_rules! route {
    ($method:ident, $path:literal, $name:literal, $sdk:literal, $scopes:expr, $event:expr, $audit:expr, $action:path) => {
        Route {
            method: Method::$method,
            path: $path,
            name: $name,
            namespace: "users",
            sdk_method: $sdk,
            scopes: $scopes,
            event: $event,
            audit: $audit,
            groups: GROUPS,
            action: action!($action),
        }
    };
}

impl Module for Users {
    fn name(&self) -> &'static str {
        "users"
    }

    fn routes(&self) -> Vec<Route> {
        use http::*;
        vec![
            // Users
            route!(
                POST,
                "/v1/users",
                "createUser",
                "create",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                users::create
            ),
            route!(
                POST,
                "/v1/users/argon2",
                "createArgon2User",
                "createArgon2User",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::argon2
            ),
            route!(
                POST,
                "/v1/users/bcrypt",
                "createBcryptUser",
                "createBcryptUser",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::bcrypt
            ),
            route!(
                POST,
                "/v1/users/md5",
                "createMD5User",
                "createMD5User",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::md5
            ),
            route!(
                POST,
                "/v1/users/sha",
                "createSHAUser",
                "createSHAUser",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::sha
            ),
            route!(
                POST,
                "/v1/users/phpass",
                "createPHPassUser",
                "createPHPassUser",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::phpass
            ),
            route!(
                POST,
                "/v1/users/scrypt",
                "createScryptUser",
                "createScryptUser",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::scrypt
            ),
            route!(
                POST,
                "/v1/users/scrypt-modified",
                "createScryptModifiedUser",
                "createScryptModifiedUser",
                WRITE,
                None,
                audit("user.create", "user/{response.$id}"),
                hashes::scrypt_modified
            ),
            route!(GET, "/v1/users", "listUsers", "list", READ, None, None, users::list),
            route!(GET, "/v1/users/:userId", "getUser", "get", READ, None, None, users::get),
            route!(
                DELETE,
                "/v1/users/:userId",
                "deleteUser",
                "delete",
                WRITE,
                Some("users.[userId].delete"),
                audit("user.delete", "user/{request.userId}"),
                users::delete
            ),
            // Attributes
            route!(
                PATCH,
                "/v1/users/:userId/status",
                "updateUserStatus",
                "updateStatus",
                WRITE,
                Some("users.[userId].update.status"),
                audit("user.update", "user/{response.$id}"),
                attributes::status
            ),
            route!(
                PUT,
                "/v1/users/:userId/labels",
                "updateUserLabels",
                "updateLabels",
                WRITE,
                Some("users.[userId].update.labels"),
                audit("user.update", "user/{response.$id}"),
                attributes::labels
            ),
            route!(
                PATCH,
                "/v1/users/:userId/impersonator",
                "updateUserImpersonator",
                "updateImpersonator",
                WRITE,
                Some("users.[userId].update.impersonator"),
                audit("user.update", "user/{response.$id}"),
                attributes::impersonator
            ),
            route!(
                PATCH,
                "/v1/users/:userId/name",
                "updateUserName",
                "updateName",
                WRITE,
                Some("users.[userId].update.name"),
                audit("user.update", "user/{response.$id}"),
                attributes::name
            ),
            route!(
                PATCH,
                "/v1/users/:userId/password",
                "updateUserPassword",
                "updatePassword",
                WRITE,
                Some("users.[userId].update.password"),
                audit("user.update", "user/{response.$id}"),
                attributes::password
            ),
            route!(
                PATCH,
                "/v1/users/:userId/email",
                "updateUserEmail",
                "updateEmail",
                WRITE,
                Some("users.[userId].update.email"),
                audit("user.update", "user/{response.$id}"),
                attributes::email
            ),
            route!(
                PATCH,
                "/v1/users/:userId/phone",
                "updateUserPhone",
                "updatePhone",
                WRITE,
                Some("users.[userId].update.phone"),
                audit("user.update", "user/{response.$id}"),
                attributes::phone
            ),
            route!(
                PATCH,
                "/v1/users/:userId/verification",
                "updateUserEmailVerification",
                "updateEmailVerification",
                WRITE,
                Some("users.[userId].update.verification"),
                audit("verification.update", "user/{request.userId}"),
                attributes::email_verification
            ),
            route!(
                PATCH,
                "/v1/users/:userId/verification/phone",
                "updateUserPhoneVerification",
                "updatePhoneVerification",
                WRITE,
                Some("users.[userId].update.verification"),
                audit("verification.update", "user/{response.$id}"),
                attributes::phone_verification
            ),
            route!(
                PATCH,
                "/v1/users/:userId/mfa",
                "updateUserMFA",
                "updateMFA",
                WRITE,
                Some("users.[userId].update.mfa"),
                audit("user.update", "user/{response.$id}"),
                mfa::update
            ),
            // Preferences
            route!(GET, "/v1/users/:userId/prefs", "getUserPrefs", "getPrefs", READ, None, None, prefs::get),
            route!(
                PATCH,
                "/v1/users/:userId/prefs",
                "updateUserPrefs",
                "updatePrefs",
                WRITE,
                Some("users.[userId].update.prefs"),
                None,
                prefs::update
            ),
            // Targets
            route!(
                POST,
                "/v1/users/:userId/targets",
                "createUserTarget",
                "createTarget",
                WRITE,
                Some("users.[userId].targets.[targetId].create"),
                audit("target.create", "target/response.$id"),
                targets::create
            ),
            route!(GET, "/v1/users/:userId/targets", "listUserTargets", "listTargets", READ, None, None, targets::list),
            route!(
                GET,
                "/v1/users/:userId/targets/:targetId",
                "getUserTarget",
                "getTarget",
                READ,
                None,
                None,
                targets::get
            ),
            route!(
                PATCH,
                "/v1/users/:userId/targets/:targetId",
                "updateUserTarget",
                "updateTarget",
                WRITE,
                Some("users.[userId].targets.[targetId].update"),
                audit("target.update", "target/{response.$id}"),
                targets::update
            ),
            route!(
                DELETE,
                "/v1/users/:userId/targets/:targetId",
                "deleteUserTarget",
                "deleteTarget",
                WRITE,
                Some("users.[userId].targets.[targetId].delete"),
                audit("target.delete", "target/{request.$targetId}"),
                targets::delete
            ),
            // Sessions, tokens, JWTs
            route!(
                POST,
                "/v1/users/:userId/sessions",
                "createUserSession",
                "createSession",
                &["users.write", "sessions.write"],
                Some("users.[userId].sessions.[sessionId].create"),
                audit("session.create", "user/{request.userId}"),
                sessions::create
            ),
            route!(
                GET,
                "/v1/users/:userId/sessions",
                "listUserSessions",
                "listSessions",
                &["users.read", "sessions.read"],
                None,
                None,
                sessions::list
            ),
            route!(
                DELETE,
                "/v1/users/:userId/sessions/:sessionId",
                "deleteUserSession",
                "deleteSession",
                &["users.write", "sessions.write"],
                Some("users.[userId].sessions.[sessionId].delete"),
                audit("session.delete", "user/{request.userId}"),
                sessions::delete
            ),
            route!(
                DELETE,
                "/v1/users/:userId/sessions",
                "deleteUserSessions",
                "deleteSessions",
                &["users.write", "sessions.write"],
                Some("users.[userId].sessions.delete"),
                audit("session.delete", "user/{user.$id}"),
                sessions::delete_all
            ),
            route!(
                POST,
                "/v1/users/:userId/tokens",
                "createUserToken",
                "createToken",
                WRITE,
                Some("users.[userId].tokens.[tokenId].create"),
                audit("tokens.create", "user/{request.userId}"),
                sessions::token
            ),
            route!(POST, "/v1/users/:userId/jwts", "createUserJWT", "createJWT", WRITE, None, None, sessions::jwt),
            // Memberships and identities
            route!(
                GET,
                "/v1/users/:userId/memberships",
                "listUserMemberships",
                "listMemberships",
                READ,
                None,
                None,
                memberships::list
            ),
            route!(GET, "/v1/users/identities", "listIdentities", "listIdentities", READ, None, None, identities::list),
            route!(
                DELETE,
                "/v1/users/identities/:identityId",
                "deleteIdentity",
                "deleteIdentity",
                WRITE,
                Some("users.[userId].identities.[identityId].delete"),
                audit("identity.delete", "identity/{request.$identityId}"),
                identities::delete
            ),
            // MFA
            route!(
                GET,
                "/v1/users/:userId/mfa/factors",
                "listUserMFAFactors",
                "listMFAFactors",
                READ,
                None,
                None,
                mfa::factors
            ),
            route!(
                GET,
                "/v1/users/:userId/mfa/challenges/:challengeId",
                "getUserMFAChallenge",
                "getMFAChallenge",
                READ,
                None,
                None,
                mfa::challenge
            ),
            route!(
                GET,
                "/v1/users/:userId/mfa/recovery-codes",
                "getUserMFARecoveryCodes",
                "getMFARecoveryCodes",
                READ,
                None,
                None,
                mfa::get_recovery_codes
            ),
            route!(
                PATCH,
                "/v1/users/:userId/mfa/recovery-codes",
                "createUserMFARecoveryCodes",
                "createMFARecoveryCodes",
                WRITE,
                Some("users.[userId].create.mfa.recovery-codes"),
                audit("user.update", "user/{response.$id}"),
                mfa::create_recovery_codes
            ),
            route!(
                PUT,
                "/v1/users/:userId/mfa/recovery-codes",
                "updateUserMFARecoveryCodes",
                "updateMFARecoveryCodes",
                WRITE,
                Some("users.[userId].update.mfa.recovery-codes"),
                audit("user.update", "user/{response.$id}"),
                mfa::update_recovery_codes
            ),
            route!(
                DELETE,
                "/v1/users/:userId/mfa/authenticators/:type",
                "deleteUserMFAAuthenticator",
                "deleteMFAAuthenticator",
                WRITE,
                Some("users.[userId].delete.mfa"),
                audit("user.update", "user/{request.userId}"),
                mfa::delete_authenticator
            ),
            // Passkeys
            route!(
                GET,
                "/v1/users/:userId/passkeys",
                "listUserPasskeys",
                "listPasskeys",
                READ,
                None,
                None,
                passkeys::list
            ),
            route!(
                GET,
                "/v1/users/:userId/passkeys/:passkeyId",
                "getUserPasskey",
                "getPasskey",
                READ,
                None,
                None,
                passkeys::get
            ),
            route!(
                PATCH,
                "/v1/users/:userId/passkeys/:passkeyId",
                "updateUserPasskey",
                "updatePasskey",
                WRITE,
                None,
                audit("passkey.update", "user/{request.userId}"),
                passkeys::update
            ),
            route!(
                DELETE,
                "/v1/users/:userId/passkeys/:passkeyId",
                "deleteUserPasskey",
                "deletePasskey",
                WRITE,
                None,
                audit("passkey.delete", "user/{request.userId}"),
                passkeys::delete
            ),
        ]
    }
}
