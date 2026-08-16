//! `DELETE /v1/users/:userId/mfa/authenticators/:type`
//! (`deleteUserMFAAuthenticator`). Rust port of
//! `Http/Users/MFA/Authenticators/Delete.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Text, WhiteList};

use crate::modules::users::base::{self, inject};
use crate::modules::users::http::users::mfa::shared;

/// `DELETE /v1/users/:userId/mfa/authenticators/:type`
/// (`deleteUserMFAAuthenticator`).
#[must_use]
pub fn delete() -> Action {
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

            let authenticator = shared::totp_authenticator(&mut db, &user_id)?
                .ok_or_else(|| Exception::new(Exception::USER_AUTHENTICATOR_NOT_FOUND))?;
            db.delete_document("authenticators", &authenticator.get_id())
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
