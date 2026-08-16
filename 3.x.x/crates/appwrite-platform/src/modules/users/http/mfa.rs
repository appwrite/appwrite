//! MFA endpoints. Rust port of `Http/Users/MFA/{Update.php,
//! Factors/XList.php, Challenges/Get.php, RecoveryCodes/{Get,Create,
//! Update}.php, Authenticators/Delete.php}`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `OTPHP`-backed TOTP secret/provisioning-URI generation (`authenticators`
//! is only read here, never created -- enrolling a TOTP authenticator is
//! an Account-module flow not yet ported).

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_auth::Proof;
use utopia_database::{AttrValue, Query};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text, WhiteList};

use crate::modules::users::base::{self, inject};
use crate::state::{document_from_json, document_to_json};

/// PHP `TOTP::getAuthenticatorFromUser()`: the Memory adapter has no
/// relationship attributes, so this queries `authenticators` directly by
/// `userId` + `type` rather than scanning an already-populated
/// `$user->getAttribute('authenticators')`.
fn totp_authenticator(
    db: &mut crate::state::ProjectDb,
    user_id: &str,
) -> Result<Option<utopia_database::Document>, Exception> {
    let mut matches = db
        .find(
            "authenticators",
            &[
                Query::equal("userId", vec![AttrValue::from(user_id)]),
                Query::equal("type", vec![AttrValue::from(appwrite_auth::mfa::TOTP)]),
                Query::limit(1),
            ],
            "read",
        )
        .map_err(base::db_error)?;
    Ok(if matches.is_empty() {
        None
    } else {
        Some(matches.remove(0))
    })
}

/// PHP `Appwrite\Auth\MFA\Type::generateBackupCodes(10, 6)`.
fn generate_backup_codes() -> Result<Vec<String>, Exception> {
    let proof = utopia_auth::Token::new(10).map_err(base::hash_error)?;
    (0..6)
        .map(|_| proof.generate().map_err(base::hash_error))
        .collect()
}

/// PHP `$user->getAttribute('mfaRecoveryCodes', [])`. `AttrValue::as_array`
/// returns the raw `IndexMap`, not a JSON array, so this goes through
/// [`document_to_json`] to get plain strings.
fn recovery_codes_of(user: &utopia_database::Document) -> Vec<Value> {
    document_to_json(user)
        .get("mfaRecoveryCodes")
        .and_then(Value::as_array)
        .cloned()
        .unwrap_or_default()
}

/// `PATCH /v1/users/:userId/mfa` (`updateUserMFA`).
#[must_use]
pub fn update() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Patch)
            .set_http_path("/v1/users/:userId/mfa")
            .desc("Update MFA")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.update")
            .label("audits.resource", "user/{response.$id}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "mfa",
                Value::Null,
                Boolean::new(),
                "Enable or disable MFA.",
                false,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let mfa = ctx
                .param_value("mfa")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(&mut db, &user_id, json!({ "mfa": mfa }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `GET /v1/users/:userId/mfa/factors` (`listUserMFAFactors`).
#[must_use]
pub fn list_factors() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/mfa/factors")
            .desc("List factors")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject", "project"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let project = base::get_project(&ctx)?;

            let totp = totp_authenticator(&mut db, &user_id)?;
            let totp_verified = totp
                .as_ref()
                .is_some_and(|doc| doc.get_attribute("verified").as_bool().unwrap_or(false));

            let mfa_factors = project
                .get("auths")
                .and_then(|auths| auths.get("mfaFactors"))
                .cloned()
                .unwrap_or_else(|| json!({}));
            let factor_enabled = |name: &str, default: bool| -> bool {
                mfa_factors
                    .get(name)
                    .and_then(Value::as_bool)
                    .unwrap_or(default)
            };

            let email = user
                .get_attribute("email")
                .as_str()
                .unwrap_or_default()
                .to_string();
            let email_verified = user
                .get_attribute("emailVerification")
                .as_bool()
                .unwrap_or(false);
            let phone = user
                .get_attribute("phone")
                .as_str()
                .unwrap_or_default()
                .to_string();
            let phone_verified = user
                .get_attribute("phoneVerification")
                .as_bool()
                .unwrap_or(false);

            Ok(json!({
                "totp": factor_enabled("totp", true) && totp_verified,
                "email": factor_enabled("email", true) && !email.is_empty() && email_verified,
                "phone": factor_enabled("phone", true) && !phone.is_empty() && phone_verified,
                "custom": factor_enabled("custom", false),
            }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_MFA_FACTORS, result)
    })
}

/// `GET /v1/users/:userId/mfa/challenges/:challengeId` (`getUserMFAChallenge`).
#[must_use]
pub fn get_challenge() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/mfa/challenges/:challengeId")
            .desc("Get MFA challenge")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "challengeId",
                json!(""),
                Text::new(256),
                "ID of the challenge.",
                false,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let challenge_id = base::param_str(&ctx, "challengeId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let challenge = db
                .get_document("challenges", &challenge_id, &[], false)
                .map_err(base::db_error)?;
            let expire = challenge
                .get_attribute("expire")
                .as_str()
                .unwrap_or_default()
                .to_string();
            let is_valid = !challenge.is_empty()
                && challenge.get_attribute("userId").as_str() == Some(user_id.as_str())
                && challenge.get_attribute("type").as_str() == Some("custom")
                && expire >= base::now_iso();
            if !is_valid {
                return Err(Exception::new(Exception::USER_INVALID_TOKEN));
            }
            Ok(document_to_json(&challenge))
        })();
        base::finish(
            &ctx,
            200,
            appwrite_response::MODEL_MFA_CHALLENGE_SECRET,
            result,
        )
    })
}

