//! `POST /v1/users/:userId/targets` (`createUserTarget`). Rust port of
//! `Http/Users/Targets/Create.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `providers` collection lookup/validation.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Text, Validator, WhiteList};

use crate::modules::users::base::{self, inject};
use crate::modules::users::validators::Email;
use crate::state::{document_from_json, document_to_json};

const MESSAGE_TYPE_EMAIL: &str = "email";
const MESSAGE_TYPE_SMS: &str = "sms";
const MESSAGE_TYPE_PUSH: &str = "push";

/// `POST /v1/users/:userId/targets` (`createUserTarget`).
#[must_use]
pub fn create() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Post)
            .set_http_path("/v1/users/:userId/targets")
            .desc("Create user target")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "target.create")
            .label("audits.resource", "target/{response.$id}")
            .param(
                "targetId",
                json!(""),
                appwrite_database::CustomId::default(),
                "Target ID. Choose a custom ID or generate a random ID with `ID.unique()`.",
                false,
            )
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "providerType",
                json!(""),
                WhiteList::new([MESSAGE_TYPE_EMAIL, MESSAGE_TYPE_SMS, MESSAGE_TYPE_PUSH]),
                "The target provider type. Can be one of the following: `email`, `sms` or `push`.",
                false,
            )
            .param(
                "identifier",
                json!(""),
                Text::new(325),
                "The target identifier (token, email, phone etc.)",
                false,
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
            let mut db = db_handle.lock();
            let target_id = base::param_str(&ctx, "targetId")?;
            let user_id = base::param_str(&ctx, "userId")?;
            let provider_type = base::param_str(&ctx, "providerType")?;
            let identifier = base::param_str(&ctx, "identifier")?;
            let provider_id = ctx
                .param_value("providerId")
                .and_then(Value::as_str)
                .unwrap_or_default();
            let name = ctx
                .param_value("name")
                .and_then(Value::as_str)
                .unwrap_or_default();

            match provider_type.as_str() {
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

            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;

            let resolved_id = appwrite_database::resolve_id(&target_id);
            let existing = db
                .get_document("targets", &resolved_id, &[], false)
                .map_err(base::db_error)?;
            if !existing.is_empty() {
                return Err(Exception::new(Exception::USER_TARGET_ALREADY_EXISTS));
            }

            let target_json = json!({
                "$id": resolved_id,
                "$permissions": base::owner_permissions(&user_id),
                "providerId": if provider_id.is_empty() { Value::Null } else { json!(provider_id) },
                "providerType": provider_type,
                "userId": user_id,
                "userInternalId": base::sequence_of(&user),
                "identifier": identifier,
                "name": if name.is_empty() { Value::Null } else { json!(name) },
                "expired": false,
            });
            let created = db
                .create_document("targets", document_from_json(target_json))
                .map_err(base::db_error)?;
            base::purge_user(&mut db, &user_id);

            Ok(document_to_json(&created))
        })();
        base::finish(&ctx, 201, appwrite_response::MODEL_TARGET, result)
    })
}
