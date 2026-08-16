//! User property update endpoints. Rust port of
//! `Http/Users/{Status,Name,Email,Phone,Password,Labels,Impersonator,
//! Verification,Verification/Phone,Prefs}/{Get,Update}.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): email
//! canonical/disposable/corporate/free metadata, cloud `$plan` gating,
//! password strength/dictionary/history enforcement, and the
//! `personalDataCheck`/`invalidateSessions` project policies are not
//! implemented -- self-hosted Appwrite's dev-seeded project never enables
//! any of them (see `base` module docs), so skipping them changes no
//! observable behavior for this milestone.

use std::collections::HashMap;

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_auth::Password;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Assoc, Boolean, Text, Validator};

use crate::modules::users::base::{self, inject};
use crate::modules::users::validators::Email;
use crate::state::document_to_json;

fn prefs_of(user: &utopia_database::Document) -> Value {
    document_to_json(user)
        .get("prefs")
        .cloned()
        .unwrap_or_else(|| json!({}))
}

/// PHP `new Phone(allowEmpty: true)`: `appwrite-auth::Phone` has no
/// allow-empty variant (only `Nullable`, which accepts `null` rather than
/// `""`), so this local wrapper accepts either.
#[derive(Debug, Clone, Copy, Default)]
struct PhoneOrEmpty;

impl Validator for PhoneOrEmpty {
    fn description(&self) -> String {
        "Value must be a valid phone number".to_string()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        utopia_validators::ValueType::String
    }

    fn is_valid(&self, value: &Value) -> bool {
        if value.as_str() == Some("") {
            return true;
        }
        appwrite_auth::Phone::new().is_valid(value)
    }
}

fn user_id_param(action: Action) -> Action {
    action.param("userId", json!(""), Text::new(36), "User ID.", false)
}

