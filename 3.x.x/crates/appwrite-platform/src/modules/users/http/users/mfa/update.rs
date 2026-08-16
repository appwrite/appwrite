//! `PATCH /v1/users/:userId/mfa` (`updateUserMFA`). Rust port of
//! `Http/Users/MFA/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::{Boolean, Text};

use crate::modules::users::base::{self, inject};

/// `PATCH /v1/users/:userId/mfa` (`updateUserMFA`).
#[must_use]
pub fn update() -> Action {
    inject(
        Action::new()
            .set_http_method(HttpMethod::Patch)
            .set_http_path("/v1/users/:userId/mfa")
            .desc("Update MFA")
            .groups(["api", "users"])
            .label("scope", "users.write")
            .label("audits.event", "user.update")
            .label("audits.resource", "user/{response.$id}")
            .param("userId", json!(""), Text::new(36), "User ID.", false)
            .param(
                "mfa",
                Value::Null,
                Boolean::new(),
                "Enable or disable MFA.",
                false,
            ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock();
            let user_id = base::param_str(&ctx, "userId")?;
            let mfa = ctx
                .param_value("mfa")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(&mut db, &user_id, json!({ "mfa": mfa }))
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
