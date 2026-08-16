//! `GET /v1/users/:userId/mfa/recovery-codes` (`getUserMFARecoveryCodes`). Rust
//! port of `Http/Users/MFA/RecoveryCodes/Get.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// `GET /v1/users/:userId/mfa/recovery-codes` (`getUserMFARecoveryCodes`).
#[must_use]
pub fn get() -> Action {
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

            let codes = base::recovery_codes_of(&user);
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