/// `PATCH /v1/users/:userId/status` (`updateUserStatus`).
#[must_use]
pub fn update_status() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/status")
                .desc("Update user status")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "status",
            Value::Null,
            Boolean::new().loose(true),
            "User Status. To activate the user pass `true` and to block the user pass `false`.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let status = ctx
                .param_value("status")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(&mut db, &user_id, json!({ "status": status }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/name` (`updateUserName`).
#[must_use]
pub fn update_name() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/name")
                .desc("Update name")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "name",
            json!(""),
            Text::new(128),
            "User name. Max length: 128 chars.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let name = base::param_str(&ctx, "name")?;
            base::update_user_fields(&mut db, &user_id, json!({ "name": name }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/email` (`updateUserEmail`). Rust port of
/// `Http/Users/Email/Update.php`, minus the email metadata/plan gating (see
/// module docs).
#[must_use]
pub fn update_email() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/email")
                .desc("Update email")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param("email", json!(""), Email::new(true), "User email.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let email = base::param_str(&ctx, "email")?.to_lowercase();
            let user = base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            if !email.is_empty() {
                // PHP checks unconditionally (not scoped to this user), so an
                // update to an email already used by *any* target -- including
                // this user's own current target -- fails the same way.
                if base::find_one(&mut db, "identities", "providerEmail", email.as_str())?
                    .is_some()
                {
                    return Err(Exception::new(Exception::USER_EMAIL_ALREADY_EXISTS));
                }
                if base::find_one(&mut db, "targets", "identifier", email.as_str())?.is_some() {
                    return Err(Exception::new(Exception::USER_TARGET_ALREADY_EXISTS));
                }
            }

            let old_email = user
                .get_attribute("email")
                .as_str()
                .unwrap_or_default()
                .to_string();

            base::update_user_fields(
                &mut db,
                &user_id,
                json!({ "email": if email.is_empty() { Value::Null } else { json!(email) }, "emailVerification": false }),
            )?;

            match base::find_target_by_identifier(&mut db, &user_id, &old_email)? {
                Some(old_target) if !email.is_empty() => {
                    let _ = db.update_document(
                        "targets",
                        &old_target.get_id(),
                        crate::state::document_from_json(json!({ "identifier": email })),
                    );
                }
                Some(old_target) => {
                    let _ = db.delete_document("targets", &old_target.get_id());
                }
                None if !email.is_empty() => {
                    base::create_target(&mut db, &user, "email", &email)?;
                }
                None => {}
            }

            let final_user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(base::user_with_targets(&mut db, &final_user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/phone` (`updateUserPhone`). Rust port of
/// `Http/Users/Phone/Update.php`.
#[must_use]
pub fn update_phone() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/phone")
                .desc("Update phone")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "number",
            json!(""),
            PhoneOrEmpty,
            "User phone number.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let number = ctx
                .param_value("number")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string();
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let old_phone = user
                .get_attribute("phone")
                .as_str()
                .unwrap_or_default()
                .to_string();

            // PHP checks unconditionally (not scoped to this user), so an
            // update to a number already used by *any* target -- including
            // this user's own current target -- fails the same way.
            if !number.is_empty()
                && base::find_one(&mut db, "targets", "identifier", number.as_str())?.is_some()
            {
                return Err(Exception::new(Exception::USER_TARGET_ALREADY_EXISTS));
            }

            let phone_value = if number.is_empty() {
                Value::Null
            } else {
                json!(number)
            };
            base::update_user_fields(
                &mut db,
                &user_id,
                json!({ "phone": phone_value, "phoneVerification": false }),
            )?;

            match base::find_target_by_identifier(&mut db, &user_id, &old_phone)? {
                Some(old_target) if !number.is_empty() => {
                    let _ = db.update_document(
                        "targets",
                        &old_target.get_id(),
                        crate::state::document_from_json(json!({ "identifier": number })),
                    );
                }
                Some(old_target) => {
                    let _ = db.delete_document("targets", &old_target.get_id());
                }
                None if !number.is_empty() => {
                    base::create_target(&mut db, &user, "sms", &number)?;
                }
                None => {}
            }

            let final_user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(base::user_with_targets(&mut db, &final_user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/password` (`updateUserPassword`). Rust port of
/// `Http/Users/Password/Update.php`, minus strength/dictionary/history
/// enforcement and session invalidation (see module docs). An empty
/// `password` clears the stored hash and returns immediately, matching the
/// early-response branch PHP's handler takes (its missing `return` after
/// `$response->dynamic()` has no further observable effect once the
/// response has already been sent).
#[must_use]
pub fn update_password() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/password")
                .desc("Update password")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "password",
            json!(""),
            appwrite_auth::Password::new(true),
            "New user password. Must be at least 8 chars.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let password = base::param_str(&ctx, "password")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            if password.is_empty() {
                return base::update_user_fields(
                    &mut db,
                    &user_id,
                    json!({ "password": "", "passwordUpdate": base::now_iso() }),
                );
            }

            let hasher = Password::create_hash(Password::ARGON2, HashMap::new())
                .map_err(base::hash_error)?;
            let hashed = hasher.hash(&password).map_err(base::hash_error)?;

            base::update_user_fields(
                &mut db,
                &user_id,
                json!({
                    "password": hashed,
                    "passwordUpdate": base::now_iso(),
                    "hash": hasher.name(),
                    "hashOptions": hasher.options(),
                }),
            )
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PUT /v1/users/:userId/labels` (`updateUserLabels`).
#[must_use]
pub fn update_labels() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Put)
                .set_http_path("/v1/users/:userId/labels")
                .desc("Update user labels")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "labels",
            json!([]),
            utopia_validators::ArrayList::new(Text::new(36)),
            "Array of user labels. Replaces the previous labels.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let labels = ctx
                .param_value("labels")
                .and_then(Value::as_array)
                .cloned()
                .unwrap_or_default();
            let mut unique = Vec::with_capacity(labels.len());
            for label in labels {
                if !unique.contains(&label) {
                    unique.push(label);
                }
            }
            base::update_user_fields(&mut db, &user_id, json!({ "labels": unique }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/impersonator` (`updateUserImpersonator`).
#[must_use]
pub fn update_impersonator() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/impersonator")
                .desc("Update user impersonator capability")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "impersonator",
            json!(false),
            Boolean::new().loose(true),
            "Whether the user can impersonate other users.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let impersonator = ctx
                .param_value("impersonator")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(&mut db, &user_id, json!({ "impersonator": impersonator }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/verification` (`updateUserEmailVerification`).
#[must_use]
pub fn update_verification() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/verification")
                .desc("Update email verification")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "verification.update")
                .label("audits.resource", "user/{request.userId}"),
        )
        .param(
            "emailVerification",
            json!(false),
            Boolean::new(),
            "User email verification status.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let verification = ctx
                .param_value("emailVerification")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(
                &mut db,
                &user_id,
                json!({ "emailVerification": verification }),
            )
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `PATCH /v1/users/:userId/verification/phone` (`updateUserPhoneVerification`).
#[must_use]
pub fn update_verification_phone() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/verification/phone")
                .desc("Update phone verification")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "verification.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "phoneVerification",
            json!(false),
            Boolean::new(),
            "User phone verification status.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let verification = ctx
                .param_value("phoneVerification")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(
                &mut db,
                &user_id,
                json!({ "phoneVerification": verification }),
            )
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}

/// `GET /v1/users/:userId/prefs` (`getUserPrefs`).
#[must_use]
pub fn get_prefs() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Get)
                .set_http_path("/v1/users/:userId/prefs")
                .desc("Get user preferences")
                .groups(["api", "users"])
                .label("scope", "users.read"),
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(prefs_of(&user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_PREFERENCES, result)
    })
}

/// `PATCH /v1/users/:userId/prefs` (`updateUserPrefs`).
#[must_use]
pub fn update_prefs() -> Action {
    inject(
        user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/prefs")
                .desc("Update user preferences")
                .groups(["api", "users"])
                .label("scope", "users.write"),
        )
        .param(
            "prefs",
            json!({}),
            Assoc,
            "Prefs key-value JSON object.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let prefs = ctx
                .param_value("prefs")
                .cloned()
                .unwrap_or_else(|| json!({}));
            base::update_user_fields(&mut db, &user_id, json!({ "prefs": prefs.clone() }))?;
            Ok(prefs)
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_PREFERENCES, result)
    })
}
