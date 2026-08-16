//! `GET /v1/users/:userId/sessions` (`listUserSessions`). Rust port of
//! `Http/Users/Sessions/XList.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_database::Query;
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};
use crate::state::document_to_json;

/// `GET /v1/users/:userId/sessions` (`listUserSessions`).
#[must_use]
pub fn xlist() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Get)
            .set_http_path("/v1/users/:userId/sessions")
            .desc("List user sessions")
            .groups(["api", "users"])
            .label("scope", ["users.read", "sessions.read"])
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
            let user =
                base::require_document(&mut db, "users", &user_id, Exception::USER_NOT_FOUND)?;
            let include_total = ctx
                .param_value("total")
                .and_then(Value::as_bool)
                .unwrap_or(true);

            // Prefer `userInternalId` (same column PHP's relationship uses).
            let queries = [
                Query::equal("userInternalId", vec![base::sequence_str(&user).into()]),
                Query::limit(100),
            ];
            let sessions = db
                .find("sessions", &queries, "read")
                .map_err(base::db_error)?;
            let total = if include_total {
                sessions.len() as i64
            } else {
                0
            };
            let items: Vec<Value> = sessions
                .iter()
                .map(|session| {
                    let mut out = document_to_json(session);
                    out["current"] = json!(false);
                    out
                })
                .collect();
            Ok(json!({ "sessions": items, "total": total }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_SESSION_LIST, result)
    })
}
