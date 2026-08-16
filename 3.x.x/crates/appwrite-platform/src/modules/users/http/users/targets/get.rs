//! `GET /v1/users/:userId/targets/:targetId` (`getUserTarget`). Rust port of
//! `Http/Users/Targets/Get.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `GET /v1/users/:userId/targets/:targetId` (`getUserTarget`).
#[must_use]
pub fn get() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/targets/:targetId")
            .desc("Get user target")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param("targetId", json!(""), Text::new(36), "Target ID.", false),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
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
            Ok(document_to_json(&target))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_TARGET, result)
    })
}
