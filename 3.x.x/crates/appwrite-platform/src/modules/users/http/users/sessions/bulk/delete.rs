//! `DELETE /v1/users/:userId/sessions` (`deleteUserSessions`). Rust port of
//! `Http/Users/Sessions/Bulk/Delete.php`.

use appwrite_exception::Exception;
use serde_json::json;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};

/// `DELETE /v1/users/:userId/sessions` (`deleteUserSessions`).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId/sessions")
            .desc("Delete user sessions")
            .groups(["api", "users"])
            .label("scope", ["users.write", "sessions.write"])
            .label("audits.event", "session.delete")
            .label("audits.resource", "user/{request.userId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            base::delete_user_sessions(&mut db, &user_id)
        })();
        base::finish_no_content(&ctx, result)
    })
}
