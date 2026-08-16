//! `PATCH /v1/users/:userId/targets/:targetId` (`updateUserTarget`). Rust port
//! of `Http/Users/Targets/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Text, Validator};

use crate::modules::users::base::{self, inject};
use crate::modules::users::validators::Email;
use crate::state::{document_from_json, document_to_json};

const MESSAGE_TYPE_EMAIL: &str = "email";
const MESSAGE_TYPE_SMS: &str = "sms";
const MESSAGE_TYPE_PUSH: &str = "push";

/// `PATCH /v1/users/:userId/targets/:targetId` (`updateUserTarget`).
#[must_use]
pub fn update() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Patch)
            .set_http_path("/v1/users/:userId/targets/:targetId")
            .desc("Update user target")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "target.update")
            .label("audits.resource", "target/{response.$id}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param("targetId", json!(""), Text::new(36), "Target ID.", false)
            .param(
                "identifier",
                json!(""),
                Text::new(325),
                "The target identifier (token, email, phone etc.)",
                true,
            )
            .param("providerId", json!(""), Text::new(36), "Provider ID.", true)
            .param(
                "name",
                json!(""),
                Text::new(128),
                "Target name. Max length: 128 chars.",
                true,
            ),
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

            let identifier = ctx
                .param_value("identifier")
                .and_then(Value::as_str)
                .unwrap_or_default();
            let provider_id = ctx
                .param_value("providerId")
                .and_then(Value::as_str)
                .unwrap_or_default();
            let name = ctx
                .param_value("name")
                .and_then(Value::as_str)
                .unwrap_or_default();

            let mut fields = serde_json::Map::new();
            if !identifier.is_empty() {
                let provider_type = target
                    .get_attribute("providerType")
                    .as_str()
                    .unwrap_or_default();
                match provider_type {
                    MESSAGE_TYPE_EMAIL => {
                        if !Email::new(false).is_valid(&json!(identifier)) {
                            return Err(Exception::new(Exception::GENERAL_ARGUMENT_INVALID));
                        }
                    }
                    MESSAGE_TYPE_SMS => {
                        if !appwrite_auth::Phone::new().is_valid(&json!(identifier)) {
                            return Err(Exception::new(Exception::GENERAL_ARGUMENT_INVALID));
                        }
                    }
                    MESSAGE_TYPE_PUSH => {}
                    _ => return Err(Exception::new(Exception::GENERAL_ARGUMENT_INVALID)),
                }
                fields.insert("identifier".into(), json!(identifier));
                fields.insert("expired".into(), json!(false));
            }
            if !provider_id.is_empty() {
                fields.insert("providerId".into(), json!(provider_id));
            }
            if !name.is_empty() {
                fields.insert("name".into(), json!(name));
            }

            let updated = db
                .update_document(
                    "targets",
                    &target_id,
                    document_from_json(Value::Object(fields)),
                )
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);
            Ok(document_to_json(&updated))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_TARGET, result)
    })
}