/// `GET /v1/users/:userId/mfa/recovery-codes` (`getUserMFARecoveryCodes`).
#[must_use]
pub fn get_recovery_codes() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/mfa/recovery-codes")
            .desc("Get MFA recovery codes")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let codes = recovery_codes_of(&user);
            if codes.is_empty() {
                return Err(Exception::new(Exception::USER_RECOVERY_CODES_NOT_FOUND));
            }
            Ok(json!({ "recoveryCodes": codes }))
        })();
        base::finish(
            &ctx,
            200,
            appwrite_response::MODEL_MFA_RECOVERY_CODES,
            result,
        )
    })
}

/// `PATCH /v1/users/:userId/mfa/recovery-codes` (`createUserMFARecoveryCodes`).
#[must_use]
pub fn create_recovery_codes() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Patch)
            .set_http_path("/v1/users/:userId/mfa/recovery-codes")
            .desc("Create MFA recovery codes")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.update")
            .label("audits.resource", "user/{response.$id}")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            if !recovery_codes_of(&user).is_empty() {
                return Err(Exception::new(
                    Exception::USER_RECOVERY_CODES_ALREADY_EXISTS,
                ));
            }

            let codes = generate_backup_codes()?;
            db.update_document(
                "users",
                &user_id,
                document_from_json(json!({ "mfaRecoveryCodes": codes })),
            )
            .map_err(base::db_error)?;

            Ok(json!({ "recoveryCodes": codes }))
        })();
        base::finish(
            &ctx,
            201,
            appwrite_response::MODEL_MFA_RECOVERY_CODES,
            result,
        )
    })
}

/// `PUT /v1/users/:userId/mfa/recovery-codes` (`updateUserMFARecoveryCodes`).
#[must_use]
pub fn update_recovery_codes() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Put)
            .set_http_path("/v1/users/:userId/mfa/recovery-codes")
            .desc("Update MFA recovery codes (regenerate)")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.update")
            .label("audits.resource", "user/{response.$id}")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            if recovery_codes_of(&user).is_empty() {
                return Err(Exception::new(Exception::USER_RECOVERY_CODES_NOT_FOUND));
            }

            let codes = generate_backup_codes()?;
            db.update_document(
                "users",
                &user_id,
                document_from_json(json!({ "mfaRecoveryCodes": codes })),
            )
            .map_err(base::db_error)?;

            Ok(json!({ "recoveryCodes": codes }))
        })();
        base::finish(
            &ctx,
            200,
            appwrite_response::MODEL_MFA_RECOVERY_CODES,
            result,
        )
    })
}

/// `DELETE /v1/users/:userId/mfa/authenticators/:type` (`deleteUserMFAAuthenticator`).
#[must_use]
pub fn delete_authenticator() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId/mfa/authenticators/:type")
            .desc("Delete authenticator")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.update")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "type",
                Value::Null,
                WhiteList::new([appwrite_auth::mfa::TOTP]),
                "Type of authenticator.",
                false,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let authenticator = totp_authenticator(&mut db, &user_id)?
                .ok_or_else(|| Exception::new(Exception::USER_AUTHENTICATOR_NOT_FOUND))?;
            db.delete_document("authenticators", &authenticator.get_id())
                .map_err(base::db_error)?;
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
