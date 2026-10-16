//! `GET /v1/users/:userId/mfa/challenges/:challengeId` (`getUserMFAChallenge`).
//! Rust port of `Http/Users/MFA/Challenges/Get.php`.

use appwrite_exception::Exception;
use serde_json::json;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Text;

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `GET /v1/users/:userId/mfa/challenges/:challengeId` (`getUserMFAChallenge`).
#[must_use]
pub fn get() -> Action {
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
        base::finish_blocking(
            ctx,
            200,
            appwrite_response::MODEL_MFA_CHALLENGE_SECRET,
            |ctx| {
                let db_handle = base::get_db(&ctx)?;
                let mut db = db_handle.lock();
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
            },
        )
        .await
    })
}
