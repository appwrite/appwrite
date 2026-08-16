//! `DELETE /v1/users/:userId/sessions/:sessionId` (`deleteUserSession`). Rust
//! port of `Http/Users/Sessions/Delete.php`.

use appwrite_exception::Exception;
use serde_json::json;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// `DELETE /v1/users/:userId/sessions/:sessionId` (`deleteUserSession`).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId/sessions/:sessionId")
            .desc("Delete user session")
            .groups(["api", "users"])
            .label("scope", ["users.write", "sessions.write"])
            .label("audits.event", "session.delete")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param("sessionId", json!(""), Text::new(36), "Session ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let session_id = base::param_str(&ctx, "sessionId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let session = db
                .get_document("sessions", &session_id, &[], false)
                .map_err(base::db_error)?;
            if session.is_empty()
                || session.get_attribute("userId").as_str() != Some(user_id.as_str())
            {
                return Err(Exception::new(Exception::USER_SESSION_NOT_FOUND));
            }
            db.delete_document("sessions", &session_id)
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
