//! `DELETE /v1/users/:userId/targets/:targetId` (`deleteUserTarget`). Rust port
//! of `Http/Users/Targets/Delete.php`.

use appwrite_event::{DeleteMessage, DeletePublisher};
use appwrite_exception::Exception;
use serde_json::json;
use std::sync::Arc;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `DELETE /v1/users/:userId/targets/:targetId` (`deleteUserTarget`).
#[must_use]
pub fn delete() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Delete)
            .set_http_path("/v1/users/:userId/targets/:targetId")
            .desc("Delete user target")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "target.delete")
            .label("audits.resource", "target/{request.targetId}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param("targetId", json!(""), Text::new(36), "Target ID.", false),
        &["response", "dbForProject", "publisherForDeletes"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<(), Exception> {
            let db_handle = base::get_db(&ctx)?;
            let deletes = ctx
                .container
                .get_as::<Arc<dyn DeletePublisher>>("publisherForDeletes")
                .map_err(|_| Exception::new(Exception::GENERAL_SERVER_ERROR))?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let target_id = base::param_str(&ctx, "targetId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let target = db
                .get_document("targets", &target_id, &[], false)
                .map_err(base::db_error)?;
            if target.is_empty()
                || target.get_attribute("userId").as_str() != Some(user_id.as_str())
            {
                return Err(Exception::new(Exception::USER_TARGET_NOT_FOUND));
            }

            db.delete_document("targets", &target_id)
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);

            let message = DeleteMessage::new(appwrite_event::DELETE_TYPE_TARGET)
                .with_document(document_to_json(&target));
            let _ = deletes.enqueue(message);
            Ok(())
        })();
        base::finish_no_content(&ctx, result)
    })
}
