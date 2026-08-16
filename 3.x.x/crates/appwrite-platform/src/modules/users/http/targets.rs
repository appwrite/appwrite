//! User notification target endpoints. Rust port of
//! `Http/Users/Targets/{Create,Get,XList,Update,Delete}.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `providers` collection lookup/validation (the `providerId` param is
//! stored as given, without checking a `providers` document exists or
//! matches `providerType` -- self-hosted messaging providers are out of
//! scope for the Users-API v1 milestone), and no `queries` DSL / cursor
//! pagination on list (see `crud::list`).

use appwrite_event::{DeleteMessage, DeletePublisher};
use appwrite_exception::Exception;
use serde_json::{json, Value};
use std::sync::Arc;
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text, Validator, WhiteList};

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
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
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

/// `GET /v1/users/:userId/targets` (`listUserTargets`), simplified: no
/// `queries` DSL / cursor pagination (see module docs).
#[must_use]
pub fn list() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/targets")
            .desc("List user targets")
            .groups(["api", "users"])
            .label("scope", "users.read")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "total",
                json!(true),
                Boolean::new().loose(true),
                "Include total count.",
                true,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let include_total = ctx
                .param_value("total")
                .and_then(Value::as_bool)
                .unwrap_or(true);

            let queries = [
                Query::equal("userId", vec![user_id.clone().into()]),
                Query::limit(100),
            ];
            let targets = db
                .find("targets", &queries, "read")
                .map_err(base::db_error)?;
            let total = if include_total {
                db.count("targets", &queries, None)
                    .map_err(base::db_error)?
            } else {
                0
            };
            Ok(json!({
                "targets": targets.iter().map(document_to_json).collect::<Vec<_>>(),
                "total": total,
            }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_TARGET_LIST, result)
    })
}

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
