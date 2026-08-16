//! `GET /v1/users/:userId/targets` (`listUserTargets`). Rust port of
//! `Http/Users/Targets/XList.php`.
//!
//! Simplifications versus PHP (documented, not silently dropped): no
//! `queries` DSL / cursor pagination on list.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `GET /v1/users/:userId/targets` (`listUserTargets`).
#[must_use]
pub fn xlist() -> Action {
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
            let mut db = db_handle.lock();
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
                db.count("targets", &queries, Some(5000))
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
