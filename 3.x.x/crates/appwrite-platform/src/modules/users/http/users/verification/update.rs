//! `PATCH /v1/users/:userId/verification` (`updateUserEmailVerification`). Rust
//! port of `Http/Users/Verification/Update.php`.

use appwrite_exception::Exception;
use serde_json::{json, Value};
use utopia_platform::{Action, HttpMethod};
use utopia_validators::Boolean;

use crate::modules::users::base::{self, inject};
use crate::modules::users::http::users::helpers;

/// `PATCH /v1/users/:userId/verification` (`updateUserEmailVerification`).
#[must_use]
pub fn update() -> Action {
    inject(
        helpers::user_id_param(
            Action::new()
                .set_http_method(HttpMethod::Patch)
                .set_http_path("/v1/users/:userId/verification")
                .desc("Update email verification")
                .groups(["api", "users"])
                .label("scope", "users.write")
                .label("audits.event", "verification.update")
                .label("audits.resource", "user/{request.userId}"),
        )
        .param(
            "emailVerification",
            json!(false),
            Boolean::new(),
            "User email verification status.",
            false,
        ),
        &["response", "dbForProject"],
    )
    .http_action(|ctx| async move {
        let result = (|| -> Result<Value, Exception> {
            let db_handle = base::get_db(&ctx)?;
            let mut db = db_handle.lock().unwrap_or_else(|e| e.into_inner());
            let user_id = base::param_str(&ctx, "userId")?;
            let verification = ctx
                .param_value("emailVerification")
                .and_then(Value::as_bool)
                .unwrap_or(false);
            base::update_user_fields(
                &mut db,
                &user_id,
                json!({ "emailVerification": verification }),
            )
        })();
        base::finish(&ctx, 200, appwrite_response::MODEL_USER, result)
    })
}
