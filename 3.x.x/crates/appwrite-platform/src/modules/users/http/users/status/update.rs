//! `PATCH /v1/users/:userId/status` (`updateUserStatus`). Rust port of
//! `Http/Users/Status/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Boolean;

use crate::modules::users::base::{self, inject};

/// `PATCH /v1/users/:userId/status` (`updateUserStatus`).
#[must_use]
pub fn update() -> Action {
    inject(
        base::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/status")
                .desc("Update user status")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "user.update")
                .label("audits.resource", "user/{response.$id}"),
        )
        .param(
            "status",
            Value::Null,
            Boolean::new().loose(true),
            "User Status. To activate the user pass `true` and to block the user pass `false`.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let status = ctx
                .param_value("status")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(&mut db, &user_id, json!({ "status": status }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
