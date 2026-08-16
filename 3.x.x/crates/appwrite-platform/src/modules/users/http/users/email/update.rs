//! `PATCH /v1/users/:userId/email` (`updateUserEmail`). Rust port of
//! `Http/Users/Email/Update.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): email
//! canonical/disposable/corporate/free metadata policies and cloud `$plan`
//! gating are not implemented (see [`crate::modules::users::base`] docs).

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};

use crate::modules::users::base::{self, inject};

use crate::modules::users::validators::Email;
use crate::state::document_from_json;

/// `PATCH /v1/users/:userId/email` (`updateUserEmail`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
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
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            if !email.is_empty() {
                // PHP checks unconditionally (not scoped to this user), so an
                // update to an email already used by *any* target -- including
                // this user's own current target -- fails the same way.
                if base::find_one(&mut db, "identities", "providerEmail", email.as_str())?.is_some()
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

            let mut fields = json!({
                "email": if email.is_empty() { Value::Null } else { json!(email) },
                "emailVerification": false,
            });
            base::merge_into(&mut fields, &base::email_metadata(Some(email.as_str())));
            base::update_user_fields_and_search(&mut db, &user_id, fields)?;

            match base::find_target_by_identifier(&mut db, &user_id, &old_email)? {
                Some(old_target) if !email.is_empty() => {
                    let _ = db.update_document(
                        "targets",
                        &old_target.get_id(),
                        document_from_json(json!({ "identifier": email })),
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
            base::purge_user(&mut db, &user_id);

            let final_user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            Ok(base::user_with_targets(&mut db, &final_user))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
