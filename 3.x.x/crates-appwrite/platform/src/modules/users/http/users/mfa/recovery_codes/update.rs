//! `PUT /v1/users/:userId/mfa/recovery-codes` (`updateUserMFARecoveryCodes`).
//! Rust port of `Http/Users/MFA/RecoveryCodes/Update.php`.

use appwrite_exception::Exception;
use serde_json::json;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};
use crate::state::document_from_json;

/// `PUT /v1/users/:userId/mfa/recovery-codes` (`updateUserMFARecoveryCodes`).
#[must_use]
pub fn update() -> Action {
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
        base::finish_blocking(
            ctx,
            200,
            appwrite_response::MODEL_MFA_RECOVERY_CODES,
            |ctx| {
                let db_handle = base::get_db(&ctx)?;
                let mut db = db_handle.lock();
                let user_id = base::param_str(&ctx, "userId")?;
                let user =
                    base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

                if base::recovery_codes_of(&user).is_empty() {
                    return Err(Exception::new(Exception::USER_RECOVERY_CODES_NOT_FOUND));
                }

                let codes = base::generate_backup_codes()?;
                db.update_document(
                    "users",
                    &user_id,
                    document_from_json(json!({ "mfaRecoveryCodes": codes })),
                )
                .map_err(base::db_error)?;

                Ok(json!({ "recoveryCodes": codes }))
            },
        )
        .await
    })
}
